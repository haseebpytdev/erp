<?php

namespace App\Services\Administration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-10.31.75
 *
 * Adaptive management layer over the ERP's existing Users / Staff / Role /
 * Branch schema. It does not create replacement masters or duplicate login
 * tables. The native creation flow remains authoritative for new users.
 */
class ErpUserManagementService
{
    private ?array $schemaCache = null;

    public function schema(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Native users table was not found.');
        }

        $userColumns = Schema::getColumnListing('users');

        $staffTable = $this->firstExistingTable([
            'staff_profiles',
            'staff',
            'employees',
            'employee_profiles',
            'staff_masters',
            'staff_master',
        ]);

        $staffColumns = $staffTable
            ? Schema::getColumnListing($staffTable)
            : [];

        $branchTable = $this->firstExistingTable([
            'branches',
            'branch_masters',
            'branch_master',
            'offices',
        ]);

        $branchColumns = $branchTable
            ? Schema::getColumnListing($branchTable)
            : [];

        $roleTable = Schema::hasTable('roles') ? 'roles' : null;
        $roleColumns = $roleTable ? Schema::getColumnListing($roleTable) : [];

        $staffLink = $this->detectStaffLink($staffTable, $staffColumns, $userColumns);
        $roleLink = $this->detectRoleLink($userColumns);
        $branchLink = $this->detectBranchLink($userColumns);

