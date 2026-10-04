import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const coordinatorPath = path.join(root, 'app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php');
const servicePath = path.join(root, 'app/Services/Sales/SalesInvoiceService.php');
const coordinator = fs.readFileSync(coordinatorPath, 'utf8');
const salesInvoiceService = fs.readFileSync(servicePath, 'utf8');
const existingLinkBranch = coordinator.match(/if \(\$existingLink\) \{[\s\S]*?\n            \}\n\n            \$items/)?.[0] ?? '';

let assertions = 0;
const has = (pattern, message) => {
  assertions += 1;
  assert.match(coordinator, pattern, message);
};
const no = (pattern, message) => {
  assertions += 1;
  assert.doesNotMatch(coordinator, pattern, message);
};

has(/final class GeneralBookingAdditionalServiceSalesInvoiceCoordinator/,
  'C67C coordinator exists');
has(/public function create\(Request \$request, int \$bookingId, int \$batchId\): array/,
  'public create contract exists');
has(/DB::transaction\(function \(\) use \(\$request, \$bookingId, \$batchId\): array \{[\s\S]*\}, 3\)/,
  'all coordinator writes use an outer transaction with retry');
has(/Booking::query\(\)->whereKey\(\$bookingId\)->lockForUpdate\(\)->firstOrFail\(\)/,
  'booking is locked');
has(/general_booking_billing_batches'[\s\S]*where\('id', \$batchId\)[\s\S]*where\('booking_id', \$bookingId\)[\s\S]*lockForUpdate\(\)/,
  'batch is booking-scoped and locked');
has(/general_booking_billing_batch_items'[\s\S]*where\('batch_id', \$batchId\)[\s\S]*where\('booking_id', \$bookingId\)[\s\S]*lockForUpdate\(\)/,
  'batch items are booking-scoped and locked');
has(/strtolower\(\(string\) \$batch->batch_type\) !== 'supplementary'/,
  'supplementary batch type is required');
has(/strtolower\(\(string\) \$batch->status\) !== 'approved'/,
  'approved status is required');
has(/\(int\) \$batch->batch_no < 1/,
  'supplementary batch number must be positive');
has(/\$existingLink = DB::table\('general_booking_invoice_links'\)[\s\S]*where\('batch_id', \$batchId\)[\s\S]*lockForUpdate\(\)/,
  'existing invoice link is checked under lock');
has(/if \(\$existingLink\) \{[\s\S]*'status' => 'already_invoiced'[\s\S]*\}/,
  'existing links are permanently idempotent');
has(/\(int\) \$existingLink->booking_id !== \$bookingId/,
  'existing link booking ownership is validated');
has(/\(int\) \$existingLink->batch_id !== \$batchId/,
  'existing link batch ownership is validated');
has(/\(int\) \$existingLink->sales_invoice_id <= 0/,
  'existing link Sales Invoice ID must be positive');
has(/SalesInvoice::query\(\)[\s\S]*whereKey\(\(int\) \$existingLink->sales_invoice_id\)[\s\S]*first\(\)/,
  'existing link requires a native invoice lookup');
has(/if \(! \$linkedInvoice\)[\s\S]*Existing supplementary invoice link has no native Sales Invoice/,
  'missing linked native invoice fails closed');
has(/if \(\(int\) \$linkedInvoice->booking_id !== \$bookingId\)[\s\S]*belongs to another booking/,
  'cross-booking linked invoice fails closed');
has(/\$linkedInvoice->customer_party_id !== null[\s\S]*\$booking->customer_party_id !== null[\s\S]*customer_party_id/,
  'existing link customer ownership is validated');
has(/\$invoiceNoSnapshot = trim\(\(string\) \(\$existingLink->invoice_no_snapshot \?\? ''\)\)/,
  'nullable invoice number snapshot is allowed');
has(/\$invoiceNoSnapshot !== ''[\s\S]*\$linkedInvoice->invoice_no[\s\S]*snapshot is inconsistent/,
  'non-null invoice number snapshot must match');
