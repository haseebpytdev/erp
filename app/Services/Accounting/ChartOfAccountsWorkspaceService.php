<?php

namespace App\Services\Accounting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * ERP-11.3.8
 * Adaptive Chart of Accounts workspace helper.
 *
 * The native ERP foundation has existed across several cumulative releases and
 * installations. This service deliberately discovers the live account table
 * and column names instead of hard-coding database IDs or replacing the
 * accounting model. Account creation remains code-based and parent-aware.
 */
class ChartOfAccountsWorkspaceService
{
    private ?array $resolved = null;

    public function schema(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        foreach (['chart_of_accounts', 'chart_accounts', 'accounts', 'account_masters', 'gl_accounts'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $map = [
                'table' => $table,
                'columns' => $columns,
                'id' => $this->first($columns, ['id', 'account_id']),
                'code' => $this->first($columns, ['code', 'account_code', 'gl_code', 'number', 'account_number']),
                'name' => $this->first($columns, ['name', 'account_name', 'title']),
                'type' => $this->first($columns, ['type', 'account_type', 'category', 'account_category']),
                'subtype' => $this->first($columns, ['subtype', 'sub_type', 'account_subtype', 'account_sub_type']),
                'parent' => $this->first($columns, ['parent_id', 'parent_account_id', 'parent_account', 'parent_code']),
                'normal' => $this->first($columns, ['normal_balance', 'normal_side', 'balance_type']),
                'posting' => $this->first($columns, ['allow_direct_journal_posting', 'allow_direct_posting', 'allow_posting', 'is_posting', 'posting_allowed', 'can_post']),
                'control_flag' => $this->first($columns, ['is_control_account', 'is_control', 'control_account']),
                'control_type' => $this->first($columns, ['control_type', 'control_code', 'control_key']),
                'notes' => $this->first($columns, ['notes', 'memo', 'remarks', 'description']),
                'status' => $this->first($columns, ['status', 'account_status']),
                'active' => $this->first($columns, ['is_active', 'active', 'enabled']),
                'created_at' => $this->first($columns, ['created_at']),
                'updated_at' => $this->first($columns, ['updated_at']),
            ];

            if ($map['id'] && $map['code'] && $map['name'] && $map['type']) {
                return $this->resolved = $map;
            }
        }

        throw new RuntimeException('The live Chart of Accounts table could not be resolved safely. No account data was changed.');
    }

    public function indexRows(array $filters): LengthAwarePaginator
    {
        $s = $this->schema();
        $table = $s['table'];
        $q = DB::table($table);

        $this->applyFilters($q, $s, $filters);

        $select = [
            $s['id'].' as id',
            $s['code'].' as code',
            $s['name'].' as name',
            $s['type'].' as type',
        ];
        foreach (['subtype', 'parent', 'normal', 'posting', 'control_flag', 'control_type', 'status', 'active'] as $key) {
            if ($s[$key]) {
                $select[] = $s[$key].' as '.$key;
            }
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }

        $rows = $q->select($select)
            ->orderBy($s['code'])
            ->paginate($perPage)
            ->withQueryString();

        $this->decorateParents($rows, $s);

        return $rows;
    }

    public function summary(): array
    {
        $s = $this->schema();
        $rows = DB::table($s['table'])
            ->select([$s['type'].' as type', DB::raw('COUNT(*) as aggregate')])
            ->groupBy($s['type'])
            ->get();

        $summary = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0, 'total' => 0];
        foreach ($rows as $row) {
            $key = $this->normalizeType((string) $row->type);
            $count = (int) $row->aggregate;
            if (isset($summary[$key])) {
                $summary[$key] += $count;
            }
            $summary['total'] += $count;
        }
        return $summary;
    }

