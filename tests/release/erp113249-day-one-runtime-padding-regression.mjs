import fs from 'node:fs';

const read = (path) => fs.readFileSync(path, 'utf8');

const service = read('app/Services/System/DayOneSequenceResetService.php');
const controller = read('app/Http/Controllers/System/ProductionDataResetController.php');
const view = read('resources/views/system/day-one-sequence-reset-v113247.blade.php');
const dayZero = read('app/Services/System/DayZeroDataResetService.php');
const version = read('VERSION.txt').trim();

function assert(name, condition) {
  if (!condition) {
    console.error(`FAIL ${name}`);
    process.exitCode = 1;
  } else {
    console.log(`PASS ${name}`);
  }
}

const telemetryDeclaration = service.match(/public const RUNTIME_TELEMETRY_TABLES = \[([\s\S]*?)\];/);
const telemetryTables = telemetryDeclaration
  ? [...telemetryDeclaration[1].matchAll(/'([^']+)'/g)].map((match) => match[1])
  : [];

assert('VERSION_REMAINS_248', version === 'v1.1.33.248-ERP11.3.248');
assert('FIRST_NUMBER_IS_1000', service.includes('public const FIRST_NUMBER = 1000;'));
assert('LAST_USED_BASELINE_IS_999', service.includes('public const LAST_USED_BASELINE = 999;'));
assert('DISPLAY_PADDING_IS_4', service.includes('public const DISPLAY_PADDING = 4;'));
assert('EXACT_RUNTIME_TELEMETRY_ALLOW_LIST', JSON.stringify(telemetryTables) === JSON.stringify(['audit_logs', 'login_events']));
assert('TELEMETRY_FILTER_PRECEDES_BUSINESS_COUNT', service.indexOf('in_array($table, self::RUNTIME_TELEMETRY_TABLES, true)') < service.indexOf('$businessClearTables[] = $table;'));
assert('IDENTITY_TARGETS_RECEIVE_BUSINESS_TABLES_ONLY', service.includes('$this->identityTargets($businessClearTables)') && !service.includes('$this->identityTargets($clearTables)'));
assert('DAY_ONE_SERVICE_NEVER_DELETES_ROWS', !service.includes('->delete(') && !service.includes('TRUNCATE'));
assert('GENUINE_NULL_ROWS_BLOCK', service.includes("if ($rows === null)") && service.includes('Unable to prove CLEAR table is empty'));
assert('GENUINE_NONEMPTY_ROWS_BLOCK', service.includes('if ((int) $rows !== 0)') && service.includes('CLEAR table now contains production rows'));
assert('READY_COMPARES_BUSINESS_TABLE_COUNTS', service.includes('count($businessClearTables) === $emptyBusinessClearTables'));
assert('NUMBER_SEQUENCES_TABLE_PROVEN', service.includes("Schema::hasTable('number_sequences')"));
assert('NUMBER_SEQUENCES_PADDING_COLUMN_PROVEN', service.includes("Schema::hasColumn('number_sequences', 'padding')"));
assert('NUMBER_SEQUENCES_PADDING_PROPOSED', service.includes("$updates['padding'] = self::DISPLAY_PADDING;"));
assert('PADDING_SCHEMA_FAILURE_BLOCKS', service.includes('Unable to prove required Day-One padding authority'));
assert('PADDING_PREVIEW_MUST_BE_READY', service.includes('Required Day-One padding update is not ready: number_sequences.padding=4.'));
assert('PADDING_SPECIAL_CASE_IS_EXACT', service.includes("$table === 'number_sequences'") && service.includes("$column === 'padding'") && service.includes('(int) $value === self::DISPLAY_PADDING'));
assert('GENERIC_COUNTER_BASELINES_REMAIN_RESTRICTED', service.includes('! $isDisplayPadding && ! in_array((int) $value, [self::LAST_USED_BASELINE, self::FIRST_NUMBER], true)'));
assert('COUNTER_VERIFICATION_COVERS_PROPOSED_UPDATES', service.includes('foreach ($updates as $column => $expected)') && service.includes('Day-One counter verification failed'));
assert('MYSQL_IDENTITY_NEXT_1000', service.includes("AUTO_INCREMENT = '.self::FIRST_NUMBER"));
assert('POSTGRES_IDENTITY_1000_FALSE', service.includes("setval(?::regclass, '.self::FIRST_NUMBER.', false)"));
assert('SQL_SERVER_IDENTITY_BASELINE_999', service.includes("RESEED, '.self::LAST_USED_BASELINE"));
assert('SQLITE_IDENTITY_BASELINE_999', service.includes("['seq' => self::LAST_USED_BASELINE]"));
assert('EXACT_CONFIRMATION_PRESERVED', service.includes("public const CONFIRMATION = 'RESET DAY ONE SEQUENCES';"));
assert('PERMANENT_COMPLETION_MARKER_PRESERVED', service.includes("storage_path('app/system/day-one-sequence-reset-completed.json')"));
assert('COMPLETION_METADATA_INCLUDES_BUSINESS_COUNT', service.includes("'business_clear_tables_verified_empty'"));
assert('COMPLETION_METADATA_INCLUDES_TELEMETRY', service.includes("'runtime_telemetry_tables_excluded'"));
assert('COMPLETION_METADATA_INCLUDES_FIRST_NUMBER', service.includes("'first_number' => self::FIRST_NUMBER"));
assert('COMPLETION_METADATA_INCLUDES_PADDING', service.includes("'display_padding' => self::DISPLAY_PADDING"));
assert('CONTROLLER_SUCCESS_SAYS_1000', controller.includes('New document numbering can start from 1000.'));
assert('CONTROLLER_SUCCESS_NO_LONGER_SAYS_1', !controller.includes('New document numbering can start from 1.'));
assert('VIEW_HEADING_SAYS_FROM_1000', view.includes('Restart Production Document Numbers From 1000'));
assert('VIEW_IDENTITY_NOTE_SAYS_1000', view.includes('Will restart next inserted ID at 1000'));
assert('VIEW_IDENTITY_TARGET_SAYS_1000', view.includes('<span class="tag">1000</span>'));
assert('VIEW_BUTTON_SAYS_FROM_1000', view.includes('FINALIZE DAY-ONE NUMBERS FROM 1000'));
assert('VIEW_RENDERS_RUNTIME_TELEMETRY_NOTICE', view.includes('Runtime telemetry excluded from business-data gate'));
assert('DAY_ZERO_CLASSIFICATION_UNCHANGED', dayZero.includes("'audit_logs',") && dayZero.includes("'login_events',"));

if (process.exitCode) {
  process.exit(process.exitCode);
}
