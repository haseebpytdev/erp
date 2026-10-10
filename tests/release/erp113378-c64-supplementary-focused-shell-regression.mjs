import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const focusedCss = read('public/erp-theme/et-focused-shell.css');
const c63Presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const c63Partial = read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php');
const c63Controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const c63ApiShow = c63Controller.split('public function apiStore')[0];
const routes = read('routes/erp103179.php');

const supplementaryPath = "^operations/bookings/[^/]+/additional-services/[^/]+/products/(?:air|hotel|transport|visa)$";
ok(middleware.includes(supplementaryPath), 'supplementary product paths are classified as focused');
ok(middleware.includes(`|| preg_match('#${supplementaryPath}#', $path)`), 'supplementary path classification is in uiRole authority');
['air', 'hotel', 'transport', 'visa'].forEach(product => {
  ok(middleware.includes(`products/(?:air|hotel|transport|visa)$`), `${product} supplementary focused route is covered`);
});
ok(middleware.includes("$role = $this->uiRole($path, $routeName);") && middleware.includes("&role='.rawurlencode($role)"), 'professional asset link uses the authoritative role');
ok(assetController.includes("if ($role === 'focused')") && assetController.includes("et-focused-shell.css"), 'focused role loads canonical focused stylesheet');
ok(focusedCss.includes('grid-template-columns:minmax(0,1fr)') && focusedCss.includes('display:none!important'), 'focused stylesheet owns shell geometry');
ok(focusedCss.includes('width:100%!important') && focusedCss.includes('max-width:none!important'), 'focused stylesheet owns full-width main/content geometry');
ok(!middleware.includes('grid-template-columns') && !presenter.includes('grid-template-columns'), 'no inline focused-shell geometry copy was added');
ok(!middleware.includes('transport.css') && !presenter.includes('transport.css'), 'no Transport-specific CSS hack was added');
ok(middleware.includes("if (str_contains($html, 'data-et-professional-ui='))") && presenter.includes('data-et-dedicated-visa-css='), 'focused stylesheet selection is idempotent and marker-guarded');
ok(presenter.includes('data-et-dedicated-visa-css=') && presenter.includes('role=focused&dedicated=1&product=visa'), 'Visa dedicated focused CSS remains preserved');
ok(c63Presenter.includes('additional-services/\\d+/products/(?:air|hotel|transport|visa)') && c63Partial.includes('data-billing-writable'), 'C63 supplementary shell and writable markers remain intact');
ok(!c63ApiShow.includes('catch (Throwable') && c63Controller.includes('productKey(string $product)'), 'C63 Transport error visibility and product allowlist remain intact');
ok(routes.includes("'/system/erp-assets/erp-professional.css'") && routes.includes("name('system.erp-assets.erp-professional-css')"), 'existing authenticated asset route remains authoritative');

console.log('PASS ' + pass + ' ERP-11.3.378 C64 supplementary focused-shell assertions');
