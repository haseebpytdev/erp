import fs from 'node:fs';

const service = fs.readFileSync('app/Services/System/DayZeroDataResetService.php', 'utf8');
const view = fs.readFileSync('resources/views/system/day-zero-data-reset-v113242.blade.php', 'utf8');
const version = fs.readFileSync('VERSION.txt', 'utf8').trim();

function assert(name, condition) {
  if (!condition) {
    console.error(`FAIL ${name}`);
    process.exitCode = 1;
  } else {
    console.log(`PASS ${name}`);
  }
}

const preserveBlock = service.slice(service.indexOf('private array $preserveExact'), service.indexOf('private array $counterTables'));
const clearBlock = service.slice(service.indexOf('private array $clearExact'), service.indexOf('private array $nullableNeutralizationCandidates'));
const destructiveTokens = [
  '->delete(',
  '->truncate(',
  'DB::statement(\'DELETE',
  'DB::statement("DELETE',
  'DB::unprepared(\'DELETE',
  'DB::unprepared("DELETE',
  'DROP TABLE',
  'TRUNCATE TABLE',
];

assert('VERSION_IS_245_RELEASE', version === 'v1.1.33.251-ERP11.3.251');
assert('EXECUTION_REMAINS_DISABLED', service.includes("public const EXECUTION_ENABLED = false;"));
assert('NO_DESTRUCTIVE_DB_OPERATION_ADDED', destructiveTokens.every((token) => !service.includes(token)));
assert('SERVICE_COST_ALLOCATIONS_IS_CLEAR', clearBlock.includes("'service_cost_allocations',"));
assert('SERVICE_COST_ALLOCATIONS_NOT_PRESERVED', !preserveBlock.includes("'service_cost_allocations',"));
assert('AIRLINES_VENDOR_NEUTRALIZATION_CANDIDATE', service.includes("'table' => 'airlines'") && service.includes("'column' => 'default_vendor_party_id'"));
assert('BOOKING_SOURCES_VENDOR_NEUTRALIZATION_CANDIDATE', service.includes("'table' => 'booking_sources'") && service.includes("'parent_table' => 'parties'"));
assert('NULLABILITY_IS_RUNTIME_INSPECTED', service.includes('private function isColumnNullable('));
assert('RAW_AND_UNRESOLVED_BLOCKERS_ARE_DISTINCT', service.includes("'raw_fk_blockers'") && service.includes("'fk_blockers'"));
assert('CYCLE_EDGES_USE_STRONGLY_CONNECTED_COMPONENTS', service.includes('private function cycleEdgeAudit(') && service.includes('$strongConnect'));
assert('CYCLE_EDGES_ARE_EXPOSED', service.includes("'dependency_cycle_edges'") && service.includes("'can_break_with_null'"));
assert('DELETE_ORDER_IS_RECALCULATED', service.includes('private function topologicalDeleteOrder(') && service.includes('$breakableCycleKeys'));
assert('UNKNOWN_TABLES_STILL_FAIL_CLOSED', service.includes("'action' => 'review'") && service.includes('Unclassified table. Must be reviewed explicitly'));
assert('BACKUP_STILL_FULL_DATABASE', service.includes("'purpose' => 'Full pre-Day-Zero database backup'") && service.includes('foreach ($this->tableNames() as $table)'));
assert('EXECUTE_METHOD_STILL_LOCKED', service.includes('Day-Zero execution is intentionally LOCKED in ERP-11.3.245 FK resolution preview'));
assert('VIEW_SHOWS_RAW_BLOCKERS', view.includes('Raw FK blockers'));
assert('VIEW_SHOWS_UNRESOLVED_BLOCKERS', view.includes('Unresolved blockers'));
assert('VIEW_SHOWS_NEUTRALIZATION_PREVIEW', view.includes('Nullable FK neutralization preview'));
assert('VIEW_SHOWS_CYCLE_EDGE_AUDIT', view.includes('Dependency cycle edge audit'));
assert('RESET_BUTTON_DISABLED', view.includes('Day-Zero Reset Locked') && /type="submit" disabled>Day-Zero Reset Locked/.test(view));
assert('CONFIRMATION_INPUT_DISABLED', /name="confirmation"[\s\S]*?disabled/.test(view));

if (process.exitCode) process.exit(process.exitCode);
