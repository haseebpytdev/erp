import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const prepaint = read('public/erp-ui/erp-sidebar-prepaint.css');
const ready = read('public/erp-ui/erp-sidebar-ready.js');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(finalizer.includes("const rootNavs = navCandidates.filter"), 'sidebar finalizer discovers root nav containers');
ok(finalizer.includes("const nestedNavs = navCandidates.filter"), 'nested nav containers are classified separately');
ok(finalizer.includes("nav.insertBefore(heading, sectionRows[0])"), 'section headings are inserted in place');
ok(!finalizer.includes('canonicalNav.appendChild') && !finalizer.includes('appendRow(row)'), 'finalizer never moves root menu rows into a new order');
ok(finalizer.includes("['operations', 'OPERATIONS'"), 'Operations section exists');
ok(finalizer.includes("['accounting', 'ACCOUNTING'"), 'Accounting section exists');
ok(finalizer.includes("['master-data', 'MASTER DATA'"), 'Master Data section exists');
for (const label of ['receipts','payments','expense vouchers','contra vouchers','chart of accounts','account mappings','journals','ledgers','reports']) {
  ok(finalizer.includes(`['${label}']`), `Accounting classification includes ${label}`);
}
ok(finalizer.includes("nav.dataset.etSidebarStable = 'ERP-11.3.241'"), 'root nav receives stable marker');
ok(finalizer.includes("nav.dataset.etSidebarLevel = 'nested'"), 'nested nav receives explicit level marker');
ok(shellCss.includes('margin:14px 8px 6px!important'), 'CSS owns section heading rhythm');
ok(shellCss.includes('margin:2px 0 6px 22px!important'), 'nested menu indentation is separate from root geometry');
ok(prepaint.includes('body.et-ui-professional:not([data-et-sidebar-ready])'), 'sidebar has a prepaint pending state');
ok(prepaint.includes('visibility: hidden'), 'native sidebar remains hidden until the single finalizer pass completes');
ok(prepaint.includes('2s forwards'), 'prepaint guard retains a finite failsafe');
ok(ready.includes("body.dataset.etSidebarReady = 'true'"), 'ready marker is applied only after finalizer execution');
ok(finalizer.includes("normalize(node.textContent) === 'safe web-based application maintenance'"), 'System Health legacy title is still detected');
ok(finalizer.includes("healthHeading.textContent = 'System Health'"), 'System Health concise title remains');
ok(finalizer.includes("status.textContent = 'Database schema is up to date.'"), 'database status copy remains concise');
ok(assetController.includes("base_path('public/erp-ui/erp-sidebar-prepaint.css')"), 'Laravel CSS response still includes prepaint guard');
ok(assetController.includes("base_path('public/erp-ui/erp-professional-finalize.js')"), 'Laravel JS response includes the single sidebar finalizer');
ok(assetController.includes("base_path('public/erp-ui/erp-sidebar-ready.js')"), 'Laravel JS response includes post-finalizer ready marker');
ok(assetController.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned authenticated UI assets remain browser-cacheable');
ok(!assetController.includes('no-store'), 'professional assets do not force refetch on every navigation');
ok(version === 'v1.1.33.240-ERP11.3.240', 'functional .241 checkpoint retains deployed .240 release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
