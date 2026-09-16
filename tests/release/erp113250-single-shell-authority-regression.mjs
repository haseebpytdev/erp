import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';

const read = path =>
  fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const sha256 = path =>
  crypto.createHash('sha256').update(read(path)).digest('hex').toUpperCase();

const shell = read('public/erp-ui/erp-shell-spacing.css');
const base = read('public/erp-ui/erp-professional.css');
const register = read('public/erp-ui/erp-booking-register-reference.css');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const groupPackage = read('resources/views/operations/bookings/group-package-unified-v103172.blade.php');
const cashVoucherLinks = read('app/Http/Middleware/PresentCashVoucherLinks.php');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const executableController = controller.replace(/\/\/[^\n]*|\/\*[\s\S]*?\*\//g, '');
const executableCssController = executableController.slice(0, executableController.indexOf('public function js'));
const freshCore = read('public/erp-theme/et-core.css');
const freshShell = read('public/erp-theme/et-shell.css');
const freshFocusedShell = read('public/erp-theme/et-focused-shell.css');
const bookingTheme = read('public/erp-theme/modules/booking.css');
const freshShellJs = read('public/erp-theme/js/shell.js');
const freshFocusedShellJs = read('public/erp-theme/js/focused-shell.js');
const focusedShellJs = freshFocusedShellJs;
const salesInvoiceFocus = read('app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(version === 'v1.1.33.293-ERP11.3.293', 'ERP-11.3.259 packaged release metadata is current');
ok(controller.includes("public/erp-theme/et-core.css") && controller.includes("public/erp-theme/et-shell.css"), 'fresh core and standard shell are served by the ERP asset authority');
ok(controller.includes("public/erp-theme/et-focused-shell.css") && controller.includes("public/erp-theme/modules/sales-invoice.css"), 'fresh focused and module theme layers are available');
ok(freshCore.includes('--et-primary:#2563EB') && freshCore.includes('--et-control-height:38px'), 'fresh core owns the approved design tokens');
ok(freshShell.includes('grid-template-columns:208px minmax(0,1fr)') && freshShell.includes('width:100%'), 'fresh shell owns the standard 208px and full-width geometry');
ok(freshFocusedShell.includes('grid-template-columns:minmax(0,1fr)') && freshFocusedShell.includes('display:none'), 'fresh focused shell removes the permanent sidebar without width hacks');
ok(freshShellJs.includes('server-rendered DOM remains authoritative') && freshFocusedShellJs.includes('no DOM reconstruction'), 'fresh shell JavaScript contains interaction only');
ok(controller.includes("request()->query('module'"), 'module stylesheet selection is request-scoped');
ok(controller.includes("'dashboard' => 'dashboard.css'") && controller.includes("'sales' => 'sales-invoice.css'"), 'module stylesheet map covers dashboard and sales invoice');
ok(!controller.includes("modules/dashboard.css'),\n            base_path('public/erp-theme/modules/booking.css')"), 'module styles are not globally concatenated');
ok(read('app/Http/Middleware/ApplyErpReleaseMetadata.php').includes("&module="), 'page module marker is passed to stylesheet authority');
const metadata = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const sidebarComposer = read('app/Services/Operations/ServerSidebarComposer.php');
ok(metadata.includes("data-et-ui-role=\"'.$role.'\""), 'server response carries a presentation role marker');
ok(metadata.includes("return 'register'"), 'register pages receive a dedicated presentation role');
ok(metadata.includes("return 'focused'"), 'focused workspaces receive a dedicated presentation role');
ok(read('public/erp-theme/modules/registers.css').includes('data-et-ui-role="register"'), 'register CSS targets the role marker');
ok(sidebarComposer.includes("'OPERATIONS'") && sidebarComposer.includes("'ACCOUNTING'"), 'server sidebar composer defines canonical groups');
ok(sidebarComposer.includes('data-et-server-sidebar') && sidebarComposer.includes('hrefs preserved'), 'server composer emits authority only after composition');
ok(metadata.includes('ServerSidebarComposer'), 'presentation middleware invokes server sidebar composer');
ok(sidebarComposer.includes('Class-only roots are ambiguous') && !sidebarComposer.includes("strtok($classes"), 'ambiguous class-only roots safely fall back');
ok(sidebarComposer.includes('duplicate ID') || sidebarComposer.includes('getAttribute(\'id\')'), 'sidebar root identity checks stable IDs');

for (const token of [
  '--et-shell-sidebar-width:208px',
  '--et-shell-gutter-x:24px',
  '--et-shell-gutter-y:18px',
  '--et-shell-bottom:28px',
  '--et-shell-topbar-height:56px',
  '--et-sidebar-brand-height:64px',
  '--et-sidebar-logo-size:36px',
  '--et-sidebar-nav-x:8px',
  '--et-sidebar-row-height:32px',
]) {
  ok(shell.includes(token), `final shell owns ${token}`);
}

const basePos = controller.indexOf('file_get_contents($base)');
const shellPos = controller.indexOf('file_get_contents($shellSpacingUi)');
const accountingPos = controller.indexOf('file_get_contents($accountingUi)');
ok(basePos >= 0 && shellPos >= 0 && shellPos > basePos, 'erp-shell-spacing.css is loaded after base CSS');
ok(
  accountingPos === -1 && shellPos > basePos,
  'legacy accounting CSS is not composed; shell spacing follows base CSS'
);

ok(!base.includes('--et-sidebar-width:220px'), 'base CSS has no 220px sidebar fallback');
ok(!base.includes('calc(100% - 22px)'), 'base CSS has no 22px calculated shell canvas');
ok(!base.includes('calc(100% - 18px)'), 'base CSS has no 18px calculated shell canvas');
ok(
  !/height:37px!important;\s*min-height:37px!important;\s*max-height:37px!important/.test(base),
  'base CSS has no fixed 37px authenticated navigation row'
);
ok(
  !register.includes('.et-reg-shell{max-width:1500px!important}'),
  'Booking Register has no 1500px outer shell cap'
);
ok(!presenter.includes('--et-booking-canvas-max'), 'booking presenter has no canvas maximum token');
ok(!presenter.includes('--et-booking-canvas-gutter'), 'booking presenter has no canvas gutter token');
ok(!presenter.includes('max-width:1280px'), 'booking presenter has no 1280px outer maximum');
ok(
  !presenter.includes('width:calc(100% - (var(--et-booking-canvas-gutter) * 2))'),
  'booking presenter has no calculated outer canvas width'
);
ok(!groupPackage.includes('fitWorkspace'), 'Group Package has no runtime outer sizing function');
ok(!groupPackage.includes('root.style.width'), 'Group Package does not write outer width');
ok(!groupPackage.includes('root.style.maxWidth'), 'Group Package does not write outer max-width');
ok(!groupPackage.includes('root.style.marginLeft'), 'Group Package does not write outer margin');
ok(
  !groupPackage.includes("addEventListener('resize', fitWorkspace"),
  'Group Package has no geometry resize listener'
);
ok(
  !cashVoucherLinks.includes('data-et-sidebar-shell='),
  'Cash Voucher middleware injects no global sidebar geometry'
);
for (const token of [
  "rewriteAnchor($html, 'Chart of Accounts'",
  "rewriteAnchor($html, 'Supplier Costing'",
  'data-et-live-accounting-nav="receipt"',
  'data-et-live-accounting-nav="payment"',
  'data-et-live-accounting-nav="expense"',
  'data-et-live-accounting-nav="contra"',
]) {
  ok(cashVoucherLinks.includes(token), `Cash Voucher navigation behavior preserves ${token}`);
}

for (const token of [
  'position:sticky!important',
  'height:100vh!important',
  'overflow-y:auto!important',
  '[data-gp-focus-sidebar].gp-focus-sidebar-open',
  '[data-et-air-focus-sidebar].et-air-focus-sidebar-open-103172',
  'html.et-booking-focus-prepaint #gp-booking',
  'html.et-booking-focus-prepaint .et-air-workspace-103172',
]) {
  ok(shell.includes(token), `final shell owns ${token}`);
}
ok(
  /html\.et-booking-focus-prepaint section\.content\{[\s\S]*?var\(--et-shell-gutter-x\)/.test(shell),
  'focused booking canvas uses the shared responsive shell gutter'
);
ok(presenter.includes("$style = ''") && !presenter.includes('<style data-et-booking-focus-shell='), 'Booking presenter no longer owns static style markup');
ok(bookingTheme.includes('et-booking-focus-page-actions') && bookingTheme.includes('et-booking-unified-canvas-11375'), 'Booking theme owns extracted focused-workspace presentation CSS');
const registersTheme = read('public/erp-theme/modules/registers.css');
const bookingRegisterLegacy = read('public/erp-ui/erp-booking-register-reference.css');
ok(registersTheme.includes('.et-booking-ref-kpis') && registersTheme.includes('.et-booking-ref-pagination'), 'fresh registers theme owns Booking Register selectors');
ok(controller.includes("'purchase' => 'registers.css'") && controller.includes("$role === 'register'"), 'fresh registers theme remains available for register role');
ok(!executableCssController.includes("$registerWorkspaceUi = base_path('public/erp-ui/erp-booking-register-reference.css')") && !executableCssController.includes('file_get_contents($registerWorkspaceUi)'), 'legacy Booking Register stylesheet is removed from runtime composition');
ok(bookingRegisterLegacy.includes('.et-booking-ref-register-card') && bookingRegisterLegacy.includes('.et-booking-ref-pagination'), 'legacy Booking Register stylesheet remains physically present for rollback');
ok(presenter.includes('str_contains($html, \'data-et-booking-focus-shell="ERP-11.3.75"\')'), 'Booking focus marker guard remains idempotent');
ok(presenter.includes("addHtmlAttribute($html, 'data-et-booking-focus-shell', 'ERP-11.3.75')"), 'Booking focus marker is emitted on semantic html markup');
ok(!presenter.includes('<style data-et-booking-focus-shell='), 'Booking focus marker is not carried by an inline style block');
ok(presenter.includes('BookingEditLockResolver') && !presenter.includes('SalesInvoiceService'), 'Booking lifecycle authority remains unchanged');

const protectedHashes = new Map([
  ['app/Services/System/DayOneSequenceResetService.php', '65D0A210D36F5A4DEDF53D2D3EFD00DD660BCDBFFB51FE9841605A3ACB8F0FEF'],
  ['app/Http/Controllers/System/ProductionDataResetController.php', '1F5628ACB584648B5AA1C24E9440E1DA29770E604C7839E7793F2BFAE70ABFBF'],
  ['resources/views/system/day-one-sequence-reset-v113247.blade.php', '94E8616F4A8AB127E57D733CFD527E78BB117BF4290534F2B003820E8B7A094F'],
  ['app/Services/System/DayZeroDataResetService.php', 'C1F01442285D80EC2B88DDC293C7AE79248619F76D033D581E619C5DDD818101'],
  ['resources/views/accounting/cash-vouchers/print.blade.php', '005F6B12C765DF26C880DC6E81AD5381518A9871A573801140F0ECAB57E66C33'],
]);

ok(
  salesInvoiceFocus.includes('$this->markHtml($html)')
    && salesInvoiceFocus.includes('$this->markBody($html)')
    && salesInvoiceFocus.includes('et-sales-invoice-focus-prepaint')
    && salesInvoiceFocus.includes('et-si11-page-103179'),
  'Sales Invoice focused shell is marked server-side before client enhancement'
);
ok(
  focusedShellJs.includes('et-sales-invoice-menu-button')
    && focusedShellJs.includes("overlay.addEventListener('click',closeMenu)")
    && focusedShellJs.includes("e.key==='Escape'"),
  'Sales Invoice focused menu remains a dismissible sidebar drawer'
);
const siGate = focusedShellJs.indexOf("dataset.etSalesInvoiceFocus!=='ERP-11.3.60'");
const siInit = focusedShellJs.indexOf("dataset.etSalesInvoiceFocusInit==='ERP-11.3.60'");
ok(siGate >= 0 && siInit > siGate, 'Sales Invoice initializer is page-scoped before idempotency marker');
ok(salesInvoiceFocus.includes('data-et-sales-invoice-focus="ERP-11.3.60"') || salesInvoiceFocus.includes("data-et-sales-invoice-focus='ERP-11.3.60'"), 'Sales Invoice server marker remains emitted');

for (const [path, expected] of protectedHashes) {
  ok(sha256(path) === expected, `protected source remains unchanged: ${path}`);
}

console.log(`PASS ${pass} single-shell authority assertions`);
