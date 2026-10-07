import fs from 'node:fs';

const read = (p) => fs.readFileSync(p, 'utf8');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const inspector = read('app/Services/Operations/NativeSalesInvoiceInspector.php');
const controller = read('app/Http/Controllers/Sales/StableBookingSalesInvoiceController.php');
const service = read('app/Services/Sales/SalesInvoiceService.php');
const scope = read('app/Services/Sales/BaseBookingInvoiceScopeResolver.php');
const classifier = read('app/Services/Sales/BookingSalesInvoiceScopeResolver.php');
const consistency = read('app/Services/Sales/BaseSalesInvoiceConsistencyResolver.php');
const c53 = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const c54 = read('app/Http/Middleware/EnforceGeneralBookingEditLock.php');
const coordinator = read('app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php');

let assertions = 0;
const ok = (value, message) => { assertions += 1; if (!value) throw new Error(message); };

ok(bridge.includes('$this->invoices->activeBase($bookingId)'), 'creation uses active BASE duplicate authority');
ok(controller.includes('$this->invoices->activeBase('), 'base route ignores supplementary-only invoices');
ok(!bridge.includes("$invoiceSummary['all_count']"), 'historical all-count is not a permanent blocker');
ok(bridge.includes('$this->creator->create($request, $bookingId)'), 'fresh creation still delegates to native creator');
ok(inspector.includes('public function activeBase'), 'active BASE resolver exists');
ok(inspector.includes("scope($model) === 'base'"), 'active BASE resolver excludes supplementary invoices');
ok(inspector.includes("['active_invoices']"), 'only active native statuses are considered');
ok(inspector.includes('SalesInvoice::query()->find($id)'), 'invoice model is resolved for scope classification');
ok(inspector.includes('BookingSalesInvoiceScopeResolver'), 'existing supplementary classifier is reused');
ok(/\['cancelled'\s*,\s*'canceled'\s*,\s*'void'\s*,\s*'voided'\s*,\s*'rejected'\]/.test(inspector), 'terminal history remains excluded from active set');
ok(!bridge.includes('historical Sales Invoice already exists'), 'historical-only blocker message is removed from creation path');
ok(service.includes('public function createFromBooking'), 'native base creator remains the service authority');
ok(service.includes('baseScope->resolve($booking)'), 'base creation still uses current base scope');
ok(service.includes('baseScope->resolve($lockedBooking)'), 'base scope is revalidated after locking');
ok(service.includes('public function createFromBookingServices'), 'supplementary creator remains available');
ok(scope.includes('supplementaryServiceIds'), 'supplementary materialized service IDs remain excluded from base scope');
ok(classifier.includes("link_type', 'supplementary"), 'supplementary link semantics remain separate');
ok(consistency.includes('class BaseSalesInvoiceConsistencyResolver'), 'C52 consistency authority remains');
ok(c53.includes('data-et-base-invoice-consistency="ERP-11.3.378-C53"'), 'C53 native warning remains');
ok(c53.includes('suppressNativeSubmit'), 'C53 native submit suppression remains');
ok(c54.includes('use App\\Services\\Operations\\BookingBillingEditLockResolver;'), 'C54 namespace fix remains');
ok(coordinator.includes('createFromBookingServices'), 'supplementary coordinator remains scoped');
ok(!service.includes('general_booking_invoice_links'), 'base service does not collapse supplementary scope');

console.log(`C55 historical base invoice recreation regression: PASS (${assertions} assertions)`);
