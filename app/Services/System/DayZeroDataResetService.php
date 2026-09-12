<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ERP-11.3.243 functional checkpoint
 *
 * Day-Zero / Fresh Production reset planner.
 *
 * IMPORTANT: this checkpoint resolves the production table classifications
 * approved after the ERP-11.3.242 live preview. Destructive execution remains
 * intentionally locked. No row mutation is authorized in this build.
 */
class DayZeroDataResetService
{
    public const CONFIRMATION = 'RESET ERP TO DAY ZERO';
    public const EXECUTION_ENABLED = false;

    private const BACKUP_MAX_AGE_SECONDS = 7200;

    /** @var array<int,string> */
    private array $preserveExact = [
        // Schema / authentication / authorization foundation.
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
        'role_permission',
        'role_user',
        'branch_user',

        // Company/accounting foundation required for a usable fresh ERP.
        'companies',
        'company',
        'company_profiles',
        'company_profile',
        'branches',
        'branch',
        'offices',
        'office',
        'departments',
        'staff',
        'currencies',
        'currency',
        'financial_years',
        'financial_year',
        'fiscal_years',
        'accounting_periods',
        'chart_of_accounts',
        'chart_accounts',
        'accounts',
        'account_mappings',
        'account_mapping',
        'accounting_mappings',
        'service_cost_allocations',

        // Workflow/security policy foundation retained for existing users.
        'approval_policies',
        'user_approval_limits',

        // Required system/reference lookup data. Business-entered masters are
        // deliberately handled explicitly in clearExact below.
        'settings',
        'system_settings',
        'app_settings',
        'configurations',
        'countries',
        'country',
        'cities',
        'city',
        'airlines',
        'airline',
        'airports',
        'airport',
        'booking_types',
        'booking_statuses',
        'booking_sources',
        'invoice_types',
        'invoice_statuses',
        'voucher_types',
        'ticket_statuses',
        'fare_types',
        'service_types',
        'product_types',
        'taxes',
        'tax_rates',
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
        'number_sequence_counters',
        'reference_sequences',
        'reference_sequence',
        'reference_counters',
        'reference_counter',
    ];

    /** @var array<int,string> */
    private array $clearExact = [
        // Runtime/test residue. User rows themselves remain preserved.
        'sessions',
        'personal_access_tokens',
        'password_reset_tokens',
        'password_resets',
        'failed_jobs',
        'jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'audit_logs',
        'login_events',

        // Operational identities should start from Day 1.
        'customers',
        'customer_masters',
        'customer_master',
        'customer_profiles',
        'suppliers',
        'supplier_masters',
        'supplier_master',
        'vendors',
        'vendor_masters',
        'vendor_master',
        'vendor_profiles',
        'agents',
        'agent_masters',
        'agent_master',
        'agent_profiles',
        'passengers',
        'passenger_masters',
        'passenger_master',
        'passenger_profiles',
        'employee_party_profiles',
        'parties',
        'party_roles',

        // Business-entered Day-1 masters/commercials are intentionally rebuilt.
        'exchange_rates',
        'group_travel_packages',
        'group_travel_package_passenger_prices',
        'hotels',
        'products_services',
        'transport_rate_cards',
        'transport_rates',
        'transport_routes',
        'transport_vehicle_types',
        'visa_rate_cards',
        'visa_service_operators',
    ];

    public function plan(): array
    {
        $items = [];
        $rowsToClear = 0;
        $clearTables = 0;
        $counterTables = 0;
        $preservedTables = 0;
        $reviewTables = 0;
        $warnings = [];

        foreach ($this->tableNames() as $table) {
            $classification = $this->classify($table);
            $rows = null;

            try {
                $rows = (int) DB::table($table)->count();
            } catch (Throwable $e) {
                $warnings[] = [
                    'table' => $table,
                    'reason' => 'Unable to count table safely: '.$e->getMessage(),
                ];
            }

            if ($classification['action'] === 'clear') {
                $clearTables++;
                $rowsToClear += (int) ($rows ?? 0);
            } elseif ($classification['action'] === 'reset_counter') {
                $counterTables++;
            } elseif ($classification['action'] === 'preserve') {
                $preservedTables++;
            } else {
                $reviewTables++;
            }

            $items[] = [
                'table' => $table,
                'action' => $classification['action'],
                'action_label' => strtoupper(str_replace('_', ' ', $classification['action'])),
                'rows' => $rows,
                'reason' => $classification['reason'],
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp($a['table'], $b['table'])
        );

        return [
            'items' => $items,
            'rows_to_clear' => $rowsToClear,
            'clear_tables' => $clearTables,
            'counter_tables' => $counterTables,
            'preserved_tables' => $preservedTables,
            'review_tables' => $reviewTables,
            'warnings' => $warnings,
            'confirmation' => self::CONFIRMATION,
            'execution_enabled' => self::EXECUTION_ENABLED,
            'ready_to_execute' => self::EXECUTION_ENABLED && $reviewTables === 0 && empty($warnings),
            'completed' => $this->completedRecord(),
        ];
    }

    public function createBackup(mixed $user): array
    {
        if ($this->completedRecord()) {
            throw new RuntimeException('Day-Zero reset is already completed and permanently locked.');
        }

        $directory = storage_path('app/day-zero-reset-backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Day-Zero backup directory.');
        }

        $stamp = now()->format('Ymd_His');
        $path = $directory.'/day-zero-full-backup_'.$stamp.'.json.gz';
        $handle = gzopen($path, 'wb9');

        if ($handle === false) {
            throw new RuntimeException('Unable to create Day-Zero backup file.');
        }

        try {
            $this->gzWrite($handle, "{\n");
            $this->gzWrite(
                $handle,
                '"meta":'.json_encode([
                    'release' => 'ERP-11.3.243-CLASSIFICATION-PREVIEW',
                    'created_at' => now()->toIso8601String(),
                    'actor_id' => $this->userId($user),
                    'actor_name' => $this->userName($user),
                    'database_driver' => DB::connection()->getDriverName(),
                    'purpose' => 'Full pre-Day-Zero database backup',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).",\n"
            );
            $this->gzWrite($handle, "\"tables\":{\n");

            $firstTable = true;
            foreach ($this->tableNames() as $table) {
                if (! $firstTable) {
                    $this->gzWrite($handle, ",\n");
                }
                $firstTable = false;

                $this->gzWrite($handle, json_encode($table).':[');
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

                $this->gzWrite($handle, ']');
            }

            $this->gzWrite($handle, "\n}\n}");
        } finally {
            gzclose($handle);
        }

        if (! is_file($path) || filesize($path) <= 0) {
            throw new RuntimeException('Day-Zero backup was not created successfully.');
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
            throw new RuntimeException('Download a fresh full Day-Zero backup first.');
        }

        if ($createdAt <= 0 || (time() - $createdAt) > self::BACKUP_MAX_AGE_SECONDS) {
            throw new RuntimeException('The Day-Zero backup is older than two hours. Download a fresh backup.');
        }

        $real = realpath($path);
        $root = realpath(storage_path('app/day-zero-reset-backups'));

        if (! $real || ! $root || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The Day-Zero backup path is invalid.');
        }
    }

    public function execute(mixed $user, string $backupPath): array
    {
        $this->validateBackup($backupPath, filemtime($backupPath) ?: 0);

        if (! self::EXECUTION_ENABLED) {
            throw new RuntimeException(
                'Day-Zero execution is intentionally LOCKED in ERP-11.3.243 classification preview. '
                .'The approved table plan may be inspected, but no database rows were changed.'
            );
        }

        throw new RuntimeException('Day-Zero execution has not been authorized for this release.');
    }

    public function completedRecord(): ?array
    {
        $path = storage_path('app/system/day-zero-reset-completed.json');

        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return [
                'completed_at' => 'unknown',
                'actor_name' => 'unknown',
            ];
        }
    }

