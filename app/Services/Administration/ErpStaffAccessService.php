<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Focused access-only orchestration for the Staff Access screen. */
class ErpStaffAccessService
{
    public function __construct(
        private readonly ErpUserManagementService $users,
        private readonly ErpPermissionMatrixService $permissions,
        private readonly ErpUserPermissionOverrideService $overrides,
        private readonly ErpUserManagementAuthority $authority,
        private readonly ErpBranchAccessAuthority $branches,
    ) {
    }

    public function indexData(mixed $actor, array $filters = []): array
    {
        $this->authority->authorize($actor);
        $actorId = $this->actorId($actor);
        $all = array_values(array_filter($this->users->users(), fn (array $user): bool => $this->sameCompany($actorId, (int) $user['id'])));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $role = strtolower(trim((string) ($filters['role'] ?? '')));
        if ($search !== '' || $status !== '' || $role !== '') {
            $all = array_values(array_filter($all, function (array $user) use ($search, $status, $role): bool {
                $haystack = strtolower(implode(' ', [(string) ($user['username'] ?? ''), (string) ($user['staff']['code'] ?? '')]));
                $roles = strtolower(implode(' ', (array) ($user['role_names'] ?? [])));
                return ($search === '' || str_contains($haystack, $search))
                    && ($status === '' || ($status === 'active' ? (bool) $user['active'] : ! (bool) $user['active']))
                    && ($role === '' || str_contains($roles, $role));
            }));
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 25;
        $total = count($all);
        $users = array_slice($all, ($page - 1) * $perPage, $perPage);
        return compact('users', 'total', 'page', 'perPage') + [
            'roles' => $this->users->roles(),
            'branches' => $this->sameCompanyBranches($actorId),
            'filters' => $filters,
            'pagination_mode' => 'BOUNDED_ADAPTIVE_SERVICE_PAGINATION',
        ];
    }

    public function read(mixed $actor, int $targetId): array
    {
        $this->authority->authorize($actor);
        $target = $this->users->user($targetId);
        abort_unless($target && $this->sameCompany($this->actorId($actor), $targetId), 404);
        $targetObject = (object) ['id' => $targetId];
        $details = $this->permissions->permissionOverrideDetails($targetObject);
        $canDelegate = $this->permissions->isSuperAdmin($actor)
            ? array_fill_keys(array_map(fn (array $row): int => (int) $row['id'], $details), true)
            : array_fill_keys(array_map(fn (array $row): int => (int) $row['id'], $this->permissions->effectivePermissions($actor)), true);
        $permissions = [];
        foreach ($details as $row) {
            $row['inherited_denied'] = ! (bool) ($row['inherited_allowed'] ?? false);
            $row['effective_denied'] = ! (bool) ($row['effective_allowed'] ?? false);
            $row['can_allow'] = isset($canDelegate[(int) $row['id']]);
            $permissions[$this->friendlyGroup($row)][] = $row;
        }
        return [
            'target' => $target,
            'roles' => $this->users->roles(),
            'branches' => $this->sameCompanyBranches($this->actorId($actor)),
            'permissions' => $permissions,
            'override_table_available' => Schema::hasTable('user_permission_overrides'),
            'read_only' => $targetId === $this->actorId($actor) || $this->isSuperAdminTarget($target),
            'is_self' => $targetId === $this->actorId($actor),
            'is_super_admin' => $this->isSuperAdminTarget($target),
            'schema' => $this->users->schema(),
            'scope_options' => ['PRIMARY_BRANCH' => 'Primary Branch', 'ASSIGNED_BRANCHES' => 'Assigned Branches', 'COMPANY_WIDE' => 'Company Wide'],
        ];
    }

    public function save(mixed $actor, int $targetId, array $input): array
    {
        $this->authority->authorize($actor);
        $actorId = $this->actorId($actor);
        if ($targetId === $actorId) throw new RuntimeException('You cannot change your own Staff Access settings.');
        $target = $this->users->user($targetId);
        if (! $target || ! $this->sameCompany($actorId, $targetId)) throw new RuntimeException('Target ERP user is outside your company boundary.');
        if ($this->isSuperAdminTarget($target)) throw new RuntimeException('Super Admin access cannot be changed here.');
        $expected = trim((string) ($input['expected_updated_at'] ?? ''));
        DB::transaction(function () use ($actor, $actorId, $targetId, $target, $input, $expected): void {
            $locked = DB::table('users')->where('id', $targetId)->lockForUpdate()->first();
            if (! $locked) throw new RuntimeException('Target ERP user account was not found.');
            if ($expected !== '' && isset($locked->updated_at) && (string) $locked->updated_at !== $expected) {
                throw new RuntimeException('Access changed since this screen was opened. Reload and review before saving.');
            }
            $roleIds = array_values(array_unique(array_filter(array_map('intval', (array) ($input['role_ids'] ?? [])), fn (int $id): bool => $id > 0)));
            $this->validateRoles($actor, $roleIds);
            $changes = $this->overrideChanges($input);
            if ($changes !== [] && ! Schema::hasTable('user_permission_overrides')) {
                throw new RuntimeException('Permission exceptions are not available until the access schema is enabled.');
            }
            $before = $this->users->user($targetId) ?? $target;
            $after = $this->users->updateAccessAssignments($targetId, [
                'role_ids' => $roleIds,
                'access_scope' => $input['access_scope'] ?? null,
                'primary_branch_id' => $input['primary_branch_id'] ?? 0,
                'branch_ids' => $input['branch_ids'] ?? [],
            ]);
            foreach ($changes as $permissionId => $effect) {
                if ($effect === 'INHERIT') $this->overrides->removeOverride($actor, $targetId, $permissionId, 'Staff Access');
                else $this->overrides->setOverride($actor, $targetId, $permissionId, $effect, 'Staff Access');
            }
            $this->auditChanges($actorId, $targetId, $before, $after);
        });
        return $this->users->user($targetId) ?? [];
    }

    private function overrideChanges(array $input): array
    {
        $result = [];
        foreach ((array) ($input['overrides'] ?? []) as $id => $effect) {
            $effect = strtoupper(trim((string) $effect));
            if (! in_array($effect, ['INHERIT', 'ALLOW', 'DENY'], true)) throw new RuntimeException('Invalid permission exception state.');
            $result[(int) $id] = $effect;
        }
        return array_filter($result, fn (string $effect): bool => $effect !== '');
    }

    private function validateRoles(mixed $actor, array $roleIds): void
    {
        if ($this->permissions->isSuperAdmin($actor)) return;
        $roles = $this->users->roles();
        foreach ($roles as $role) {
            if (! in_array((int) $role['id'], $roleIds, true)) continue;
            $name = strtolower(str_replace(['-', '_'], ' ', (string) $role['name']));
            if (in_array(trim($name), ['super admin', 'superadmin', 'administrator'], true) || str_contains($name, 'permission') || str_contains($name, 'user management')) {
                throw new RuntimeException('Managers may only assign roles within their delegated authority; protected management roles are denied.');
            }
        }
    }

    private function auditChanges(int $actorId, int $targetId, array $before, array $after): void
    {
        if (! Schema::hasTable('audit_logs')) throw new RuntimeException('Audit log storage is required for Staff Access changes.');
        $events = [
            'role_names' => 'staff_access.roles_changed',
            'primary_branch_id' => 'staff_access.primary_branch_changed',
            'branch_ids' => 'staff_access.assigned_branches_changed',
            'access_scope' => 'staff_access.scope_changed',
        ];
        foreach ($events as $key => $event) {
            if (($before[$key] ?? null) === ($after[$key] ?? null)) continue;
            DB::table('audit_logs')->insert([
                'user_id' => $actorId, 'event' => $event, 'auditable_type' => 'App\\Models\\User', 'auditable_id' => $targetId,
                'old_values' => json_encode([$key => $before[$key] ?? null]), 'new_values' => json_encode([$key => $after[$key] ?? null]),
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(), 'created_at' => now(),
            ]);
        }
    }

    private function friendlyGroup(array $row): string
    {
        $text = strtolower((string) ($row['name'] ?? '').' '.($row['code'] ?? '').' '.($row['group'] ?? ''));
        return match (true) {
            str_contains($text, 'supplier') || str_contains($text, 'costing') => 'Supplier Costing',
            str_contains($text, 'invoice') => 'Sales Invoices',
            str_contains($text, 'receipt') || str_contains($text, 'payment') || str_contains($text, 'cash') => 'Payment / Receipt',
            str_contains($text, 'journal') || str_contains($text, 'ledger') || str_contains($text, 'account') => 'Accounting',
            str_contains($text, 'report') => 'Travel Reports',
            str_contains($text, 'master') || str_contains($text, 'airline') || str_contains($text, 'hotel') || str_contains($text, 'visa') || str_contains($text, 'transport') => 'Master Data',
            str_contains($text, 'user') || str_contains($text, 'role') || str_contains($text, 'permission') || str_contains($text, 'admin') => 'Administration',
            str_contains($text, 'booking') || str_contains($text, 'passenger') || str_contains($text, 'travel') => 'Bookings & Travel Services',
            str_contains($text, 'system') || str_contains($text, 'setting') || str_contains($text, 'health') => 'System / Settings',
            default => 'Other / Unmapped',
        };
    }

    private function sameCompany(int $actorId, int $targetId): bool
    {
        $columns = Schema::getColumnListing('users');
        $company = collect(['company_id', 'company', 'organization_id'])->first(fn (string $column): bool => in_array($column, $columns, true));
        if (! $company) return true;
        $a = DB::table('users')->where('id', $actorId)->value($company);
        $b = DB::table('users')->where('id', $targetId)->value($company);
        return $a !== null && $b !== null && (string) $a === (string) $b;
    }

    private function sameCompanyBranches(int $actorId): array
    {
        $all = $this->users->branches();
        $columns = $this->users->schema()['user_columns'];
        $company = collect(['company_id', 'company', 'organization_id'])->first(fn (string $column): bool => in_array($column, $columns, true));
        if (! $company || ! $this->users->schema()['branches_table']) return $all;
        $value = DB::table('users')->where('id', $actorId)->value($company);
        $table = $this->users->schema()['branches_table'];
        if (! in_array('company_id', Schema::getColumnListing($table), true)) return $all;
        $ids = DB::table($table)->where('company_id', $value)->pluck($this->users->schema()['branch_id_column'])->map(fn ($id): int => (int) $id)->all();
        return array_values(array_filter($all, fn (array $branch): bool => in_array((int) $branch['id'], $ids, true)));
    }

    private function isSuperAdminTarget(array $target): bool
    {
        foreach ((array) ($target['role_names'] ?? []) as $role) {
            if (in_array(strtolower(str_replace(['-', '_'], ' ', trim((string) $role))), ['super admin', 'superadmin'], true)) return true;
        }
        return false;
    }

    private function actorId(mixed $actor): int
    {
        return (int) ($actor?->getAuthIdentifier() ?? $actor?->id ?? 0);
    }
}
