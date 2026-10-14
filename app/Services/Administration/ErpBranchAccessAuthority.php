<?php

namespace App\Services\Administration;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C89.2.1 canonical branch authority for booking-bound operational data.
 *
 * This service deliberately owns scope normalization and branch resolution so
 * controllers do not grow incompatible branch checks of their own.
 */
final class ErpBranchAccessAuthority
{
    public const PRIMARY_BRANCH = 'PRIMARY_BRANCH';
    public const ASSIGNED_BRANCHES = 'ASSIGNED_BRANCHES';
    public const COMPANY_WIDE = 'COMPANY_WIDE';

    public function __construct(private readonly ErpPermissionMatrixService $permissions)
    {
    }

    public function isSuperAdmin(mixed $user): bool
    {
        return (bool) $user && $this->permissions->isSuperAdmin($user);
    }

    public function effectiveScope(mixed $user): ?string
    {
        if ($this->isSuperAdmin($user)) {
            return self::COMPANY_WIDE;
        }

        $raw = strtoupper(trim((string) $this->attribute($user, 'access_scope')));
        if ($raw === '') {
            return self::PRIMARY_BRANCH;
        }

        return in_array($raw, [self::PRIMARY_BRANCH, self::ASSIGNED_BRANCHES, self::COMPANY_WIDE], true)
            ? $raw
            : null;
    }

    public function primaryBranchId(mixed $user): ?int
    {
        foreach (['primary_branch_id', 'branch_id', 'home_branch_id'] as $field) {
            $id = $this->positive($this->attribute($user, $field));
            if ($id && $this->branchBelongsToUserCompany($id, $user)) {
                return $id;
            }
        }

        $userId = $this->positive($this->attribute($user, 'id'));
        foreach ($this->branchLinkTables() as $table) {
            if (! Schema::hasTable($table) || ! $userId) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $userColumn = $this->first($columns, ['user_id', 'staff_user_id']);
            $branchColumn = $this->first($columns, ['branch_id', 'office_id']);
            if (! $userColumn || ! $branchColumn) {
                continue;
            }
            $query = DB::table($table)->where($userColumn, $userId);
            foreach (['is_primary', 'primary', 'is_default', 'default'] as $flag) {
                if (in_array($flag, $columns, true)) {
                    $id = $this->positive((clone $query)->where($flag, 1)->value($branchColumn));
                    if ($id && $this->branchBelongsToUserCompany($id, $user)) {
                        return $id;
                    }
                }
            }
        }

        $staffId = $this->positive($this->attribute($user, 'staff_id'));
        if ($staffId && Schema::hasTable('staff') && Schema::hasColumn('staff', 'branch_id')) {
            $id = $this->positive(DB::table('staff')->where('id', $staffId)->value('branch_id'));
            if ($id && $this->branchBelongsToUserCompany($id, $user)) {
                return $id;
            }
        }

        $assigned = $this->assignedBranchIds($user);
        return count($assigned) === 1 ? $assigned[0] : null;
    }

    public function assignedBranchIds(mixed $user): array
    {
        $userId = $this->positive($this->attribute($user, 'id'));
        if (! $userId) {
            return [];
        }

        foreach ($this->branchLinkTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $userColumn = $this->first($columns, ['user_id', 'staff_user_id']);
            $branchColumn = $this->first($columns, ['branch_id', 'office_id']);
            if (! $userColumn || ! $branchColumn) {
                continue;
            }
            return $this->companyBranches(
                DB::table($table)->where($userColumn, $userId)->pluck($branchColumn)->map(fn ($v): int => (int) $v)->all(),
                $user
            );
        }

        return [];
    }

    public function allowedBranchIds(mixed $user): ?array
    {
        if ($this->isSuperAdmin($user)) {
            return null;
        }

        return match ($this->effectiveScope($user)) {
            self::PRIMARY_BRANCH => ($id = $this->primaryBranchId($user)) ? [$id] : [],
            self::ASSIGNED_BRANCHES => $this->assignedBranchIds($user),
            self::COMPANY_WIDE => $this->companyBranches($this->allBranchIds(), $user),
            default => [],
        };
    }

    public function mayAccessBranch(mixed $user, ?int $branchId): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }
        $branchId = (int) $branchId;
        $allowed = $this->allowedBranchIds($user);
        return $branchId > 0 && is_array($allowed) && in_array($branchId, $allowed, true);
    }

    public function assertMayAccessBranch(mixed $user, ?int $branchId): void
    {
        abort_unless($this->mayAccessBranch($user, $branchId), 403, 'The requested branch is outside your authorized scope.');
    }

    public function scopeQuery(Builder $query, mixed $user, string $branchColumn = 'branch_id'): Builder
    {
        $allowed = $this->allowedBranchIds($user);
        if ($allowed === null) {
            return $query;
        }
        if ($allowed === []) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereIn($branchColumn, $allowed);
    }

    public function branchFilter(mixed $user, mixed $requested): ?int
    {
        if ($requested === null || trim((string) $requested) === '') {
            return null;
        }
        $branchId = $this->positive($requested);
        $this->assertMayAccessBranch($user, $branchId);
        return $branchId;
    }

    private function allBranchIds(): array
    {
        $table = $this->branchTable();
        if (! $table) {
            return [];
        }
        $id = $this->first(Schema::getColumnListing($table), ['id', 'branch_id', 'office_id']);
        return $id ? DB::table($table)->pluck($id)->map(fn ($v): int => (int) $v)->filter()->values()->all() : [];
    }

    private function companyBranches(array $ids, mixed $user): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $companyId = $this->positive($this->attribute($user, 'company_id'));
        $table = $this->branchTable();
        if (! $table || ! $ids || ! $companyId) {
            return [];
        }
        $columns = Schema::getColumnListing($table);
        if (! in_array('company_id', $columns, true)) {
            return $ids;
        }
        $id = $this->first($columns, ['id', 'branch_id', 'office_id']);
        return DB::table($table)->whereIn($id, $ids)->where('company_id', $companyId)->pluck($id)->map(fn ($v): int => (int) $v)->all();
    }

    private function branchBelongsToUserCompany(int $branchId, mixed $user): bool
    {
        return in_array($branchId, $this->companyBranches([$branchId], $user), true);
    }

    private function branchTable(): ?string
    {
        foreach (['branches', 'branch_master', 'branch_masters', 'offices', 'office_master', 'office_masters'] as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }
        return null;
    }

    private function branchLinkTables(): array
    {
        return ['branch_user', 'user_branches', 'user_branch', 'branch_user_access', 'user_branch_access', 'user_allowed_branches', 'allowed_branch_user'];
    }

    private function attribute(mixed $user, string $key): mixed
    {
        if (! $user) return null;
        try { return method_exists($user, 'getAttribute') ? ($user->getAttribute($key) ?? null) : ($user->{$key} ?? null); } catch (\Throwable) { return null; }
    }

    private function positive(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function first(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate;
        return null;
    }
}
