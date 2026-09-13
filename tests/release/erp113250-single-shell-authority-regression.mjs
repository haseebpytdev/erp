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
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(version === 'v1.1.33.250-ERP11.3.250', 'ERP-11.3.250 packaged release metadata is current');

for (const token of [
  '--et-shell-sidebar-width:208px',
  '--et-shell-gutter-x:24px',
  '--et-shell-gutter-y:18px',
  '--et-shell-bottom:28px',
  '--et-shell-topbar-height:56px',
  '--et-sidebar-brand-height:64px',
  '--et-sidebar-logo-size:36px',
  '--et-sidebar-nav-x:8px',
  '--et-sidebar-row-height:36px',
]) {
  ok(shell.includes(token), `final shell owns ${token}`);
}

const basePos = controller.indexOf('file_get_contents($base)');
const shellPos = controller.indexOf('file_get_contents($shellSpacingUi)');
ok(basePos >= 0 && shellPos > basePos, 'erp-shell-spacing.css is loaded after base CSS');
ok(
  controller.indexOf('file_get_contents($accountingUi)') < shellPos &&
  controller.indexOf('file_get_contents($registerWorkspaceUi)') < shellPos,
  'erp-shell-spacing.css is loaded after module CSS'
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

const protectedHashes = new Map([
  ['app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php', 'F501A489CE79E7C1909FBA14223C979834343EB9F2579CFA2A6919041D55C995'],
  ['app/Services/System/DayOneSequenceResetService.php', '65D0A210D36F5A4DEDF53D2D3EFD00DD660BCDBFFB51FE9841605A3ACB8F0FEF'],
  ['app/Http/Controllers/System/ProductionDataResetController.php', '1F5628ACB584648B5AA1C24E9440E1DA29770E604C7839E7793F2BFAE70ABFBF'],
  ['resources/views/system/day-one-sequence-reset-v113247.blade.php', '94E8616F4A8AB127E57D733CFD527E78BB117BF4290534F2B003820E8B7A094F'],
  ['app/Services/System/DayZeroDataResetService.php', 'C1F01442285D80EC2B88DDC293C7AE79248619F76D033D581E619C5DDD818101'],
  ['resources/views/accounting/cash-vouchers/print.blade.php', '005F6B12C765DF26C880DC6E81AD5381518A9871A573801140F0ECAB57E66C33'],
]);

for (const [path, expected] of protectedHashes) {
  ok(sha256(path) === expected, `protected source remains unchanged: ${path}`);
}

console.log(`PASS ${pass} single-shell authority assertions`);
