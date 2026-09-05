<?php

namespace App\Services\System;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-10.31.72
 *
 * One-time cleanup for residual financial state left after the main UAT reset.
 * It is intentionally separate from ProductionDataResetService because the
 * main reset has already completed on live and must remain permanently locked.
 */
class PostResetFinancialCleanupService
{
    public const CONFIRMATION = 'CLEAR FINANCIAL RESIDUE';
    private const BACKUP_MAX_AGE_SECONDS = 7200;

    /** Monetary state columns that can make a clean dashboard look non-zero. */
    private array $stateColumns = [
        'opening_balance', 'current_balance', 'closing_balance', 'balance',
        'debit_balance', 'credit_balance', 'payable_balance', 'receivable_balance',
        'supplier_balance', 'vendor_balance', 'customer_balance',
        'supplier_cost', 'supplier_cost_amount', 'supplier_amount', 'supplier_total',
        'confirmed_cost', 'confirmed_supplier_cost', 'forecast_cost',
        'cost_amount', 'total_cost', 'net_cost', 'purchase_cost',
        'payable_amount', 'payable_total', 'amount_payable', 'outstanding_amount',
        'gross_margin', 'net_margin', 'gross_profit', 'net_profit', 'profit',
        'profit_amount', 'margin_amount', 'gross_profit_amount', 'net_profit_amount',
        'total_debit', 'total_credit', 'debit_total', 'credit_total',
        'amount', 'total_amount', 'net_amount', 'base_amount',
        'cost', 'cost_total', 'supplier_payable', 'vendor_payable',
        'payable', 'receivable', 'outstanding', 'exposure',
        'opening_debit', 'opening_credit', 'closing_debit', 'closing_credit',
        'debit', 'credit', 'dr', 'cr',
        'debit_amount', 'credit_amount', 'dr_amount', 'cr_amount',
        'opening_dr', 'opening_cr', 'closing_dr', 'closing_cr',
        'opening_debit_amount', 'opening_credit_amount',
        'closing_debit_amount', 'closing_credit_amount',
        'current_debit', 'current_credit',
        'ledger_balance', 'running_balance', 'balance_amount',
        'available_balance', 'book_balance', 'account_balance',
        'opening_amount', 'closing_amount',
        'balance_forward', 'brought_forward', 'carried_forward',
        'supplier_outstanding', 'vendor_outstanding',
        'supplier_exposure', 'vendor_exposure',
        'supplier_cost_total', 'vendor_cost_total',
        'forecast_supplier_cost', 'package_supplier_cost',
        'actual_supplier_cost', 'period_supplier_cost',
    ];

    private array $zeroBalanceTables = [
        'parties', 'party_master', 'party_masters',
        'accounts', 'chart_accounts', 'chart_of_accounts',
        'account_master', 'account_masters',
        'parties', 'party_master', 'party_masters',
        'customers', 'customer_master', 'customer_masters',
        'suppliers', 'supplier_master', 'supplier_masters',
        'vendors', 'vendor_master', 'vendor_masters',
        'transporters', 'transporter_master', 'transporter_masters',
    ];

    public function mainResetCompleted(): bool
    {
        foreach ([
            storage_path('app/production-reset/production-transaction-reset.completed.json'),
            storage_path('app/production-reset/ERP-10.31.70.completed.json'),
            storage_path('app/production-reset/ERP-10.31.71.completed.json'),
            storage_path('app/production-reset/ERP-10.31.72.completed.json'),
        ] as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    public function completedRecord(): ?array
    {
        $path = $this->completionMarkerPath();

        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR
            );

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return [
                'completed_at' => 'unknown',
                'actor_name' => 'unknown',
            ];
        }
    }

