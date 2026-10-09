import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const focus = read('public/erp11335/booking-focus.js');
const release = read('config/et_erp_release.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const routes = read('routes/erp103179.php');
const workspace = read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php');
const context = read('app/Services/Operations/ProductWorkspaceContext.php');
const items = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const c57 = read('app/Http/Middleware/PresentSalesInvoicePrintV2.php');
const c56 = read('app/Services/Operations/NativeSalesInvoiceCreationVerifier.php');
const c55 = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const c54 = read('app/Http/Middleware/EnforceGeneralBookingEditLock.php');
const c53 = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const dashboardGate = /^\/operations\/bookings\/[^\/]+\/?$/i;

ok(focus.includes("/^\\/operations\\/bookings\\/[^\\/]+\\/?$/i"), 'exact dashboard gate accepts canonical route segments');
ok(focus.includes("match(/^\\/operations\\/bookings\\/") && focus.includes("match[1]"), 'canonical booking route segment is captured without numeric coercion');
ok(focus.includes("link.href='/operations/bookings/'+match[1]+'/additional-services'"), 'Additional Services target preserves the booking route segment');
ok(focus.includes("link.textContent='Additional Services'"), 'Additional Services label remains exact');
ok(focus.includes('etBookingFocusReconcileAdditionalServices') && focus.includes('client.nextSibling'), 'Additional Services remains after Client Preview and before Menu');
ok(!focus.includes("/^\\/operations\\/bookings\\/\\d+\\/?$/i"), 'numeric-only dashboard gate is removed');
ok(dashboardGate.test('/operations/bookings/123') && dashboardGate.test('/operations/bookings/BK-2026-0024'), 'numeric and non-numeric booking keys match the dashboard gate');
ok(!dashboardGate.test('/operations/bookings/123/products/transport') && !dashboardGate.test('/operations/bookings/BK-2026-0024/additional-services') && !dashboardGate.test('/operations/bookings/BK-2026-0024/review'), 'nested product, Additional Services, and review routes do not match the dashboard gate');
ok(routes.includes("bookings.additional-services.index"), 'Additional Services landing route remains named');
ok(routes.includes("additional-services/start"), 'New Batch route remains present');
ok(workspace.includes('data-etgp-dedicated-product-host'), 'Air/Hotel/Transport/Visa supplementary fields use native shared workspace');
ok(context.includes("['ORIGINAL', 'SUPPLEMENTARY']") && context.includes('billingBatchId'), 'ProductWorkspaceContext separation remains');
ok(items.includes('general_booking_billing_batch_items'), 'supplementary persistence remains batch scoped');
ok(presenter.includes("system.erp-assets.booking-focus") && presenter.includes('rawurlencode($assetVersion)'), 'booking-focus remains release-version cache busted');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C46'"), 'active asset version is C46');
ok(c57.includes('verticalizePassengerCells'), 'C57 remains protected');
ok(c56.includes('activeBaseInvoices'), 'C56 remains protected');
ok(c55.includes('activeBase'), 'C55 remains protected');
ok(c54.includes('BookingBillingEditLockResolver'), 'C54 remains protected');
ok(c53.includes('BaseSalesInvoiceConsistencyResolver'), 'C53 remains protected');
ok(focus.includes('data-et-additional-services-entry'), 'booking-level Additional Services action remains present');
ok(focus.includes('pageRegisterLink.parentNode.insertBefore(toolbar,pageRegisterLink)'), 'base booking action area remains unchanged');

console.log('PASS ' + pass + ' ERP-11.3.378 C59 Additional Services Route Key assertions');
