import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceManager.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceController.php');
const show = read('resources/views/operations/bookings/additional-services/show.blade.php');

const start = manager.indexOf('public function show(int $bookingId, int $batchId)');
const end = manager.indexOf('\n    private function ensureBase', start);
const body = manager.slice(start, end);
let assertions = 0;
const ok = (condition, message) => { assertions++; assert.ok(condition, message); };

const bookingBranch = body.indexOf("if (($state['booking_missing'] ?? false)) return $state;");
const schemaBranch = body.indexOf("if (! ($state['schema_ready'] ?? false)) return $state + ['batch_missing' => false];");
const query = body.indexOf("DB::table('general_booking_billing_batches')");
const missBranch = body.indexOf("if (! $batch) return $state + ['batch_missing' => true];");
const realReturn = body.indexOf("'batch' => (array) $batch");

ok(bookingBranch >= 0, 'booking-missing authority returns state');
ok(schemaBranch > bookingBranch && schemaBranch < query, 'schema unavailable branch precedes batch query');
ok(schemaBranch >= 0 && body.slice(schemaBranch, query).includes("'batch_missing' => false"), 'schema missing batch_missing false');
ok(query > schemaBranch, 'schema-ready path queries the requested batch');
ok(missBranch > query && missBranch < realReturn, 'only query miss sets batch_missing true');
ok(realReturn > missBranch, 'real batch returns normal state');
ok(!body.includes("|| ! ($state['schema_ready'] ?? false)) return $state + ['batch_missing' => true]"), 'schema missing is not a query miss');
ok(controller.includes("($state['schema_ready'] ?? false) && ($state['batch_missing'] ?? false)"), 'controller 404s only ready-schema query misses');
ok(show.includes("!($state['schema_ready'] ?? false) || !is_array($state['batch'] ?? null)"), 'controlled unavailable view remains defensive');
ok(show.includes('Additional Services unavailable'), 'controlled unavailable heading preserved');
ok(manager.includes('public function indexState') && !manager.slice(manager.indexOf('public function indexState'), manager.indexOf('public function start')).includes('insertGetId'), 'index GET remains read-only');
console.log(`ERP378 ADDITIONAL SERVICES C47 REGRESSION: PASS (${assertions} assertions)`);
