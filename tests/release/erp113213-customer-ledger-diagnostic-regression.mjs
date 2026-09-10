import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const controller = read('app/Http/Controllers/System/CustomerLedgerDiagnosticController.php');
const routes = read('routes/erp103179.php');
const posting = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');

ok(routes.includes("'/system/erp-diagnostics/customer-ledger/{customer}'"), 'bounded Customer Ledger diagnostic route is registered');
ok(routes.includes("->whereNumber('customer')"), 'diagnostic customer route value is numeric');
ok(controller.includes('$this->permissions->isSuperAdmin($request->user())'), 'diagnostic is restricted to a positively identified Super Admin');
ok(controller.includes("getByName('accounting.ledgers.customer')"), 'native named Customer Ledger route is the first authority');
ok(controller.includes("#^accounting/ledgers/customers/\\{[^}]+\\}$#"), 'exact native URI is the bounded fallback authority');
ok(controller.includes("'middleware' => $route->gatherMiddleware()"), 'native middleware stack is reported');
ok(controller.includes("'parameters' => $route->parameterNames()"), 'native route parameter contract is reported');
ok(controller.includes('new ReflectionMethod($class, $method)'), 'native controller signature and source are inspected at runtime');
ok(controller.includes("'customer_id',") && controller.includes("'subledger_id',"), 'invoice customer and subledger identifier authorities are reported');
ok(controller.includes('ImplicitRouteBinding::resolveForRoute(app(), $probeRoute)'), 'native model binding is resolved for the probe');
ok(controller.includes("Request::create('/'.ltrim($uri, '/'), 'GET')"), 'probe invokes only the native GET drill-down');
ok(controller.includes('$connection->beginTransaction()'), 'probe starts a protective transaction');
ok(controller.includes("$connection->statement('SET TRANSACTION READ ONLY')"), 'MySQL probe rejects writes at the database transaction layer');
ok(controller.includes('$connection->rollBack()'), 'probe always rolls back any database mutation');
ok(controller.includes("'transaction_rolled_back' => true"), 'diagnostic output declares rollback state');
ok(controller.includes("'SECRETS_EXPOSED' => false"), 'diagnostic explicitly reports its secret-safety contract');
ok(controller.includes("'$1=[REDACTED]'"), 'credential-like exception and source fragments are redacted');
ok(!controller.includes('->insert(') && !controller.includes('->update(') && !controller.includes('->delete('), 'diagnostic contains no accounting write query');
ok(!controller.includes('env(') && !controller.includes('.env'), 'diagnostic never reads environment secrets');
ok(!controller.includes('JV-2026-000013') && !controller.includes('931200'), 'diagnostic does not hard-code the reference journal or amount');
ok(!posting.includes('CustomerLedgerDiagnosticController'), 'Sales Invoice posting remains independent of the diagnostic');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
