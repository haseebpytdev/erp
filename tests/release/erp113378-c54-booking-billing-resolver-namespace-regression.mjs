import fs from 'node:fs';

const read = (p) => fs.readFileSync(p, 'utf8');
const resolverPath = 'app/Services/Operations/BookingBillingEditLockResolver.php';
const resolver = read(resolverPath);
const middlewarePath = 'app/Http/Middleware/EnforceGeneralBookingEditLock.php';
const middleware = read(middlewarePath);
const focused = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const c53 = read('tests/release/erp113378-c53-native-invoice-consistency-ui-regression.mjs');
const invoice = read('app/Services/Sales/SalesInvoiceService.php');
const review = read('app/Http/Controllers/Operations/GeneralBookingReviewController.php');
const scope = read('app/Services/Sales/BaseBookingInvoiceScopeResolver.php');
const consistency = read('app/Services/Sales/BaseSalesInvoiceConsistencyResolver.php');
const classifier = read('app/Services/Sales/BookingSalesInvoiceScopeResolver.php');
const coordinator = read('app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php');
const config = read('config/et_erp_release.php');

let assertions = 0;
const ok = (value, message) => { assertions += 1; if (!value) throw new Error(message); };

ok(resolver.includes('namespace App\\Services\\Operations;'), 'resolver namespace is authoritative');
ok(resolver.includes('final class BookingBillingEditLockResolver'), 'authoritative resolver exists');
ok(middleware.includes('use App\\Services\\Operations\\BookingBillingEditLockResolver;'), 'middleware imports service resolver');
ok(middleware.includes('private readonly BookingBillingEditLockResolver $billingLocks'), 'middleware consumes imported resolver');
ok(!middleware.includes('use App\\Http\\Middleware\\BookingBillingEditLockResolver;'), 'incorrect middleware import is absent');
ok(!resolver.includes('namespace App\\Http\\Middleware;'), 'resolver is not duplicated under middleware namespace');
ok(focused.includes('BaseSalesInvoiceConsistencyResolver'), 'native invoice presentation remains C53-aware');
ok(focused.includes('data-et-base-invoice-consistency="ERP-11.3.378-C53"'), 'C53 out-of-sync marker remains');
ok(focused.includes('suppressNativeSubmit'), 'C53 native Submit suppression remains');
ok(focused.includes("getByName('sales.invoices.submit')"), 'C53 exact submit route targeting remains');
ok(!focused.includes('Cancel Draft Invoice'), 'C53 does not remove Cancel Draft UI');
ok(invoice.includes('guardBaseConsistency($invoice)'), 'C52 guard remains in invoice service');
ok((invoice.match(/guardBaseConsistency\(\$invoice\)/g) || []).length === 3, 'submit approve post remain guarded');
ok(review.includes('$billingLocks->resolve($booking)'), 'booking review billing lock remains');
ok(scope.includes('class BaseBookingInvoiceScopeResolver'), 'base scope logic remains');
ok(consistency.includes('class BaseSalesInvoiceConsistencyResolver'), 'base consistency logic remains');
ok(classifier.includes("link_type', 'supplementary"), 'supplementary classification remains');
ok(coordinator.includes('createFromBookingServices'), 'supplementary coordinator remains');
ok(c53.includes('Cancel Draft Invoice'), 'C53 regression preserves cancellation contract');
ok(c53.includes('Save Draft'), 'C53 regression preserves draft save contract');
ok(c53.includes('supplementary'), 'C53 regression covers supplementary isolation');
ok(!read('config/et_erp_release.php').includes('C54'), 'no asset bump was introduced');
ok(/'asset_version' => 'ERP-11\.3\.378-C(?:68|69)'/.test(config), 'asset version remains current');
ok(!read('public/erp11390/general-progressive-step1.js').includes('C54'), 'public assets remain unchanged');

const badNamespace = [resolverPath, middlewarePath, 'app/Services/Operations/BookingWorkspaceShellPresenter.php']
  .map(read)
  .join('\n')
  .match(/App\\Http\\Middleware\\BookingBillingEditLockResolver/g) || [];
ok(badNamespace.length === 0, 'no application source uses the bad namespace');

console.log(`C54 booking billing resolver namespace regression: PASS (${assertions} assertions)`);