    public function plan(): array
    {
        $items = [];
        $rowsToDelete = 0;
        $rowsToZero = 0;
        $warnings = [];

        foreach ($this->tableNames() as $table) {
            try {
                $columns = Schema::getColumnListing($table);
            } catch (Throwable $e) {
                $warnings[] = [
                    'table' => $table,
                    'reason' => 'Could not inspect columns: '.$e->getMessage(),
                ];
                continue;
            }

            $classification = $this->classify($table, $columns);

            if ($classification['action'] === 'preserve') {
                continue;
            }

            if ($classification['action'] === 'zero_columns') {
                $zeroColumns = $classification['columns'];
                $query = $this->nonZeroQuery($table, $zeroColumns);
                $count = $query ? (int) $query->count() : 0;

                if ($count <= 0) {
                    continue;
                }

                $rowsToZero += $count;

                $items[] = [
                    'table' => $table,
                    'action' => 'zero_columns',
                    'action_label' => 'Zero financial balances',
                    'rows' => $count,
                    'columns' => $zeroColumns,
                    'reason' => $classification['reason'],
                ];

                continue;
            }

            if ($classification['action'] === 'delete_all') {
                $count = (int) DB::table($table)->count();

                if ($count <= 0) {
                    continue;
                }

                $rowsToDelete += $count;

                $items[] = [
                    'table' => $table,
                    'action' => 'delete_all',
                    'action_label' => 'Delete residual rows',
                    'rows' => $count,
                    'columns' => $classification['columns'] ?? [],
                    'reason' => $classification['reason'],
                ];
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp($a['table'], $b['table'])
        );

        return [
            'items' => $items,
            'rows_to_delete' => $rowsToDelete,
            'rows_to_zero' => $rowsToZero,
            'tables_to_clean' => count($items),
            'warnings' => $warnings,
            'confirmation' => self::CONFIRMATION,
            'main_reset_completed' => $this->mainResetCompleted(),
            'completed' => $this->completedRecord(),
        ];
    }

    public function createBackup(mixed $user): array
    {
        if (! $this->mainResetCompleted()) {
            throw new RuntimeException(
                'Main Production Transaction Reset completion marker was not found.'
            );
        }

        if ($this->completedRecord()) {
            throw new RuntimeException(
                'Post-reset financial cleanup was already completed and is locked.'
            );
        }

        $plan = $this->plan();
        $directory = storage_path('app/production-reset-backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create cleanup backup directory.');
        }

        $path = $directory.'/financial-residue-backup_'.now()->format('Ymd_His').'.json.gz';
        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new RuntimeException('Unable to create financial residue backup file.');
        }

