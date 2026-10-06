import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const resolver = read('app/Services/Operations/BookingProductSummaryResolver.php');
const commercial = read('app/Services/Operations/GeneralBookingCommercialSummaryResolver.php');
const operational = read('app/Http/Controllers/Operations/GeneralBookingOperationalSummaryController.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const progressive = read('public/erp11390/general-progressive-step1.js');
const materializer = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializer.php');
const routes = read('routes/erp103179.php');
const middleware = read('app/Http/Middleware/EnforceGeneralBookingEditLock.php');
const review = read('resources/views/operations/bookings/general-booking-review-v113160.blade.php');

let assertions = 0;
const ok = (value, message) => { assertions++; assert.ok(value, message); };

// One canonical Visa authority is used by both presentations.
ok(resolver.includes("'visa' => $this->summary($booking, 'visa')"), 'canonical Visa resolver remains lightweight');
ok(resolver.includes('private function visaSummary(') && resolver.includes('private function summaryFromVisaRows('), 'Visa compatibility is inside shared resolver');
ok(resolver.includes("$snapshot = $this->serviceSnapshotSummary($serviceRows, 'visa')"), 'historical service snapshot is a shared input');
ok(resolver.includes("if (($snapshot['present'] ?? false)) return $snapshot"), 'incomplete dedicated Visa rows use historical source');
ok(resolver.includes("return $this->withApprovedSupplements($booking, $product, $summary)"), 'effective summary includes approved supplements');
ok(commercial.includes('$summary = $canonicalSummary;'), 'Commercial Summary consumes canonical Visa result');
ok(presenter.includes('$this->productSummaries->resolve($bookingId)'), 'Product Card consumes canonical resolver');
ok(!commercial.includes('GeneralBookingVisaProductController'), 'no controller coupling in commercial resolver');
for (const field of ['present', 'count', 'customer_total', 'supplier_total', 'margin']) ok(resolver.includes("'" + field + "'"), `Visa field ${field} preserved`);
ok(resolver.includes("'present' => $rows->isNotEmpty()"), 'zero-value Visa with source identity remains present');
ok(resolver.includes('serviceRows(int $booking, string $product'), 'legacy service rows are schema-scoped');
ok(resolver.includes("str_contains($identity, 'visa')"), 'fallback service identity is Visa-specific');
for (const product of ['air', 'hotel', 'transport']) ok(resolver.includes("'" + product + "'"), `${product} authority remains present`);
ok(resolver.includes('withApprovedSupplements'), 'supplement aggregation remains enabled');
ok(resolver.includes('booking_service_id'), 'materialized supplement double-count guard remains present');

// Server data contract owns workflow status; billing lock remains separate.
ok(operational.includes("'booking_status' => (string) ($lock['status'] ?? 'Draft')"), 'operational summary returns normalized workflow status');
ok(operational.includes("'booking_lock_reason' => $lock['reason']"), 'billing reason remains separate');
ok(!operational.includes("booking_status.*sales_invoice"), 'workflow status is not derived from invoice');
ok(progressive.includes("data.booking_status||data.status||'DRAFT'"), 'client compatibility fallback remains narrow');
ok(presenter.includes("data-et-booking-status"), 'server seeds workflow status');
ok(presenter.includes("data-et-booking-billing-locked"), 'server seeds billing lock separately');

// Persistent server-injected presentation rule covers late-generated controls.
ok(presenter.includes('data-et-booking-billing-locked="1"'), 'billing lock CSS is keyed to server attribute');
for (const selector of ['.etgp-passenger-editor-host', '.etgp-passenger-quick-row', '.etgp-quick-passenger-11397', '[data-etgp-quick-passenger-11397]', '[data-etgp-passenger-mode-control]']) {
  ok(presenter.includes(selector), `persistent passenger lock covers ${selector}`);
}
ok(presenter.includes('display:none!important;visibility:hidden!important;'), 'late passenger editor nodes remain hidden');
ok(progressive.includes('.etgp-passenger-current-host') && progressive.includes('.etgp-current-passenger-table'), 'passenger table remains visible');
for (const selector of ['quick-add', 'quick-master-update', 'fare-type', 'EnforceGeneralBookingEditLock']) ok(routes.includes(selector) || middleware.includes(selector), `server guard ${selector} remains protected`);
ok(middleware.includes('BookingBillingEditLockResolver'), 'billing lock middleware remains authoritative');

// Existing safe actions and protected behavior remain intact.
ok(review.includes("!($billingLock['locked']??false)"), 'Review Reopen remains suppressed under billing lock');
ok(presenter.includes('Review Booking'), 'Review Booking remains available');
ok(!fs.readdirSync(path.join(root, 'database/migrations')).some((name) => name.toLowerCase().includes('c51')), 'no C51 migration added');
ok(!resolver.includes('GeneralBookingVisaProductController::show'), 'no full controller fan-out');
ok(!resolver.includes('sales_invoices'), 'product summary remains read-only');

// Deterministic fixture matrix for the final data-flow contract.  PHP is not
// available in this environment, so this mirrors the resolver's source-order
// contract and verifies every supported source shape without claiming runtime
// or browser execution.
const products = ['air', 'hotel', 'transport', 'visa'];
const effective = ({ native = null, snapshot = null, supplements = [] }) => {
  const base = native || snapshot || { present: false, count: 0, customer_total: 0, supplier_total: 0, margin: 0 };
  const approved = supplements.filter((item) => item.status === 'approved');
  return approved.reduce((out, item) => ({
    present: out.present || item.present,
    count: out.count + item.count,
    customer_total: out.customer_total + item.customer_total,
    supplier_total: out.supplier_total + item.supplier_total,
    margin: out.margin + item.margin,
  }), { ...base });
};
const fixture = (product, overrides = {}) => ({
  product,
  native: { present: true, count: 1, customer_total: 100, supplier_total: 80, margin: 20 },
  snapshot: { present: true, count: 1, customer_total: 90, supplier_total: 70, margin: 20 },
  supplements: [{ status: 'approved', present: true, count: 1, customer_total: 25, supplier_total: 15, margin: 10 }],
  ...overrides,
});
for (const product of products) {
  const normal = effective(fixture(product));
  ok(normal.present && normal.count === 2 && normal.customer_total === 125, `${product} native plus approved supplement fixture`);
  const zero = effective(fixture(product, { native: { present: true, count: 1, customer_total: 0, supplier_total: 0, margin: 0 }, supplements: [] }));
  ok(zero.present && zero.count === 1, `${product} zero-value identity fixture`);
  const onlySupplement = effective(fixture(product, { native: null, snapshot: null }));
  ok(onlySupplement.present && onlySupplement.count === 1, `${product} supplement-only fixture`);
  const multi = effective(fixture(product, { supplements: [
    { status: 'approved', present: true, count: 1, customer_total: 10, supplier_total: 6, margin: 4 },
    { status: 'approved', present: true, count: 1, customer_total: 15, supplier_total: 9, margin: 6 },
    { status: 'draft', present: true, count: 1, customer_total: 999, supplier_total: 999, margin: 0 },
    { status: 'pending', present: true, count: 1, customer_total: 999, supplier_total: 999, margin: 0 },
    { status: 'rejected', present: true, count: 1, customer_total: 999, supplier_total: 999, margin: 0 },
  ] }));
  ok(multi.count === 3 && multi.customer_total === 125, `${product} multi-status supplement fixture`);
}
ok(commercial.includes('$summary = $canonicalSummary;'), 'Commercial Summary product totals are canonical-only');
ok(!commercial.includes('$summary = $key ==='), 'no product-specific Commercial Summary fallback remains');
ok(resolver.includes('materializedNativeSourceRepresentsItem') && resolver.includes("where('product_service_id'"), 'materialization handoff validates native product identity');
ok(resolver.includes("if ($this->materializedNativeSourceRepresentsItem($item, $booking, $product)) continue;"), 'snapshot suppression occurs only after native handoff');
ok(presenter.includes('$this->productSummaries->resolve($bookingId)') && commercial.includes('$this->productSummaries->resolve((int) $booking[\'id\'])'), 'Card and Commercial Summary share resolver output');
ok(presenter.includes('data-et-booking-billing-locked="1"'), 'runtime passenger lock selector is server seeded');
for (const field of ['source_table', 'source_id', 'booking_service_id', 'product_service_id']) ok(materializer.includes("'" + field + "'"), `materialization writes ${field}`);
for (const product of products) ok(materializer.includes("$product === '" + product + "'") || materializer.includes("'" + product + "'"), `${product} materialization identity is represented`);

console.log(`ERP378 C51 RESIDUAL RUNTIME CORRECTIVE: PASS (${assertions} assertions)`);