no(/\$linkedInvoice->status\s*!==|\$linkedInvoice->status\s*===|where\('status'/,
  'existing link has no status reinvoice filter');
has(/'invoice_status' => \$linkedInvoice->status/,
  'valid existing link returns native invoice status without filtering');
has(/'invoice_sequence' => \(int\) \$existingLink->invoice_sequence/,
  'valid existing link returns its existing sequence');
assertions += 1;
assert.doesNotMatch(existingLinkBranch, /createFromBookingServices/,
  'broken or valid existing links never call native creation');
assertions += 1;
assert.doesNotMatch(existingLinkBranch, /->delete\(|->update\(/,
  'broken links are not auto-repaired');
has(/\$this->integrity->build\(\$bookingId, \$batchId\)/,
  'snapshot integrity authority is used');
has(/hash_equals\(\(string\) \$batch->source_snapshot_hash, \(string\) \(\$frozen\['hash'\] \?\? ''\)\)/,
  'persisted snapshot hash is verified');
has(/\$this->planner->plan\(\$bookingId, \$batchId\)/,
  'materialization planner is used');
has(/materialization_state[\s\S]*already_materialized[\s\S]*code[\s\S]*already_materialized/,
  'only fully materialized batches proceed');
has(/! \$item->source_table[\s\S]*\$item->source_id[\s\S]*\$item->booking_service_id[\s\S]*\$item->product_service_id/,
  'partial or incomplete materialization fails closed');
has(/pluck\('booking_service_id'\)[\s\S]*unique\(\)->sort\(\)->values\(\)->all\(\)/,
  'service scope is unique and deterministically sorted');
has(/whereIn\('id', \$serviceIds\)->where\('booking_id', \$bookingId\)/,
  'every selected service belongs to the booking');
has(/\$service->product_service_id !== \(int\) \$item->product_service_id/,
  'batch product service ownership is checked');
has(/\$frozen\['totals'\]\['customer_total'\][\s\S]*round\(/,
  'frozen customer total is authoritative');
has(/Schema::hasColumn\('booking_services', 'line_total'\)/,
  'native line total availability is fail-closed');
has(/DB::table\('booking_services'\)->whereIn\('id', \$serviceIds\)->sum\('line_total'\)/,
  'native selected service totals are computed before creation');
has(/abs\(\$nativeTotal - \$expectedTotal\) > 0\.01/,
  'pre-create native total must reconcile');
has(/\$this->salesInvoices->createFromBookingServices\(\$request, \$booking, \$serviceIds\)/,
  'only the scoped native creator is called');
no(/NativeSalesInvoiceRuntimeBridge|NativeSalesInvoiceCreationVerifier/,
  'runtime bridge and verifier are not used');
has(/instanceof SalesInvoice[\s\S]*getKey\(\)/,
  'native result must be a persisted SalesInvoice');
has(/\$invoice->booking_id !== \$bookingId[\s\S]*strtoupper\(\(string\) \$invoice->status\) !== 'DRAFT'/,
  'native invoice booking and draft identity are verified');
has(/\$invoice->customer_party_id !== \(int\) \$booking->customer_party_id/,
  'native invoice customer identity is verified');
has(/trim\(\(string\) \$invoice->invoice_no\) === ''/,
  'native invoice number is required');
has(/pluck\('source_booking_service_id'\)[\s\S]*unique\(\)[\s\S]*sort\(\)->values\(\)->all\(\) !== \$serviceIds/,
  'native lines exactly match selected service IDs');
has(/\$line->product_service_id !== \(int\) \$service->product_service_id/,
  'native line Product Service links are verified');
has(/\$invoice->lines->sum\('line_total'\)[\s\S]*\$invoice->grand_total[\s\S]*\$expectedTotal/,
  'native line and grand totals reconcile to frozen total');
has(/general_booking_invoice_links'[\s\S]*where\('booking_id', \$bookingId\)[\s\S]*lockForUpdate\(\)/,
  'invoice link rows are locked for sequence allocation');
has(/\$invoiceSequence = \$links->max\('invoice_sequence'\) === null \? 1 : \(\(int\) \$links->max\('invoice_sequence'\) \+ 1\)/,
  'invoice sequence is allocated deterministically under lock');
has(/GeneralBookingBillingBatchContract::assertLinkConsistency\(\(int\) \$batch->batch_no, 'supplementary', \$invoiceSequence, 'supplementary'\)/,
  'link contract authority is enforced');
has(/'link_type' => 'supplementary'/,
  'supplementary link type is persisted');
has(/'invoice_no_snapshot' => \$invoice->invoice_no/,
  'invoice number snapshot is persisted');
has(/Schema::hasColumn\('general_booking_invoice_links', 'created_at'\)/,
  'link timestamps follow deployed schema');
has(/Schema::hasColumn\('general_booking_billing_batches', 'invoice_created_at'\)/,
  'batch invoice timestamp is stamped only when supported');
no(/submit|journal|CashVoucher|->post|->approve|->pay|payment|receipt/,
  'coordinator does not post or settle accounting');
no(/Route::|resources\/views|public\/erp-theme|Controller/,
  'coordinator does not add routes, UI, or controllers');
has(/return \[\s*'status' => 'created'/,
  'created response is explicit');
has(/return \[\s*'status' => 'already_invoiced'/,
  'idempotent response is explicit');

assertions += 1;
assert.match(salesInvoiceService, /function createFromBookingServices\(/,
  'C67B native scoped creator remains present');
assertions += 1;
assert.doesNotMatch(salesInvoiceService, /function createFromBookingServices\([\s\S]*NativeSalesInvoiceRuntimeBridge/,
  'C67C does not alter native service with a bridge');

console.log(`C67C supplementary invoice coordinator regression: PASS (${assertions} assertions)`);
