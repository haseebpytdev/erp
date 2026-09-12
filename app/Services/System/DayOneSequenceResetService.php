<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-11.3.247 functional checkpoint.
 *
 * One-time post-Day-Zero numbering finalization. This service never deletes
 * business rows. It is allowed to run only after the Day-Zero completion
 * marker exists and only while every genuine business Day-Zero CLEAR table
 * is still empty. Runtime/security telemetry may repopulate after Day-Zero
 * and is explicitly excluded from the business-data and identity-reset gates.
 *
 * It normalizes two numbering authorities:
 *  - native document/reference counter rows already classified by the
 *    Day-Zero planner; and
 *  - database identity / auto-increment state on emptied CLEAR tables so
 *    ID-derived booking, voucher, invoice and posting references restart at 1000.
 */
final class DayOneSequenceResetService
{
    public const CONFIRMATION = 'RESET DAY ONE SEQUENCES';
    public const FIRST_NUMBER = 1000;
    public const LAST_USED_BASELINE = 999;
    public const DISPLAY_PADDING = 4;

    /** @var array<int,string> */
    public const RUNTIME_TELEMETRY_TABLES = [
        'audit_logs',
        'login_events',
    ];

    public function __construct(
        private readonly DayZeroDataResetService $planner
    ) {
    }

    /** @return array<string,mixed> */
    public function plan(): array
    {
        $dayZeroCompleted = $this->planner->completedRecord();
        $completed = $this->completedRecord();
        $blockers = [];
        $warnings = [];
        $identityTargets = [];

        if (! $dayZeroCompleted) {
            $blockers[] = 'Day-Zero completion marker is missing.';
        }

        if ($completed) {
            $blockers[] = 'Day-One sequence normalization is already completed and permanently locked.';
        }

        $dayZeroPlan = $this->planner->plan();
        $emptyBusinessClearTables = 0;
        $businessClearTables = [];
        $runtimeTelemetry = array_map(
            static fn (string $table): array => [
                'table' => $table,
                'rows' => null,
                'present' => false,
                'excluded_from_business_gate' => true,
                'identity_reseeded' => false,
            ],
            self::RUNTIME_TELEMETRY_TABLES
        );

        foreach ((array) ($dayZeroPlan['items'] ?? []) as $item) {
            if (($item['action'] ?? null) !== 'clear') {
                continue;
            }

            $table = (string) ($item['table'] ?? '');
            $rows = $item['rows'] ?? null;

            if (in_array($table, self::RUNTIME_TELEMETRY_TABLES, true)) {
                foreach ($runtimeTelemetry as &$telemetry) {
                    if ($telemetry['table'] === $table) {
                        $telemetry['rows'] = $rows;
                        $telemetry['present'] = true;
                        break;
                    }
                }
                unset($telemetry);
                continue;
            }

            $businessClearTables[] = $table;

            if ($rows === null) {
                $blockers[] = 'Unable to prove CLEAR table is empty: '.$table.'.';
                continue;
            }

            if ((int) $rows !== 0) {
                $blockers[] = 'CLEAR table now contains production rows and sequence reset is blocked: '.$table.' ('.(int) $rows.' row(s)).';
                continue;
            }

            $emptyBusinessClearTables++;
        }

        if ((int) ($dayZeroPlan['review_tables'] ?? -1) !== 0) {
            $blockers[] = 'Day-Zero planner contains REVIEW tables.';
        }

        if (! empty($dayZeroPlan['warnings'])) {
            $blockers[] = 'Day-Zero planner contains schema/count warnings.';
        }

        if (($dayZeroPlan['counter_reset_ready'] ?? false) !== true) {
            $blockers[] = 'Native counter reset preview is not ready.';
        }

        if ($businessClearTables !== []) {
            try {
                $identityTargets = $this->identityTargets($businessClearTables);
            } catch (Throwable $e) {
                $warnings[] = 'Unable to inspect identity/auto-increment targets safely: '.$e->getMessage();
            }
        }

        if ($identityTargets === [] && $businessClearTables !== []) {
            $warnings[] = 'No identity/auto-increment targets were discovered for business CLEAR tables.';
        }

        $paddingAuthorityReady = false;
        try {
            if (! Schema::hasTable('number_sequences')) {
                $blockers[] = 'Required Day-One padding authority table is missing: number_sequences.';
            } elseif (! Schema::hasColumn('number_sequences', 'padding')) {
                $blockers[] = 'Required Day-One padding authority column is missing: number_sequences.padding.';
            } else {
                $paddingAuthorityReady = true;
            }
        } catch (Throwable $e) {
            $blockers[] = 'Unable to prove required Day-One padding authority: '.$e->getMessage();
        }

        $counterPreview = $this->dayOneCounterPreview(
            array_values((array) ($dayZeroPlan['counter_reset_preview'] ?? [])),
            $paddingAuthorityReady
        );

        $paddingPreviewReady = false;
        foreach ($counterPreview as $item) {
            if (($item['ready'] ?? false) !== true || empty($item['proposed_updates'])) {
                $blockers[] = 'Counter reset target is not ready: '.(string) ($item['table'] ?? 'unknown').'.';
            }

            if (
                ($item['table'] ?? null) === 'number_sequences'
                && (($item['proposed_updates']['padding'] ?? null) === self::DISPLAY_PADDING)
                && (($item['ready'] ?? false) === true)
            ) {
                $paddingPreviewReady = true;
            }
        }

        if (! $paddingPreviewReady) {
            $blockers[] = 'Required Day-One padding update is not ready: number_sequences.padding=4.';
        }

        $ready = (
            $dayZeroCompleted !== null
            && $completed === null
            && $blockers === []
            && $warnings === []
            && count($businessClearTables) === $emptyBusinessClearTables
            && ($dayZeroPlan['counter_reset_ready'] ?? false) === true
        );

        return [
            'confirmation' => self::CONFIRMATION,
            'day_zero_completed' => $dayZeroCompleted,
            'completed' => $completed,
            'clear_tables' => count($businessClearTables),
            'empty_clear_tables' => $emptyBusinessClearTables,
            'business_clear_tables' => count($businessClearTables),
            'empty_business_clear_tables' => $emptyBusinessClearTables,
            'runtime_telemetry' => $runtimeTelemetry,
            'runtime_telemetry_tables' => self::RUNTIME_TELEMETRY_TABLES,
            'identity_targets' => $identityTargets,
            'identity_target_count' => count($identityTargets),
            'counter_preview' => $counterPreview,
            'counter_tables' => count($counterPreview),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'ready' => $ready,
        ];
    }

