import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const resolver = read('app/Services/Operations/BookingProductSummaryResolver.php');
const commercial = read('app/Services/Operations/GeneralBookingCommercialSummaryResolver.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const review = read('app/Http/Controllers/Operations/GeneralBookingReviewController.php');
const blade = read('resources/views/operations/bookings/general-booking-review-v113160.blade.php');
const billing = read('app/Services/Operations/BookingBillingEditLockResolver.php');
const editMiddleware = read('app/Http/Middleware/EnforceGeneralBookingEditLock.php');
const routes = read('routes/erp103179.php');
const c49a = read('tests/release/erp113378-c49a-shared-supplementary-product-workspaces-regression.mjs');
const c49b = read('tests/release/erp113378-c49b-product-card-consistency-regression.mjs');
const c49c = read('tests/release/erp113378-c49c-supplementary-invoice-lifecycle-regression.mjs');

let assertions = 0;
const ok = (value, message) => { assertions++; assert.ok(value, message); };

ok(resolver.includes("'visa' => $this->summary($booking, 'visa')"), 'Visa uses lightweight canonical resolver');
ok(resolver.includes('serviceSnapshotSummary') && resolver.includes('hasSourceIdentity'), 'legacy service snapshot fallback exists');
for (const field of ['sale_pkr','customer_total','selling_total','sale_total','total_sale','customer_amount','sale_amount','selling_amount','gross_sale','selling_price','sale_price','customer_price','receivable_amount']) ok(resolver.includes(`'${field}'`), `Visa sale alias ${field} supported`);
for (const field of ['vendor_cost_pkr','supplier_total','vendor_total','cost_total','total_cost','vendor_amount','cost_amount','supplier_amount','gross_cost','net_supplier_cost','supplier_cost','supplier_cost_amount','net_cost','purchase_cost','purchase_price']) ok(resolver.includes(`'${field}'`), `Visa cost alias ${field} supported`);
ok(resolver.includes("'present' => $rows->isNotEmpty()") && resolver.includes("'present' => $count > 0"), 'source presence is explicit');
ok(resolver.includes("$product === 'visa'" ) && resolver.includes('abs($sale) > 0.00001'), 'zero-only Visa canonical totals fall back to history');
ok(resolver.includes("$product === 'visa' && (array_key_exists('sale_pkr', $row) || array_key_exists('vendor_cost_pkr', $row))"), 'Visa legacy identity is retained even when totals are zero');
ok(resolver.includes("'present' => (bool) ($native['present'] ?? false) || $supplementCount > 0"), 'approved supplement presence is merged');
ok(commercial.includes("$canonicalSummary = (array) ($canonical[$key] ?? [])") && commercial.includes("($canonicalSummary['present'] ?? false) ? $canonicalSummary : $snapshotSummary"), 'empty canonical summary cannot erase valid historical snapshot');
ok(!commercial.includes('$canonical[$key] ?? $snapshot'), 'commercial resolver no longer blindly prefers empty canonical data');
ok(presenter.includes('$workflow = $this->bookingLocks->resolve($bookingId)') && presenter.includes("'status' => (string) ($workflow['status'] ?? 'Draft')"), 'workflow status remains badge authority');
ok(presenter.includes("$reason = trim((string) ($lock['reason'] ?? ''))") && presenter.includes('if ($reason !== \'\') return $reason;'), 'billing lock reason is shown verbatim');
ok(presenter.includes("'billing_locked' => true") && presenter.includes("'billing_code'"), 'billing lock remains separately exposed');
ok(presenter.includes("data-et-booking-billing-locked") && presenter.includes("data-et-booking-lock-reason"), 'billing lock state reaches the runtime shell');
ok(review.includes('BookingBillingEditLockResolver') && review.includes('$billingLock = $billingLocks->resolve($booking)'), 'review view receives billing lock authority');
ok(blade.includes("$approvalStatus==='Approved'&&$canReopen&&!($billingLock['locked']??false)"), 'billing-locked approved booking has no reopen action');
ok(billing.includes('Draft Sales Invoice') && billing.includes('active Sales Invoice'), 'operator-facing billing reasons remain defined');
ok(editMiddleware.includes('BookingBillingEditLockResolver') && editMiddleware.includes('booking_billing_locked'), 'server mutation guard remains centralized');
ok(routes.includes('quick-add') && routes.includes('quick-master-update') && routes.includes('fare-type'), 'passenger write routes remain explicit');
ok(routes.includes('EnforceGeneralBookingEditLock'), 'booking writes retain server edit-lock middleware');
ok(c49a.includes('ProductWorkspaceContext') && c49b.includes('approved supplementary'), 'C49A/B contracts remain protected');
ok(c49c.includes('BookingBillingEditLockResolver') && c49c.includes('billing'), 'C49C billing lifecycle remains protected');
ok(!fs.readdirSync(path.join(root, 'database/migrations')).some((name) => name.toLowerCase().includes('c50')), 'no C50 migration added');
ok(!resolver.includes('GeneralBookingVisaProductController::show'), 'no full Visa controller fan-out');
ok(!resolver.includes('sales_invoices'), 'product resolver remains read-only');

console.log(`ERP378 C50 VISA LEGACY/BILLING LOCK: PASS (${assertions} assertions)`);
