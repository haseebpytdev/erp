<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ERP-10.31.79
 *
 * Read-only adapter over the ERP's native role/permission tables.
 * No replacement RBAC tables are created. It supports Spatie-style pivots and
 * common native ERP variants, then exposes one effective permission set for
 * navigation and direct-route authorization.
 */
class ErpPermissionMatrixService
{
    private ?array $schemaCache = null;

    public function __construct(
        private readonly ErpUserManagementService $users
    ) {
    }

    public function schema(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        $permissionTable = $this->firstExistingTable([
            'permissions',
            'permission_masters',
            'permission_master',
        ]);
        $permissionColumns = $permissionTable
            ? Schema::getColumnListing($permissionTable)
            : [];

        $roleTable = Schema::hasTable('roles') ? 'roles' : null;
        $roleColumns = $roleTable ? Schema::getColumnListing($roleTable) : [];

        $rolePermission = $this->detectRolePermissionLink();
        $userPermission = $this->detectUserPermissionLink();

        return $this->schemaCache = [
            'permission_table' => $permissionTable,
            'permission_columns' => $permissionColumns,
            'permission_id_column' => $this->firstColumn($permissionColumns, ['id']),
            'permission_code_column' => $this->firstColumn($permissionColumns, [
                'code', 'permission_code', 'slug', 'key', 'name',
            ]),
            'permission_name_column' => $this->firstColumn($permissionColumns, [
                'display_name', 'title', 'label', 'name', 'code',
            ]),
            'permission_description_column' => $this->firstColumn($permissionColumns, [
                'description', 'details', 'notes',
            ]),
            'permission_group_column' => $this->firstColumn($permissionColumns, [
                'group', 'category', 'module', 'section', 'permission_group',
            ]),
            'roles_table' => $roleTable,
            'role_columns' => $roleColumns,
            'role_id_column' => $this->firstColumn($roleColumns, ['id']),
            'role_name_column' => $this->firstColumn($roleColumns, ['name', 'title', 'code']),
            'role_permission_link' => $rolePermission,
            'user_permission_link' => $userPermission,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function allPermissions(): array
    {
        $schema = $this->schema();
        $table = $schema['permission_table'];
        $idColumn = $schema['permission_id_column'];

        if (! $table || ! $idColumn) {
            return [];
        }

        $rows = DB::table($table)->get();
        $result = [];

        foreach ($rows as $row) {
            $a = (array) $row;
            $name = $this->value($a, $schema['permission_name_column']);
            $code = $this->value($a, $schema['permission_code_column']);
            $description = $this->value($a, $schema['permission_description_column']);
            $group = $this->value($a, $schema['permission_group_column']);

            if ($group === '') {
                $group = $this->deriveGroup($name.' '.$code.' '.$description);
            }

            $result[] = [
                'id' => (int) ($a[$idColumn] ?? 0),
                'name' => $name !== '' ? $name : $this->humanize($code),
                'code' => $code,
                'description' => $description,
                'group' => $group,
                'search' => $this->normalize(implode(' ', [
                    $name, $code, $description, $group,
                ])),
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $g = strcasecmp((string) $a['group'], (string) $b['group']);
            return $g !== 0 ? $g : strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function effectivePermissions(mixed $user): array
    {
        if (! $user) {
            return [];
        }

        /* Prefer the native authorization package if the User model exposes it. */
        try {
            if (method_exists($user, 'getAllPermissions')) {
                $native = $user->getAllPermissions();
                $rows = [];

                foreach ($native as $permission) {
                    $a = is_object($permission) ? (array) $permission->getAttributes() : (array) $permission;
                    $name = trim((string) ($a['display_name'] ?? $a['title'] ?? $a['label'] ?? $a['name'] ?? $a['code'] ?? ''));
                    $code = trim((string) ($a['code'] ?? $a['permission_code'] ?? $a['slug'] ?? $a['key'] ?? $a['name'] ?? ''));
                    $description = trim((string) ($a['description'] ?? $a['details'] ?? ''));
                    $group = trim((string) ($a['group'] ?? $a['category'] ?? $a['module'] ?? $a['section'] ?? ''));
                    if ($group === '') {
                        $group = $this->deriveGroup($name.' '.$code.' '.$description);
                    }
                    $rows[] = [
                        'id' => (int) ($a['id'] ?? 0),
                        'name' => $name !== '' ? $name : $this->humanize($code),
                        'code' => $code,
                        'description' => $description,
                        'group' => $group,
                        'search' => $this->normalize(implode(' ', [$name, $code, $description, $group])),
                    ];
                }

                if ($rows !== []) {
                    return $this->uniquePermissions($rows);
                }
            }
        } catch (Throwable) {
        }

        $userId = $this->userId($user);
        if ($userId <= 0) {
            return [];
        }

        $all = $this->allPermissions();
        if ($all === []) {
            return [];
        }

        $allowedIds = [];
        $profile = null;
        try {
            $profile = $this->users->user($userId);
        } catch (Throwable) {
        }

        $roleIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($profile['role_ids'] ?? [])
        ))));

        $schema = $this->schema();
        $roleLink = $schema['role_permission_link'];

        if ($roleLink && $roleIds !== []) {
            try {
                $query = DB::table($roleLink['table'])
                    ->whereIn($roleLink['role_column'], $roleIds);
                $allowedIds = array_merge(
                    $allowedIds,
                    array_map('intval', $query->pluck($roleLink['permission_column'])->all())
                );
            } catch (Throwable) {
            }
        }

        $userLink = $schema['user_permission_link'];
        if ($userLink) {
            try {
                $query = DB::table($userLink['table'])
                    ->where($userLink['user_column'], $userId);
                if ($userLink['model_type_column']) {
                    $query->where($userLink['model_type_column'], $this->userModelType($user, $userLink));
                }
                $allowedIds = array_merge(
                    $allowedIds,
                    array_map('intval', $query->pluck($userLink['permission_column'])->all())
                );
            } catch (Throwable) {
            }
        }

        $allowedIds = array_values(array_unique(array_filter($allowedIds)));
        if ($allowedIds === []) {
            return [];
        }

        return array_values(array_filter(
            $all,
            static fn (array $permission): bool => in_array((int) $permission['id'], $allowedIds, true)
        ));
    }

    public function isSuperAdmin(mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        $roles = [];
        try {
            if (method_exists($user, 'getRoleNames')) {
                $roles = array_merge($roles, (array) $user->getRoleNames()->all());
            }
        } catch (Throwable) {
        }

        $id = $this->userId($user);
        if ($id > 0) {
            try {
                $profile = $this->users->user($id);
                $roles = array_merge($roles, (array) ($profile['role_names'] ?? []));
            } catch (Throwable) {
            }
        }

        foreach ($roles as $role) {
            $normalized = $this->normalize((string) $role);
            if (in_array($normalized, ['super admin', 'superadmin'], true)) {
                return true;
            }
        }

        foreach (['is_super_admin', 'super_admin'] as $attribute) {
            try {
                if ((bool) ($user->{$attribute} ?? false)) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        return false;
    }

    public function hasPermissionLike(mixed $user, array $phrases): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $phrases = array_values(array_filter(array_map(
            fn (string $phrase): string => $this->normalize($phrase),
            $phrases
        )));

        foreach ($this->effectivePermissions($user) as $permission) {
            $haystack = (string) ($permission['search'] ?? '');
            foreach ($phrases as $phrase) {
                if ($phrase !== '' && str_contains($haystack, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasCapability(
        mixed $user,
        array $moduleTerms,
        array $actionTerms = [],
        array $groupTerms = []
    ): bool {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $moduleTerms = $this->normalizedTerms($moduleTerms);
        $actionTerms = $this->normalizedTerms($actionTerms);
        $groupTerms = $this->normalizedTerms($groupTerms);
        $broadActions = ['manage', 'administer', 'full access', 'all access'];

        foreach ($this->effectivePermissions($user) as $permission) {
            $haystack = (string) ($permission['search'] ?? '');
            $group = $this->normalize((string) ($permission['group'] ?? ''));

            $moduleMatch = $moduleTerms === [];
            foreach ($moduleTerms as $term) {
                if ($term !== '' && str_contains($haystack, $term)) {
                    $moduleMatch = true;
                    break;
                }
            }

            /*
             * Group/category is metadata, not a substitute for the module
             * capability itself. This avoids granting Receipts because the
             * user merely has a different Cash & Bank permission, or granting
             * Supplier Costing because they have an unrelated Commercial
             * permission. Group fallback is used only for descriptors that do
             * not define explicit module terms.
             */
            if (! $moduleMatch && $moduleTerms === [] && $groupTerms !== []) {
                foreach ($groupTerms as $term) {
                    if ($term !== '' && str_contains($group, $term)) {
                        $moduleMatch = true;
                        break;
                    }
                }
            }

            if (! $moduleMatch) {
                continue;
            }

            if ($actionTerms === []) {
                return true;
            }

            foreach (array_merge($actionTerms, $broadActions) as $term) {
                if ($term !== '' && str_contains($haystack, $term)) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * ERP-10.31.79
     *
     * Strict module capability matcher for navigation / route authorization.
     *
     * A module is granted only when one effective permission contains one of
     * the module's explicit capability phrases. Generic domain words are not
     * enough. This prevents permissions such as "view service cost" from
     * unlocking Products & Services, or "ticket accounting" from unlocking
     * Bookings.
     *
     * Action checks remain optional: GET/list/show can use the matched module
     * capability; mutating requests additionally require an action term or a
     * broad manage/full-access term.
     */
    public function hasStrictCapability(
        mixed $user,
        array $capabilityPhrases,
        array $actionTerms = []
    ): bool {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $phrases = $this->normalizedTerms($capabilityPhrases);
        $actions = $this->normalizedTerms($actionTerms);
        $broadActions = [
            'manage',
            'administer',
            'full access',
            'all access',
            'create',
            'edit',
            'update',
            'delete',
            'approve',
            'post',
        ];

        if ($phrases === []) {
            return false;
        }

        foreach ($this->effectivePermissions($user) as $permission) {
            $haystack = $this->normalize(
                implode(' ', [
                    (string) ($permission['name'] ?? ''),
                    (string) ($permission['code'] ?? ''),
                    (string) ($permission['description'] ?? ''),
                ])
            );

            $capabilityMatch = false;

            foreach ($phrases as $phrase) {
                if (
                    $phrase !== ''
                    && $this->containsPhrase($haystack, $phrase)
                ) {
                    $capabilityMatch = true;
                    break;
                }
            }

            if (! $capabilityMatch) {
                continue;
            }

            if ($actions === []) {
                return true;
            }

            foreach (
                array_values(
                    array_unique(
                        array_merge($actions, $broadActions)
                    )
                )
                as $action
            ) {
                if (
                    $action !== ''
                    && $this->containsPhrase($haystack, $action)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function containsPhrase(
        string $haystack,
        string $phrase
    ): bool {
        $haystack = ' '.$this->normalize($haystack).' ';
        $phrase = $this->normalize($phrase);

        if ($phrase === '') {
            return false;
        }

        /*
         * Normalized strings are space-separated. Padding both sides gives us
         * phrase boundaries instead of arbitrary substring matches.
         */
        return str_contains(
            $haystack,
            ' '.$phrase.' '
        );
    }

    public function summary(): array
    {
        $permissions = $this->allPermissions();
        $groups = array_values(array_unique(array_filter(array_map(
            static fn (array $permission): string => trim((string) ($permission['group'] ?? '')),
            $permissions
        ))));

        $roles = 0;
        try {
            $roles = count($this->users->roles());
        } catch (Throwable) {
        }

        return [
            'roles' => $roles,
            'permissions' => count($permissions),
            'groups' => count($groups),
        ];
    }

    private function detectRolePermissionLink(): ?array
    {
        foreach (['role_has_permissions', 'permission_role', 'role_permissions', 'role_permission'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $role = $this->firstColumn($columns, ['role_id']);
            $permission = $this->firstColumn($columns, ['permission_id']);
            if ($role && $permission) {
                return [
                    'table' => $table,
                    'role_column' => $role,
                    'permission_column' => $permission,
                ];
            }
        }
        return null;
    }

    private function detectUserPermissionLink(): ?array
    {
        foreach (['model_has_permissions', 'permission_user', 'user_permissions', 'user_permission'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $user = $this->firstColumn($columns, ['model_id', 'user_id']);
            $permission = $this->firstColumn($columns, ['permission_id']);
            if ($user && $permission) {
                return [
                    'table' => $table,
                    'user_column' => $user,
                    'permission_column' => $permission,
                    'model_type_column' => in_array('model_type', $columns, true) ? 'model_type' : null,
                ];
            }
        }
        return null;
    }

    private function deriveGroup(string $text): string
    {
        $n = $this->normalize($text);
        $groups = [
            'Accounting' => ['journal', 'ledger', 'trial balance', 'financial statement', 'chart of account'],
            'Cash & Bank' => ['receipt', 'payment', 'bank reconciliation', 'cash'],
            'Sales' => ['sales invoice', 'invoice', 'sales'],
            'Purchase & Costing' => ['supplier cost', 'costing', 'vendor bill', 'purchase'],
            'Refunds' => ['refund', 'credit note'],
            'Reports' => ['report', 'analytics'],
            'Ticketing' => ['ticket', 'pnr', 'fare'],
            'Travel Operations' => ['booking', 'passenger', 'hotel', 'visa', 'travel voucher'],
            'Master Data' => ['party', 'airline', 'airport', 'product', 'service', 'travel master'],
            'Administration' => ['user', 'staff', 'role', 'permission', 'approval authority'],
            'Organization' => ['branch', 'organization', 'financial year', 'currency'],
            'System' => ['system', 'setting', 'configuration'],
        ];
        foreach ($groups as $group => $terms) {
            foreach ($terms as $term) {
                if (str_contains($n, $term)) {
                    return $group;
                }
            }
        }
        return 'Other';
    }

    private function uniquePermissions(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = (string) (($row['id'] ?? 0) ?: ($row['code'] ?? '') ?: ($row['name'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
        }
        return $result;
    }

    private function normalizedTerms(array $terms): array
    {
        return array_values(array_filter(array_map(
            fn ($term): string => $this->normalize((string) $term),
            $terms
        )));
    }

    private function userId(mixed $user): int
    {
        try {
            if (method_exists($user, 'getAuthIdentifier')) {
                return (int) $user->getAuthIdentifier();
            }
            return (int) ($user->id ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function userModelType(mixed $user, array $link): string
    {
        if (! ($link['model_type_column'] ?? null)) {
            return '';
        }
        try {
            return is_object($user) ? get_class($user) : 'App\\Models\\User';
        } catch (Throwable) {
            return 'App\\Models\\User';
        }
    }

    private function firstExistingTable(array $tables): ?string
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                return $table;
            }
        }
        return null;
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function value(array $row, ?string $column): string
    {
        return $column ? trim((string) ($row[$column] ?? '')) : '';
    }

    private function humanize(string $value): string
    {
        $value = trim(str_replace(['.', '_', '-'], ' ', $value));
        return $value === '' ? 'Permission' : ucwords(strtolower($value));
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim(str_replace(['.', '_', '-', '/', ':'], ' ', $value)));
        return trim((string) preg_replace('/\\s+/', ' ', $value));
    }
}
