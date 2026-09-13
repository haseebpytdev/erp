import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const shell = read('public/erp-ui/erp-shell-spacing.css');
const professionalJs = read('public/erp-ui/erp-professional.js');
const finalizeJs = read('public/erp-ui/erp-professional-finalize.js');
const dashboardCss = read('public/erp-ui/erp-professional.css');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(version === 'v1.1.33.252-ERP11.3.252', 'functional checkpoint retains ERP-11.3.252 release metadata');
ok(shell.includes('--et-shell-sidebar-width:208px'), 'shared sidebar width remains 208px');
ok(shell.includes('--et-shell-gutter-x:24px'), 'desktop shell gutter remains 24px');
ok(shell.includes('--et-shell-gutter-x:16px'), 'responsive shell gutter remains 16px');

const desktopShell = shell.match(/@media\(min-width:900px\)\{[\s\S]*?\n}\n\n\/\*/)?.[0] || '';
ok(desktopShell.includes('body.et-ui-professional .app-shell'), 'standard app shell grid rule is desktop scoped');
ok(
  /grid-template-columns:\s*var\(--et-shell-sidebar-width\)\s*minmax\(0,1fr\)!important;/.test(desktopShell),
  'desktop shell uses the sidebar variable and flexible main track'
);

const standardHost = shell.match(/html:not\(\.et-booking-focus-prepaint\) body\.et-ui-professional main>section\.content,[\s\S]*?box-sizing:border-box!important;/)?.[0] || '';
ok(standardHost.includes('padding-top:0!important'), 'standard host removes duplicate native top padding');
ok(standardHost.includes('html:not(.et-booking-focus-prepaint)'), 'standard host correction excludes booking focus');
ok(
  shell.includes('main>.et-ui-utility-topbar+*') && shell.includes('margin-top:var(--et-shell-gutter-y)!important'),
  'shell adjacent-sibling top rhythm remains authoritative'
);
ok(shell.includes('html.et-booking-focus-prepaint .app-shell{\n  grid-template-columns:minmax(0,1fr)!important;'), 'booking focus remains one-column');
ok(!professionalJs.includes('style.gridTemplateColumns') && !finalizeJs.includes('style.gridTemplateColumns'), 'no JavaScript shell sizing is introduced');
ok(!dashboardCss.includes('[data-et-dashboard-header="true"]{\n  grid-template-columns'), 'no Dashboard-specific grid workaround exists');
ok(shell.includes('@media print{'), 'print isolation remains present');

for (const path of [
  'app/Services/System/DayOneSequenceResetService.php',
  'app/Services/System/DayZeroDataResetService.php',
  'app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php',
  'resources/views/accounting/cash-vouchers/print.blade.php'
]) ok(fs.existsSync(new URL('../../' + path, import.meta.url)), `protected source remains present: ${path}`);

console.log(`PASS ${pass} shell grid and top rhythm assertions`);
