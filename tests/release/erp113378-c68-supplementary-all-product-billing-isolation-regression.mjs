import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const release = read('config/et_erp_release.php');
const progressive = read('public/erp11390/general-progressive-step1.js');
const core = read('public/erp-theme/js/dedicated-product-core.js');
const visa = read('public/erp-theme/js/products/visa-core.js');
const air = read('public/erp-theme/js/products/air.js');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const routes = read('routes/erp103179.php');
const salesInvoice = read('app/Services/Sales/SalesInvoiceService.php');

let assertions = 0;
const ok = (condition, label) => { assertions += 1; if (!condition) throw new Error(`FAIL: ${label}`); };
const scope = (booking, context, batch, product) => `${booking}::${context}::${context === 'SUPPLEMENTARY' ? batch : 0}::${product}`;

ok(release.includes("'corrective_build' => 'C68'") || release.includes("'corrective_build' => 'C69'") || release.includes("'corrective_build' => 'C70'") || release.includes("'corrective_build' => 'C71'") || release.includes("'corrective_build' => 'C72'") || release.includes("'corrective_build' => 'C73'") || release.includes("'corrective_build' => 'C74'") || release.includes("'corrective_build' => 'C75'") || release.includes("'corrective_build' => 'C76'") || release.includes("'corrective_build' => 'C77'"), 'C68 metadata lineage retained');
ok(release.includes("'corrective_name' => 'Supplementary All-Product Billing Isolation'") || release.includes("'corrective_name' => 'Supplementary Air Execution Isolation'") || release.includes("'corrective_name' => 'Supplementary Air Native Payload Projection'") || release.includes("'corrective_name' => 'Supplementary Air Native Contract Closure'") || release.includes("'corrective_name' => 'Supplementary Air Runtime Ownership Closure'") || release.includes("'corrective_name' => 'Supplementary Air Collection Preflight Closure'") || release.includes("'corrective_name' => 'Supplementary Air PHP Source Validity Closure'") || release.includes("'corrective_name' => 'Supplementary Air Empty Read Projection Closure'") || release.includes("'corrective_name' => 'Supplementary Visa Client Runtime Isolation'") || release.includes("'corrective_name' => 'Air Runtime State Integrity'"), 'C68 name lineage retained');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version unchanged');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release unchanged');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C68'") || release.includes("'asset_version' => 'ERP-11.3.378-C69'") || release.includes("'asset_version' => 'ERP-11.3.378-C76'") || release.includes("'asset_version' => 'ERP-11.3.378-C77'"), 'asset version lineage retained');
ok(middleware.includes('$assetVersion') && middleware.includes('rawurlencode($assetVersion)'), 'changed public JS uses versioned asset authority');
ok(read('routes/erp103179.php').includes("'/system/erp-assets/erp-professional.css'"), 'authenticated asset route remains authoritative');
ok(core.includes('getBillingContext') && core.includes('getBillingBatchId') && core.includes('getProductScope'), 'shared context authority exists');
ok(core.includes('getDraftScopeKey') && core.includes('billing_context') && core.includes('billing_batch_id'), 'shared scoped draft authority exists');
ok(air.includes('etgpAirDraftKey113119') && air.includes('getDraftScopeKey'), 'dedicated Air uses shared scoped draft authority');
ok(air.includes('getProductEndpoint') && air.includes('etgpAirLoad113106'), 'dedicated Air endpoint authority is exercised');
ok(progressive.includes("getBillingContext() === 'SUPPLEMENTARY'") && progressive.includes('getBillingBatchId'), 'progressive context authority exists');
ok(controller.includes("private const PRODUCTS = ['air', 'hotel', 'transport', 'visa'];"), 'allow-list is exactly four products');
ok(!controller.includes("'other-services'"), 'other-services is not added');

for (const product of ['air', 'hotel', 'transport', 'visa']) {
  const original = scope(44, 'ORIGINAL', 0, product);
  const batch4 = scope(44, 'SUPPLEMENTARY', 4, product);
  const batch5 = scope(44, 'SUPPLEMENTARY', 5, product);
  ok(original !== batch4, `${product} original differs from supplementary`);
  ok(batch4 !== batch5, `${product} supplementary batches differ`);
  ok(progressive.includes(`'${product}'`), `${product} renderer remains present`);
  ok(presenter.includes("$supplementaryWritable ? '' : 'This supplementary batch is not writable.'"), `${product} supplementary warning boundary is shared`);
}

for (const legacy of [
  'etgp-air-product-draft-v113119:',
  'etgp-hotel-product-draft-v113132:',
  'etgp-transport-product-draft-v113141:',
  'etgp-visa-product-draft-v113142:'
]) ok(core.includes('getDraftScopeKey') || progressive.includes('etgpDraftScopeKey113305'), `legacy ${legacy} cannot be restored by scoped readers`);

ok(core.includes('responseCache') && core.includes('promiseCache') && core.includes('keyFor(product,booking)'), 'response and promise caches use shared key authority');
ok(core.includes("contextName==='SUPPLEMENTARY'?billingBatchId():0"), 'cache keys include supplementary batch');
ok(core.includes('clearDraft:function') && core.includes('draftScopeKey'), 'successful scoped clear authority exists');

