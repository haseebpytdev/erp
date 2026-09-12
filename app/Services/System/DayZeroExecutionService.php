<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-11.3.246 Day-Zero execution authority.
 *
 * This service is deliberately separate from the read-only planner so the
 * destructive boundary is explicit and reviewable. It may run once only, and
 * only after the live planner, fresh-backup validation and all dependency gates
 * pass again immediately before mutation.
 */
class DayZeroExecutionService
{
    public const EXECUTION_ENABLED = true;

    public function __construct(
        private readonly DayZeroDataResetService $planner
    ) {
    }

    public function execute(
        mixed $user,
        string $backupPath,
        mixed $backupCreatedAt
    ): array {
        if (! self::EXECUTION_ENABLED) {
            throw new RuntimeException('Day-Zero execution is disabled for this release.');
        }

        $this->planner->validateBackup($backupPath, $backupCreatedAt);

        if ($this->planner->completedRecord()) {
            throw new RuntimeException('Day-Zero reset is already completed and permanently locked.');
        }

        $plan = $this->planner->plan();
        $this->assertExecutablePlan($plan);

        $executionLock = $this->acquireExecutionLock($user, $backupPath);
        $committed = false;

        try {
            $result = DB::transaction(function () use ($plan): array {
                $preservedNeutralized = $this->applyNeutralizations(
                    $plan['neutralization_preview'] ?? [],
                    'preserved-master'
                );

                $cycleNeutralized = $this->applyCycleNeutralizations(
                    $plan['dependency_cycle_edges'] ?? []
                );

                $rowsDeleted = 0;
                $tablesCleared = 0;
                $deleteOrder = array_values($plan['delete_order'] ?? []);

                foreach ($deleteOrder as $table) {
                    $this->assertSafeIdentifier($table, 'table');

                    if (! Schema::hasTable($table)) {
                        throw new RuntimeException('Day-Zero CLEAR table disappeared before execution: '.$table);
                    }

                    $rowsDeleted += (int) DB::table($table)->delete();
                    $tablesCleared++;
                }

                $counterRowsUpdated = $this->resetCounters(
                    $plan['counter_reset_preview'] ?? []
                );

                $this->verifyClearedTables($deleteOrder);
                $this->verifyCounters($plan['counter_reset_preview'] ?? []);

                return [
                    'rows_deleted' => $rowsDeleted,
                    'tables_cleared' => $tablesCleared,
                    'preserved_fk_rows_neutralized' => $preservedNeutralized,
                    'cycle_fk_rows_neutralized' => $cycleNeutralized,
                    'counter_rows_updated' => $counterRowsUpdated,
                ];
            }, 1);

            $committed = true;

            try {
                $marker = $this->writeCompletionMarker(
                    $user,
                    $backupPath,
                    $plan,
                    $result
                );
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Day-Zero database changes committed, but the permanent completion marker could not be written. '
                    .'The execution lock has been retained; do not retry automatically. '.$e->getMessage(),
                    previous: $e
                );
            }

            $this->releaseExecutionLock($executionLock);

            return $result + [
                'completed_at' => $marker['completed_at'],
                'backup_sha256' => $marker['backup_sha256'],
            ];
        } catch (Throwable $e) {
            if (! $committed) {
                $this->releaseExecutionLock($executionLock);
            }

            throw $e;
        }
    }

    /** @param array<string,mixed> $plan */
    private function assertExecutablePlan(array $plan): void
    {
        if (! empty($plan['completed'])) {
            throw new RuntimeException('Day-Zero reset is already completed and permanently locked.');
        }

        $checks = [
            'review tables' => ((int) ($plan['review_tables'] ?? -1)) === 0,
            'schema/count warnings' => empty($plan['warnings']),
            'unresolved FK blockers' => ((int) ($plan['fk_blockers'] ?? -1)) === 0,
            'FK neutralization warnings' => empty($plan['neutralization_warnings']),
            'dependency cycles' => ((int) ($plan['dependency_cycles'] ?? -1)) === 0,
            'dependency warnings' => empty($plan['dependency_warnings']),
            'dependency plan' => ($plan['dependency_plan_ready'] ?? false) === true,
            'counter reset plan' => ($plan['counter_reset_ready'] ?? false) === true,
            'overall safety preview' => ($plan['safety_preview_ready'] ?? false) === true,
        ];

        foreach ($checks as $label => $passed) {
            if (! $passed) {
                throw new RuntimeException('Day-Zero execution blocked by live safety gate: '.$label.'.');
            }
        }

        $deleteOrder = array_values($plan['delete_order'] ?? []);
        $clearTables = (int) ($plan['clear_tables'] ?? -1);

        if ($clearTables < 0 || count($deleteOrder) !== $clearTables) {
            throw new RuntimeException('Day-Zero execution blocked: CLEAR-table delete order is incomplete.');
        }

        if (count(array_unique($deleteOrder)) !== count($deleteOrder)) {
            throw new RuntimeException('Day-Zero execution blocked: duplicate table found in delete order.');
        }

        $rawBlockers = (int) ($plan['raw_fk_blockers'] ?? 0);
        $neutralizations = array_values($plan['neutralization_preview'] ?? []);

        if (count($neutralizations) !== $rawBlockers) {
            throw new RuntimeException(
                'Day-Zero execution blocked: every raw preserved-to-CLEAR FK must have one approved neutralization.'
            );
        }

        foreach ($neutralizations as $item) {
            if (($item['nullable'] ?? null) !== true || ($item['status'] ?? null) !== 'pass') {
                throw new RuntimeException('Day-Zero execution blocked: unsafe preserved-master FK neutralization.');
            }
        }

        foreach (array_values($plan['dependency_cycle_edges'] ?? []) as $edge) {
            if (($edge['can_break_with_null'] ?? false) !== true || ($edge['nullable'] ?? null) !== true) {
                throw new RuntimeException('Day-Zero execution blocked: dependency-cycle edge is not safely nullable.');
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function applyNeutralizations(array $items, string $context): int
    {
        $updated = 0;

        foreach ($items as $item) {
            if (($item['nullable'] ?? null) !== true || ($item['status'] ?? null) !== 'pass') {
                throw new RuntimeException('Unsafe '.$context.' FK neutralization reached execution.');
            }

            $table = (string) ($item['child_table'] ?? '');
            $column = (string) ($item['child_column'] ?? '');

            $this->assertSafeIdentifier($table, 'table');
            $this->assertSafeIdentifier($column, 'column');

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                throw new RuntimeException('Neutralization target disappeared: '.$table.'.'.$column);
            }

            $updated += (int) DB::table($table)
                ->whereNotNull($column)
                ->update([$column => null]);
        }

        return $updated;
    }

    /** @param array<int,array<string,mixed>> $edges */
    private function applyCycleNeutralizations(array $edges): int
    {
        $items = [];

        foreach ($edges as $edge) {
            if (($edge['can_break_with_null'] ?? false) !== true || ($edge['nullable'] ?? null) !== true) {
                throw new RuntimeException('Unsafe dependency-cycle edge reached execution.');
            }

            $items[] = $edge + ['status' => 'pass'];
        }

        return $this->applyNeutralizations($items, 'cycle-break');
    }

    /** @param array<int,array<string,mixed>> $items */
    private function resetCounters(array $items): int
    {
        $updated = 0;

        foreach ($items as $item) {
            if (($item['ready'] ?? false) !== true) {
                throw new RuntimeException('Counter reset item is not ready for execution.');
            }

            $table = (string) ($item['table'] ?? '');
            $updates = (array) ($item['proposed_updates'] ?? []);

            $this->assertSafeIdentifier($table, 'counter table');

            if (! Schema::hasTable($table) || $updates === []) {
                throw new RuntimeException('Counter reset target is unavailable or has no restart values: '.$table);
            }

            foreach (array_keys($updates) as $column) {
                $this->assertSafeIdentifier((string) $column, 'counter column');

                if (! Schema::hasColumn($table, (string) $column)) {
                    throw new RuntimeException('Counter column disappeared: '.$table.'.'.$column);
                }
            }

            $updated += (int) DB::table($table)->update($updates);
        }

        return $updated;
    }

    /** @param array<int,string> $tables */
    private function verifyClearedTables(array $tables): void
    {
        foreach ($tables as $table) {
            $this->assertSafeIdentifier($table, 'table');

            if ((int) DB::table($table)->count() !== 0) {
                throw new RuntimeException('Post-reset verification failed; table is not empty: '.$table);
            }
        }
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
                        throw new RuntimeException(
                            'Post-reset counter verification failed: '.$table.'.'.$column.'.'
                        );
                    }
                }
            }
        }
    }

    private function acquireExecutionLock(mixed $user, string $backupPath): string
    {
        $directory = storage_path('app/system');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create Day-Zero system lock directory.');
        }

        $completed = $directory.'/day-zero-reset-completed.json';
        $lock = $directory.'/day-zero-reset-running.json';

        if (is_file($completed)) {
            throw new RuntimeException('Day-Zero reset is already completed and permanently locked.');
        }

        $handle = @fopen($lock, 'x');

        if ($handle === false) {
            throw new RuntimeException(
                'A Day-Zero execution lock already exists. Do not retry until the prior attempt is reviewed.'
            );
        }

        try {
            fwrite(
                $handle,
                json_encode([
                    'started_at' => now()->toIso8601String(),
                    'actor_id' => $this->userId($user),
                    'actor_name' => $this->userName($user),
                    'backup_file' => basename($backupPath),
                    'release' => config('et_erp_release.release', 'ERP'),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
        } finally {
            fclose($handle);
        }

        return $lock;
    }

    private function releaseExecutionLock(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function writeCompletionMarker(
        mixed $user,
        string $backupPath,
        array $plan,
        array $result
    ): array {
        $directory = storage_path('app/system');
        $path = $directory.'/day-zero-reset-completed.json';

        if (is_file($path)) {
            throw new RuntimeException('Day-Zero completion marker already exists.');
        }

        $backupHash = hash_file('sha256', $backupPath);

        if ($backupHash === false) {
            throw new RuntimeException('Unable to hash the Day-Zero backup after execution.');
        }

        $marker = [
            'release' => config('et_erp_release.release', 'ERP'),
            'completed_at' => now()->toIso8601String(),
            'actor_id' => $this->userId($user),
            'actor_name' => $this->userName($user),
            'backup_file' => basename($backupPath),
            'backup_sha256' => $backupHash,
            'rows_deleted' => (int) ($result['rows_deleted'] ?? 0),
            'tables_cleared' => (int) ($result['tables_cleared'] ?? 0),
            'preserved_fk_rows_neutralized' => (int) ($result['preserved_fk_rows_neutralized'] ?? 0),
            'cycle_fk_rows_neutralized' => (int) ($result['cycle_fk_rows_neutralized'] ?? 0),
            'counter_rows_updated' => (int) ($result['counter_rows_updated'] ?? 0),
            'clear_tables_planned' => (int) ($plan['clear_tables'] ?? 0),
            'counter_tables_planned' => (int) ($plan['counter_tables'] ?? 0),
            'review_tables_at_execution' => (int) ($plan['review_tables'] ?? -1),
            'unresolved_fk_blockers_at_execution' => (int) ($plan['fk_blockers'] ?? -1),
            'dependency_cycles_at_execution' => (int) ($plan['dependency_cycles'] ?? -1),
        ];

        $temp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $json = json_encode(
            $marker,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )."\n";

        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary Day-Zero completion marker.');
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Unable to publish permanent Day-Zero completion marker.');
        }

        return $marker;
    }

    private function assertSafeIdentifier(string $value, string $label): void
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException('Unsafe Day-Zero '.$label.' identifier: '.$value);
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
