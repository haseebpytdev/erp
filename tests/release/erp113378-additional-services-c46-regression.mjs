import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceManager.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceController.php');
const index = read('resources/views/operations/bookings/additional-services/index.blade.php');
const show = read('resources/views/operations/bookings/additional-services/show.blade.php');

let assertions = 0;
const ok = (condition, message) => { assertions++; assert.ok(condition, message); };

ok(manager.includes("'schema_ready' => false") && manager.includes('SCHEMA_MESSAGE'), 'schema missing state is explicit');
ok(manager.includes("'batch_missing' => true") && manager.includes('where(\'id\', $batchId)'), 'batch missing follows actual query');
ok(controller.includes("($state['schema_ready'] ?? false) && ($state['batch_missing'] ?? false)"), 'ready-schema missing batch is 404');
ok(!controller.includes("!($state['schema_ready'] ?? false) || ($state['batch_missing'] ?? false)"), 'schema missing is not treated as batch 404');
ok(show.includes("!($state['schema_ready'] ?? false) || !is_array($state['batch'] ?? null)"), 'show requires real batch');
ok(show.includes('Additional Services unavailable') && manager.includes('database upgrade'), 'controlled unavailable state');
ok(!show.includes("Status: Draft") || show.includes('@else'), 'schema missing cannot show fake draft');
ok(!show.includes("'PKR'") && !show.includes('Currency') || show.includes('@else'), 'schema missing cannot show fake currency');
ok(show.includes('product choices') || show.includes('Choose a product for the next phase'), 'product workspace guarded');
ok(index.includes("state['entry_message']") && index.includes('et-alert-info'), 'entry message rendered as info');
for (const message of ['Base Sales Invoice is still Draft','Base Sales Invoice is pending approval','No active Base Sales Invoice','Multiple active legacy Sales Invoices','Continue the existing Additional Services draft','Additional Services is pending approval','approved and awaiting']) {
  ok(manager.includes(message), `entry message: ${message}`);
}
ok(index.includes('Continue Additional Services') && index.includes("state['can_start']"), 'draft continue action');
ok(manager.includes("can_start_new_batch") && manager.includes("! $open['blocking']"), 'open draft suppresses add action');
const action = (entry, open = null) => open ? false : ['approved', 'posted', 'final', 'finalized'].includes(entry);
ok(action('approved', null) && !action('approved', 'draft') && !action('approved', 'pending_approval') && !action('approved', 'approved_uninvoiced'), 'open batch action contract');
ok(manager.includes('indexState') && !manager.slice(manager.indexOf('public function indexState'), manager.indexOf('public function start')).includes('insertGetId'), 'index remains read-only');
ok(manager.includes("$this->blocked($bookingId, $this->baseStatusCode($status)"), 'base blocked state keeps booking id');
ok(!manager.includes('NativeSalesInvoiceInspector'), 'unused dependency remains removed');
console.log(`ERP378 ADDITIONAL SERVICES C46 REGRESSION: PASS (${assertions} assertions)`);