    /** @return array<string,mixed> */
    public function execute(mixed $user): array
    {
        if ($this->completedRecord()) {
            throw new RuntimeException('Day-One sequence normalization is already completed and permanently locked.');
        }

        $plan = $this->plan();

        if (($plan['ready'] ?? false) !== true) {
            throw new RuntimeException(
                'Day-One sequence normalization is blocked: '
                .implode(' ', array_merge((array) ($plan['blockers'] ?? []), (array) ($plan['warnings'] ?? [])))
            );
        }

        $counterRowsUpdated = $this->resetCounters((array) $plan['counter_preview']);
        $identitiesReset = $this->resetIdentities((array) $plan['identity_targets']);

        $this->verifyCounters((array) $plan['counter_preview']);
        $this->verifyIdentities((array) $plan['identity_targets']);

        $record = [
            'release' => config('et_erp_release.release', 'ERP'),
            'completed_at' => now()->toIso8601String(),
            'actor_id' => $this->userId($user),
            'actor_name' => $this->userName($user),
            'clear_tables_verified_empty' => (int) ($plan['empty_clear_tables'] ?? 0),
            'business_clear_tables_verified_empty' => (int) ($plan['empty_business_clear_tables'] ?? 0),
            'runtime_telemetry_tables_excluded' => (array) ($plan['runtime_telemetry'] ?? []),
            'first_number' => self::FIRST_NUMBER,
            'display_padding' => self::DISPLAY_PADDING,
            'identity_targets_reset' => $identitiesReset,
            'counter_rows_updated' => $counterRowsUpdated,
            'confirmation' => self::CONFIRMATION,
        ];

        $this->writeCompletionMarker($record);

        return $record;
    }

