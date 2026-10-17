<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * C89.3 backend-only current-state permission exception service.
 * Legacy direct permission pivots remain untouched and ALLOW-only.
 */
class ErpUserPermissionOverrideService
{
    public function __construct(
        private readonly ErpPermissionMatrixService $permissions,
        private readonly ErpUserManagementAuthority $authority,
        private readonly ErpUserManagementService $users,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function current(int $targetUserId): array
    {
        if (! Schema::hasTable('user_permission_overrides')) {
            return [];
        }

        return DB::table('user_permission_overrides')
            ->where('user_id', $targetUserId)
            ->orderBy('permission_id')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /** @param array<int|string,string> $changes */
    public function syncOverrides(mixed $actor, int $targetUserId, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        if (! Schema::hasTable('user_permission_overrides')) {
            throw new RuntimeException('Permission override storage is not available.');
        }

        foreach ($changes as $permissionId => $effect) {
            $effect = strtoupper(trim((string) $effect));
            if (! in_array($effect, ['INHERIT', 'ALLOW', 'DENY'], true)) {
                throw new RuntimeException('Unknown permission override state.');
            }
            if ($effect === 'INHERIT') {
                $this->removeOverride($actor, $targetUserId, (int) $permissionId, 'ERP User Management');
            } else {
                $this->setOverride($actor, $targetUserId, (int) $permissionId, $effect, 'ERP User Management');
            }
        }
    }

    public function setOverride(
        mixed $actor,
        int $targetUserId,
        int|string $permission,
        string $effect,
        ?string $reason = null
    ): void {
        $effect = strtoupper(trim($effect));
        if (! in_array($effect, ['ALLOW', 'DENY'], true)) {
            throw new RuntimeException('Permission override effect must be ALLOW or DENY.');
        }

        $permissionRow = $this->resolvePermission($permission);
        $this->authorizeMutation($actor, $targetUserId, $permissionRow);

        DB::transaction(function () use ($actor, $targetUserId, $permissionRow, $effect, $reason): void {
            $existing = DB::table('user_permission_overrides')
                ->where('user_id', $targetUserId)
                ->where('permission_id', (int) $permissionRow['id'])
                ->lockForUpdate()
                ->first();
            $oldEffect = $existing ? strtoupper((string) $existing->effect) : null;

            DB::table('user_permission_overrides')->updateOrInsert(
                [
                    'user_id' => $targetUserId,
                    'permission_id' => (int) $permissionRow['id'],
                ],
                [
                    'effect' => $effect,
                    'reason' => $reason !== null ? trim($reason) : null,
                    'created_by' => $existing?->created_by ?: $this->actorId($actor),
                    'updated_by' => $this->actorId($actor),
                    'updated_at' => now(),
                    'created_at' => $existing?->created_at ?: now(),
                ]
            );

            $event = $oldEffect === null
                ? 'permission_override.'.strtolower($effect).'_added'
                : ($oldEffect === $effect ? 'permission_override.changed' : 'permission_override.changed');
            $this->writeAudit($actor, $targetUserId, $event, $permissionRow, $oldEffect, $effect, $reason);
        });
    }

    public function removeOverride(
        mixed $actor,
        int $targetUserId,
        int|string $permission,
        ?string $reason = null
    ): void {
        $permissionRow = $this->resolvePermission($permission);
        $this->authorizeMutation($actor, $targetUserId, $permissionRow);

        DB::transaction(function () use ($actor, $targetUserId, $permissionRow, $reason): void {
            $query = DB::table('user_permission_overrides')
                ->where('user_id', $targetUserId)
                ->where('permission_id', (int) $permissionRow['id'])
                ->lockForUpdate();
            $existing = $query->first();
            if (! $existing) {
                return;
            }

            $query->delete();
            $this->writeAudit(
                $actor,
                $targetUserId,
                'permission_override.removed',
                $permissionRow,
                strtoupper((string) $existing->effect),
                null,
                $reason
            );
        });
    }

    /** @return array<string,mixed> */
    private function resolvePermission(int|string $permission): array
    {
        foreach ($this->permissions->allPermissions() as $row) {
            if ((is_int($permission) || ctype_digit((string) $permission)) && (int) $row['id'] === (int) $permission) {
                return $row;
            }
            $needle = strtolower(trim((string) $permission));
            if ($needle !== '' && in_array($needle, [strtolower((string) ($row['code'] ?? '')), strtolower((string) ($row['name'] ?? ''))], true)) {
                return $row;
            }
        }

        throw new RuntimeException('Unknown permission override target.');
    }

    private function authorizeMutation(mixed $actor, int $targetUserId, array $permission): void
    {
        if (! $actor || ! $this->authority->canManage($actor)) {
            throw new RuntimeException('You are not authorized to manage permission overrides.');
        }

        $actorId = $this->actorId($actor);
        if ($actorId <= 0 || $actorId === $targetUserId) {
            throw new RuntimeException('Users cannot edit their own permission overrides.');
        }

        $target = $this->users->user($targetUserId);
        if (! $target) {
            throw new RuntimeException('Target ERP user account was not found.');
        }
        if ($this->arrayIsSuperAdmin($target)) {
            throw new RuntimeException('Super Admin permission overrides are not editable.');
        }
        if (! $this->sameCompany($actorId, $targetUserId)) {
            throw new RuntimeException('Cross-company permission overrides are not permitted.');
        }
        if (! $this->permissions->hasPermissionLike($actor, [
            'manage roles and permissions',
            'manage permissions',
            'manage administration',
        ])) {
            throw new RuntimeException('Administration permission is required to manage overrides.');
        }
        if (! $this->permissions->isSuperAdmin($actor) && ! $this->actorPossesses($actor, $permission)) {
            throw new RuntimeException('Managers may not grant permissions they do not possess.');
        }
    }

    private function actorPossesses(mixed $actor, array $permission): bool
    {
        $needle = strtolower(trim((string) ($permission['name'] ?? $permission['code'] ?? '')));
        foreach ($this->permissions->effectivePermissions($actor) as $row) {
            if ((int) ($row['id'] ?? 0) === (int) ($permission['id'] ?? 0)) {
                return true;
            }
            if ($needle !== '' && strtolower(trim((string) ($row['name'] ?? $row['code'] ?? ''))) === $needle) {
                return true;
            }
        }
        return false;
    }

    private function sameCompany(int $actorId, int $targetId): bool
    {
        $columns = Schema::getColumnListing('users');
        $company = collect(['company_id', 'company', 'organization_id'])->first(fn ($column) => in_array($column, $columns, true));
        if (! $company) {
            return true;
        }
        $actorCompany = DB::table('users')->where('id', $actorId)->value($company);
        $targetCompany = DB::table('users')->where('id', $targetId)->value($company);
        return $actorCompany !== null && $targetCompany !== null && (string) $actorCompany === (string) $targetCompany;
    }

    private function writeAudit(mixed $actor, int $targetUserId, string $event, array $permission, ?string $old, ?string $new, ?string $reason): void
    {
        if (! Schema::hasTable('audit_logs')) {
            throw new RuntimeException('Audit log storage is required for permission override mutations.');
        }

        DB::table('audit_logs')->insert([
            'user_id' => $this->actorId($actor),
            'event' => $event,
            'auditable_type' => 'App\\Models\\User',
            'auditable_id' => $targetUserId,
            'old_values' => json_encode(['permission_id' => (int) $permission['id'], 'permission' => $permission['name'] ?? $permission['code'] ?? null, 'effect' => $old, 'reason' => $reason]),
            'new_values' => json_encode(['permission_id' => (int) $permission['id'], 'permission' => $permission['name'] ?? $permission['code'] ?? null, 'effect' => $new, 'reason' => $reason]),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function actorId(mixed $actor): int
    {
        return (int) ($actor?->getAuthIdentifier() ?? $actor?->id ?? 0);
    }

    private function arrayIsSuperAdmin(array $user): bool
    {
        foreach ((array) ($user['role_names'] ?? []) as $role) {
            if (in_array(strtolower(trim(str_replace(['-', '_'], ' ', (string) $role))), ['super admin', 'superadmin'], true)) {
                return true;
            }
        }
        return false;
    }
}