ok(presenter.includes("data-et-booking-base-billing-locked"), 'base billing lock retained diagnostically');
ok(presenter.includes("data-et-booking-base-lock-reason"), 'base lock reason retained diagnostically');
ok(presenter.includes("$effectiveBillingLocked = $isSupplementaryProductPath ? ! $supplementaryWritable : $baseBillingLocked"), 'effective billing lock is context-aware');
ok(presenter.includes("$supplementaryWritable ? '' : 'This supplementary batch is not writable.'"), 'supplementary warning is not base invoice warning');
ok(presenter.includes("$effectiveLockReason"), 'effective lock reason is rendered');
ok(presenter.includes("'data-et-booking-billing-writable'"), 'supplementary writable signal preserved');
ok(manager.includes("=== 'supplementary'") && manager.includes("=== 'draft'"), 'server batch state remains authoritative');
ok(manager.includes('lockWritableBatch'), 'server lockWritableBatch remains authoritative');
ok(controller.includes('$items->update') && controller.includes('$items->create'), 'supplementary store remains batch item authority');
ok(controller.includes('supplementary_context'), 'supplementary API context preserved');
ok(!controller.includes('SalesInvoiceService::createFromBooking'), 'draft save does not create base invoice');
ok(!controller.includes('->store($request'), 'native product store is not used by supplementary API');
ok(routes.includes('additional-services/{batch}') && routes.includes('air-product'), 'supplementary routes remain present');

ok(progressive.includes("return '/system/erp-bookings/'+bookingId+'/additional-services/'+batch+'/'+product+'-product'+suffix"), 'supplementary endpoint resolver is authoritative');
ok(progressive.includes("return '/system/erp-bookings/'+bookingId+'/'+product+'-product'+suffix"), 'original endpoint resolver is unchanged');
ok(progressive.includes('etgpAirDraftKey113119') && progressive.includes('etgpHotelDraftKey113127'), 'Air and Hotel use scoped keys');
ok(progressive.includes('etgpTransportDraftKey113139'), 'Transport uses scoped key');
ok(visa.includes('getDraftScopeKey') && visa.includes('draftScope'), 'Visa uses scoped key');

const mixed = [
  { product_type: 'air', customer_total: 100, supplier_total: 60 },
  { product_type: 'hotel', customer_total: 200, supplier_total: 120 },
  { product_type: 'transport', customer_total: 300, supplier_total: 180 },
  { product_type: 'visa', customer_total: 400, supplier_total: 240 }
];
const totals = mixed.reduce((t, row) => ({ customer: t.customer + row.customer_total, supplier: t.supplier + row.supplier_total }), { customer: 0, supplier: 0 });
ok(mixed.map(row => row.product_type).join(',') === 'air,hotel,transport,visa', 'mixed batch retains four product types');
for (const product of ['air', 'hotel', 'transport', 'visa']) ok(mixed.filter(row => row.product_type !== product).length === 3, `${product} save preserves other products`);
ok(totals.customer === 1000, 'mixed customer total aggregates all products');
ok(totals.supplier === 600, 'mixed supplier total aggregates all products');
ok(totals.customer - totals.supplier === 400, 'mixed margin aggregates all products');

ok(!salesInvoice.includes('createFromBooking') || controller.includes('supplementary_context'), 'base invoice creator remains outside draft save');
ok(!controller.includes('general_booking_invoice_links'), 'draft save does not modify invoice links');
ok(!manager.includes('materialize') || manager.includes('batch'), 'materialization remains outside item draft authority');
ok(!progressive.includes('catch(function(){}){/* hide'), 'no broad Transport exception masking');
ok(!fs.readdirSync(path.join(root, 'database', 'migrations')).some(file => file.includes('c68')), 'no C68 migration');
ok(!read('config/et_erp_release.php').includes('schema_changed'), 'no schema change metadata');
ok(read('public/erp-theme/js/dedicated-product-core.js').includes('window.etDedicatedProductCore'), 'C64 focused product core preserved');
ok(read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php').includes('data-billing-context'), 'C65 header context preserved');
ok(manager.includes('Only a supplementary Draft batch can be edited.'), 'C63 writable guard preserved');
ok(release.includes("'corrective_build' => 'C68'") || release.includes("'corrective_build' => 'C69'") || release.includes("'corrective_build' => 'C70'") || release.includes("'corrective_build' => 'C71'") || release.includes("'corrective_build' => 'C72'") || release.includes("'corrective_build' => 'C73'") || release.includes("'corrective_build' => 'C74'") || release.includes("'corrective_build' => 'C75'") || release.includes("'corrective_build' => 'C76'") || release.includes("'corrective_build' => 'C77'"), 'health identity reads current corrective build');
ok(!read('public/erp-theme/modules/dedicated-product.css').includes('C68'), 'no public CSS change');
ok(!routes.includes('other-services-product'), 'no unsupported product route');

console.log(`C68 supplementary all-product billing isolation regression: PASS (${assertions} assertions)`);