    public function completedRecord(): ?array
    {
        $path = $this->completionMarkerPath();

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

    /**
     * @param array<int,string> $tables
     * @return array<int,array<string,mixed>>
     */
    private function identityTargets(array $tables): array
    {
        $driver = strtolower((string) DB::connection()->getDriverName());
        $targets = [];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ($tables as $table) {
                $rows = DB::select(
                    "SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA LIKE '%auto_increment%'",
                    [$table]
                );

                foreach ($rows as $row) {
                    $column = (string) (((array) $row)['column_name'] ?? ((array) $row)['COLUMN_NAME'] ?? '');
                    if ($column !== '') {
                        $targets[] = ['table' => $table, 'column' => $column, 'driver' => $driver, 'sequence' => null];
                    }
                }
            }

            return $targets;
        }

        if ($driver === 'pgsql') {
            foreach ($tables as $table) {
                $columns = DB::select(
                    "SELECT column_name, is_identity, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND (is_identity = 'YES' OR column_default LIKE 'nextval(%')",
                    [$table]
                );

                foreach ($columns as $row) {
                    $column = (string) (((array) $row)['column_name'] ?? '');
                    if ($column === '') {
                        continue;
                    }
                    $sequenceRow = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS sequence_name', [$table, $column]);
                    $sequence = (string) (((array) ($sequenceRow ?? []))['sequence_name'] ?? '');
                    if ($sequence === '') {
                        throw new RuntimeException('Unable to resolve PostgreSQL sequence for '.$table.'.'.$column.'.');
                    }
                    $targets[] = ['table' => $table, 'column' => $column, 'driver' => $driver, 'sequence' => $sequence];
                }
            }

            return $targets;
        }

        if ($driver === 'sqlsrv') {
            foreach ($tables as $table) {
                $rows = DB::select(
                    'SELECT c.name AS column_name FROM sys.identity_columns c INNER JOIN sys.tables t ON c.object_id = t.object_id WHERE t.name = ?',
                    [$table]
                );
                foreach ($rows as $row) {
                    $column = (string) (((array) $row)['column_name'] ?? '');
                    if ($column !== '') {
                        $targets[] = ['table' => $table, 'column' => $column, 'driver' => $driver, 'sequence' => null];
                    }
                }
            }

            return $targets;
        }

        if ($driver === 'sqlite') {
            $hasSequence = DB::selectOne("SELECT name FROM sqlite_master WHERE type='table' AND name='sqlite_sequence'") !== null;
            if (! $hasSequence) {
                return [];
            }
            foreach ($tables as $table) {
                $row = DB::selectOne('SELECT name, seq FROM sqlite_sequence WHERE name = ?', [$table]);
                if ($row !== null) {
                    $targets[] = ['table' => $table, 'column' => 'rowid', 'driver' => $driver, 'sequence' => 'sqlite_sequence'];
                }
            }

            return $targets;
        }

        throw new RuntimeException('Unsupported database driver for Day-One identity reset: '.$driver.'.');
    }