        try {
            $this->gzWrite($handle, "{\n");
            $this->gzWrite(
                $handle,
                '"meta":'.json_encode([
                    'release' => 'ERP-10.31.79',
                    'created_at' => now()->toIso8601String(),
                    'actor_id' => $this->userId($user),
                    'actor_name' => $this->userName($user),
                    'rows_to_delete' => $plan['rows_to_delete'],
                    'rows_to_zero' => $plan['rows_to_zero'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).",\n"
            );

            $this->gzWrite($handle, "\"tables\":{\n");
            $firstTable = true;

            foreach ($plan['items'] as $item) {
                $table = (string) $item['table'];

                if (! $firstTable) {
                    $this->gzWrite($handle, ",\n");
                }
                $firstTable = false;

                $this->gzWrite(
                    $handle,
                    json_encode($table).':{"action":'.json_encode($item['action']).',"rows":['
                );

                $firstRow = true;
                foreach (DB::table($table)->cursor() as $row) {
                    if (! $firstRow) {
                        $this->gzWrite($handle, ',');
                    }
                    $firstRow = false;

                    $this->gzWrite(
                        $handle,
                        json_encode(
                            (array) $row,
                            JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_INVALID_UTF8_SUBSTITUTE
                        )
                    );
                }

                $this->gzWrite($handle, ']}');
            }

            $this->gzWrite($handle, "\n}\n}");
        } finally {
            gzclose($handle);
        }

        if (! is_file($path) || filesize($path) <= 0) {
            throw new RuntimeException('Financial residue backup was not created successfully.');
        }

        return [
            'path' => $path,
            'filename' => basename($path),
            'created_at' => now()->timestamp,
            'size' => (int) filesize($path),
        ];
    }

    public function validateBackup(?string $path, mixed $createdAt): void
    {
        $createdAt = (int) $createdAt;

        if (! $path || ! is_file($path) || filesize($path) <= 0) {
            throw new RuntimeException('Download the financial residue backup first.');
        }

        if ($createdAt <= 0 || (time() - $createdAt) > self::BACKUP_MAX_AGE_SECONDS) {
            throw new RuntimeException('The cleanup backup is older than two hours. Download a fresh backup.');
        }

        $real = realpath($path);
        $root = realpath(storage_path('app/production-reset-backups'));

        if (! $real || ! $root || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid financial cleanup backup path.');
        }
    }

    public function execute(mixed $user, string $backupPath): array
    {
        if (! $this->mainResetCompleted()) {
            throw new RuntimeException('Main Production Transaction Reset must be completed first.');
        }

        if ($this->completedRecord()) {
            throw new RuntimeException('Post-reset financial cleanup was already completed and is locked.');
        }

        if (! is_file($backupPath) || filesize($backupPath) <= 0) {
            throw new RuntimeException('A valid cleanup backup is required.');
        }

        $plan = $this->plan();
        $driver = strtolower((string) DB::connection()->getDriverName());
        $deleted = [];
        $zeroed = [];

        $this->disableForeignKeys($driver);

        try {
            DB::beginTransaction();

            foreach ($plan['items'] as $item) {
                $table = (string) $item['table'];

                if ($item['action'] === 'delete_all') {
                    $deleted[$table] = (int) DB::table($table)->delete();
                    continue;
                }

                if ($item['action'] === 'zero_columns') {
                    $updates = [];
                    foreach ((array) $item['columns'] as $column) {
                        $updates[(string) $column] = 0;
                    }

                    if ($updates) {
                        $query = $this->nonZeroQuery($table, array_keys($updates));
                        $zeroed[$table] = $query ? (int) $query->update($updates) : 0;
                    }
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw new RuntimeException(
                'Financial residue cleanup stopped and rolled back: '.$e->getMessage(),
                previous: $e
            );
        } finally {
            $this->enableForeignKeys($driver);
        }

        $identityWarnings = [];
        foreach ($plan['items'] as $item) {
            if ($item['action'] !== 'delete_all') {
                continue;
            }

            try {
                $this->resetIdentity((string) $item['table'], $driver);
            } catch (Throwable $e) {
                $identityWarnings[(string) $item['table']] = $e->getMessage();
            }
        }

        $verification = [];
        foreach ($plan['items'] as $item) {
            $table = (string) $item['table'];

            if ($item['action'] === 'delete_all') {
                $remaining = (int) DB::table($table)->count();
                if ($remaining > 0) {
                    $verification[$table] = $remaining.' row(s) remain';
                }
                continue;
            }

            if ($item['action'] === 'zero_columns') {
                $query = $this->nonZeroQuery($table, (array) $item['columns']);
                $remaining = $query ? (int) $query->count() : 0;
                if ($remaining > 0) {
                    $verification[$table] = $remaining.' row(s) still have non-zero state';
                }
            }
        }

        if ($verification) {
            throw new RuntimeException(
                'Cleanup verification failed: '.json_encode($verification)
            );
        }

        /*
         * ERP-10.31.79 final reconciliation:
         * re-scan the full schema AFTER the cleanup. Do not write the one-time
         * completion lock while any recognized residual financial state still
         * remains.
         */
        $postPlan = $this->plan();

        if (
            (int) ($postPlan['rows_to_delete'] ?? 0) > 0
            || (int) ($postPlan['rows_to_zero'] ?? 0) > 0
            || ! empty($postPlan['items'] ?? [])
        ) {
            $left = array_map(
                static fn (array $item): array => [
                    'table' => $item['table'] ?? null,
                    'action' => $item['action'] ?? null,
                    'rows' => $item['rows'] ?? null,
                    'columns' => $item['columns'] ?? [],
                ],
                (array) ($postPlan['items'] ?? [])
            );

            throw new RuntimeException(
                'Final financial reconciliation found residual state after cleanup: '
                .json_encode($left)
            );
        }

        try {
            Cache::flush();
        } catch (Throwable) {
        }

        $record = [
            'release' => 'ERP-10.31.79',
            'completed_at' => now()->toIso8601String(),
            'actor_id' => $this->userId($user),
            'actor_name' => $this->userName($user),
            'backup_path' => $backupPath,
            'rows_deleted' => array_sum($deleted),
            'rows_zeroed' => array_sum($zeroed),
            'tables_deleted' => count($deleted),
            'tables_zeroed' => count($zeroed),
            'deleted' => $deleted,
            'zeroed' => $zeroed,
            'identity_warnings' => $identityWarnings,
        ];

        $this->writeCompletedRecord($record);

        return $record;
    }

    private function classify(string $table, array $columns): array
    {
        $name = strtolower(trim($table));
        $columns = array_values(array_unique(array_map('strtolower', $columns)));
        $state = array_values(array_intersect($this->stateColumns, $columns));

        if ($this->isHardProtected($name)) {
            if (in_array($name, $this->zeroBalanceTables, true) && $state) {
                return [
                    'action' => 'zero_columns',
                    'columns' => $state,
                    'reason' => 'Preserve structural/master rows but clear residual financial balance/cost state.',
                ];
            }

            return [
                'action' => 'preserve',
                'columns' => [],
                'reason' => 'Protected ERP structure/master table.',
            ];
        }

        /* Transporter identity/master survives; its balances may be zeroed. */
        if (
            (str_contains($name, 'transport') || str_contains($name, 'vehicle') || preg_match('/(^|_)routes?(_|$)/', $name) === 1)
            && ! str_starts_with($name, 'booking_')
        ) {
            if ($state) {
                return [
                    'action' => 'zero_columns',
                    'columns' => $state,
                    'reason' => 'Transport master preserved; residual balance/cost state reset to zero.',
                ];
            }

            return [
                'action' => 'preserve',
                'columns' => [],
                'reason' => 'Transport master preserved.',
            ];
        }

        if ($this->isResidualFinancialTableName($name)) {
            return [
                'action' => 'delete_all',
                'columns' => $state,
                'reason' => 'Residual financial/cost/payable/summary table detected by table role.',
            ];
        }

        if ($state && $this->hasTransactionalDiscriminator($columns)) {
            return [
                'action' => 'delete_all',
                'columns' => $state,
                'reason' => 'Non-zero financial-state columns found on a transactional/summary table.',
            ];
        }

        /*
         * ERP-10.31.75 second-pass reconciliation:
         * after the main transaction reset, an unknown table can still feed
         * Dashboard Payables/Supplier Cost/Gross Profit. If it carries known
         * monetary state but has no master/config signature, zero only those
         * monetary columns instead of deleting the row.
         */
        $masterLike = (
            str_contains($name, 'master')
            || str_contains($name, 'setting')
            || str_contains($name, 'config')
            || str_contains($name, 'mapping')
            || str_contains($name, 'lookup')
            || str_ends_with($name, '_types')
            || str_ends_with($name, '_statuses')
            || str_ends_with($name, '_rates')
        );

        if ($state && ! $masterLike) {
            return [
                'action' => 'zero_columns',
                'columns' => $state,
                'reason' => 'Second-pass residual monetary state zeroed after production transaction reset.',
            ];
        }

        /* Safe default: unknown/master/config tables survive. */
        return [
            'action' => 'preserve',
            'columns' => [],
            'reason' => 'Safe-default preservation.',
        ];
    }

    private function isHardProtected(string $name): bool
    {
        $exact = [
            'migrations', 'users', 'user', 'roles', 'role', 'permissions', 'permission',
            'model_has_roles', 'model_has_permissions', 'role_has_permissions',
            'password_reset_tokens', 'sessions', 'personal_access_tokens',
            'companies', 'company', 'company_profiles', 'company_profile',
            'branches', 'branch', 'offices', 'office',
            'currencies', 'currency', 'currency_rates', 'financial_years', 'financial_year',
            'chart_of_accounts', 'chart_accounts', 'accounts',
            'account_mappings', 'account_mapping', 'accounting_mappings',
            'settings', 'system_settings', 'app_settings', 'configurations',
            'airlines', 'airline', 'airports', 'airport', 'countries', 'country', 'cities', 'city',
            'hotels', 'hotel_masters', 'hotel_master', 'travel_masters', 'travel_master',
            'products', 'product_masters', 'product_master', 'services', 'service_masters', 'service_master',
            'service_types', 'product_types', 'taxes', 'tax_rates',
            'booking_types', 'booking_statuses', 'booking_sources',
            'invoice_types', 'invoice_statuses', 'voucher_types', 'ticket_statuses', 'fare_types',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'parties', 'party_master', 'party_masters',
            'customers', 'customer_master', 'customer_masters',
            'suppliers', 'supplier_master', 'supplier_masters',
            'vendors', 'vendor_master', 'vendor_masters',
            'account_master', 'account_masters',
        ];

        if (in_array($name, $exact, true)) {
            return true;
        }

        return (
            preg_match('/(^|_)(company|branch|office)(_|$)/', $name) === 1
            || preg_match('/(^|_)(role|permission)(s|_|$)/', $name) === 1
            || preg_match('/(^|_)financial_year/', $name) === 1
            || preg_match('/(^|_)account_mapping/', $name) === 1
        );
    }

    private function isResidualFinancialTableName(string $name): bool
    {
        /* Do not delete obvious lookup/config masters solely because they say cost. */
        $masterLike = (
            str_contains($name, 'master')
            || str_contains($name, 'setting')
            || str_contains($name, 'config')
            || str_contains($name, 'mapping')
            || str_contains($name, 'lookup')
            || str_ends_with($name, '_types')
            || str_ends_with($name, '_statuses')
            || str_ends_with($name, '_rates')
        );

        if ($masterLike) {
            return false;
        }

        $patterns = [
            '/(^|_)(supplier|vendor)_(cost|costs|costing|costings|bill|bills|invoice|invoices|balance|balances|exposure|ledger|ledgers)(_|$)/',
            '/(^|_)(purchase|purchases|expense|expenses|costing|costings|payable|payables|accrual|accruals)(_|$)/',
            '/(^|_)(account|party|supplier|vendor)_(balance|balances|summary|summaries|snapshot|snapshots|rollup|rollups)(_|$)/',
            '/(^|_)(dashboard|financial)_(snapshot|snapshots|summary|summaries|metric|metrics|kpi|kpis|rollup|rollups)(_|$)/',
            '/(^|_)trial_balance(_|$)/',
            '/(^|_)aged_(payable|payables|receivable|receivables)(_|$)/',
            '/(^|_)(profit|profits|profitability|margin|margins)_(snapshot|snapshots|summary|summaries|entries|entry)(_|$)/',
            '/(^|_)cost_(entries|entry|snapshots|snapshot|summaries|summary|allocations|allocation)(_|$)/',
            '/(^|_)supplier_cost(s|_|$)/',
            '/(^|_)vendor_cost(s|_|$)/',
            '/(^|_)accounts_payable(s|_|$)/',
            '/(^|_)ap_(entries|entry|balances|balance|documents|document)(_|$)/',
            '/(^|_)(supplier|vendor)_(commercial|commercials|pricing|prices|rate|rates|amount|amounts)(_|$)/',
            '/(^|_)(supplier|vendor|party|account)_(opening|current|closing)_(balance|balances)(_|$)/',
            '/(^|_)(dashboard|management|finance|financial)_(cache|caches|aggregate|aggregates|total|totals)(_|$)/',
            '/(^|_)(booking|service|ticket)_(cost|costs|margin|margins|profit|profits|commercial|commercials)(_|$)/',
            '/(^|_)(supplier|vendor)_(payable|payables|outstanding|outstandings)(_|$)/',
            '/(^|_)(opening|closing|current)_(balance|balances)(_|$)/',
            '/(^|_)(account|party|supplier|vendor)_(opening|closing|current)_(balance|balances)(_|$)/',
            '/(^|_)(account|party)_(balance_forward|balance_forwards|brought_forward|carried_forward)(_|$)/',
            '/(^|_)(balance_forward|balance_forwards|opening_balances|closing_balances)(_|$)/',
            '/(^|_)(supplier|vendor)_(opening|closing|current)_(amount|amounts|exposure|outstanding)(_|$)/',
            '/(^|_)(management|dashboard|finance|financial)_(overview|overviews|trend|trends|series|chart|charts)(_|$)/',
            '/(^|_)(monthly|daily|period)_(supplier_cost|supplier_costs|cost|costs|profit|profits|margin|margins)(_|$)/',
            '/(^|_)(supplier|vendor)_costing_(header|headers|line|lines|detail|details|summary|summaries)(_|$)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasTransactionalDiscriminator(array $columns): bool
    {
        foreach ([
            'booking_id', 'invoice_id', 'sales_invoice_id', 'supplier_id', 'vendor_id',
            'party_id', 'journal_id', 'voucher_id', 'payment_id', 'receipt_id',
            'document_id', 'reference_no', 'document_no', 'status',
            'transaction_date', 'posting_date', 'invoice_date', 'booking_date',
            'created_at', 'updated_at', 'period_year', 'period_month',
            'branch_id', 'customer_id', 'service_id', 'product_id',
            'supplier_invoice_id', 'vendor_invoice_id', 'costing_id',
            'account_id', 'ledger_account_id', 'chart_account_id',
            'financial_year_id', 'fiscal_year_id',
            'entry_id', 'journal_entry_id', 'journal_line_id',
            'source_id', 'source_type', 'reference_type',
        ] as $column) {
            if (in_array($column, $columns, true)) {
                return true;
            }
        }

        return false;
    }

    private function nonZeroQuery(string $table, array $columns): ?Builder
    {
        $columns = array_values(array_filter($columns));
        if (! $columns) {
            return null;
        }

        return DB::table($table)->where(function (Builder $query) use ($columns): void {
            foreach ($columns as $column) {
                $query->orWhere(function (Builder $part) use ($column): void {
                    $part->whereNotNull($column)->where($column, '!=', 0);
                });
            }
        });
    }

    /** @return array<int,string> */
    private function tableNames(): array
    {
        $driver = strtolower((string) DB::connection()->getDriverName());
        $tables = [];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
                $values = array_values((array) $row);
                if (isset($values[0])) {
                    $tables[] = (string) $values[0];
                }
            }
        } elseif ($driver === 'sqlite') {
            foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $row) {
                $tables[] = (string) ($row->name ?? '');
            }
        } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            foreach (DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema() AND table_type='BASE TABLE'") as $row) {
                $tables[] = (string) ($row->table_name ?? '');
            }
        } else {
            foreach (Schema::getTables() as $row) {
                if (is_array($row)) {
                    $tables[] = (string) ($row['name'] ?? $row['table_name'] ?? '');
                } elseif (is_object($row)) {
                    $tables[] = (string) ($row->name ?? $row->table_name ?? '');
                }
            }
        }

        $tables = array_values(array_unique(array_filter(array_map('trim', $tables))));
        sort($tables);
        return $tables;
    }

    private function disableForeignKeys(string $driver): void
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            return;
        }
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
    }

    private function enableForeignKeys(string $driver): void
    {
        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                return;
            }
            if ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        } catch (Throwable) {
        }
    }

    private function resetIdentity(string $table, string $driver): void
    {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $quoted = '`'.str_replace('`', '``', $table).'`';
            DB::statement('ALTER TABLE '.$quoted.' AUTO_INCREMENT = 1');
            return;
        }

        if ($driver === 'sqlite') {
            try {
                DB::table('sqlite_sequence')->where('name', $table)->delete();
            } catch (Throwable) {
            }
        }
    }

    private function completionMarkerPath(): string
    {
        return storage_path('app/production-reset/post-reset-financial-cleanup-v3.completed.json');
    }

    private function writeCompletedRecord(array $record): void
    {
        $path = $this->completionMarkerPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cleanup completed but completion lock directory could not be created.');
        }

        if (file_put_contents(
            $path,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) === false) {
            throw new RuntimeException('Cleanup completed but completion lock could not be written.');
        }
    }

    private function userId(mixed $user): mixed
    {
        try {
            if (is_object($user) && method_exists($user, 'getAuthIdentifier')) {
                return $user->getAuthIdentifier();
            }
            return $user->id ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    private function userName(mixed $user): string
    {
        if (! $user) {
            return 'Unknown';
        }

        foreach (['name', 'full_name', 'username', 'email'] as $attribute) {
            try {
                $value = trim((string) ($user->{$attribute} ?? ''));
                if ($value !== '') {
                    return $value;
                }
            } catch (Throwable) {
            }
        }

        return 'User';
    }

    private function gzWrite(mixed $handle, string $content): void
    {
        if (gzwrite($handle, $content) === false) {
            throw new RuntimeException('Unable to write cleanup backup.');
        }
    }
}
