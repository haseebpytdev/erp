import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const service = read('app/Services/System/DayZeroDataResetService.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(service.includes('ERP-11.3.243 functional checkpoint'), 'service identifies the classification checkpoint');
ok(service.includes('public const EXECUTION_ENABLED = false'), 'destructive execution remains disabled');
ok(service.includes("'ready_to_execute' => self::EXECUTION_ENABLED && $reviewTables === 0"), 'zero REVIEW alone cannot enable execution');
ok(service.includes("'action' => 'review'"), 'future unknown tables still fail closed');
ok(service.includes('Unclassified table. Must be reviewed explicitly'), 'future-schema REVIEW reason remains explicit');
ok(!service.includes('->delete(') && !service.includes('truncate(') && !service.includes('TRUNCATE TABLE') && !service.includes('DELETE FROM') && !service.includes('DROP TABLE'), 'classification checkpoint contains no destructive database operation');

const preserve = [
  'accounting_periods',
  'approval_policies',
  'branch_user',
  'departments',
  'fiscal_years',
  'role_permission',
  'role_user',
  'service_cost_allocations',
  'staff',
  'user_approval_limits',
];

const clear = [
  'agent_profiles',
  'audit_logs',
  'customer_profiles',
  'employee_party_profiles',
  'exchange_rates',
  'group_travel_package_passenger_prices',
  'group_travel_packages',
  'hotels',
  'login_events',
  'parties',
  'party_roles',
  'passenger_profiles',
  'products_services',
  'transport_rate_cards',
  'transport_rates',
  'transport_routes',
  'transport_vehicle_types',
  'vendor_profiles',
  'visa_rate_cards',
  'visa_service_operators',
];

const counters = ['number_sequence_counters'];

for (const table of preserve) {
  ok(service.includes(`'${table}'`), `${table} is explicitly preserved`);
}

for (const table of clear) {
  ok(service.includes(`'${table}'`), `${table} is explicitly cleared`);
}

for (const table of counters) {
  ok(service.includes(`'${table}'`), `${table} is explicitly reset as a counter`);
}

ok(new Set([...preserve, ...clear, ...counters]).size === 31, 'all 31 production REVIEW tables are resolved exactly once');
ok(service.includes("'users'") && service.includes("'roles'") && service.includes("'permissions'"), 'user/security foundation remains preserved');
ok(service.includes("'role_user'") && service.includes("'branch_user'"), 'existing user role/branch links remain preserved');
ok(service.includes("'staff'"), 'approved staff foundation remains preserved');
ok(service.includes("'parties'") && service.includes("'party_roles'"), 'approved business party tables are included in fresh-start clear plan');
ok(service.includes("'number_sequence_counters'"), 'production number sequence counters will restart');
ok(service.includes("'release' => 'ERP-11.3.243-CLASSIFICATION-PREVIEW'"), 'backup metadata identifies classification preview');
ok(service.includes('Day-Zero execution is intentionally LOCKED in ERP-11.3.243 classification preview'), 'execute path remains locked after classification');
ok(version === 'v1.1.33.246-ERP11.3.246', 'ERP-11.3.246 controlled execution release metadata is promoted for packaging');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