        return $this->schemaCache = [
            'users_table' => 'users',
            'user_columns' => $userColumns,
            'username_column' => $this->firstColumn($userColumns, [
                'username', 'user_name', 'login_name', 'name',
            ]),
            'email_column' => $this->firstColumn($userColumns, [
                'email', 'login_email', 'email_address',
            ]),
            'password_column' => $this->firstColumn($userColumns, ['password']),
            'active_column' => $this->firstColumn($userColumns, [
                'is_active', 'active', 'status', 'account_status',
            ]),
            'access_scope_column' => $this->firstColumn($userColumns, [
                'access_scope', 'branch_scope', 'scope',
            ]),
            'primary_branch_column' => $this->firstColumn($userColumns, [
                'primary_branch_id', 'branch_id', 'home_branch_id',
            ]),

            'staff_table' => $staffTable,
            'staff_columns' => $staffColumns,
            'staff_id_column' => $staffTable ? $this->firstColumn($staffColumns, ['id']) : null,
            'staff_code_column' => $this->firstColumn($staffColumns, [
                'employee_code', 'staff_code', 'code', 'employee_no',
            ]),
            'staff_name_column' => $this->firstColumn($staffColumns, [
                'full_name', 'name', 'staff_name', 'employee_name',
            ]),
            'staff_email_column' => $this->firstColumn($staffColumns, [
                'email', 'work_email', 'email_address',
            ]),
            'staff_status_column' => $this->firstColumn($staffColumns, [
                'employment_status', 'status', 'is_active', 'active',
            ]),
            'staff_link' => $staffLink,

            'roles_table' => $roleTable,
            'role_id_column' => $roleTable ? $this->firstColumn($roleColumns, ['id']) : null,
            'role_name_column' => $roleTable ? $this->firstColumn($roleColumns, ['name', 'title', 'code']) : null,
            'role_link' => $roleLink,

            'branches_table' => $branchTable,
            'branch_id_column' => $branchTable ? $this->firstColumn($branchColumns, ['id']) : null,
            'branch_name_column' => $branchTable ? $this->firstColumn($branchColumns, ['name', 'branch_name', 'title']) : null,
            'branch_code_column' => $branchTable ? $this->firstColumn($branchColumns, ['code', 'branch_code']) : null,
            'branch_link' => $branchLink,
        ];
    }

    public function users(): array
    {
        $schema = $this->schema();
        $rows = DB::table('users')->orderBy('id')->get();
        $staffRows = $this->staffRows();
        $staffById = [];

        foreach ($staffRows as $staff) {
            $staffById[(int) $staff['id']] = $staff;
        }

        $result = [];

        foreach ($rows as $row) {
            $array = (array) $row;
            $id = (int) ($array['id'] ?? 0);
            $staffId = $this->linkedStaffId($id, $array);
            $staff = $staffId ? ($staffById[$staffId] ?? null) : null;

            $result[] = [
                'id' => $id,
                'username' => $this->value($array, $schema['username_column']),
                'login_email' => $this->value($array, $schema['email_column']),
                'active' => $this->activeValue($array, $schema['active_column']),
                'access_scope' => $this->value($array, $schema['access_scope_column']),
                'primary_branch_id' => $this->intValue($array, $schema['primary_branch_column']),
                'staff_id' => $staffId,
                'staff' => $staff,
                'role_ids' => $this->assignedRoleIds($id),
                'role_names' => $this->assignedRoleNames($id),
                'branch_ids' => $this->assignedBranchIds($id),
            ];
        }

        return $result;
    }

    public function user(int $id): ?array
    {
        foreach ($this->users() as $user) {
            if ((int) $user['id'] === $id) {
                return $user;
            }
        }

        return null;
    }

    public function findUserByUsername(string $username): ?array
    {
        $username = trim($username);

        if ($username === '') {
            return null;
        }

        foreach ($this->users() as $user) {
            if (strcasecmp((string) $user['username'], $username) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function findUserByStaffCode(string $staffCode): ?array
    {
        $staffCode = trim($staffCode);

        if ($staffCode === '') {
            return null;
        }

        foreach ($this->users() as $user) {
            $code = (string) ($user['staff']['code'] ?? '');

            if ($code !== '' && strcasecmp($code, $staffCode) === 0) {
                return $user;
            }
        }

        return null;
    }

    public function staffRows(): array
    {
        $schema = $this->schema();
        $table = $schema['staff_table'];

        if (! $table || ! $schema['staff_id_column']) {
            return [];
        }

        $rows = DB::table($table)
            ->orderBy($schema['staff_id_column'])
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $array = (array) $row;

            $result[] = [
                'id' => (int) ($array[$schema['staff_id_column']] ?? 0),
                'code' => $this->value($array, $schema['staff_code_column']),
                'name' => $this->value($array, $schema['staff_name_column']),
                'email' => $this->value($array, $schema['staff_email_column']),
                'status' => $this->value($array, $schema['staff_status_column']),
            ];
        }

        return $result;
    }

    public function roles(): array
    {
        $schema = $this->schema();

        if (! $schema['roles_table'] || ! $schema['role_id_column']) {
            return [];
        }

        $rows = DB::table($schema['roles_table'])
            ->orderBy($schema['role_name_column'] ?: $schema['role_id_column'])
            ->get();

        return array_map(
            fn ($row): array => [
                'id' => (int) ((array) $row)[$schema['role_id_column']],
                'name' => $this->value((array) $row, $schema['role_name_column']),
            ],
            $rows->all()
        );
    }

    public function branches(): array
    {
        $schema = $this->schema();

        if (! $schema['branches_table'] || ! $schema['branch_id_column']) {
            return [];
        }

        $rows = DB::table($schema['branches_table'])
            ->orderBy($schema['branch_name_column'] ?: $schema['branch_id_column'])
            ->get();

        return array_map(
            fn ($row): array => [
                'id' => (int) ((array) $row)[$schema['branch_id_column']],
                'name' => $this->value((array) $row, $schema['branch_name_column']),
                'code' => $this->value((array) $row, $schema['branch_code_column']),
            ],
            $rows->all()
        );
    }

    public function updateUser(int $id, array $input, int $actorId): array
    {
        $schema = $this->schema();
        $current = DB::table('users')->where('id', $id)->first();

        if (! $current) {
            throw new RuntimeException('ERP user account was not found.');
        }

        $currentArray = (array) $current;
        $updates = [];

        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['login_email'] ?? ''));

        if ($schema['username_column']) {
            if ($username === '') {
                throw new RuntimeException('User Name is required.');
            }

            if (
                DB::table('users')
                    ->where($schema['username_column'], $username)
                    ->where('id', '!=', $id)
                    ->exists()
            ) {
                throw new RuntimeException('This User Name is already in use.');
            }

            $updates[$schema['username_column']] = $username;
        }

        if ($schema['email_column']) {
            if ($email === '') {
                throw new RuntimeException('Email / Login is required.');
            }

            if (
                DB::table('users')
                    ->where($schema['email_column'], $email)
                    ->where('id', '!=', $id)
                    ->exists()
            ) {
                throw new RuntimeException('This Email / Login is already used by another ERP user.');
            }

            $updates[$schema['email_column']] = $email;
        }

        if ($schema['access_scope_column']) {
            $updates[$schema['access_scope_column']] = trim((string) ($input['access_scope'] ?? ''));
        }

        if ($schema['primary_branch_column']) {
            $primaryBranchId = (int) ($input['primary_branch_id'] ?? 0);
            $updates[$schema['primary_branch_column']] = $primaryBranchId > 0 ? $primaryBranchId : null;
        }

        if ($schema['active_column']) {
            $requestedActive = (bool) ($input['active'] ?? false);

            if ($id === $actorId && ! $requestedActive) {
                throw new RuntimeException('You cannot deactivate your own signed-in ERP account.');
            }

            $updates[$schema['active_column']] = $this->databaseActiveValue(
                $currentArray[$schema['active_column']] ?? null,
                $requestedActive
            );
        }

        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');

        if ($password !== '') {
            if (strlen($password) < 10) {
                throw new RuntimeException('New password must be at least 10 characters.');
            }

            if ($password !== $passwordConfirmation) {
                throw new RuntimeException('New password and confirmation do not match.');
            }

            if (! $schema['password_column']) {
                throw new RuntimeException('Native password column could not be resolved.');
            }

            $updates[$schema['password_column']] = Hash::make($password);
        }

        DB::transaction(function () use ($id, $updates, $input, $actorId): void {
            if ($updates !== []) {
                DB::table('users')->where('id', $id)->update($updates);
            }

            $this->syncStaffLink($id, (int) ($input['staff_id'] ?? 0));

            if ($id !== $actorId) {
                $this->syncRoles($id, array_map('intval', (array) ($input['role_ids'] ?? [])));
            }

            $this->syncBranches($id, array_map('intval', (array) ($input['branch_ids'] ?? [])));
        });

        return $this->user($id) ?? [];
    }

    public function setActive(int $id, bool $active, int $actorId): void
    {
        $schema = $this->schema();

        if (! $schema['active_column']) {
            throw new RuntimeException('Native user active/status column could not be resolved.');
        }

        if ($id === $actorId && ! $active) {
            throw new RuntimeException('You cannot deactivate your own signed-in ERP account.');
        }

        $row = DB::table('users')->where('id', $id)->first();

        if (! $row) {
            throw new RuntimeException('ERP user account was not found.');
        }

        $current = (array) $row;

        DB::table('users')->where('id', $id)->update([
            $schema['active_column'] => $this->databaseActiveValue(
                $current[$schema['active_column']] ?? null,
                $active
            ),
        ]);
    }

    private function linkedStaffId(int $userId, array $user): ?int
    {
        $schema = $this->schema();
        $link = $schema['staff_link'];

        if (! $link) {
            return null;
        }

        if ($link['mode'] === 'user_fk') {
            $id = (int) ($user[$link['column']] ?? 0);
            return $id > 0 ? $id : null;
        }

        if ($link['mode'] === 'staff_fk') {
            $row = DB::table($schema['staff_table'])
                ->where($link['column'], $userId)
                ->first();

            if (! $row) {
                return null;
            }

            $array = (array) $row;
            $id = (int) ($array[$schema['staff_id_column']] ?? 0);
            return $id > 0 ? $id : null;
        }

        if ($link['mode'] === 'pivot') {
            $row = DB::table($link['table'])
                ->where($link['user_column'], $userId)
                ->first();

            if (! $row) {
                return null;
            }

            $array = (array) $row;
            $id = (int) ($array[$link['staff_column']] ?? 0);
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function assignedRoleIds(int $userId): array
    {
        $schema = $this->schema();
        $link = $schema['role_link'];

        if (! $link) {
            return [];
        }

        if ($link['mode'] === 'direct') {
            $value = DB::table('users')->where('id', $userId)->value($link['column']);
            return (int) $value > 0 ? [(int) $value] : [];
        }

        $query = DB::table($link['table'])->where($link['user_column'], $userId);

        if ($link['model_type_column']) {
            $modelType = $this->userModelType($userId, $link);
            if ($modelType !== '') {
                $query->where($link['model_type_column'], $modelType);
            }
        }

        return array_values(array_unique(array_map(
            'intval',
            $query->pluck($link['role_column'])->all()
        )));
    }

    private function assignedRoleNames(int $userId): array
    {
        $schema = $this->schema();
        $ids = $this->assignedRoleIds($userId);

        if (! $ids || ! $schema['roles_table'] || ! $schema['role_name_column']) {
            return [];
        }

        return DB::table($schema['roles_table'])
            ->whereIn($schema['role_id_column'], $ids)
            ->pluck($schema['role_name_column'])
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    private function assignedBranchIds(int $userId): array
    {
        $schema = $this->schema();
        $link = $schema['branch_link'];

        if (! $link) {
            $primary = (int) DB::table('users')->where('id', $userId)->value(
                $schema['primary_branch_column'] ?: 'id'
            );

            return $schema['primary_branch_column'] && $primary > 0 ? [$primary] : [];
        }

        return array_values(array_unique(array_map(
            'intval',
            DB::table($link['table'])
                ->where($link['user_column'], $userId)
                ->pluck($link['branch_column'])
                ->all()
        )));
    }

    private function syncStaffLink(int $userId, int $staffId): void
    {
        $schema = $this->schema();
        $link = $schema['staff_link'];

        if (! $link) {
            return;
        }

        $userRow = DB::table('users')->where('id', $userId)->first();
        $currentStaffId = $userRow
            ? $this->linkedStaffId($userId, (array) $userRow)
            : null;

        if ((int) ($currentStaffId ?? 0) === $staffId) {
            return;
        }

        if ($staffId > 0) {
            $existingUser = $this->userIdForStaff($staffId);

            if ($existingUser && $existingUser !== $userId) {
                throw new RuntimeException('Selected Staff profile is already linked to another ERP login.');
            }
        }

        if ($link['mode'] === 'user_fk') {
            DB::table('users')->where('id', $userId)->update([
                $link['column'] => $staffId > 0 ? $staffId : null,
            ]);
            return;
        }

        if ($link['mode'] === 'staff_fk') {
            DB::table($schema['staff_table'])
                ->where($link['column'], $userId)
                ->update([$link['column'] => null]);

            if ($staffId > 0) {
                DB::table($schema['staff_table'])
                    ->where($schema['staff_id_column'], $staffId)
                    ->update([$link['column'] => $userId]);
            }
            return;
        }

        if ($link['mode'] === 'pivot') {
            DB::table($link['table'])->where($link['user_column'], $userId)->delete();

            if ($staffId > 0) {
                $row = [
                    $link['user_column'] => $userId,
                    $link['staff_column'] => $staffId,
                ];
                $columns = Schema::getColumnListing($link['table']);
                if (in_array('created_at', $columns, true)) {
                    $row['created_at'] = now();
                }
                if (in_array('updated_at', $columns, true)) {
                    $row['updated_at'] = now();
                }
                DB::table($link['table'])->insert($row);
            }
        }
    }

    private function userIdForStaff(int $staffId): ?int
    {
        $schema = $this->schema();
        $link = $schema['staff_link'];

        if (! $link) {
            return null;
        }

        if ($link['mode'] === 'user_fk') {
            $id = (int) DB::table('users')->where($link['column'], $staffId)->value('id');
            return $id > 0 ? $id : null;
        }

        if ($link['mode'] === 'staff_fk') {
            $id = (int) DB::table($schema['staff_table'])
                ->where($schema['staff_id_column'], $staffId)
                ->value($link['column']);
            return $id > 0 ? $id : null;
        }

        if ($link['mode'] === 'pivot') {
            $id = (int) DB::table($link['table'])
                ->where($link['staff_column'], $staffId)
                ->value($link['user_column']);
            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function syncRoles(int $userId, array $roleIds): void
    {
        $schema = $this->schema();
        $link = $schema['role_link'];

        if (! $link) {
            return;
        }

        $roleIds = array_values(array_unique(array_filter($roleIds, fn ($id) => $id > 0)));

        if ($schema['roles_table']) {
            $valid = DB::table($schema['roles_table'])
                ->whereIn($schema['role_id_column'], $roleIds)
                ->pluck($schema['role_id_column'])
                ->map(fn ($value) => (int) $value)
                ->all();
            $roleIds = $valid;
        }

        if ($link['mode'] === 'direct') {
            DB::table('users')->where('id', $userId)->update([
                $link['column'] => $roleIds[0] ?? null,
            ]);
            return;
        }

        $delete = DB::table($link['table'])->where($link['user_column'], $userId);
        $modelType = '';

        if ($link['model_type_column']) {
            $modelType = $this->userModelType($userId, $link);
            if ($modelType !== '') {
                $delete->where($link['model_type_column'], $modelType);
            }
        }

        $delete->delete();

        foreach ($roleIds as $roleId) {
            $row = [
                $link['user_column'] => $userId,
                $link['role_column'] => $roleId,
            ];

            if ($link['model_type_column']) {
                $row[$link['model_type_column']] = $modelType !== '' ? $modelType : 'App\\Models\\User';
            }

            $columns = Schema::getColumnListing($link['table']);
            if (in_array('created_at', $columns, true)) {
                $row['created_at'] = now();
            }
            if (in_array('updated_at', $columns, true)) {
                $row['updated_at'] = now();
            }
            DB::table($link['table'])->insert($row);
        }
    }

    private function syncBranches(int $userId, array $branchIds): void
    {
        $schema = $this->schema();
        $link = $schema['branch_link'];

        if (! $link) {
            return;
        }

        $branchIds = array_values(array_unique(array_filter($branchIds, fn ($id) => $id > 0)));

        if ($schema['branches_table']) {
            $branchIds = DB::table($schema['branches_table'])
                ->whereIn($schema['branch_id_column'], $branchIds)
                ->pluck($schema['branch_id_column'])
                ->map(fn ($value) => (int) $value)
                ->all();
        }

        DB::table($link['table'])->where($link['user_column'], $userId)->delete();

        foreach ($branchIds as $branchId) {
            $row = [
                $link['user_column'] => $userId,
                $link['branch_column'] => $branchId,
            ];
            $columns = Schema::getColumnListing($link['table']);
            if (in_array('created_at', $columns, true)) {
                $row['created_at'] = now();
            }
            if (in_array('updated_at', $columns, true)) {
                $row['updated_at'] = now();
            }
            DB::table($link['table'])->insert($row);
        }
    }

    private function detectStaffLink(?string $staffTable, array $staffColumns, array $userColumns): ?array
    {
        if (! $staffTable) {
            return null;
        }

        foreach (['staff_id', 'staff_profile_id', 'employee_id'] as $column) {
            if (in_array($column, $userColumns, true)) {
                return ['mode' => 'user_fk', 'column' => $column];
            }
        }

        foreach (['user_id', 'erp_user_id', 'login_user_id'] as $column) {
            if (in_array($column, $staffColumns, true)) {
                return ['mode' => 'staff_fk', 'column' => $column];
            }
        }

        foreach (['staff_user', 'user_staff', 'staff_user_links', 'user_staff_links'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $userColumn = $this->firstColumn($columns, ['user_id', 'erp_user_id']);
            $staffColumn = $this->firstColumn($columns, ['staff_id', 'staff_profile_id', 'employee_id']);

            if ($userColumn && $staffColumn) {
                return [
                    'mode' => 'pivot',
                    'table' => $table,
                    'user_column' => $userColumn,
                    'staff_column' => $staffColumn,
                ];
            }
        }

        return null;
    }

    private function detectRoleLink(array $userColumns): ?array
    {
        if (in_array('role_id', $userColumns, true)) {
            return ['mode' => 'direct', 'column' => 'role_id'];
        }

        foreach (['model_has_roles', 'role_user', 'user_roles', 'user_role'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $userColumn = $this->firstColumn($columns, ['model_id', 'user_id']);
            $roleColumn = $this->firstColumn($columns, ['role_id']);

            if ($userColumn && $roleColumn) {
                return [
                    'mode' => 'pivot',
                    'table' => $table,
                    'user_column' => $userColumn,
                    'role_column' => $roleColumn,
                    'model_type_column' => in_array('model_type', $columns, true) ? 'model_type' : null,
                ];
            }
        }

        return null;
    }

    private function detectBranchLink(array $userColumns): ?array
    {
        foreach ([
            'branch_user',
            'user_branches',
            'user_branch',
            'branch_user_access',
            'user_branch_access',
            'user_allowed_branches',
            'allowed_branch_user',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $userColumn = $this->firstColumn($columns, ['user_id']);
            $branchColumn = $this->firstColumn($columns, ['branch_id']);

            if ($userColumn && $branchColumn) {
                return [
                    'table' => $table,
                    'user_column' => $userColumn,
                    'branch_column' => $branchColumn,
                ];
            }
        }

        return null;
    }

    private function userModelType(int $userId, array $link): string
    {
        if (! $link['model_type_column']) {
            return '';
        }

        $existing = DB::table($link['table'])
            ->where($link['user_column'], $userId)
            ->value($link['model_type_column']);

        return trim((string) ($existing ?: 'App\\Models\\User'));
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
        if (! $column) {
            return '';
        }

        return trim((string) ($row[$column] ?? ''));
    }

    private function intValue(array $row, ?string $column): ?int
    {
        if (! $column) {
            return null;
        }

        $value = (int) ($row[$column] ?? 0);
        return $value > 0 ? $value : null;
    }

    private function activeValue(array $row, ?string $column): bool
    {
        if (! $column) {
            return true;
        }

        $value = $row[$column] ?? true;

        if (is_bool($value) || is_int($value)) {
            return (bool) $value;
        }

        $normalized = strtolower(trim((string) $value));

        return ! in_array($normalized, ['0', 'false', 'inactive', 'disabled', 'blocked', 'suspended'], true);
    }

    private function databaseActiveValue(mixed $current, bool $active): mixed
    {
        if (is_string($current)) {
            $normalized = strtolower(trim($current));

            if (in_array($normalized, ['active', 'inactive'], true)) {
                return $active ? 'ACTIVE' : 'INACTIVE';
            }

            if (in_array($normalized, ['enabled', 'disabled'], true)) {
                return $active ? 'ENABLED' : 'DISABLED';
            }
        }

        return $active ? 1 : 0;
    }
}
