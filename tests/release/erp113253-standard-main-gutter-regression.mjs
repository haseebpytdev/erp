import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const shell = read('public/erp-ui/erp-shell-spacing.css');
const professionalJs = read('public/erp-ui/erp-professional.js');
const finalizeJs = read('public/erp-ui/erp-professional-finalize.js');

let pass = 0;
const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

const standardMain = shell.match(/body\.et-ui-professional \.content-wrapper>main,[\s\S]*?\n}\n\n\/\*/)?.[0] || '';
ok(standardMain.includes('body.et-ui-professional .app-shell>main.main,'), 'native standard grid main participates in the shared main authority');
ok(/padding:\s*0\s*var\(--et-shell-gutter-x\)\s*var\(--et-shell-bottom\)!important;/.test(standardMain), 'standard main uses the common shell gutter declaration');
ok(shell.includes('--et-shell-gutter-x:24px'), 'desktop gutter remains 24px');
ok(shell.includes('--et-shell-gutter-x:16px'), 'responsive gutter remains 16px');

const standardHost = shell.match(/html:not\(\.et-booking-focus-prepaint\) body\.et-ui-professional main>section\.content,[\s\S]*?box-sizing:border-box!important;/)?.[0] || '';
ok(standardHost.includes('padding-top:0!important'), 'standard immediate host has no duplicate top padding');
ok(standardHost.includes('padding-left:0!important'), 'standard immediate host has no duplicate left gutter');
ok(standardHost.includes('padding-right:0!important'), 'standard immediate host has no duplicate right gutter');

const normalTopbar = shell.match(/body\.et-ui-professional main>\.topbar,[\s\S]*?border-radius:0!important;/)?.[0] || '';
ok(normalTopbar.includes('margin-left:calc(\n    var(--et-shell-gutter-x) * -1'), 'normal topbar keeps negative gutter bleed');
ok(normalTopbar.includes('padding-left:var(--et-shell-gutter-x)!important'), 'normal topbar keeps internal horizontal gutter');
ok(shell.includes('html.et-booking-focus-prepaint .app-shell{\n  grid-template-columns:minmax(0,1fr)!important;'), 'booking focus remains one-column');
const focusMain = shell.match(/html\.et-booking-focus-prepaint \.app-shell>main,[\s\S]*?padding-bottom:0!important;/)?.[0] || '';
ok(focusMain.includes('padding-left:0!important') && focusMain.includes('padding-right:0!important'), 'booking focus explicitly resets main horizontal gutter');
ok(/grid-template-columns:\s*var\(--et-shell-sidebar-width\)\s*minmax\(0,1fr\)!important;/.test(shell), '.252 desktop grid track remains authoritative');
ok(!professionalJs.includes('style.gridTemplateColumns') && !finalizeJs.includes('style.gridTemplateColumns'), 'no JavaScript shell sizing is introduced');
ok(!/style=["'][^"']*(?:padding|grid-template-columns)/i.test(shell), 'no inline shell CSS is introduced');
ok(shell.includes('@media print{'), 'print authority remains present');

for (const path of [
  'app/Services/System/DayOneSequenceResetService.php',
  'app/Services/System/DayZeroDataResetService.php',
  'app/Http/Middleware/PresentSalesInvoiceFocusedWorkspace.php',
  'resources/views/accounting/cash-vouchers/print.blade.php'
]) ok(fs.existsSync(new URL('../../' + path, import.meta.url)), `protected source remains present: ${path}`);

console.log(`PASS ${pass} standard main gutter assertions`);
