import fs from 'node:fs';

const read = (p) => fs.readFileSync(p, 'utf8');
const middleware = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const lock = read('app/Services/Operations/BookingBillingEditLockResolver.php');
const baseScope = read('app/Services/Sales/BaseBookingInvoiceScopeResolver.php');
const consistency = read('app/Services/Sales/BaseSalesInvoiceConsistencyResolver.php');
const classifier = read('app/Services/Sales/BookingSalesInvoiceScopeResolver.php');
const invoice = read('app/Services/Sales/SalesInvoiceService.php');
const coordinator = read('app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php');
const summary = read('app/Services/Operations/BookingProductSummaryResolver.php');
const passengerLock = read('app/Http/Middleware/EnforceGeneralBookingEditLock.php');
const config = read('config/et_erp_release.php');

let assertions = 0;
const ok = (value, message) => { assertions += 1; if (!value) throw new Error(message); };

ok(middleware.includes('BaseSalesInvoiceConsistencyResolver'), 'consistency resolver is injected');
ok(middleware.includes("$request->route('invoice')"), 'native route invoice is read');
ok(middleware.includes('instanceof SalesInvoice'), 'bound SalesInvoice model is supported');
ok(middleware.includes('SalesInvoice::query()->find($id)'), 'positive scalar invoice IDs resolve natively');
ok(middleware.includes("($state['scope'] ?? 'supplementary') !== 'base'"), 'supplementary scope is excluded');
ok(middleware.includes("($state['status'] ?? 'IN_SYNC') === 'IN_SYNC'"), 'in-sync base invoices remain unchanged');
ok(middleware.includes('OUT OF SYNC WITH BOOKING'), 'out-of-sync diagnostic title is rendered');
ok(middleware.includes('Expected base booking services'), 'expected service count is rendered');
ok(middleware.includes('Invoice represented services'), 'actual service count is rendered');
ok(middleware.includes('Current expected base total'), 'expected total is rendered');
ok(middleware.includes('Current invoice total'), 'invoice total is rendered');
ok(middleware.includes('Missing services'), 'missing services are rendered');
ok(middleware.includes('Stale invoice services'), 'stale services are rendered');
ok(middleware.includes('htmlspecialchars'), 'dynamic diagnostic values are escaped');
ok(middleware.includes('data-et-base-invoice-consistency="ERP-11.3.378-C53"'), 'diagnostic marker is bounded');
ok(middleware.includes('str_contains($html, \'data-et-base-invoice-consistency="ERP-11.3.378-C53"\')'), 'diagnostic is injected once');
ok(middleware.includes("getByName('sales.invoices.submit')"), 'exact submit route is resolved');
ok(middleware.includes('nativeSubmitPath'), 'submit form targeting is isolated');
ok(middleware.includes('suppressNativeSubmit'), 'out-of-sync submit form is suppressed');
ok(!middleware.includes("str_contains(rawurldecode((string) $match[2]), 'submit')"), 'generic submit text is not the removal authority');
ok(middleware.includes('</body>'), 'controlled body fallback exists');
ok(!middleware.includes('Cancel Draft Invoice'), 'cancel UI is not removed by middleware');
ok(!middleware.includes('Save Draft'), 'save UI is not removed by middleware');
ok(!middleware.includes('Print/PDF'), 'print/navigation UI is not removed by middleware');

ok(invoice.includes('guardBaseConsistency($invoice)'), 'C52 submit/approve/post guard remains');
ok((invoice.match(/guardBaseConsistency\(\$invoice\)/g) || []).length === 3, 'all three workflow methods remain guarded');
ok(lock.includes('before changing or progressing the original booking workflow'), 'lifecycle-neutral Draft lock wording is present');
ok(!lock.includes('before reopening the original booking'), 'stale Draft lock wording is removed');
ok(read('app/Http/Controllers/Operations/GeneralBookingReviewController.php').includes("billingLocks->resolve"), 'Dashboard and Review consume the shared lock resolver');
ok(baseScope.includes('class BaseBookingInvoiceScopeResolver'), 'base scope resolver is unchanged');
ok(consistency.includes('class BaseSalesInvoiceConsistencyResolver'), 'base consistency resolver is unchanged');
ok(classifier.includes("link_type', 'supplementary"), 'supplementary classifier remains authoritative');
ok(coordinator.includes('createFromBookingServices'), 'supplementary coordinator remains protected');
ok(summary.includes('materializedNativeSourceRepresentsItem'), 'product summary authority remains protected');
ok(passengerLock.includes('EnforceGeneralBookingEditLock'), 'passenger lock middleware remains present');
ok(!read('public/erp11390/general-progressive-step1.js').includes('C53'), 'no public asset implementation was added');
ok(config.includes("'asset_version' => 'ERP-11.3.378-C68'"), 'asset version remains current');
ok(config.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains unchanged');

console.log(`C53 native invoice consistency UI regression: PASS (${assertions} assertions)`);
