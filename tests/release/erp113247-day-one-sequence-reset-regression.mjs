import fs from 'node:fs';

const service = fs.readFileSync('app/Services/System/DayOneSequenceResetService.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/System/ProductionDataResetController.php', 'utf8');
const view = fs.readFileSync('resources/views/system/day-one-sequence-reset-v113247.blade.php', 'utf8');
const dayZero = fs.readFileSync('app/Services/System/DayZeroExecutionService.php', 'utf8');
const version = fs.readFileSync('VERSION.txt', 'utf8').trim();

function assert(name, condition) {
  if (!condition) {
    console.error(`FAIL ${name}`);
    process.exitCode = 1;
  } else {
    console.log(`PASS ${name}`);
  }
}

assert('VERSION_STAYS_246_DURING_247_FUNCTIONAL_CHECKPOINT', version === 'v1.1.33.246-ERP11.3.246');
assert('DAY_ZERO_EXECUTION_AUTHORITY_REMAINS_ENABLED', dayZero.includes('public const EXECUTION_ENABLED = true;'));
assert('DAY_ONE_REQUIRES_DAY_ZERO_COMPLETION', service.includes('$dayZeroCompleted = $this->planner->completedRecord();') && service.includes('Day-Zero completion marker is missing.'));
assert('DAY_ONE_EXACT_CONFIRMATION', service.includes("public const CONFIRMATION = 'RESET DAY ONE SEQUENCES';"));
assert('EVERY_CLEAR_TABLE_MUST_STILL_BE_EMPTY', service.includes("($item['action'] ?? null) !== 'clear'") && service.includes('CLEAR table now contains production rows and sequence reset is blocked'));
assert('NATIVE_COUNTER_PREVIEW_REUSED', service.includes("$dayZeroPlan['counter_reset_preview']") && service.includes("$dayZeroPlan['counter_reset_ready']"));
assert('MYSQL_AUTO_INCREMENT_RESET_TO_ONE', service.includes('AUTO_INCREMENT = 1'));
assert('POSTGRES_NEXT_INSERT_ONE', service.includes("setval(?::regclass, 1, false)"));
assert('SQLSERVER_NEXT_INSERT_ONE', service.includes('DBCC CHECKIDENT') && service.includes('RESEED, 0'));
assert('SQLITE_NEXT_INSERT_ONE', service.includes("DB::table('sqlite_sequence')->where('name', $table)->update(['seq' => 0])"));
assert('COUNTERS_ONLY_NORMALIZED_TO_ZERO_OR_ONE', service.includes("in_array((int) $value, [0, 1], true)"));
assert('NO_BUSINESS_ROW_DELETE', !service.includes('->delete(') && !service.includes('TRUNCATE TABLE') && !service.includes('disableForeignKeyConstraints'));
assert('ONE_TIME_SEQUENCE_MARKER', service.includes('day-one-sequence-reset-completed.json') && service.includes('already completed and permanently locked'));
assert('CONTROLLER_SWITCHES_ONLY_AFTER_DAY_ZERO_COMPLETE', controller.includes('if ($service->completedRecord())') && controller.includes("'system.day-one-sequence-reset-v113247'"));
assert('PRE_COMPLETION_DAY_ZERO_PATH_RETAINED', controller.includes('DayZeroExecutionService $executor') && controller.includes('$executor->execute('));
assert('DAY_ONE_EXECUTE_ROUTE_REUSED', controller.includes('if ($planner->completedRecord())') && controller.includes('$sequences->execute($request->user())'));
assert('DAY_ONE_ACK_REQUIRED', controller.includes("'acknowledge' => ['accepted']") && controller.includes('no new production business data has been entered since Day-Zero'));
assert('VIEW_EXPECTS_BOOKING_FROM_ONE', view.includes('BK-{{ $year }}-000001'));
assert('VIEW_EXPECTS_NATIVE_INVOICE_FROM_ONE', view.includes('SI-{{ $year }}-000001'));
assert('VIEW_EXPECTS_GROUP_INVOICE_FROM_ONE', view.includes('ET-SI-{{ $year }}-000001'));
assert('VIEW_EXPECTS_GROUP_VOUCHER_FROM_ONE', view.includes('ET-UV-{{ $year }}-000001'));
assert('VIEW_EXPECTS_CASH_VOUCHERS_FROM_ONE', ['RV-', 'PV-', 'EV-', 'CV-', 'CAR-', 'SAP-'].every(prefix => view.includes(prefix)));
assert('VIEW_EXPECTS_ADJUSTMENT_COSTING_JOURNAL_FROM_ONE', ['AA-', 'SC-', 'JV-'].every(prefix => view.includes(prefix)));
assert('BUTTON_ONLY_WHEN_READY', view.includes('@elseif($plan[\'ready\'])') && view.includes('FINALIZE DAY-ONE NUMBERS FROM 1'));
assert('STAFF_ACTIVITY_ACKNOWLEDGEMENT', view.includes('no staff have entered new production business data since the Day-Zero reset'));

if (process.exitCode) process.exit(process.exitCode);
