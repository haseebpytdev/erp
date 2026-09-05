<?php

namespace App\Services\System;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-10.31.72
 *
 * Controlled transactional-data reset for the Easy Ticket Travel ERP.
 *
 * Principles:
 * - Never auto-runs from a migration/deploy.
 * - Runtime schema inspection: works with the native ERP plus cumulative tables.
 * - Preserves structural/master data required for the ERP to operate.
 * - Clears booking/sales/accounting/payment/voucher/test operational identities.
 * - Preserves all transporter / route / vehicle master data.
 * - Requires a backup before execute.
 */
class ProductionDataResetService
{
    public const CONFIRMATION = 'RESET LIVE TRANSACTIONS';

    private const BACKUP_MAX_AGE_SECONDS = 7200;

    /** @var array<int,string> */
    private array $identityTables = [
        'customers',
        'customer_masters',
        'customer_master',
        'suppliers',
        'supplier_masters',
        'supplier_master',
        'vendors',
        'vendor_masters',
        'vendor_master',
        'agents',
        'agent_masters',
        'agent_master',
        'passengers',
        'passenger_masters',
        'passenger_master',
    ];

    /** @var array<int,string> */
    private array $partyTables = [
        'parties',
        'party_masters',
        'party_master',
    ];

    /** @var array<int,string> */
    private array $counterTables = [
        'document_sequences',
        'document_sequence',
        'document_counters',
        'document_counter',
        'number_sequences',
        'number_sequence',
        'number_counters',
        'number_counter',
        'reference_sequences',
        'reference_sequence',
        'reference_counters',
        'reference_counter',
    ];

