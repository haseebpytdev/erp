<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-11.3.245 functional checkpoint
 *
 * Day-Zero / Fresh Production FK-resolution preview.
 *
 * IMPORTANT: this checkpoint resolves the live preview blockers discovered by
 * ERP-11.3.244 without enabling destructive execution. It classifies
 * service_cost_allocations as transactional CLEAR data, previews safe nullable
 * FK neutralization for preserved masters that point to CLEAR parents, exposes
 * cycle edges/nullability, and recalculates the effective child-before-parent
 * delete order after those preview-only resolutions.
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

        // Transactional allocation data must not survive a Day-Zero reset.
        'service_cost_allocations',

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

    /**
     * Preserved master columns which may safely be neutralized to NULL before
     * CLEAR parents are removed, but only when runtime schema proves nullable.
     *
     * @var array<int,array{table:string,column:string,parent_table:string}>
     */
    private array $nullableNeutralizationCandidates = [
        [
            'table' => 'airlines',
            'column' => 'default_vendor_party_id',
            'parent_table' => 'parties',
        ],
        [
            'table' => 'booking_sources',
            'column' => 'default_vendor_party_id',
            'parent_table' => 'parties',
        ],
    ];

    public function plan(): array
    {
        $items = [];
        $actions = [];
        $rowsToClear = 0;
        $clearTables = 0;
        $counterTables = 0;
        $preservedTables = 0;
        $reviewTables = 0;
        $warnings = [];

        foreach ($this->tableNames() as $table) {
            $classification = $this->classify($table);
            $actions[$table] = $classification['action'];
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

        $dependency = $this->dependencyAudit($actions);
        $counterPreview = $this->counterResetPreview($actions);

        $safetyPreviewReady = (
            $reviewTables === 0
            && empty($warnings)
            && $dependency['ready']
            && $counterPreview['ready']
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

            // ERP-11.3.245 FK-resolution preview.
            'fk_relationships' => count($dependency['relationships']),
            'fk_blockers' => count($dependency['blockers']),
            'fk_blocker_items' => $dependency['blockers'],
            'raw_fk_blockers' => count($dependency['raw_blockers']),
            'raw_fk_blocker_items' => $dependency['raw_blockers'],
            'neutralization_preview' => $dependency['neutralizations'],
            'neutralization_warnings' => $dependency['neutralization_warnings'],
            'dependency_cycles' => count($dependency['cycle_tables']),
            'dependency_cycle_tables' => $dependency['cycle_tables'],
            'dependency_cycle_edges' => $dependency['cycle_edges'],
            'dependency_warnings' => $dependency['warnings'],
            'delete_order' => $dependency['delete_order'],
            'dependency_plan_ready' => $dependency['ready'],
            'counter_reset_preview' => $counterPreview['items'],
            'counter_reset_warnings' => $counterPreview['warnings'],
            'counter_reset_ready' => $counterPreview['ready'],
            'safety_preview_ready' => $safetyPreviewReady,

            'ready_to_execute' => self::EXECUTION_ENABLED && $safetyPreviewReady,
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
                    'release' => 'ERP-11.3.245-FK-RESOLUTION-PREVIEW',
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
                'Day-Zero execution is intentionally LOCKED in ERP-11.3.245 FK resolution preview. '
                .'Nullable FK neutralization and cycle edges may be inspected, but no database rows were changed.'
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
            '/(^|_)accounting_entries?($|_)/',
            '/(^|_)accounting_postings?($|_)/',
            '/(^|_)accounting_transactions?($|_)/',
            '/(^|_)transactions?($|_)/',
            '/(^|_)tickets?($|_)/',
            '/(^|_)air_ticket/',
            '/(^|_)hotel_stays?($|_)/',
            '/(^|_)flight_segments?($|_)/',
            '/(^|_)itinerary_segments?($|_)/',
            '/(^|_)commercial_amendments?($|_)/',
            '/(^|_)workflow_/',
            '/(^|_)approval_(events?|history|actions?)/',
            '/(^|_)travel_voucher_/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,mixed>
     */
    private function dependencyAudit(array $actions): array
    {
        $relationships = $this->foreignKeyRelationships();
        $rawBlockers = [];
        $blockers = [];
        $warnings = [];
        $neutralizationWarnings = [];
        $neutralizations = [];
        $clearTables = array_keys(array_filter(
            $actions,
            static fn (string $action): bool => $action === 'clear'
        ));

        $candidateMap = [];
        foreach ($this->nullableNeutralizationCandidates as $candidate) {
            $candidateMap[$candidate['table'].'.'.$candidate['column'].'->'.$candidate['parent_table']] = $candidate;
        }

        $effectiveRelationships = [];

        foreach ($relationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];
            $childAction = $actions[$child] ?? 'review';
            $parentAction = $actions[$parent] ?? 'review';

            $isRawBlocker = $childAction !== 'clear' && $parentAction === 'clear';
            $resolvedByNeutralization = false;

            if ($isRawBlocker) {
                $rawBlockers[] = $relationship;
                $key = $child.'.'.$relationship['child_column'].'->'.$parent;
                $candidate = $candidateMap[$key] ?? null;

                if ($candidate !== null) {
                    $nullable = $this->isColumnNullable($child, $relationship['child_column']);
                    $neutralization = $relationship;
                    $neutralization['nullable'] = $nullable;
                    $neutralization['status'] = $nullable === true ? 'pass' : 'blocked';
                    $neutralization['operation'] = $nullable === true
                        ? $child.'.'.$relationship['child_column'].' = NULL before clearing '.$parent
                        : 'No safe NULL neutralization available';
                    $neutralizations[] = $neutralization;

                    if ($nullable === true) {
                        $resolvedByNeutralization = true;
                    } else {
                        $neutralizationWarnings[] = [
                            'table' => $child,
                            'column' => $relationship['child_column'],
                            'reason' => $nullable === false
                                ? 'Neutralization candidate is NOT NULL; cannot safely clear parent.'
                                : 'Unable to determine column nullability safely.',
                        ];
                    }
                }

                if (! $resolvedByNeutralization) {
                    $blockers[] = $relationship;
                }
            }

            if (! $resolvedByNeutralization) {
                $effectiveRelationships[] = $relationship;
            }
        }

        $graph = [];
        $inDegree = [];

        foreach ($clearTables as $table) {
            $graph[$table] = [];
            $inDegree[$table] = 0;
        }

        foreach ($effectiveRelationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];

            if (($actions[$child] ?? null) !== 'clear' || ($actions[$parent] ?? null) !== 'clear') {
                continue;
            }

            // child must be deleted before parent: child -> parent
            if (! in_array($parent, $graph[$child], true)) {
                $graph[$child][] = $parent;
                $inDegree[$parent]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }
        sort($queue);

        $deleteOrder = [];
        while ($queue !== []) {
            $table = array_shift($queue);
            $deleteOrder[] = $table;

            foreach ($graph[$table] as $parent) {
                $inDegree[$parent]--;
                if ($inDegree[$parent] === 0) {
                    $queue[] = $parent;
                    sort($queue);
                }
            }
        }

        $cycleTables = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree > 0) {
                $cycleTables[] = $table;
            }
        }
        sort($cycleTables);

        $cycleEdges = [];
        if ($cycleTables !== []) {
            $cycleLookup = array_fill_keys($cycleTables, true);
            foreach ($effectiveRelationships as $relationship) {
                $child = $relationship['child_table'];
                $parent = $relationship['parent_table'];

                if (! isset($cycleLookup[$child], $cycleLookup[$parent])) {
                    continue;
                }
                if (($actions[$child] ?? null) !== 'clear' || ($actions[$parent] ?? null) !== 'clear') {
                    continue;
                }

                $edge = $relationship;
                $edge['nullable'] = $this->isColumnNullable($child, $relationship['child_column']);
                $edge['can_break_with_null'] = $edge['nullable'] === true;
                $edge['operation'] = $edge['can_break_with_null']
                    ? $child.'.'.$relationship['child_column'].' = NULL before delete ordering'
                    : 'Cycle edge cannot be safely neutralized by NULL';
                $cycleEdges[] = $edge;
            }
        }

        $resolvableCycleKeys = [];
        foreach ($cycleEdges as $edge) {
            if (($edge['can_break_with_null'] ?? false) === true) {
                $resolvableCycleKeys[$this->relationshipKey($edge)] = true;
            }
        }

        if ($resolvableCycleKeys !== []) {
            $cycleResolvedRelationships = array_values(array_filter(
                $effectiveRelationships,
                fn (array $relationship): bool => ! isset($resolvableCycleKeys[$this->relationshipKey($relationship)])
            ));
            [$deleteOrder, $cycleTables] = $this->topologicalDeleteOrder($actions, $clearTables, $cycleResolvedRelationships);
        }

        if ($cycleTables !== []) {
            $warnings[] = [
                'table' => implode(', ', $cycleTables),
                'reason' => 'Dependency cycle remains after applying only schema-proven nullable preview resolutions.',
            ];
        }

        return [
            'relationships' => $relationships,
            'raw_blockers' => $rawBlockers,
            'blockers' => $blockers,
            'neutralizations' => $neutralizations,
            'neutralization_warnings' => $neutralizationWarnings,
            'cycle_tables' => $cycleTables,
            'cycle_edges' => $cycleEdges,
            'warnings' => $warnings,
            'delete_order' => $deleteOrder,
            'ready' => empty($blockers)
                && empty($neutralizationWarnings)
                && empty($cycleTables)
                && count($deleteOrder) === count($clearTables),
        ];
    }

    /**
     * @param array<string,string> $actions
     * @param array<int,string> $clearTables
     * @param array<int,array<string,mixed>> $relationships
     * @return array{0:array<int,string>,1:array<int,string>}
     */
    private function topologicalDeleteOrder(array $actions, array $clearTables, array $relationships): array
    {
        $graph = [];
        $inDegree = [];

        foreach ($clearTables as $table) {
            $graph[$table] = [];
            $inDegree[$table] = 0;
        }

        foreach ($relationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];

            if (($actions[$child] ?? null) !== 'clear' || ($actions[$parent] ?? null) !== 'clear') {
                continue;
            }

            if (! in_array($parent, $graph[$child], true)) {
                $graph[$child][] = $parent;
                $inDegree[$parent]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree === 0) {
                $queue[] = $table;
            }
        }
        sort($queue);

        $deleteOrder = [];
        while ($queue !== []) {
            $table = array_shift($queue);
            $deleteOrder[] = $table;

            foreach ($graph[$table] as $parent) {
                $inDegree[$parent]--;
                if ($inDegree[$parent] === 0) {
                    $queue[] = $parent;
                    sort($queue);
                }
            }
        }

        $cycleTables = [];
        foreach ($inDegree as $table => $degree) {
            if ($degree > 0) {
                $cycleTables[] = $table;
            }
        }
        sort($cycleTables);

        return [$deleteOrder, $cycleTables];
    }

    /** @param array<string,mixed> $relationship */
    private function relationshipKey(array $relationship): string
    {
        return ($relationship['child_table'] ?? '')
            .'.'.($relationship['child_column'] ?? '')
            .'->'.($relationship['parent_table'] ?? '')
            .'.'.($relationship['parent_column'] ?? '');
    }

    /**
     * @return array<int,array{child_table:string,child_column:string,parent_table:string,parent_column:string,constraint_name:?string}>
     */
    private function foreignKeyRelationships(): array
    {
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();
        $relationships = [];

        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $rows = DB::select(
                    'SELECT CONSTRAINT_NAME AS constraint_name, TABLE_NAME AS child_table, COLUMN_NAME AS child_column, REFERENCED_TABLE_NAME AS parent_table, REFERENCED_COLUMN_NAME AS parent_column '
                    .'FROM information_schema.KEY_COLUMN_USAGE '
                    .'WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL '
                    .'ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
                    [$database]
                );
            } elseif ($driver === 'pgsql') {
                $rows = DB::select(
                    "SELECT tc.constraint_name, kcu.table_name AS child_table, kcu.column_name AS child_column, ccu.table_name AS parent_table, ccu.column_name AS parent_column\n"
                    ."FROM information_schema.table_constraints tc\n"
                    ."JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema\n"
                    ."JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema\n"
                    ."WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()\n"
                    .'ORDER BY kcu.table_name, tc.constraint_name'
                );
            } elseif ($driver === 'sqlite') {
                $rows = [];
                foreach ($this->tableNames() as $table) {
                    $quoted = str_replace("'", "''", $table);
                    foreach (DB::select("PRAGMA foreign_key_list('{$quoted}')") as $row) {
                        $rows[] = (object) [
                            'constraint_name' => null,
                            'child_table' => $table,
                            'child_column' => $row->from ?? null,
                            'parent_table' => $row->table ?? null,
                            'parent_column' => $row->to ?? 'id',
                        ];
                    }
                }
            } elseif (in_array($driver, ['sqlsrv', 'dblib'], true)) {
                $rows = DB::select(
                    'SELECT fk.name AS constraint_name, OBJECT_NAME(fkc.parent_object_id) AS child_table, COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS child_column, '
                    .'OBJECT_NAME(fkc.referenced_object_id) AS parent_table, COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS parent_column '
                    .'FROM sys.foreign_key_columns fkc JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id '
                    .'ORDER BY OBJECT_NAME(fkc.parent_object_id), fk.name'
                );
            } else {
                return [];
            }

            foreach ($rows as $row) {
                $child = strtolower((string) ($row->child_table ?? ''));
                $parent = strtolower((string) ($row->parent_table ?? ''));
                $childColumn = strtolower((string) ($row->child_column ?? ''));
                $parentColumn = strtolower((string) ($row->parent_column ?? 'id'));

                if ($child === '' || $parent === '' || $childColumn === '') {
                    continue;
                }

                $relationships[] = [
                    'constraint_name' => isset($row->constraint_name) ? (string) $row->constraint_name : null,
                    'child_table' => $child,
                    'child_column' => $childColumn,
                    'parent_table' => $parent,
                    'parent_column' => $parentColumn,
                ];
            }
        } catch (Throwable $e) {
            return [];
        }

        return $relationships;
    }

    private function isColumnNullable(string $table, string $column): ?bool
    {
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::table('information_schema.COLUMNS')
                    ->select('IS_NULLABLE')
                    ->where('TABLE_SCHEMA', $database)
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->first();

                if ($row === null) {
                    return null;
                }

                return strtoupper((string) ($row->IS_NULLABLE ?? '')) === 'YES';
            }

            if ($driver === 'pgsql') {
                $row = DB::table('information_schema.columns')
                    ->select('is_nullable')
                    ->where('table_schema', DB::raw('current_schema()'))
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->first();

                if ($row === null) {
                    return null;
                }

                return strtoupper((string) ($row->is_nullable ?? '')) === 'YES';
            }

            if ($driver === 'sqlite') {
                $quoted = str_replace("'", "''", $table);
                foreach (DB::select("PRAGMA table_info('{$quoted}')") as $row) {
                    if (strtolower((string) ($row->name ?? '')) !== strtolower($column)) {
                        continue;
                    }

                    return ((int) ($row->notnull ?? 0)) === 0;
                }

                return null;
            }

            if (in_array($driver, ['sqlsrv', 'dblib'], true)) {
                $row = DB::table('INFORMATION_SCHEMA.COLUMNS')
                    ->select('IS_NULLABLE')
                    ->where('TABLE_CATALOG', $database)
                    ->where('TABLE_NAME', $table)
                    ->where('COLUMN_NAME', $column)
                    ->first();

                if ($row === null) {
                    return null;
                }

                return strtoupper((string) ($row->IS_NULLABLE ?? '')) === 'YES';
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,mixed>
     */
    private function counterResetPreview(array $actions): array
    {
        $items = [];
        $warnings = [];

        foreach ($actions as $table => $action) {
            if ($action !== 'reset_counter') {
                continue;
            }

            $columns = [];
            try {
                $columns = Schema::getColumnListing($table);
            } catch (Throwable $e) {
                $warnings[] = [
                    'table' => $table,
                    'reason' => 'Unable to inspect counter columns safely: '.$e->getMessage(),
                ];
                continue;
            }

            $columns = array_map('strtolower', $columns);
            $resetColumns = array_values(array_intersect(
                $columns,
                ['next_number', 'next_value', 'current_number', 'current_value', 'last_number', 'last_value', 'sequence', 'counter']
            ));

            if ($resetColumns === []) {
                $warnings[] = [
                    'table' => $table,
                    'reason' => 'No recognized numbering column found. Counter reset requires explicit review.',
                ];
                continue;
            }

            $items[] = [
                'table' => $table,
                'columns' => $resetColumns,
                'preview' => 'Reset recognized numbering columns to fresh-production values after transactional clear.',
            ];
        }

        return [
            'items' => $items,
            'warnings' => $warnings,
            'ready' => empty($warnings),
        ];
    }

    /** @return array<int,string> */
    private function tableNames(): array
    {
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $rows = DB::select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']);
                $tables = [];
                foreach ($rows as $row) {
                    $values = array_values((array) $row);
                    if (isset($values[0])) {
                        $tables[] = (string) $values[0];
                    }
                }
            } elseif ($driver === 'sqlite') {
                $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $tables = array_map(static fn ($row): string => (string) $row->name, $rows);
            } elseif ($driver === 'pgsql') {
                $rows = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema()");
                $tables = array_map(static fn ($row): string => (string) $row->tablename, $rows);
            } elseif (in_array($driver, ['sqlsrv', 'dblib'], true)) {
                $rows = DB::select("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE'");
                $tables = array_map(static fn ($row): string => (string) $row->TABLE_NAME, $rows);
            } else {
                throw new RuntimeException('Unsupported database driver for Day-Zero planning: '.$driver);
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to enumerate Day-Zero database tables: '.$e->getMessage(), 0, $e);
        }

        $tables = array_values(array_unique(array_filter(array_map(
            static fn (string $table): string => strtolower(trim($table)),
            $tables
        ))));
        sort($tables);

        return $tables;
    }

    /** @param resource $handle */
    private function gzWrite($handle, string $text): void
    {
        if (gzwrite($handle, $text) === false) {
            throw new RuntimeException('Unable to write Day-Zero backup data.');
        }
    }

    private function userId(mixed $user): mixed
    {
        if (is_object($user)) {
            return $user->id ?? null;
        }

        return null;
    }

    private function userName(mixed $user): string
    {
        if (is_object($user)) {
            return (string) ($user->name ?? $user->email ?? 'unknown');
        }

        return 'unknown';
    }
}