    public function parentOptions(int $limit = 1000): array
    {
        $s = $this->schema();
        $query = DB::table($s['table'])
            ->select([$s['id'].' as id', $s['code'].' as code', $s['name'].' as name', $s['type'].' as type'])
            ->orderBy($s['code'])
            ->limit(max(25, min($limit, 2000)));

        $this->activeOnly($query, $s);

        return $query->get()->map(static fn ($r): array => [
            'id' => (int) $r->id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'type' => (string) $r->type,
        ])->all();
    }

    public function nextCode(int $parentId): string
    {
        $s = $this->schema();
        $parent = DB::table($s['table'])->where($s['id'], $parentId)->first();
        if (! $parent) {
            throw new RuntimeException('Selected parent account no longer exists.');
        }

        $parentCode = trim((string) $parent->{$s['code']});
        if ($parentCode === '') {
            throw new RuntimeException('Selected parent account has no account code.');
        }

        return $this->nextCodeForParentRow($parent, $s);
    }

    public function create(array $data): int
    {
        $s = $this->schema();

        return DB::transaction(function () use ($data, $s): int {
            $parentId = isset($data['parent_id']) && $data['parent_id'] !== '' ? (int) $data['parent_id'] : null;
            $parent = null;
            $code = ''; // ERP-11.3.5: normal account creation is parent-driven/read-only.

            if (! $parentId) {
                throw new RuntimeException('Select a parent account. Account codes are generated automatically and cannot be entered manually.');
            }

            if ($parentId) {
                // Lock the parent and current child range. The code is generated again
                // inside the transaction; the browser preview is never authoritative.
                $parent = DB::table($s['table'])
                    ->where($s['id'], $parentId)
                    ->lockForUpdate()
                    ->first();
                if (! $parent) {
                    throw new RuntimeException('Selected parent account no longer exists.');
                }

                if ($s['parent']) {
                    $parentValue = $this->parentStorageValue($parent, $s);
                    DB::table($s['table'])
                        ->where($s['parent'], $parentValue)
                        ->lockForUpdate()
                        ->get([$s['id']]);
                } else {
                    // A hierarchy cannot be persisted without a parent column.
                    throw new RuntimeException('This live Chart of Accounts schema has no parent-account column. Child accounts cannot be created safely.');
                }

                $code = $this->nextCodeForParentRow($parent, $s);
            }

            $duplicate = DB::table($s['table'])->where($s['code'], $code)->lockForUpdate()->exists();
            if ($duplicate) {
                throw new RuntimeException('Account code '.$code.' already exists. Refresh and try again.');
            }

            $type = strtolower(trim((string) $data['type']));
            $insert = [
                $s['code'] => $code,
                $s['name'] => trim((string) $data['name']),
                $s['type'] => $this->storageType($type, $s),
            ];

            if ($s['subtype']) {
                $insert[$s['subtype']] = $this->nullableString($data['subtype'] ?? null);
            }
            if ($s['parent']) {
                $insert[$s['parent']] = $parent ? $this->parentStorageValue($parent, $s) : null;
            }
            if ($s['normal']) {
                $insert[$s['normal']] = $this->storageNormalBalance($type, $s);
            }
            if ($s['posting']) {
                $insert[$s['posting']] = ! empty($data['allow_posting']) ? 1 : 0;
            }
            if ($s['control_flag']) {
                $insert[$s['control_flag']] = ! empty($data['is_control']) ? 1 : 0;
            }
            if ($s['control_type']) {
                $insert[$s['control_type']] = ! empty($data['is_control'])
                    ? $this->nullableString($data['control_type'] ?? null)
                    : null;
            }
            if ($s['notes']) {
                $insert[$s['notes']] = $this->nullableString($data['notes'] ?? null);
            }
            if ($s['status']) {
                $insert[$s['status']] = 'active';
            }
            if ($s['active']) {
                $insert[$s['active']] = 1;
            }
            if ($s['created_at']) {
                $insert[$s['created_at']] = now();
            }
            if ($s['updated_at']) {
                $insert[$s['updated_at']] = now();
            }

            // Carry only installation-scope foreign keys from the selected parent.
            // This prevents cross-company/branch insertion where those columns exist,
            // without cloning accounting classifications or protected control flags.
            if ($parent) {
                foreach (['company_id', 'branch_id', 'organization_id', 'tenant_id', 'legal_entity_id'] as $scopeColumn) {
                    if (in_array($scopeColumn, $s['columns'], true) && ! array_key_exists($scopeColumn, $insert)) {
                        $insert[$scopeColumn] = $parent->{$scopeColumn} ?? null;
                    }
                }
            }

            try {
                return (int) DB::table($s['table'])->insertGetId($insert, $s['id']);
            } catch (\Throwable $e) {
                throw new RuntimeException('Account could not be created safely: '.$e->getMessage(), previous: $e);
            }
        }, 3);
    }