    /**
     * Tables/patterns that must survive production reset.
     *
     * These include ERP structure plus the specifically requested transporter
     * data. Operational identities are evaluated before generic "master"
     * preservation so test passenger/customer/supplier masters can still clear.
     */
    public function plan(): array
    {
        $items = [];
        $preserved = [];
        $warnings = [];
        $rowsToDelete = 0;

        foreach ($this->tableNames() as $table) {
            $classification = $this->classify($table);

            if ($classification['action'] === 'preserve') {
                $preserved[] = [
                    'table' => $table,
                    'reason' => $classification['reason'],
                ];
                continue;
            }

            if ($classification['action'] === 'skip') {
                $warnings[] = [
                    'table' => $table,
                    'reason' => $classification['reason'],
                ];
                continue;
            }

            $count = 0;

            try {
                if ($classification['action'] === 'delete_party_rows') {
                    $query = $this->partyCleanupQuery($table);
                    $count = $query ? (int) $query->count() : 0;
                } elseif ($classification['action'] === 'reset_counter') {
                    $count = (int) DB::table($table)->count();
                } else {
                    $count = (int) DB::table($table)->count();
                }
            } catch (Throwable $e) {
                $warnings[] = [
                    'table' => $table,
                    'reason' => 'Unable to count safely: '.$e->getMessage(),
                ];
                continue;
            }

            if (
                in_array(
                    $classification['action'],
                    ['delete_all', 'delete_party_rows'],
                    true
                )
            ) {
                $rowsToDelete += $count;
            }

            $items[] = [
                'table' => $table,
                'action' => $classification['action'],
                'action_label' => $this->actionLabel($classification['action']),
                'rows' => $count,
                'reason' => $classification['reason'],
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int =>
                strcmp($a['table'], $b['table'])
        );

        usort(
            $preserved,
            static fn (array $a, array $b): int =>
                strcmp($a['table'], $b['table'])
        );

        return [
            'items' => $items,
            'preserved' => $preserved,
            'warnings' => $warnings,
            'rows_to_delete' => $rowsToDelete,
            'tables_to_clear' => count(
                array_filter(
                    $items,
                    static fn (array $item): bool =>
                        in_array(
                            $item['action'],
                            ['delete_all', 'delete_party_rows'],
                            true
                        )
                )
            ),
            'counter_tables' => count(
                array_filter(
                    $items,
                    static fn (array $item): bool =>
                        $item['action'] === 'reset_counter'
                )
            ),
            'confirmation' => self::CONFIRMATION,
            'completed' => $this->completedRecord(),
        ];
    }

    public function createBackup(mixed $user): array
    {
        $plan = $this->plan();

        if ($plan['completed']) {
            throw new RuntimeException(
                'Production reset was already completed. A second reset is locked.'
            );
        }

        $directory = storage_path(
            'app/production-reset-backups'
        );

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException(
                'Unable to create production reset backup directory.'
            );
        }

        $stamp = now()->format('Ymd_His');
        $path = $directory.'/transaction-reset-backup_'.$stamp.'.json.gz';

        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create transaction backup file.'
            );
        }

        try {
            $this->gzWrite($handle, "{\n");
            $this->gzWrite(
                $handle,
                '"meta":'.json_encode([
                    'release' => 'ERP-10.31.72',
                    'created_at' => now()->toIso8601String(),
                    'actor_id' => $this->userId($user),
                    'actor_name' => $this->userName($user),
                    'database_driver' => DB::connection()->getDriverName(),
                    'rows_planned_for_delete' => $plan['rows_to_delete'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).",\n"
            );

            $this->gzWrite($handle, "\"tables\":{\n");

            $firstTable = true;

            foreach ($plan['items'] as $item) {
                if (
                    ! in_array(
                        $item['action'],
                        ['delete_all', 'delete_party_rows', 'reset_counter'],
                        true
                    )
                ) {
                    continue;
                }

                $table = (string) $item['table'];

                if (! $firstTable) {
                    $this->gzWrite($handle, ",\n");
                }

                $firstTable = false;

                $this->gzWrite(
                    $handle,
                    json_encode($table).':{"action":'
                    .json_encode($item['action'])
                    .',"rows":['
                );

                $firstRow = true;

                /*
                 * Back up the complete table for every reset-touched table.
                 * For selective party cleanup this intentionally includes
                 * preserved rows too, making manual restoration easier.
                 */
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
            throw new RuntimeException(
                'Transaction backup was not created successfully.'
            );
        }

        return [
            'path' => $path,
            'filename' => basename($path),
            'created_at' => now()->timestamp,
            'size' => (int) filesize($path),
        ];
    }

    public function validateBackup(
        ?string $path,
        mixed $createdAt
    ): void {
        $createdAt = (int) $createdAt;

        if (
            ! $path
            || ! is_file($path)
            || filesize($path) <= 0
        ) {
            throw new RuntimeException(
                'Download the pre-reset transaction backup first.'
            );
        }

        if (
            $createdAt <= 0
            || (time() - $createdAt) > self::BACKUP_MAX_AGE_SECONDS
        ) {
            throw new RuntimeException(
                'The reset backup is older than two hours. Download a fresh backup before reset.'
            );
        }

        $real = realpath($path);
        $backupRoot = realpath(
            storage_path('app/production-reset-backups')
        );

        if (
            ! $real
            || ! $backupRoot
            || ! str_starts_with(
                $real,
                $backupRoot.DIRECTORY_SEPARATOR
            )
        ) {
            throw new RuntimeException(
                'The reset backup path is invalid.'
            );
        }
    }

    public function execute(
        mixed $user,
        string $backupPath
    ): array {
        if ($this->completedRecord()) {
            throw new RuntimeException(
                'Production Transaction Reset was already completed and is now locked.'
            );
        }

        if (! is_file($backupPath) || filesize($backupPath) <= 0) {
            throw new RuntimeException(
                'A valid pre-reset backup file is required.'
            );
        }

        $plan = $this->plan();

        $deleted = [];
        $counterReset = [];
        $errors = [];

        $connection = DB::connection();
        $driver = strtolower(
            (string) $connection->getDriverName()
        );

        $this->disableForeignKeys($driver);

        try {
            DB::beginTransaction();

            foreach ($plan['items'] as $item) {
                $table = (string) $item['table'];
                $action = (string) $item['action'];

                try {
                    if ($action === 'delete_all') {
                        $count = (int) DB::table($table)->delete();

                        $deleted[$table] = $count;

                        continue;
                    }

                    if ($action === 'delete_party_rows') {
                        $query = $this->partyCleanupQuery($table);

                        if (! $query) {
                            continue;
                        }

                        $count = (int) $query->delete();

                        $deleted[$table] = $count;

                        continue;
                    }

                    if ($action === 'reset_counter') {
                        $columns = Schema::getColumnListing($table);
                        $updates = [];

                        foreach ([
                            'current_value',
                            'last_number',
                            'last_value',
                            'counter',
                            'sequence_value',
                        ] as $column) {
                            if (in_array($column, $columns, true)) {
                                $updates[$column] = 0;
                            }
                        }

                        foreach ([
                            'next_number',
                            'next_value',
                        ] as $column) {
                            if (in_array($column, $columns, true)) {
                                $updates[$column] = 1;
                            }
                        }

                        if ($updates) {
                            DB::table($table)->update($updates);
                            $counterReset[$table] = array_keys($updates);
                        }
                    }
                } catch (Throwable $e) {
                    $errors[$table] = $e->getMessage();

                    throw $e;
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw new RuntimeException(
                'Reset stopped and database transaction was rolled back at table '
                .(array_key_last($errors) ?: 'unknown')
                .': '.$e->getMessage(),
                previous: $e
            );
        } finally {
            $this->enableForeignKeys($driver);
        }

        /*
         * Reset identity/autoincrement only AFTER the successful data commit.
         * A failure here cannot reintroduce transactions, so it is reported as
         * a warning instead of compromising the cleared transactional state.
         */
        $identityWarnings = [];

        foreach ($plan['items'] as $item) {
            if ($item['action'] !== 'delete_all') {
                continue;
            }

            try {
                $this->resetIdentity(
                    (string) $item['table'],
                    $driver
                );
            } catch (Throwable $e) {
                $identityWarnings[
                    (string) $item['table']
                ] = $e->getMessage();
            }
        }

        $verification = [];

        foreach ($plan['items'] as $item) {
            if ($item['action'] !== 'delete_all') {
                continue;
            }

            try {
                $remaining = (int) DB::table(
                    (string) $item['table']
                )->count();

                if ($remaining > 0) {
                    $verification[
                        (string) $item['table']
                    ] = $remaining;
                }
            } catch (Throwable $e) {
                $verification[
                    (string) $item['table']
                ] = 'verify_error: '.$e->getMessage();
            }
        }

        if ($verification) {
            throw new RuntimeException(
                'Reset completed deletes but verification found remaining rows: '
                .json_encode($verification)
            );
        }

        $record = [
            'release' => 'ERP-10.31.72',
            'completed_at' => now()->toIso8601String(),
            'actor_id' => $this->userId($user),
            'actor_name' => $this->userName($user),
            'backup_path' => $backupPath,
            'rows_deleted' => array_sum($deleted),
            'tables_cleared' => count($deleted),
            'deleted' => $deleted,
            'counter_reset' => $counterReset,
            'identity_warnings' => $identityWarnings,
        ];

        $this->writeCompletedRecord($record);

        return $record;
    }

    public function completedRecord(): ?array
    {
        /*
         * ERP-10.31.72: the live production reset was completed on
         * ERP-10.31.70. A release bump must NEVER reopen the destructive tool.
         * Recognize the stable marker and all legacy reset markers.
         */
        foreach ($this->completionMarkerPaths() as $path) {
            if (! is_file($path)) {
                continue;
            }

            try {
                $decoded = json_decode(
                    (string) file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR
                );

                return is_array($decoded)
                    ? $decoded
                    : null;
            } catch (Throwable) {
                return [
                    'completed_at' => 'unknown',
                    'actor_name' => 'unknown',
                    'rows_deleted' => null,
                    'tables_cleared' => null,
                ];
            }
        }

        return null;
    }

    private function classify(string $table): array
    {
        $name = strtolower(trim($table));

        if ($name === '') {
            return [
                'action' => 'skip',
                'reason' => 'Invalid table name.',
            ];
        }

        /*
         * Transporter / route / vehicle MASTER data is explicitly protected.
         *
         * Do not mistake booking transport/service rows for transport masters:
         * booking_group_package_transports, booking_transport_segments, etc.
         * are transactional and must clear.
         */
        $transportLike = (
            str_contains($name, 'transport')
            || str_contains($name, 'vehicle')
            || preg_match('/(^|_)routes?(_|$)/', $name) === 1
        );

        $transportTransaction = (
            str_starts_with($name, 'booking_')
            || str_contains($name, '_booking_')
            || preg_match('/(^|_)bookings?($|_)/', $name) === 1
            || str_contains($name, 'invoice')
            || str_contains($name, 'voucher')
            || str_contains($name, 'payment')
            || str_contains($name, 'receipt')
        );

        if (
            $transportLike
            && ! $transportTransaction
        ) {
            return [
                'action' => 'preserve',
                'reason' => 'Transporter / route / vehicle master data preserved.',
            ];
        }

        /*
         * Test operational identities are intentionally cleared so staff start
         * with real customers/suppliers/passengers rather than UAT identities.
         */
        if (in_array($name, $this->identityTables, true)) {
            return [
                'action' => 'delete_all',
                'reason' => 'Test operational identity master cleared for production start.',
            ];
        }

        if (in_array($name, $this->partyTables, true)) {
            if ($this->partyCleanupDescriptor($name)) {
                return [
                    'action' => 'delete_party_rows',
                    'reason' => 'Customer/supplier/vendor/agent/passenger parties cleared; company/transporter parties preserved.',
                ];
            }

            return [
                'action' => 'skip',
                'reason' => 'Party table has no safe role/type discriminator; preserved to avoid deleting company/transporter identity.',
            ];
        }

        if (in_array($name, $this->counterTables, true)) {
            return [
                'action' => 'reset_counter',
                'reason' => 'Recognized document/reference counter reset.',
            ];
        }

        if ($this->isProtectedTable($name)) {
            return [
                'action' => 'preserve',
                'reason' => 'ERP structure / company / accounting / travel master data preserved.',
            ];
        }

        if ($this->isTransactionalTable($name)) {
            return [
                'action' => 'delete_all',
                'reason' => 'Transactional/UAT operational data cleared.',
            ];
        }

        /*
         * Generic master/config tables are preserved by default.
         */
        if (
            str_contains($name, 'master')
            || str_contains($name, 'setting')
            || str_contains($name, 'config')
            || str_contains($name, 'mapping')
            || str_contains($name, 'lookup')
            || str_contains($name, 'type')
            || str_contains($name, 'status')
        ) {
            return [
                'action' => 'preserve',
                'reason' => 'Master/configuration/lookup table preserved.',
            ];
        }

        return [
            'action' => 'preserve',
            'reason' => 'Unknown table preserved by safe default.',
        ];
    }

    private function isProtectedTable(string $name): bool
    {
        $exact = [
            'migrations',
            'users',
            'user',
            'roles',
            'role',
            'permissions',
            'permission',
            'model_has_roles',
            'model_has_permissions',
            'role_has_permissions',
            'password_reset_tokens',
            'password_resets',
            'sessions',
            'personal_access_tokens',
            'failed_jobs',
            'jobs',
            'job_batches',
            'cache',
            'cache_locks',

            'companies',
            'company',
            'company_profiles',
            'company_profile',
            'branches',
            'branch',
            'offices',
            'office',

            'currencies',
            'currency',
            'currency_rates',
            'financial_years',
            'financial_year',

            'chart_of_accounts',
            'chart_accounts',
            'accounts',
            'account_mappings',
            'account_mapping',
            'accounting_mappings',

            'settings',
            'system_settings',
            'app_settings',
            'configurations',

            'airlines',
            'airline',
            'airports',
            'airport',
            'countries',
            'country',
            'cities',
            'city',
            'hotels',
            'hotel_masters',
            'hotel_master',
            'travel_masters',
            'travel_master',

            'products',
            'product_masters',
            'product_master',
            'services',
            'service_masters',
            'service_master',
            'service_types',
            'product_types',
            'taxes',
            'tax_rates',

            'booking_types',
            'booking_statuses',
            'booking_sources',
            'invoice_types',
            'invoice_statuses',
            'voucher_types',
            'ticket_statuses',
            'fare_types',
        ];

        if (in_array($name, $exact, true)) {
            return true;
        }

        return (
            preg_match('/(^|_)(company|branch|office)(_|$)/', $name) === 1
            || preg_match('/(^|_)(role|permission)(s|_|$)/', $name) === 1
            || preg_match('/(^|_)currenc(y|ies|y_)/', $name) === 1
            || preg_match('/(^|_)financial_year/', $name) === 1
            || preg_match('/(^|_)(chart_of_accounts|account_mapping)/', $name) === 1
            || preg_match('/(^|_)(airline|airport|country|city)(s|_|$)/', $name) === 1
        );
    }

    private function isTransactionalTable(string $name): bool
    {
        /*
         * Explicit known cumulative transactional tables.
         */
        $exact = [
            'booking_group_package_unified',
            'booking_group_package_passengers',
            'booking_group_package_flights',
            'booking_group_package_hotels',
            'booking_group_package_transports',
            'booking_group_package_services',
            'booking_group_package_commercial_amendments',
            'booking_group_umrah_contexts',
            'booking_group_umrah_invoice_links',
            'sales_invoice_air_ticket_line_links',
        ];

        if (in_array($name, $exact, true)) {
            return true;
        }

        $patterns = [
            '/(^|_)bookings?($|_)/',
            '/(^|_)booking_/',
            '/(^|_)sales_invoices?($|_)/',
            '/(^|_)sales_invoice_/',
            '/(^|_)invoices?($|_)/',
            '/(^|_)invoice_/',
            '/(^|_)journals?($|_)/',
            '/(^|_)journal_/',
            '/(^|_)ledger(s|_|$)/',
            '/(^|_)ledger_/',
            '/(^|_)general_ledger/',
            '/(^|_)gl_entries?($|_)/',
            '/(^|_)receipts?($|_)/',
            '/(^|_)receipt_/',
            '/(^|_)payments?($|_)/',
            '/(^|_)payment_/',
            '/(^|_)refunds?($|_)/',
            '/(^|_)refund_/',
            '/(^|_)credit_notes?($|_)/',
            '/(^|_)credit_note_/',
            '/(^|_)debit_notes?($|_)/',
            '/(^|_)debit_note_/',
            '/(^|_)advances?($|_)/',
            '/(^|_)advance_/',
            '/(^|_)commissions?($|_)/',
            '/(^|_)commission_/',
            '/(^|_)vouchers?($|_)/',
            '/(^|_)voucher_/',
            '/(^|_)receivables?($|_)/',
            '/(^|_)payables?($|_)/',
            '/customer_receivable/',
            '/supplier_payable/',
            '/(^|_)accounting_entries?($|_)/',
            '/(^|_)posting(s|_|$)/',
            '/(^|_)transactions?($|_)/',
            '/(^|_)tickets?($|_)/',
            '/(^|_)ticket_/',
            '/air_ticket/',
            '/(^|_)hotel_stays?($|_)/',
            '/(^|_)flight_segments?($|_)/',
            '/(^|_)itinerary_segments?($|_)/',
            '/commercial_amendments?/',
            '/workflow_(events?|history|actions?)/',
            '/approval_(events?|history|actions?)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                /*
                 * Master/type/status tables were already protected above.
                 */
                return true;
            }
        }

        return false;
    }

    private function partyCleanupDescriptor(string $table): ?array
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $columns = Schema::getColumnListing($table);

        foreach ([
            'party_type',
            'type',
            'party_category',
            'category',
            'role_type',
            'entity_type',
        ] as $column) {
            if (in_array($column, $columns, true)) {
                return [
                    'mode' => 'type_column',
                    'column' => $column,
                ];
            }
        }

        $flags = array_values(
            array_intersect(
                [
                    'is_customer',
                    'is_supplier',
                    'is_vendor',
                    'is_agent',
                    'is_passenger',
                    'is_client',
                ],
                $columns
            )
        );

        if ($flags) {
            return [
                'mode' => 'flags',
                'flags' => $flags,
                'transporter_flag' =>
                    in_array('is_transporter', $columns, true)
                        ? 'is_transporter'
                        : null,
            ];
        }

        return null;
    }

    private function partyCleanupQuery(string $table): ?Builder
    {
        $descriptor = $this->partyCleanupDescriptor($table);

        if (! $descriptor) {
            return null;
        }

        $query = DB::table($table);

        if ($descriptor['mode'] === 'type_column') {
            $column = (string) $descriptor['column'];

            $deleteTerms = [
                'customer',
                'client',
                'supplier',
                'vendor',
                'agent',
                'passenger',
            ];

            $preserveTerms = [
                'transport',
                'company',
                'internal',
                'branch',
                'office',
            ];

            $query->where(
                function (Builder $where) use ($column, $deleteTerms): void {
                    foreach ($deleteTerms as $term) {
                        $where->orWhere(
                            $column,
                            'like',
                            '%'.$term.'%'
                        );
                    }
                }
            );

            foreach ($preserveTerms as $term) {
                $query->where(
                    $column,
                    'not like',
                    '%'.$term.'%'
                );
            }

            return $query;
        }

        $flags = (array) ($descriptor['flags'] ?? []);

        $query->where(
            function (Builder $where) use ($flags): void {
                foreach ($flags as $flag) {
                    $where->orWhere($flag, true);
                }
            }
        );

        if ($descriptor['transporter_flag'] ?? null) {
            $query->where(
                (string) $descriptor['transporter_flag'],
                '!=',
                true
            );
        }

        return $query;
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'delete_all' => 'Delete all rows',
            'delete_party_rows' => 'Delete test operational parties',
            'reset_counter' => 'Reset document counter',
            default => ucfirst(str_replace('_', ' ', $action)),
        };
    }

    /** @return array<int,string> */
    private function tableNames(): array
    {
        $driver = strtolower(
            (string) DB::connection()->getDriverName()
        );

        $tables = [];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (
                DB::select(
                    "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"
                )
                as $row
            ) {
                $values = array_values((array) $row);

                if (isset($values[0])) {
                    $tables[] = (string) $values[0];
                }
            }
        } elseif ($driver === 'sqlite') {
            foreach (
                DB::select(
                    "SELECT name FROM sqlite_master
                     WHERE type='table'
                     AND name NOT LIKE 'sqlite_%'"
                )
                as $row
            ) {
                $tables[] = (string) ($row->name ?? '');
            }
        } elseif (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            foreach (
                DB::select(
                    "SELECT table_name
                     FROM information_schema.tables
                     WHERE table_schema = current_schema()
                     AND table_type='BASE TABLE'"
                )
                as $row
            ) {
                $tables[] = (string) ($row->table_name ?? '');
            }
        } else {
            /*
             * Laravel schema manager fallback for any other supported driver.
             */
            try {
                foreach (Schema::getTables() as $row) {
                    if (is_array($row)) {
                        $tables[] = (string) (
                            $row['name']
                            ?? $row['table_name']
                            ?? ''
                        );
                    } elseif (is_object($row)) {
                        $tables[] = (string) (
                            $row->name
                            ?? $row->table_name
                            ?? ''
                        );
                    }
                }
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Unable to inspect database tables for reset: '
                    .$e->getMessage(),
                    previous: $e
                );
            }
        }

        $tables = array_values(
            array_unique(
                array_filter(
                    array_map('trim', $tables)
                )
            )
        );

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

            return;
        }

        /*
         * Postgres only defers constraints that were declared DEFERRABLE.
         * cPanel production is MySQL/MariaDB, but keeping this safe fallback
         * avoids issuing unsupported syntax on other drivers.
         */
        if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            try {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
            } catch (Throwable) {
            }
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

    private function resetIdentity(
        string $table,
        string $driver
    ): void {
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $quoted = '`'.str_replace('`', '``', $table).'`';

            DB::statement(
                'ALTER TABLE '.$quoted.' AUTO_INCREMENT = 1'
            );

            return;
        }

        if ($driver === 'sqlite') {
            try {
                DB::table('sqlite_sequence')
                    ->where('name', $table)
                    ->delete();
            } catch (Throwable) {
            }

            return;
        }

        /*
         * Native SERIAL/IDENTITY reset is intentionally not guessed for
         * PostgreSQL because sequence names are schema-specific.
         */
    }

    private function writeCompletedRecord(array $record): void
    {
        $path = $this->completionMarkerPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException(
                'Transactions were cleared but the one-time reset completion lock could not be written.'
            );
        }

        $written = file_put_contents(
            $path,
            json_encode(
                $record,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            ),
            LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException(
                'Transactions were cleared but the one-time reset completion lock could not be written.'
            );
        }
    }

    private function completionMarkerPath(): string
    {
        return storage_path(
            'app/production-reset/production-transaction-reset.completed.json'
        );
    }

    /** @return array<int,string> */
    private function completionMarkerPaths(): array
    {
        return [
            $this->completionMarkerPath(),
            storage_path('app/production-reset/ERP-10.31.70.completed.json'),
            storage_path('app/production-reset/ERP-10.31.71.completed.json'),
            storage_path('app/production-reset/ERP-10.31.72.completed.json'),
        ];
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

        foreach ([
            'name',
            'full_name',
            'username',
            'email',
        ] as $attribute) {
            try {
                $value = trim(
                    (string) ($user->{$attribute} ?? '')
                );

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
            throw new RuntimeException(
                'Unable to write transaction backup.'
            );
        }
    }
}
