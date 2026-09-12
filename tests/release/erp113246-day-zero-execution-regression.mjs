import fs from 'node:fs';

const executor = fs.readFileSync('app/Services/System/DayZeroExecutionService.php', 'utf8');
const planner = fs.readFileSync('app/Services/System/DayZeroDataResetService.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/System/ProductionDataResetController.php', 'utf8');
const view = fs.readFileSync('resources/views/system/day-zero-data-reset-v113246.blade.php', 'utf8');
const version = fs.readFileSync('VERSION.txt', 'utf8').trim();

function assert(name, condition) {
  if (!condition) {
    console.error(`FAIL ${name}`);
    process.exitCode = 1;
  } else {
    console.log(`PASS ${name}`);
  }
}

assert('VERSION_STAYS_245_DURING_246_FUNCTIONAL_CHECKPOINT', version === 'v1.1.33.245-ERP11.3.245');
assert('PLANNER_REMAINS_FAIL_CLOSED', planner.includes("'action' => 'review'") && planner.includes('Unclassified table. Must be reviewed explicitly'));
assert('EXECUTION_AUTHORITY_EXPLICITLY_ENABLED', executor.includes('public const EXECUTION_ENABLED = true;'));
assert('FRESH_BACKUP_REVALIDATED', executor.includes('$this->planner->validateBackup($backupPath, $backupCreatedAt)'));
assert('LIVE_PLAN_REBUILT_BEFORE_MUTATION', executor.includes('$plan = $this->planner->plan();') && executor.includes('$this->assertExecutablePlan($plan);'));
assert('ZERO_REVIEW_GATE', executor.includes("'review tables' => ((int) ($plan['review_tables'] ?? -1)) === 0"));
assert('ZERO_UNRESOLVED_FK_GATE', executor.includes("'unresolved FK blockers' => ((int) ($plan['fk_blockers'] ?? -1)) === 0"));
assert('ZERO_DEPENDENCY_CYCLE_GATE', executor.includes("'dependency cycles' => ((int) ($plan['dependency_cycles'] ?? -1)) === 0"));
assert('DEPENDENCY_AND_COUNTER_GATES', executor.includes("'dependency plan' => ($plan['dependency_plan_ready'] ?? false) === true") && executor.includes("'counter reset plan' => ($plan['counter_reset_ready'] ?? false) === true"));
assert('EVERY_RAW_FK_MUST_HAVE_SAFE_NEUTRALIZATION', executor.includes('count($neutralizations) !== $rawBlockers'));
assert('TRANSACTIONAL_MUTATION_BOUNDARY', executor.includes('DB::transaction(function () use ($plan): array'));
assert('PRESERVED_FK_NEUTRALIZATION_EXECUTES', executor.includes("->whereNotNull($column)") && executor.includes("->update([$column => null])"));
assert('CYCLE_NEUTRALIZATION_EXECUTES', executor.includes('applyCycleNeutralizations'));
assert('CLEAR_TABLES_DELETE_IN_VERIFIED_ORDER', executor.includes("DB::table($table)->delete()") && executor.includes("$deleteOrder = array_values($plan['delete_order'] ?? [])"));
assert('COUNTERS_RESET_FROM_PREVIEW', executor.includes("$updates = (array) ($item['proposed_updates'] ?? [])") && executor.includes('DB::table($table)->update($updates)'));
assert('POST_DELETE_ZERO_VERIFICATION', executor.includes("Post-reset verification failed; table is not empty"));
assert('POST_COUNTER_VERIFICATION', executor.includes('Post-reset counter verification failed'));
assert('CONCURRENT_EXECUTION_LOCK', executor.includes("day-zero-reset-running.json") && executor.includes("fopen($lock, 'x')"));
assert('PERMANENT_COMPLETION_MARKER', executor.includes('day-zero-reset-completed.json') && executor.includes('writeCompletionMarker'));
assert('COMMITTED_WITHOUT_MARKER_FAILS_CLOSED', executor.includes('database changes committed, but the permanent completion marker could not be written') && executor.includes('do not retry automatically'));
assert('BACKUP_HASH_RECORDED', executor.includes("hash_file('sha256', $backupPath)") && executor.includes("'backup_sha256'"));
assert('CONTROLLER_USES_EXECUTOR', controller.includes('DayZeroExecutionService') && controller.includes('$executor->execute('));
assert('AUTHORITY_STILL_USED_ON_EXECUTE', controller.includes('$authority->authorize($request->user());'));
assert('EXACT_CONFIRMATION_STILL_REQUIRED', controller.includes("'in:'.DayZeroDataResetService::CONFIRMATION"));
assert('ACKNOWLEDGEMENT_STILL_REQUIRED', controller.includes("'acknowledge' => ['accepted']"));
assert('EXECUTION_VIEW_IS_NEW_AUTHORIZED_SCREEN', controller.includes("'system.day-zero-data-reset-v113246'"));
assert('BUTTON_ONLY_AVAILABLE_WHEN_EXECUTION_READY', view.includes('@elseif($executionReady)'));
assert('PERMANENT_BUTTON_PRESENT', view.includes('PERMANENTLY RESET ERP TO DAY ZERO'));
assert('CLIENT_FINAL_CONFIRM_PRESENT', view.includes("confirm('FINAL WARNING: Permanently reset the ERP to Day Zero now?')"));
assert('COMPLETION_LOCK_SHOWN', view.includes('Day-Zero is permanently completed and locked.'));

if (process.exitCode) process.exit(process.exitCode);