    /**
     * Day-Zero expresses restart semantics as:
     * - last/current value = 0
     * - next value = 1
     *
     * Production Day-One starts at plain 1000 instead:
     * - last/current value = 999
     * - next value = 1000
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function dayOneCounterPreview(array $items, bool $paddingAuthorityReady): array
    {
        foreach ($items as &$item) {
            $table = (string) ($item['table'] ?? '');
            $source = (array) ($item['proposed_updates'] ?? []);
            $updates = [];
            $ready = (($item['ready'] ?? false) === true);

            foreach ($source as $column => $value) {
                $value = (int) $value;

                if ($value === 0) {
                    $updates[$column] = self::LAST_USED_BASELINE;
                    continue;
                }

                if ($value === 1) {
                    $updates[$column] = self::FIRST_NUMBER;
                    continue;
                }

                $updates[$column] = $value;
                $ready = false;
            }

            if ($table === 'number_sequences') {
                if (! $paddingAuthorityReady) {
                    $ready = false;
                } else {
                    $updates['padding'] = self::DISPLAY_PADDING;
                }
            }

            $item['proposed_updates'] = $updates;
            $item['ready'] = $ready && $updates !== [];
        }
        unset($item);

        return $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function resetCounters(array $items): int
    {
        $updated = 0;

        foreach ($items as $item) {
            if (($item['ready'] ?? false) !== true) {
                throw new RuntimeException('Counter reset target is not ready.');
            }

            $table = (string) ($item['table'] ?? '');
            $updates = (array) ($item['proposed_updates'] ?? []);
            $this->assertSafeIdentifier($table, 'counter table');

            if (! Schema::hasTable($table) || $updates === []) {
                throw new RuntimeException('Counter reset target is unavailable: '.$table.'.');
            }

            foreach ($updates as $column => $value) {
                $this->assertSafeIdentifier((string) $column, 'counter column');
                if (! Schema::hasColumn($table, (string) $column)) {
                    throw new RuntimeException('Counter column disappeared: '.$table.'.'.$column.'.');
                }
                $isDisplayPadding = (
                    $table === 'number_sequences'
                    && $column === 'padding'
                    && (int) $value === self::DISPLAY_PADDING
                );

                if (! $isDisplayPadding && ! in_array((int) $value, [self::LAST_USED_BASELINE, self::FIRST_NUMBER], true)) {
                    throw new RuntimeException('Unexpected Day-One counter baseline for '.$table.'.'.$column.'.');
                }
            }

            $updated += (int) DB::table($table)->update($updates);
        }

        return $updated;
    }

    /** @param array<int,array<string,mixed>> $targets */
    private function resetIdentities(array $targets): int
    {
        $count = 0;

        foreach ($targets as $target) {
            $table = (string) ($target['table'] ?? '');
            $driver = (string) ($target['driver'] ?? '');
            $this->assertSafeIdentifier($table, 'identity table');

            if ((int) DB::table($table)->count() !== 0) {
                throw new RuntimeException('Identity reset blocked because table is no longer empty: '.$table.'.');
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $quoted = '`'.str_replace('`', '``', $table).'`';
                DB::statement('ALTER TABLE '.$quoted.' AUTO_INCREMENT = '.self::FIRST_NUMBER);
                $count++;
                continue;
            }

            if ($driver === 'pgsql') {
                $sequence = (string) ($target['sequence'] ?? '');
                if ($sequence === '') {
                    throw new RuntimeException('PostgreSQL sequence target is missing for '.$table.'.');
                }
                DB::statement('SELECT setval(?::regclass, '.self::FIRST_NUMBER.', false)', [$sequence]);
                $count++;
                continue;
            }

            if ($driver === 'sqlsrv') {
                $quoted = '['.str_replace(']', ']]', $table).']';
                DB::statement('DBCC CHECKIDENT ('.$quoted.', RESEED, '.self::LAST_USED_BASELINE.')');
                $count++;
                continue;
            }

            if ($driver === 'sqlite') {
                DB::table('sqlite_sequence')->where('name', $table)->update(['seq' => self::LAST_USED_BASELINE]);
                $count++;
                continue;
            }

            throw new RuntimeException('Unsupported identity reset driver: '.$driver.'.');
        }

        return $count;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function verifyCounters(array $items): void
    {
        foreach ($items as $item) {
            $table = (string) ($item['table'] ?? '');
            $updates = (array) ($item['proposed_updates'] ?? []);

            foreach (DB::table($table)->get(array_keys($updates)) as $row) {
                $values = (array) $row;
                foreach ($updates as $column => $expected) {
                    if (! array_key_exists($column, $values) || (string) $values[$column] !== (string) $expected) {
                        throw new RuntimeException('Day-One counter verification failed: '.$table.'.'.$column.'.');
                    }
                }
            }
        }
    }

    /** @param array<int,array<string,mixed>> $targets */
    private function verifyIdentities(array $targets): void
    {
        foreach ($targets as $target) {
            $table = (string) ($target['table'] ?? '');
            $driver = (string) ($target['driver'] ?? '');

            if ((int) DB::table($table)->count() !== 0) {
                throw new RuntimeException('Post-reset identity verification found rows in '.$table.'.');
            }

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne(
                    'SELECT AUTO_INCREMENT AS next_value FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                    [$table]
                );
                $next = (int) (((array) ($row ?? []))['next_value'] ?? ((array) ($row ?? []))['AUTO_INCREMENT'] ?? 0);
                if ($next !== self::FIRST_NUMBER) {
                    throw new RuntimeException('MySQL AUTO_INCREMENT verification failed for '.$table.'; expected next value '.self::FIRST_NUMBER.', got '.$next.'.');
                }
                continue;
            }

            if ($driver === 'sqlite') {
                $row = DB::selectOne('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]);
                $seq = $row === null ? 0 : (int) (((array) $row)['seq'] ?? -1);
                if ($seq !== self::LAST_USED_BASELINE) {
                    throw new RuntimeException('SQLite sequence verification failed for '.$table.'.');
                }
                continue;
            }

            // PostgreSQL setval(..., 1000, false) and SQL Server RESEED 999 are
            // explicit next-insert=1000 operations; the mutation itself is the
            // authoritative verification for those drivers.
        }
    }

    /** @param array<string,mixed> $record */
    private function writeCompletionMarker(array $record): void
    {
        $path = $this->completionMarkerPath();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Day-One marker directory.');
        }

        if (is_file($path)) {
            throw new RuntimeException('Day-One sequence completion marker already exists.');
        }

        $temp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary Day-One completion marker.');
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Unable to publish Day-One completion marker.');
        }
    }

    private function completionMarkerPath(): string
    {
        return storage_path('app/system/day-one-sequence-reset-completed.json');
    }

    private function assertSafeIdentifier(string $value, string $label): void
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException('Unsafe Day-One '.$label.' identifier: '.$value.'.');
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
