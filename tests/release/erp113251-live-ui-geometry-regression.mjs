import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const shell = read('public/erp-ui/erp-shell-spacing.css');
const registerCss = read('public/erp-ui/erp-booking-register-reference.css');
const registerJs = read('public/erp-ui/erp-register-workspace.js');
const cashVoucherIndex = read('resources/views/accounting/cash-vouchers/index.blade.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(version === 'v1.1.33.251-ERP11.3.251', 'functional checkpoint leaves release metadata at ERP-11.3.250');
for (const token of [
  '--et-shell-sidebar-width:208px',
  '--et-sidebar-nav-x:8px',
  '--et-sidebar-row-height:36px',
  '--et-shell-gutter-x:24px',
  '--et-shell-gutter-x:16px',
  'width:min(86vw,var(--et-shell-sidebar-width))'
]) ok(shell.includes(token), `shell owns ${token}`);
ok(!shell.includes('--et-shell-sidebar-width:224px'), 'no 224px desktop sidebar authority remains');
ok(/\.sidebar \.nav,[\s\S]*?display:flex!important;[\s\S]*?flex-direction:column!important;[\s\S]*?align-items:stretch!important;/.test(shell), 'root navigation has a vertical stack contract');
ok(/\.et-ui-nav-section\{[\s\S]*?position:static!important;[\s\S]*?float:none!important;[\s\S]*?transform:none!important;[\s\S]*?flex:none!important;/.test(shell), 'generated section headings remain in normal flow');
ok(/\.nav>\[data-et-sidebar-group\][\s\S]*?width:100%!important;[\s\S]*?margin:0!important;[\s\S]*?padding:0!important;[\s\S]*?transform:none!important;/.test(shell), 'root grouped row wrappers are normalized without touching nested rows');
ok(!/margin:\s*-/.test(shell.match(/\.et-ui-nav-section\{[\s\S]*?\n}\n/)?.[0] || ''), 'section headings have no negative margin');

ok(shell.includes('main>section.content') && shell.includes('main>.page-content') && shell.includes('main>.page-body'), 'immediate native standard content hosts are normalized');
ok(shell.includes('padding-left:0!important') && shell.includes('padding-right:0!important'), 'standard host reset removes only outer duplicate gutters');

const focusHeader = shell.match(/html\.et-booking-focus-prepaint body\.et-ui-professional main>\.topbar,[\s\S]*?box-sizing:border-box!important;/)?.[0] || '';
ok(focusHeader.includes('width:100%!important'), 'focus utility header is constrained to its focus canvas');
ok(focusHeader.includes('margin-left:0!important') && focusHeader.includes('margin-right:0!important'), 'focus utility header has no negative bleed');
ok(focusHeader.includes('padding-left:var(--et-shell-gutter-x)!important') && focusHeader.includes('padding-right:var(--et-shell-gutter-x)!important'), 'focus utility header content uses the shared gutter');
ok(!focusHeader.includes('calc('), 'focus utility header has no full-bleed width calculation');

ok(registerCss.includes('.et-booking-ref-table-scroll{width:100%;overflow-x:auto'), 'booking table keeps horizontal scrolling');
ok(registerCss.includes('.et-booking-row-menu-panel-portal{display:block!important;position:fixed!important'), 'portal panel is fixed outside table scrolling');
for (const token of [
  'document.body.appendChild(panel)',
  'trigger.getBoundingClientRect()',
  'window.innerWidth - panelRect.width - margin',
  "event.key === 'Escape'",
  "window.addEventListener('resize'",
  "window.addEventListener('scroll'",
  'activeRowPopover.panel.contains(event.target)',
  "document.createComment('et-booking-row-menu-panel')"
]) ok(registerJs.includes(token), `row popover behavior includes ${token}`);
ok(!registerJs.includes("href = '/bookings"), 'row popover does not construct or rewrite booking URLs');

ok(cashVoucherIndex.includes('.et-bottom-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:stretch}'), 'bottom row remains two columns on desktop');
ok(cashVoucherIndex.includes('@media(max-width:980px){') && cashVoucherIndex.includes('.et-bottom-row{grid-template-columns:1fr}'), 'bottom row remains one column responsively');
ok(cashVoucherIndex.includes('.et-bottom-row>.et-card{margin-top:0}'), 'grid siblings cannot inherit generic vertical card spacing');

const protectedPaths = [
  'app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php',
  'app/Services/System/DayOneSequenceResetService.php',
  'app/Services/System/DayZeroDataResetService.php',
  'resources/views/accounting/cash-vouchers/print.blade.php'
];
for (const path of protectedPaths) ok(fs.existsSync(new URL('../../' + path, import.meta.url)), `protected source remains present: ${path}`);

console.log(`PASS ${pass} live UI geometry assertions`);
