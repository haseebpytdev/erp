import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const transport = read('app/Http/Controllers/Operations/GeneralBookingTransportProductController.php');
const apiShowSource = controller.split('public function apiStore')[0];
const transportShowSource = transport.split('public function activeServiceId')[0];
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const partial = read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php');
const productView = read('resources/views/operations/bookings/additional-services/product.blade.php');
const js = read('public/erp11390/general-progressive-step1.js');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const release = read('config/et_erp_release.php');

const products = ['air', 'hotel', 'transport', 'visa'];
products.forEach(product => {
  ok(routes.includes("{product}-product") && routes.includes("whereIn('product', ['air','hotel','transport','visa'])"), `${product} API argument binding`);
  ok(js.includes(`additional-services/'+batch+'/'+product+'-product`), `${product} supplementary URL adapter`);
});
ok(controller.includes('private const PRODUCTS') && controller.includes('productKey(string $product)') && controller.includes('abort_unless(in_array($product, self::PRODUCTS, true), 404)'), 'unknown product fails closed');
ok(presenter.includes("additional-services/\\d+/products/(?:air|hotel|transport|visa)") && presenter.includes('et-booking-products-prepaint'), 'supplementary product path receives native prepaint');
ok(presenter.includes('data-et-dedicated-product-core') && presenter.includes('data-et-general-progressive-css'), 'supplementary product assets remain native');
ok(presenter.includes('$supplementaryWritable ? \'0\' : \'1\'') && partial.includes('$supplementaryWritable ? \'0\' : \'1\''), 'base lock is not reused as supplementary editor lock');
ok(partial.includes('data-etgp-dedicated-product="1"') && productView.includes('etgpMountDedicatedProduct113305'), 'one native renderer and shared product shell');
ok(manager.includes("=== 'supplementary'") && manager.includes("=== 'draft'") && manager.includes('general_booking_invoice_links'), 'supplementary draft write guards remain batch scoped');
ok(!apiShowSource.includes('catch (Throwable') && !apiShowSource.includes("if ($product !== 'transport') throw $exception"), 'supplementary GET does not swallow native Throwable failures');
ok(transport.includes('public function show(') && transport.includes("$table ? $this->transportRows") && transport.includes("'transports' => $rows"), 'native transport GET returns an empty-safe read payload');
ok(transportShowSource.includes('findTransportService') && transportShowSource.includes('$table ? $this->transportRows') && !transportShowSource.includes('ensureTransportService('), 'empty transport GET requires no native row or master creation');
ok(js.includes('etgpIsSupplementaryDraftWorkspace113305') && js.includes("getBillingContext() === 'SUPPLEMENTARY'") && js.includes('locked=supplementaryWorkspace?!supplementary'), 'supplementary runtime lock remains unlocked while draft');
ok(js.indexOf('etBookingWorkspaceContext113305.setRoot(root,root.dataset.bookingReference||\'\')') < js.indexOf('etgpSeedInitialBookingLock113162();', js.indexOf('window.etgpMountDedicatedProduct113305')), 'dedicated runtime seeds lock after supplementary context is established');
ok(js.includes('etgpApplyBookingLock113162') && js.includes('if(!supplementaryWorkspace&&data&&data.booking_status)'), 'original lock behavior remains status-authoritative');
ok(!controller.includes('->store($request, $booking)') && !controller.includes('ensureTransportService($booking'), 'GET performs no native transport write/master creation');
ok(js.includes("return '/system/erp-bookings/'+bookingId+'/'+product+'-product'+suffix"), 'original endpoints preserved');
ok(partial.includes('data-billing-context') && partial.includes('data-billing-batch-id') && partial.includes('data-billing-writable') && partial.includes('batch'), 'batch identity and server writable authority remain explicit');
ok(!partial.includes('shared-product-entry-fields') && !productView.includes('shared-product-entry-fields'), 'old generic supplementary form remains absent');
ok(!routes.includes('database/migrations') && !release.includes('C63'), 'no migration/schema or application version change');
ok(manager.includes("'writable' => strtolower((string) $batch->batch_type) === 'supplementary'") && controller.includes("'writable' => (bool) ($state['writable'] ?? false)"), 'supplementary writable state originates from server batch state');
ok(manager.includes('Only a supplementary Draft batch can be edited.') && manager.includes('Invoiced batches are read-only.') && manager.includes('Supplementary batch does not belong to this booking.'), 'server draft, invoice-lock and ownership guards remain authoritative');
ok(presenter.includes('data-et-booking-billing-writable') && js.includes('supplementaryWorkspace?!supplementary'), 'non-writable supplementary batches remain locked');
ok(js.includes("dataset.billingWritable||'0'"), 'supplementary plus batch ID alone is insufficient for runtime unlock');
ok(/'asset_version' => 'ERP-11\.3\.378-C(?:68|69)'/.test(release), 'public asset version is current for the runtime JS guard');

console.log('PASS ' + pass + ' ERP-11.3.378 C63 supplementary product runtime and shell assertions');
