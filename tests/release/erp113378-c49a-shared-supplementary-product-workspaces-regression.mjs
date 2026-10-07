import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const routes = read('routes/erp103179.php');
const originalController = read('app/Http/Controllers/Operations/ProductWorkspaceController.php');
const supplementaryController = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const contract = read('app/Services/Operations/SharedProductWorkspaceContract.php');
const context = read('app/Services/Operations/ProductWorkspaceContext.php');
const supplementaryView = read('resources/views/operations/bookings/additional-services/product.blade.php');
const fields = read('resources/views/operations/bookings/partials/shared-product-entry-fields.blade.php');
const originalView = read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php');
const sharedWorkspace = originalView;
const hub = read('resources/views/operations/bookings/products-hub-v113304.blade.php');

let assertions = 0;
const ok = (condition, message) => { assertions++; assert.ok(condition, message); };

ok(context.includes('billingContext') && context.includes('billingBatchId'), 'context carries billing metadata');
ok(context.includes("['ORIGINAL', 'SUPPLEMENTARY']") && context.includes('SUPPLEMENTARY') && context.includes('billing batch'), 'context validates supplementary state');
ok(contract.includes("private const PRODUCTS = ['air', 'hotel', 'transport', 'visa']"), 'shared contract owns four product variants');
for (const product of ['air', 'hotel', 'transport', 'visa']) {
  ok(contract.includes("'" + product + "' =>"), `${product} contract fields exist`);
  ok(fields.includes(`$product==='${product}'`) || fields.includes(`$product==='${product}'`), `${product} fields render through shared partial`);
}
ok(supplementaryView.includes("partials.product-workspace-v113305") && sharedWorkspace.includes('data-etgp-dedicated-product-host') && sharedWorkspace.includes('data-billing-context="{{ $context->billingContext ?? \'ORIGINAL\' }}"'), 'supplementary view uses shared native workspace/context');
ok(supplementaryController.includes('new ProductWorkspaceContext($booking, $product, \'SUPPLEMENTARY\', $batch'), 'supplementary controller supplies context');
ok(originalController.includes('new ProductWorkspaceContext((int) $row->id, $product, \'ORIGINAL\')'), 'original controller supplies context');
ok(originalView.includes('data-billing-context="{{ $context->billingContext ?? \'ORIGINAL\' }}"'), 'original view exposes original context');
ok(manager.includes('SharedProductWorkspaceContract $products') && manager.includes('$this->products->normalize') && manager.includes('$this->products->validate') && manager.includes('$this->products->commercial'), 'supplementary manager delegates product contract');
ok(manager.includes('lockWritableBatch') && manager.includes('general_booking_billing_batch_items') && manager.includes('product_snapshot'), 'supplementary draft persistence remains batch-scoped');
ok(manager.includes("where('booking_id', $bookingId)") && manager.includes("where('batch_id', $batchId)"), 'supplementary writes remain booking/batch scoped');
ok(!manager.includes('GeneralBookingAdditionalServiceProductController'), 'manager has no controller fan-out');
ok(routes.includes("whereIn('product', ['air','hotel','transport','visa'])"), 'supplementary routes remain limited to supported products');
ok(!routes.includes("whereIn('product', ['air','hotel','transport','visa','other-services'])"), 'Other Services remains deferred');
ok(hub.includes('other-services') && hub.includes('Open Additional Services'), 'Other Services remains deferred from native product cards');
ok(!manager.includes('sales_invoices') && !manager.includes('journal_entries'), 'no invoice/accounting writes added');
ok(!supplementaryController.includes('Migration') && !manager.includes('Schema::create'), 'no migration logic added');

console.log(`ERP378 C49A SHARED SUPPLEMENTARY PRODUCT WORKSPACES: PASS (${assertions} assertions)`);
