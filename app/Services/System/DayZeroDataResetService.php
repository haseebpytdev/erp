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
 * IMPORTANT: this checkpoint resolves the live ERP-11.3.244 execution-safety
 * blockers without enabling destructive execution. It reclassifies
 * service_cost_allocations as transactional CLEAR data, previews schema-proven
 * nullable FK neutralization for preserved masters, exposes exact cycle edges,
 * and recalculates the effective child-before-parent delete order.
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

        // Transactional allocation rows belong to UAT/business transactions.
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
     * Preserved master columns that may be neutralized before their CLEAR
     * parent is removed, but only when the live schema proves the FK nullable.
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
            'raw_fk_blockers' => count($dependency['raw_blockers']),
            'raw_fk_blocker_items' => $dependency['raw_blockers'],
            'fk_blockers' => count($dependency['blockers']),
            'fk_blocker_items' => $dependency['blockers'],
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

    /**
     * @param array<string,string> $actions
     * @return array<string,mixed>
     */
    private function dependencyAudit(array $actions): array
    {
        try {
            $relationships = $this->foreignKeyRelationships();
        } catch (Throwable $e) {
            return [
                'relationships' => [],
                'raw_blockers' => [],
                'blockers' => [],
                'neutralizations' => [],
                'neutralization_warnings' => [],
                'cycle_tables' => [],
                'cycle_edges' => [],
                'warnings' => [
                    'Unable to inspect foreign-key dependencies safely: '.$e->getMessage(),
                ],
                'delete_order' => [],
                'ready' => false,
            ];
        }

        $rawBlockers = [];
        $blockers = [];
        $neutralizations = [];
        $neutralizationWarnings = [];
        $clearTables = [];

        foreach ($actions as $table => $action) {
            if ($action === 'clear') {
                $clearTables[] = $table;
            }
        }

        sort($clearTables);

        $candidateMap = [];
        foreach ($this->nullableNeutralizationCandidates as $candidate) {
            $candidateMap[
                $candidate['table']."\0".$candidate['column']."\0".$candidate['parent_table']
            ] = true;
        }

        foreach ($relationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];
            $childAction = $actions[$child] ?? 'review';
            $parentAction = $actions[$parent] ?? 'review';

            if ($parentAction !== 'clear' || $childAction === 'clear') {
                continue;
            }

            $raw = $relationship + [
                'child_action' => $childAction,
                'parent_action' => $parentAction,
                'reason' => strtoupper($childAction).' table references a CLEAR parent table.',
            ];
            $rawBlockers[] = $raw;

            $candidateKey = $child."\0".$relationship['child_column']."\0".$parent;

            if (! isset($candidateMap[$candidateKey])) {
                $blockers[] = $raw;
                continue;
            }

            $nullable = $this->isColumnNullable($child, $relationship['child_column']);
            $neutralization = $raw + [
                'nullable' => $nullable,
                'status' => $nullable === true ? 'pass' : 'blocked',
                'operation' => $nullable === true
                    ? $child.'.'.$relationship['child_column'].' = NULL before clearing '.$parent
                    : 'No safe NULL neutralization available.',
            ];
            $neutralizations[] = $neutralization;

            if ($nullable !== true) {
                $blockers[] = $raw;
                $neutralizationWarnings[] = [
                    'table' => $child,
                    'column' => $relationship['child_column'],
                    'reason' => $nullable === false
                        ? 'Neutralization candidate is NOT NULL; cannot safely clear parent.'
                        : 'Unable to determine column nullability safely.',
                ];
            }
        }

        $cycleAudit = $this->cycleEdgeAudit($clearTables, $relationships, $actions);
        $breakableCycleKeys = [];

        foreach ($cycleAudit['edges'] as $edge) {
            if (($edge['can_break_with_null'] ?? false) === true) {
                $breakableCycleKeys[$this->relationshipKey($edge)] = true;
            }
        }

        $effectiveEdges = [];

        foreach ($relationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];

            if (($actions[$child] ?? null) !== 'clear' || ($actions[$parent] ?? null) !== 'clear') {
                continue;
            }

            if (isset($breakableCycleKeys[$this->relationshipKey($relationship)])) {
                continue;
            }

            $effectiveEdges[] = [$child, $parent];
        }

        $deleteOrder = $this->topologicalDeleteOrder($clearTables, $effectiveEdges);
        $cycleTables = array_values(array_diff($clearTables, $deleteOrder));
        sort($cycleTables);

        $dependencyWarnings = [];
        if ($cycleTables !== []) {
            $dependencyWarnings[] = 'Dependency cycle remains after applying only schema-proven nullable preview resolutions: '.implode(', ', $cycleTables).'.';
        }

        return [
            'relationships' => $relationships,
            'raw_blockers' => $rawBlockers,
            'blockers' => $blockers,
            'neutralizations' => $neutralizations,
            'neutralization_warnings' => $neutralizationWarnings,
            'cycle_tables' => $cycleTables,
            'cycle_edges' => $cycleAudit['edges'],
            'warnings' => $dependencyWarnings,
            'delete_order' => $deleteOrder,
            'ready' => (
                empty($blockers)
                && empty($neutralizationWarnings)
                && empty($cycleTables)
                && count($deleteOrder) === count($clearTables)
            ),
        ];
    }

    /**
     * Return only FK edges that are actually inside a cyclic strongly-connected
     * component. This avoids labelling unrelated residual edges as cycle edges.
     *
     * @param array<int,string> $clearTables
     * @param array<int,array<string,string>> $relationships
     * @param array<string,string> $actions
     * @return array{edges:array<int,array<string,mixed>>}
     */
    private function cycleEdgeAudit(array $clearTables, array $relationships, array $actions): array
    {
        $adjacency = [];
        foreach ($clearTables as $table) {
            $adjacency[$table] = [];
        }

        foreach ($relationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];

            if (($actions[$child] ?? null) !== 'clear' || ($actions[$parent] ?? null) !== 'clear') {
                continue;
            }

            if (isset($adjacency[$child], $adjacency[$parent])) {
                $adjacency[$child][$parent] = true;
            }
        }

        $index = 0;
        $indices = [];
        $lowLink = [];
        $stack = [];
        $onStack = [];
        $components = [];

        $strongConnect = function (string $node) use (&$strongConnect, &$index, &$indices, &$lowLink, &$stack, &$onStack, &$components, $adjacency): void {
            $indices[$node] = $index;
            $lowLink[$node] = $index;
            $index++;
            $stack[] = $node;
            $onStack[$node] = true;

            foreach (array_keys($adjacency[$node] ?? []) as $next) {
                if (! array_key_exists($next, $indices)) {
                    $strongConnect($next);
                    $lowLink[$node] = min($lowLink[$node], $lowLink[$next]);
                } elseif (! empty($onStack[$next])) {
                    $lowLink[$node] = min($lowLink[$node], $indices[$next]);
                }
            }

            if ($lowLink[$node] !== $indices[$node]) {
                return;
            }

            $component = [];
            do {
                $member = array_pop($stack);
                if ($member === null) {
                    break;
                }
                $onStack[$member] = false;
                $component[] = $member;
            } while ($member !== $node);

            sort($component);
            $components[] = $component;
        };

        foreach ($clearTables as $table) {
            if (! array_key_exists($table, $indices)) {
                $strongConnect($table);
            }
        }

        $componentId = [];
        $cyclicComponentIds = [];

        foreach ($components as $id => $component) {
            foreach ($component as $table) {
                $componentId[$table] = $id;
            }

            if (count($component) > 1) {
                $cyclicComponentIds[$id] = true;
                continue;
            }

            $only = $component[0] ?? null;
            if ($only !== null && isset($adjacency[$only][$only])) {
                $cyclicComponentIds[$id] = true;
            }
        }

        $edges = [];

        foreach ($relationships as $relationship) {
            $child = $relationship['child_table'];
            $parent = $relationship['parent_table'];

            if (($actions[$child] ?? null) !== 'clear' || ($actions[$parent] ?? null) !== 'clear') {
                continue;
            }

            $childComponent = $componentId[$child] ?? null;
            $parentComponent = $componentId[$parent] ?? null;

            if ($childComponent === null || $childComponent !== $parentComponent || ! isset($cyclicComponentIds[$childComponent])) {
                continue;
            }

            $nullable = $this->isColumnNullable($child, $relationship['child_column']);
            $edges[] = $relationship + [
                'nullable' => $nullable,
                'can_break_with_null' => $nullable === true,
                'operation' => $nullable === true
                    ? $child.'.'.$relationship['child_column'].' = NULL before delete ordering'
                    : 'Cycle edge cannot be safely neutralized by NULL.',
            ];
        }

        return ['edges' => $edges];
    }

    /** @param array<string,mixed> $relationship */
    private function relationshipKey(array $relationship): string
    {
        return implode("\0", [
            (string) ($relationship['child_table'] ?? ''),
            (string) ($relationship['child_column'] ?? ''),
            (string) ($relationship['parent_table'] ?? ''),
            (string) ($relationship['parent_column'] ?? ''),
            (string) ($relationship['constraint'] ?? ''),
        ]);
    }

    private function isColumnNullable(string $table, string $column): ?bool
    {
        $connection = DB::connection();
        $driver = strtolower((string) $connection->getDriverName());

        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $row = DB::selectOne(
                    'SELECT IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );

                if ($row === null) {
                    return null;
                }

                return strtoupper((string) (((array) $row)['is_nullable'] ?? ((array) $row)['IS_NULLABLE'] ?? '')) === 'YES';
            }

            if ($driver === 'pgsql') {
                $row = DB::selectOne(
                    'SELECT is_nullable FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                    [$table, $column]
                );

                if ($row === null) {
                    return null;
                }

                return strtoupper((string) (((array) $row)['is_nullable'] ?? '')) === 'YES';
            }

            if ($driver === 'sqlsrv') {
                $row = DB::selectOne(
                    'SELECT IS_NULLABLE AS is_nullable FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$table, $column]
                );

                if ($row === null) {
                    return null;
                }

                return strtoupper((string) (((array) $row)['is_nullable'] ?? ((array) $row)['IS_NULLABLE'] ?? '')) === 'YES';
            }

            if ($driver === 'sqlite') {
                $quoted = '"'.str_replace('"', '""', $table).'"';
                foreach (DB::select('PRAGMA table_info('.$quoted.')') as $row) {
                    $array = (array) $row;
                    if (strcasecmp((string) ($array['name'] ?? ''), $column) !== 0) {
                        continue;
                    }

                    return ((int) ($array['notnull'] ?? 0)) === 0;
                }

                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param array<int,string> $clearTables
     * @param array<int,array{0:string,1:string}> $edges
     * @return array<int,string>
     */
    private function topologicalDeleteOrder(array $clearTables, array $edges): array
    {
        $adjacency = [];
        $indegree = [];

        foreach ($clearTables as $table) {
            $adjacency[$table] = [];
            $indegree[$table] = 0;
        }

        $seen = [];

        foreach ($edges as [$child, $parent]) {
            if (! isset($adjacency[$child], $adjacency[$parent])) {
                continue;
            }

            $key = $child."\0".$parent;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $adjacency[$child][$parent] = true;
            $indegree[$parent]++;
        }

        $queue = [];

        foreach ($indegree as $table => $count) {
            if ($count === 0) {
                $queue[] = $table;
            }
        }

        sort($queue);
        $order = [];

        while ($queue !== []) {
            $table = array_shift($queue);
            $order[] = $table;

            $parents = array_keys($adjacency[$table]);
            sort($parents);

            foreach ($parents as $parent) {
                $indegree[$parent]--;

                if ($indegree[$parent] === 0) {
                    $queue[] = $parent;
                    sort($queue);
                }
            }
        }

        return $order;
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

            try {
                $columns = Schema::getColumnListing($table);
                $updates = [];

                foreach ([
                    'current_value',
                    'last_number',
                    'last_value',
                    'counter',
                    'sequence_value',
                    'current_number',
                    'last_number_used',
                ] as $column) {
                    if (in_array($column, $columns, true)) {
                        $updates[$column] = 0;
                    }
                }

                foreach ([
                    'next_number',
                    'next_value',
                    'next_sequence',
                    'next_no',
                ] as $column) {
                    if (in_array($column, $columns, true)) {
                        $updates[$column] = 1;
                    }
                }

                $rowCount = (int) DB::table($table)->count();

                if ($updates === []) {
                    $warnings[] = 'Counter table '.$table.' has no recognized restart column.';
                }

                $items[] = [
                    'table' => $table,
                    'rows' => $rowCount,
                    'columns' => $columns,
                    'proposed_updates' => $updates,
                    'ready' => $updates !== [],
                ];
            } catch (Throwable $e) {
                $warnings[] = 'Unable to preview counter reset for '.$table.': '.$e->getMessage();

                $items[] = [
                    'table' => $table,
                    'rows' => null,
                    'columns' => [],
                    'proposed_updates' => [],
                    'ready' => false,
                ];
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcmp($a['table'], $b['table'])
        );

        return [
            'items' => $items,
            'warnings' => $warnings,
            'ready' => empty($warnings),
        ];
    }

    /** @return array<int,array<string,string>> */
    private function foreignKeyRelationships(): array
    {
        $connection = DB::connection();
        $driver = strtolower((string) $connection->getDriverName());
        $relationships = [];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $rows = DB::select(
                "SELECT CONSTRAINT_NAME AS constraint_name,
                        TABLE_NAME AS child_table,
                        COLUMN_NAME AS child_column,
                        REFERENCED_TABLE_NAME AS parent_table,
                        REFERENCED_COLUMN_NAME AS parent_column
                   FROM information_schema.KEY_COLUMN_USAGE
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND REFERENCED_TABLE_NAME IS NOT NULL
               ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION"
            );

            foreach ($rows as $row) {
                $relationships[] = $this->normalizeForeignKeyRow((array) $row);
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT tc.constraint_name AS constraint_name,
                        kcu.table_name AS child_table,
                        kcu.column_name AS child_column,
                        ccu.table_name AS parent_table,
                        ccu.column_name AS parent_column
                   FROM information_schema.table_constraints tc
                   JOIN information_schema.key_column_usage kcu
                     ON tc.constraint_name = kcu.constraint_name
                    AND tc.table_schema = kcu.table_schema
                   JOIN information_schema.constraint_column_usage ccu
                     ON ccu.constraint_name = tc.constraint_name
                    AND ccu.table_schema = tc.table_schema
                  WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_schema = current_schema()
               ORDER BY kcu.table_name, tc.constraint_name, kcu.ordinal_position"
            );

            foreach ($rows as $row) {
                $relationships[] = $this->normalizeForeignKeyRow((array) $row);
            }
        } elseif ($driver === 'sqlsrv') {
            $rows = DB::select(
                "SELECT fk.name AS constraint_name,
                        OBJECT_NAME(fkc.parent_object_id) AS child_table,
                        COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS child_column,
                        OBJECT_NAME(fkc.referenced_object_id) AS parent_table,
                        COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS parent_column
                   FROM sys.foreign_key_columns fkc
                   JOIN sys.foreign_keys fk
                     ON fk.object_id = fkc.constraint_object_id
               ORDER BY child_table, constraint_name, fkc.constraint_column_id"
            );

            foreach ($rows as $row) {
                $relationships[] = $this->normalizeForeignKeyRow((array) $row);
            }
        } elseif ($driver === 'sqlite') {
            foreach ($this->tableNames() as $table) {
                $quoted = '"'.str_replace('"', '""', $table).'"';
                $rows = DB::select('PRAGMA foreign_key_list('.$quoted.')');

                foreach ($rows as $row) {
                    $array = (array) $row;

                    $relationships[] = [
                        'constraint' => 'sqlite_fk_'.(string) ($array['id'] ?? ''),
                        'child_table' => $table,
                        'child_column' => (string) ($array['from'] ?? ''),
                        'parent_table' => (string) ($array['table'] ?? ''),
                        'parent_column' => (string) ($array['to'] ?? ''),
                    ];
                }
            }
        } else {
            throw new RuntimeException('Unsupported database driver for Day-Zero dependency audit: '.$driver);
        }

        $relationships = array_values(
            array_filter(
                $relationships,
                static fn (array $relationship): bool =>
                    $relationship['child_table'] !== ''
                    && $relationship['parent_table'] !== ''
            )
        );

        usort(
            $relationships,
            static function (array $a, array $b): int {
                return strcmp(
                    implode("\0", [
                        $a['child_table'],
                        $a['constraint'],
                        $a['child_column'],
                        $a['parent_table'],
                        $a['parent_column'],
                    ]),
                    implode("\0", [
                        $b['child_table'],
                        $b['constraint'],
                        $b['child_column'],
                        $b['parent_table'],
                        $b['parent_column'],
                    ])
                );
            }
        );

        return $relationships;
    }

    /** @param array<string,mixed> $row */
    private function normalizeForeignKeyRow(array $row): array
    {
        return [
            'constraint' => trim((string) (
                $row['constraint_name']
                ?? $row['CONSTRAINT_NAME']
                ?? ''
            )),
            'child_table' => trim((string) (
                $row['child_table']
                ?? $row['CHILD_TABLE']
                ?? ''
            )),
            'child_column' => trim((string) (
                $row['child_column']
                ?? $row['CHILD_COLUMN']
                ?? ''
            )),
            'parent_table' => trim((string) (
                $row['parent_table']
                ?? $row['PARENT_TABLE']
                ?? ''
            )),
            'parent_column' => trim((string) (
                $row['parent_column']
                ?? $row['PARENT_COLUMN']
                ?? ''
            )),
        ];
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