    public function normalBalance(string $type): string
    {
        return in_array(strtolower($type), ['asset', 'expense', 'cost'], true) ? 'DEBIT' : 'CREDIT';
    }

    public function schemaLabel(): string
    {
        try {
            return $this->schema()['table'];
        } catch (\Throwable) {
            return 'unresolved';
        }
    }

    private function applyFilters(Builder $q, array $s, array $filters): void
    {
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function (Builder $x) use ($s, $like): void {
                $x->where($s['code'], 'like', $like)->orWhere($s['name'], 'like', $like);
                if ($s['subtype']) {
                    $x->orWhere($s['subtype'], 'like', $like);
                }
                if ($s['control_type']) {
                    $x->orWhere($s['control_type'], 'like', $like);
                }
            });
        }

        $type = strtolower(trim((string) ($filters['type'] ?? '')));
        if (in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
            $q->whereRaw('LOWER('.$this->quoteIdentifier($s['type']).') = ?', [$type]);
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status === 'active') {
            $this->activeOnly($q, $s);
        } elseif ($status === 'inactive') {
            if ($s['active']) {
                $q->where($s['active'], 0);
            } elseif ($s['status']) {
                $q->whereRaw('LOWER('.$this->quoteIdentifier($s['status']).') NOT IN (?, ?)', ['active', 'enabled']);
            }
        }
    }

    private function activeOnly(Builder $q, array $s): void
    {
        if ($s['active']) {
            $q->where($s['active'], 1);
        } elseif ($s['status']) {
            $q->whereIn(DB::raw('LOWER('.$this->quoteIdentifier($s['status']).')'), ['active', 'enabled']);
        }
    }

    private function decorateParents(LengthAwarePaginator $rows, array $s): void
    {
        if (! $s['parent']) {
            foreach ($rows->items() as $row) {
                $row->parent_code = null;
                $row->parent_name = null;
            }
            return;
        }

        $values = collect($rows->items())
            ->pluck('parent')
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->unique()
            ->values();

        if ($values->isEmpty()) {
            return;
        }

        $parentIsId = $this->parentUsesId($s);
        $lookupColumn = $parentIsId ? $s['id'] : $s['code'];
        $parents = DB::table($s['table'])
            ->whereIn($lookupColumn, $values->all())
            ->get([$s['id'].' as id', $s['code'].' as code', $s['name'].' as name', $lookupColumn.' as lookup'])
            ->keyBy(fn ($r) => (string) $r->lookup);

        foreach ($rows->items() as $row) {
            $parent = $parents->get((string) ($row->parent ?? ''));
            $row->parent_code = $parent?->code;
            $row->parent_name = $parent?->name;
        }
    }

    private function nextCodeForParentRow(object $parent, array $s): string
    {
        $parentCode = trim((string) $parent->{$s['code']});
        $childCodes = [];

        if ($s['parent']) {
            $childCodes = DB::table($s['table'])
                ->where($s['parent'], $this->parentStorageValue($parent, $s))
                ->pluck($s['code'])
                ->map(fn ($v) => trim((string) $v))
                ->filter()
                ->values()
                ->all();
        }

        if (preg_match('/^\d+$/', $parentCode) === 1) {
            $candidate = (int) $parentCode + 1;
            $numericChildren = array_values(array_filter(array_map(
                static fn (string $code): ?int => preg_match('/^\d+$/', $code) === 1 ? (int) $code : null,
                $childCodes
            ), static fn ($v): bool => $v !== null));
            if ($numericChildren !== []) {
                $candidate = max($candidate, max($numericChildren) + 1);
            }

            // Account codes are globally unique. Skip any reserved/root code already in use.
            while (DB::table($s['table'])->where($s['code'], (string) $candidate)->exists()) {
                $candidate++;
            }
            return (string) $candidate;
        }

        $max = 0;
        foreach ($childCodes as $child) {
            if (preg_match('/^'.preg_quote($parentCode, '/').'[-\.]?(\d+)$/i', $child, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }
        do {
            $max++;
            $candidate = $parentCode.'-'.str_pad((string) $max, 2, '0', STR_PAD_LEFT);
        } while (DB::table($s['table'])->where($s['code'], $candidate)->exists());

        return $candidate;
    }

    private function parentStorageValue(object $parent, array $s): mixed
    {
        if (! $s['parent']) {
            return null;
        }
        return $this->parentUsesId($s)
            ? $parent->{$s['id']}
            : $parent->{$s['code']};
    }

    private function storageType(string $normalized, array $s): string
    {
        $sample = DB::table($s['table'])->whereNotNull($s['type'])->value($s['type']);
        $sample = is_string($sample) ? trim($sample) : '';
        if ($sample !== '' && $sample === strtoupper($sample)) {
            return strtoupper($normalized);
        }
        if ($sample !== '' && $sample === ucfirst(strtolower($sample))) {
            return ucfirst($normalized);
        }
        return strtolower($normalized);
    }

    private function storageNormalBalance(string $type, array $s): string
    {
        $value = $this->normalBalance($type);
        $sample = $s['normal'] ? DB::table($s['table'])->whereNotNull($s['normal'])->value($s['normal']) : null;
        $sample = is_string($sample) ? trim($sample) : '';
        if ($sample !== '' && $sample === strtolower($sample)) {
            return strtolower($value);
        }
        if ($sample !== '' && $sample === ucfirst(strtolower($sample))) {
            return ucfirst(strtolower($value));
        }
        return strtoupper($value);
    }

    private function parentUsesId(array $s): bool
    {
        if (! $s['parent']) {
            return true;
        }
        if (str_ends_with(strtolower($s['parent']), '_id')) {
            return true;
        }

        $sample = DB::table($s['table'])->whereNotNull($s['parent'])->value($s['parent']);
        if ($sample === null || $sample === '') {
            return false;
        }

        $matchesId = DB::table($s['table'])->where($s['id'], $sample)->exists();
        $matchesCode = DB::table($s['table'])->where($s['code'], (string) $sample)->exists();
        if ($matchesId && ! $matchesCode) {
            return true;
        }
        if ($matchesCode && ! $matchesId) {
            return false;
        }

        return str_contains(strtolower($s['parent']), 'id');
    }

    private function normalizeType(string $value): string
    {
        $v = strtolower(trim($value));
        if (str_contains($v, 'asset')) return 'asset';
        if (str_contains($v, 'liab')) return 'liability';
        if (str_contains($v, 'equity') || str_contains($v, 'capital')) return 'equity';
        if (str_contains($v, 'income') || str_contains($v, 'revenue')) return 'income';
        if (str_contains($v, 'expense') || str_contains($v, 'cost')) return 'expense';
        return $v;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function first(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Identifiers come only from Schema::getColumnListing, not user input.
        $driver = DB::connection()->getDriverName();
        return $driver === 'mysql' ? '`'.$identifier.'`' : '"'.$identifier.'"';
    }
}