    private function classify(string $table): array
    {
        $name = strtolower(trim($table));

        if ($name === '') {
            return ['action' => 'review', 'reason' => 'Invalid/empty table name.'];
        }

        if (in_array($name, $this->preserveExact, true)) {
            return [
                'action' => 'preserve',
                'reason' => 'Approved Day-Zero foundation: authentication, authorization, organization, accounting or seeded reference data.',
            ];
        }

        if (in_array($name, $this->counterTables, true)) {
            return [
                'action' => 'reset_counter',
                'reason' => 'Approved Day-Zero document/reference numbering reset.',
            ];
        }

        if (in_array($name, $this->clearExact, true)) {
            return [
                'action' => 'clear',
                'reason' => 'Approved Day-Zero business/UAT/runtime data should start empty.',
            ];
        }

        if ($this->isTransactionalTable($name)) {
            return [
                'action' => 'clear',
                'reason' => 'Recognized booking/sales/accounting/payment/voucher/workflow transaction table.',
            ];
        }

        // Anything not positively known remains REVIEW. This preserves the
        // fail-closed guarantee if a future schema adds a new table.
        return [
            'action' => 'review',
            'reason' => 'Unclassified table. Must be reviewed explicitly before Day-Zero execution can ever be enabled.',
        ];
    }

    private function isTransactionalTable(string $name): bool
    {
        $patterns = [
            '/(^|_)bookings?($|_)/',
            '/(^|_)booking_/',
            '/(^|_)sales_invoices?($|_)/',
            '/(^|_)sales_invoice_/',
            '/(^|_)invoices?($|_)/',
            '/(^|_)invoice_/',
            '/(^|_)supplier_cost/',
            '/(^|_)costings?($|_)/',
            '/(^|_)journals?($|_)/',
            '/(^|_)journal_/',
            '/(^|_)ledgers?($|_)/',
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
            '/(^|_)debit_notes?($|_)/',
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
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> */
    private function tableNames(): array
    {
        $connection = DB::connection();
        $driver = strtolower((string) $connection->getDriverName());
        $tables = [];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            foreach (DB::select('SHOW TABLES') as $row) {
                $values = array_values((array) $row);
                if (isset($values[0])) {
                    $tables[] = (string) $values[0];
                }
            }
        } elseif ($driver === 'sqlite') {
            foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $row) {
                $tables[] = (string) $row->name;
            }
        } elseif ($driver === 'pgsql') {
            foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()") as $row) {
                $tables[] = (string) $row->tablename;
            }
        } elseif ($driver === 'sqlsrv') {
            foreach (DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'") as $row) {
                $tables[] = (string) ($row->TABLE_NAME ?? '');
            }
        } else {
            throw new RuntimeException('Unsupported database driver for Day-Zero preview: '.$driver);
        }

        $tables = array_values(array_unique(array_filter(array_map('trim', $tables))));
        sort($tables);

        return $tables;
    }

    private function gzWrite(mixed $handle, string $value): void
    {
        if (gzwrite($handle, $value) === false) {
            throw new RuntimeException('Unable to write Day-Zero backup data.');
        }
    }

    private function userId(mixed $user): mixed
    {
        try {
            return $user?->getAuthIdentifier();
        } catch (Throwable) {
            return null;
        }
    }

    private function userName(mixed $user): string
    {
        foreach (['name', 'username', 'email'] as $attribute) {
            try {
                $value = trim((string) ($user->{$attribute} ?? ''));
                if ($value !== '') {
                    return $value;
                }
            } catch (Throwable) {
            }
        }

        return 'unknown';
    }
}
