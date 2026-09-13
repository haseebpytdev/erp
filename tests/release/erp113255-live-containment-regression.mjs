import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const css = read('public/erp-ui/erp-professional.css');
const shell = read('public/erp-ui/erp-shell-spacing.css');
const js = read('public/erp-ui/erp-professional.js');
const accounting = read('resources/views/accounting/cash-vouchers/index.blade.php');
const popover = read('public/erp-ui/erp-booking-register-reference.css');
const registerJs = read('public/erp-ui/erp-register-workspace.js');
let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

const kpi = css.match(/body\.et-ui-module-dashboard \.et-dashboard-kpi\{[\s\S]*?\n\}/)?.[0] || '';
const accent = css.match(/body\.et-ui-module-dashboard \.et-dashboard-kpi::before\{[\s\S]*?\n\}/)?.[0] || '';
ok(kpi.includes('position:relative!important'), 'KPI cards establish containment positioning');
ok(kpi.includes('overflow:hidden!important') && !kpi.includes('overflow:visible'), 'KPI cards clip accent overflow');
for (const marker of ['position:absolute','top:0','left:0','right:0','height:3px','margin:0!important']) ok(accent.includes(marker), `KPI accent uses contained ${marker}`);
ok(!accent.includes('margin:-'), 'negative KPI accent margin is removed');
ok(css.includes('[data-et-dashboard-kpi="gross-profit"]') && css.includes('--et-kpi-accent:#1a9a63'), 'KPI accent variants remain');

ok(js.includes("const healthShellHosts = 'main,section.content,.content,.page-content,.page-body,.app-shell"), 'health shell exclusion list is explicit');
ok(js.includes('const healthChrome ='), 'health chrome rejection is separate from shell traversal boundaries');
ok(!js.includes('if (!titleNode || titleNode.closest(healthShellHosts))'), 'title nodes inside the page shell are not rejected');
ok(js.includes("const main = titleNode.closest('main')"), 'health panel resolution is scoped to the local main');
ok(js.includes('findHealthAction = (scope, text)') && js.includes('findHealthAction(main, actionText)'), 'health action search is scoped within main');
ok(js.includes('if (candidate.matches(healthShellHosts)) break;'), 'shell hosts stop ancestor traversal');
ok(!js.includes('candidate.matches(healthShellHosts)\n          &&'), 'shell hosts cannot be returned as candidates');
ok(js.includes("exactLeaf(document, 'Application Cache')") && js.includes("Clear Application Cache"), 'Application Cache requires heading and action');
ok(js.includes("exactLeaf(document, 'Database Upgrade')") && js.includes("Run Safe Database Upgrade"), 'Database Upgrade requires heading and action');
ok(js.includes('candidate.contains(actionNode)'), 'health panel contains its own action');
ok(js.includes('forbiddenTexts.some'), 'health panels reject unrelated sections');
ok(js.includes('return null'), 'ambiguous health panel resolution fails closed');
ok(js.includes('node.matches(healthShellHosts)') && js.includes('removeAttribute'), 'stale shell health tagging cleanup is scoped');
ok(js.includes("healthTitle = exactLeaf(document, 'System Health & Updates')"), 'module detection remains System Health & Updates');
ok(css.includes('[data-et-health-section]') && css.includes('.et-health-status{'), 'inner health section and status-card styling remain');
ok(css.includes('[data-et-dangerous-actions="true"]'), 'Dangerous Actions styling remains');

ok(shell.includes('--et-shell-sidebar-width:208px'), 'sidebar width remains 208px');
ok(shell.includes('--et-sidebar-brand-height:64px') && shell.includes('--et-sidebar-logo-size:36px'), 'brand geometry remains');
ok(shell.includes('--et-sidebar-nav-x:8px'), 'nav inset remains 8px');
ok(shell.includes('--et-sidebar-row-height:32px') && shell.includes('margin:2px 0!important'), 'sidebar rhythm is 32px rows with 2px margins');
ok(shell.includes('margin:14px 6px 7px!important'), 'section heading rhythm is strengthened');
ok(shell.includes('margin-bottom:4px!important'), 'Dashboard group separation remains small');
ok(shell.includes('position:static!important') && shell.includes('transform:none!important'), 'section flow remains normal');
ok(css.includes('color-mix(in srgb,var(--et-nav-accent) 12%,transparent)') && css.includes('rgba(255,255,255,.14)'), 'icon treatments remain');

ok(accounting.includes('align-items:stretch;margin-top:18px') && accounting.includes('.et-bottom-row>.et-card{margin-top:0}'), 'Accounting .254 rhythm remains');
ok(popover.includes('width:132px') && popover.includes('position:fixed!important') && popover.includes('z-index:10050!important'), 'Booking popover .254 contract remains');
for (const marker of ['document.body.appendChild','Escape','resize','scroll']) ok(registerJs.includes(marker), `Booking portal lifecycle remains: ${marker}`);
ok(!registerJs.includes('location.href') && !registerJs.includes('window.location'), 'Booking URLs remain native');
ok(shell.includes('body.et-ui-professional .app-shell>main.main'), '.253 main gutter authority remains');
ok(shell.includes('var(--et-shell-sidebar-width)') && shell.includes('minmax(0,1fr)'), '.252 grid authority remains');
ok(shell.includes('--et-shell-gutter-x:24px') && shell.includes('--et-shell-gutter-x:16px'), 'shell gutters remain');
ok(shell.includes('html.et-booking-focus-prepaint .app-shell'), 'booking focus override remains');
ok(!js.includes('style.gridTemplateColumns'), 'no JS shell sizing');
ok(!fs.existsSync(new URL('../../public/erp-ui/erp-ui-consistency.css', import.meta.url)), 'no global stylesheet added');
ok(!fs.existsSync(new URL('../../database/migrations/2026_09_13_erp113255.php', import.meta.url)), 'no migration');
ok(shell.includes('@media print{'), 'print authority unchanged');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
