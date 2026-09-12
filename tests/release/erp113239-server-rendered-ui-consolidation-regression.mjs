import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const releaseMiddleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const interactions = read('public/erp-ui/erp-register-workspace.js');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const registerCss = read('public/erp-ui/erp-booking-register-reference.css');
const accountingCss = read('public/erp-ui/erp-accounting-vouchers.css');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const cashIndex = read('resources/views/accounting/cash-vouchers/index.blade.php');
const cashForm = read('resources/views/accounting/cash-vouchers/form.blade.php');
const cashShow = read('resources/views/accounting/cash-vouchers/show.blade.php');
const adjustmentForm = read('resources/views/accounting/advance-adjustments/form.blade.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(presenter.includes("'operations/bookings' => ["), 'Booking Register has server-side presentation configuration');
ok(presenter.includes("'sales/invoices' => ["), 'Sales Invoice Register has server-side presentation configuration');
ok(presenter.includes("'supplier-costing' => ["), 'Supplier Costing Register has server-side presentation configuration');
ok(presenter.includes("view('system.register-workspace-v113239'"), 'presenter renders one final shared register fragment server-side');
ok(presenter.includes("'/<main\\b([^>]*)>([\\s\\S]*?)<\\/main>/i'"), 'presenter normalizes the native main canvas before response delivery');
ok(presenter.includes('utilityTopbarHtml($nativeMain)'), 'presenter preserves the native utility topbar before replacing register content');
ok(presenter.includes('class="et-shell-content-frame et-register-content-frame"'), 'final register markup is wrapped in the shared shell canvas');
ok(presenter.includes('data-et-register-server="ERP-11.3.239"'), 'server-rendered register response keeps deterministic first-paint marker');
ok(releaseMiddleware.includes('app(PresentUnifiedRegisterWorkspace::class)->present('), 'server register presentation runs before professional asset injection');
ok(releaseMiddleware.indexOf('PresentUnifiedRegisterWorkspace::class') < releaseMiddleware.indexOf('$html = $this->injectProfessionalUi'), 'final register HTML exists before browser assets are injected');

ok(view.includes('et-booking-ref-kpis'), 'KPI structure is emitted by Blade, not JavaScript');
ok(view.includes('Search &amp; Filter') && view.includes('data-register-filter-card'), 'filter structure is emitted by Blade');
ok(view.includes('et-booking-ref-register-card') && view.includes('et-register-server-table'), 'register table shell is emitted by Blade');
ok(view.includes('data-register-export-visible') && view.includes('data-register-export-selected'), 'export controls are emitted by Blade');
ok(view.includes('data-register-row-select') && view.includes('data-register-row-menu'), 'selection and row-action controls are emitted by Blade');
ok(view.includes('Quick Workflow') && view.includes("$config['breakdown_title']") && view.includes('Recent Activity'), 'insight cards are emitted by Blade');
ok(view.includes('$nativePaginatorHtml'), 'native server pagination remains reachable when the host controller paginates');

ok(interactions.includes('var pageSize = 15;'), 'minimal interaction layer keeps 15-row in-page pagination');
ok(!/createElement\(['"](?:section|article|table|form|header|main)/.test(interactions), 'interaction JS does not construct register page structure');
ok(!interactions.includes('innerHTML =') && !interactions.includes('insertAdjacentHTML'), 'interaction JS does not replace structural HTML after paint');
ok(!interactions.includes('fetch(') && !interactions.includes('XMLHttpRequest'), 'interaction JS performs no network data request');
ok(!interactions.includes('localStorage') && !interactions.includes('sessionStorage'), 'interaction JS adds no persistent browser state');
ok(!/\.submit\(|requestSubmit\(|FormData\(/.test(interactions), 'interaction JS cannot submit business forms');

ok(!controller.includes("base_path('public/erp-ui/erp-operation-registers.css')"), 'obsolete shared register patch CSS is removed from served bundle');
ok(!controller.includes("base_path('public/erp-ui/erp-operation-registers.js')"), 'obsolete .236 register DOM script is removed from served bundle');
ok(!controller.includes("base_path('public/erp-ui/erp-booking-register-reference.js')"), 'obsolete .237 Booking DOM reconstruction is removed from served bundle');
ok(!controller.includes("base_path('public/erp-ui/erp-commercial-register-reference.js')"), 'obsolete .238 commercial DOM reconstruction is removed from served bundle');
ok(controller.includes("base_path('public/erp-ui/erp-booking-register-reference.css')"), 'one approved register visual stylesheet is served');
ok(controller.includes("base_path('public/erp-ui/erp-register-workspace.js')"), 'one minimal register interaction script is served');
ok(controller.includes("base_path('public/erp-ui/erp-shell-spacing.css')"), 'shell spacing authority is served after module styles');
ok(controller.includes('file_get_contents($registerWorkspaceUi)."\\n".file_get_contents($shellSpacingUi)'), 'shell spacing authority is the final CSS layer');

ok(controller.includes("base_path('public/erp-ui/erp-accounting-vouchers.css')"), 'authoritative Accounting voucher stylesheet remains served');
ok(accountingCss.includes('.et-fin-modes') && accountingCss.includes('.cvf27-card') && accountingCss.includes('.cvs27-card') && accountingCss.includes('.aa-wrap'), 'Accounting register/form/detail/adjustment design remains consolidated in one stylesheet');
ok(cashIndex.includes('et-fin') && cashForm.includes('cvf27') && cashShow.includes('cvs27') && adjustmentForm.includes('aa-'), 'Accounting workspaces remain server-rendered native Blade structures');
ok(!interactions.includes('accounting/'), 'new register interaction script does not mask or reconstruct Accounting pages');

ok(registerCss.includes('.et-booking-ref-kpis') && registerCss.includes('.et-booking-ref-filter-card') && registerCss.includes('.et-booking-ref-register-card') && registerCss.includes('.et-booking-ref-insights'), 'approved register visual system remains intact');
ok(shellCss.includes('--et-shell-gutter-x:24px') && shellCss.includes('--et-shell-gutter-y:20px'), 'one desktop shell gutter system is authoritative');
ok(!finalizer.includes("heading.style.setProperty('margin'"), 'sidebar finalizer no longer competes with CSS spacing');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned immutable browser caching remains intact');
ok(version === 'v1.1.33.248-ERP11.3.248', 'functional .240 checkpoint retains deployed .239 release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
