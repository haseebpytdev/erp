import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Operations/ProductWorkspaceController.php');
const view = read('resources/views/operations/bookings/product-workspace-v113305.blade.php');
const runtime = read('public/erp11390/general-progressive-step1.js');
const bookingFocus = read('public/erp11335/booking-focus.js');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const metadata = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
let pass = 0;
const ok = (value, message) => { assert.ok(value, message); pass++; };

for (const product of ['air', 'hotel', 'transport', 'visa', 'other-services']) {
  ok(routes.includes("'/operations/bookings/{booking}/products/{product}'") && routes.includes("whereIn('product', ['air', 'hotel', 'transport', 'visa', 'other-services'])"), `${product} dedicated GET route exists`);
  ok(routes.includes("->name('bookings.products.workspace')"), `${product} route has a stable name`);
}
ok(controller.includes("private const PRODUCTS = ['air', 'hotel', 'transport', 'visa', 'other-services'];"), 'controller uses one strict product allowlist');
ok(controller.includes("product-workspace-v113305"), 'all products use one shared workspace view');
ok(view.includes('data-etgp-dedicated-product="1"') && view.includes('data-etgp-product-key'), 'shared view exposes neutral product mount metadata');
ok(view.includes("url('/operations/bookings/'.$bookingId)") && view.includes("route('bookings.review.show"), 'dedicated pages provide direct Back and Review links');
for (const [key, renderer] of [['air', 'renderAirProductWorkspace113106'], ['hotel', 'renderHotelProductWorkspace113127'], ['transport', 'renderTransportProductWorkspace113139'], ['visa', 'renderVisaProductWorkspace113142']]) {
  ok(key === 'visa' ? runtime.includes("else renderVisaProductWorkspace113142(host)") : (runtime.includes(`if(key==='${key}')`) || runtime.includes(`else if(key==='${key}')`)), `${key} has a dedicated mount branch`);
  ok(runtime.includes(renderer), `${key} renderer remains the existing runtime owner`);
}
ok(runtime.includes('window.etgpMountDedicatedProduct113305'), 'dedicated mount is an executable shared runtime entry point');
ok(runtime.includes("etBookingWorkspaceContext113305.setRoot(root,root.dataset.bookingReference||'')"), 'context initializes from dedicated root');
ok(runtime.includes('resolved>0') && runtime.includes('state.root'), 'context falls back to dedicated root booking ID when URL authority is unavailable');
ok(runtime.includes('products(?:\\/(?:air|hotel|transport|visa|other-services))?'), 'booking ID resolver accepts every dedicated product URL');
ok(runtime.includes('etgpSeedInitialBookingLock113162()'), 'dedicated mount seeds existing lock authority');
ok(runtime.includes("fetch(api.getApiBase()+'/'+String(id)+'/air-product'"), 'passenger loading remains structured and server-backed');
ok(presenter.includes("products(?:/(?:air|hotel|transport|visa|other-services))?"), 'focused presenter recognizes dedicated product paths');
ok(presenter.includes("$this->addHtmlClass($html, 'et-general-progressive-step1-11390')") && presenter.includes('if ($isProductsWorkspacePath)'), 'dedicated paths receive the progressive runtime html class independently of visible GENERAL text');
ok(metadata.includes("products/(?:air|hotel|transport|visa|other-services)"), 'asset role classification recognizes dedicated product paths');
ok(view.includes('Operational workspace not configured yet.'), 'Other Services is an honest placeholder without fake persistence');
ok(!view.includes('data-etgp-product-buttons'), 'dedicated pages do not render aggregate product cards');
ok(routes.includes("Route::get('/operations/bookings/{booking}/products', [BookingProductsHubController::class, 'show'])"), 'aggregate Products route remains preserved');
ok(!controller.includes('DB::table(\'booking_services\')->insert') && !controller.includes('DB::table(\'bookings\')->update'), 'workspace controller performs no product or lifecycle persistence');
ok(presenter.includes('data-et-booking-products-launcher="1"') && presenter.includes('margin:18px 0'), 'main Booking launcher is normal in-document content');
const launcher = presenter.slice(presenter.indexOf('data-et-booking-products-launcher="1"') - 120, presenter.indexOf('data-et-booking-products-launcher="1"') + 900);
ok(!launcher.includes('position:fixed') && !launcher.includes('position:absolute'), 'main Booking launcher is not a floating overlay');
ok(view.includes("config('et_erp_release.version") && !view.includes('?v=11.3.305'), 'dedicated assets use current release metadata rather than a stale hard-coded version');
ok(controller.includes('string $product') && controller.includes('in_array($product, self::PRODUCTS, true)'), 'route product parameter is received and strictly validated by the controller');
ok(view.includes('data-etgp-booking-context="1"') && view.includes('etgp-toolbar'), 'dedicated pages reuse the native progressive Booking header authority');
ok(view.includes('Client Preview') && view.includes('Booking Register'), 'focused Booking header keeps native actions');
ok(presenter.includes('data-et-booking-focus-shell="ERP-11.3.75"') && bookingFocus.includes("menu.textContent='☰ Menu'") && bookingFocus.includes('toggleMenu'), 'existing focused-shell runtime owns the Menu action');
ok(view.includes('GENERAL / MULTI-SERVICE') && view.includes("$customer['name']"), 'focused header exposes booking type and customer context');
ok(!view.includes('et-booking-focus-context') && !view.includes('Dashboard') && !view.includes('Sales Invoice'), 'dedicated product pages avoid a duplicate generic or manual Booking header');
console.log(`erp113307-dedicated-product-pages-regression: ${pass} assertions passed`);
