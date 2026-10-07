import fs from 'node:fs';

const read = (p) => fs.readFileSync(p, 'utf8');
const verifier = read('app/Services/Operations/NativeSalesInvoiceCreationVerifier.php');
const inspector = read('app/Services/Operations/NativeSalesInvoiceInspector.php');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const service = read('app/Services/Sales/SalesInvoiceService.php');
const scope = read('app/Services/Sales/BaseBookingInvoiceScopeResolver.php');
const classifier = read('app/Services/Sales/BookingSalesInvoiceScopeResolver.php');
const consistency = read('app/Services/Sales/BaseSalesInvoiceConsistencyResolver.php');
const c54 = read('app/Http/Middleware/EnforceGeneralBookingEditLock.php');
const c53 = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');

let assertions = 0;
const ok = (value, message) => { assertions += 1; if (!value) throw new Error(message); };

ok(verifier.includes('activeBaseInvoices($bookingId)'), 'verifier uses active BASE cardinality authority');
ok(!verifier.includes("$summary['all_count']"), 'verifier no longer requires lifetime all_count');
ok(verifier.includes('$activeBaseCount = count($activeBaseInvoices)'), 'verifier counts active BASE invoices');
ok(verifier.includes('$activeBaseCount === 0'), 'zero active BASE fails closed');
ok(verifier.includes('$activeBaseCount > 1'), 'more than one active BASE fails closed');
ok(inspector.includes('public function activeBaseInvoices(int $bookingId): array'), 'cardinality helper exists');
ok(inspector.includes("['active_invoices']"), 'cardinality helper starts from active invoice candidates');
ok(/\['cancelled'\s*,\s*'canceled'\s*,\s*'void'\s*,\s*'voided'\s*,\s*'rejected'\]/.test(inspector), 'terminal statuses remain excluded');
ok(inspector.includes("scope($model) === 'base'"), 'supplementary invoices are excluded from BASE cardinality');
ok(classifier.includes("link_type', 'supplementary"), 'supplementary link semantics remain authoritative');
ok(verifier.includes('bookingColumn') && verifier.includes('!==$bookingId'), 'booking ownership verification remains');
ok(verifier.includes('customerColumn') && verifier.includes('!==$customerId'), 'customer verification remains');
ok(verifier.includes('expectedTotal') && verifier.includes('totalMismatch'), 'amount verification remains');
ok(verifier.includes('lineCount<max(1,$minimumLines)'), 'service-line count verification remains');
ok(verifier.includes('lineSummary($table,$invoiceId)'), 'line-total verification remains');
ok(bridge.includes('$this->invoices->activeBase($bookingId)'), 'C55 pre-create active BASE guard remains');
ok(service.includes('public function createFromBooking'), 'native BASE creator remains');
ok(service.includes('baseScope->resolve($lockedBooking)'), 'BASE scope resolver remains in native flow');
ok(!service.includes('general_booking_invoice_links'), 'native BASE service does not absorb supplementary links');
ok(c54.includes('use App\\Services\\Operations\\BookingBillingEditLockResolver;'), 'C54 namespace correction remains');
ok(c53.includes('data-et-base-invoice-consistency="ERP-11.3.378-C53"'), 'C53 consistency protection remains');
ok(scope.includes('supplementaryServiceIds'), 'C52 supplementary service exclusion remains');
ok(service.includes('public function createFromBookingServices'), 'supplementary creation method remains');

console.log(`C56 post-create verifier regression: PASS (${assertions} assertions)`);
