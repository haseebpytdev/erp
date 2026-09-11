import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const prepaint = read('public/erp-ui/erp-sidebar-prepaint.css');
const ready = read('public/erp-ui/erp-sidebar-ready.js');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(finalizer.includes("const rootNavs = navCandidates.filter"), 'sidebar finalizer discovers every root nav container');
ok(finalizer.includes("const canonicalNav = dashboardLink ? rootNavFor(dashboardLink) : rootNavs[0]"), 'dashboard nav becomes canonical sidebar order authority');
ok(finalizer.includes("dashboardRow.dataset.etSidebarGroup = 'dashboard'"), 'Dashboard remains first standalone row');
ok(!finalizer.includes('wholeLabel.endsWith'), 'finalizer does not use suffix matching that can confuse Vouchers with Expense Vouchers');
ok(finalizer.includes("['operations', 'OPERATIONS'"), 'Operations group exists');
ok(finalizer.includes("['bookings']") && finalizer.includes("['sales invoices']") && finalizer.includes("['supplier costing']"), 'Operations contains only core operational rows');
ok(finalizer.includes("['accounting', 'ACCOUNTING'"), 'Accounting group exists');
for (const label of ['receipts','payments','expense vouchers','contra vouchers','chart of accounts','account mappings','journals','ledgers','reports']) {
  ok(finalizer.includes(`['${label}']`), `Accounting ordering includes ${label}`);
}
ok(finalizer.includes("canonicalNav.dataset.etSidebarGrouped = 'reference-v2'"), 'final canonical grouping is marked');
ok(finalizer.includes("heading.style.setProperty('margin', '16px 9px 9px', 'important')"), 'section headings retain final 16px top and 9px bottom separation');
ok(prepaint.includes('body.et-ui-professional:not([data-et-sidebar-ready])'), 'sidebar has a prepaint pending state');
ok(prepaint.includes('visibility: hidden'), 'native sidebar rows stay hidden until normalization completes');
ok(prepaint.includes('2s forwards'), 'prepaint guard has a finite failsafe reveal');
ok(ready.includes("body.dataset.etSidebarReady = 'true'"), 'sidebar ready marker is applied after the finalizer');
ok(finalizer.includes("normalize(node.textContent) === 'safe web-based application maintenance'"), 'System Health legacy maintenance title is detected');
ok(finalizer.includes("healthHeading.textContent = 'System Health'"), 'System Health page gets concise title');
ok(finalizer.includes("status.textContent = 'Database schema is up to date.'"), 'database status copy is concise');
ok(finalizer.includes('ticket-level sale, purchase and commissions are visible'), 'commercial explanation block is recognized');
ok(finalizer.includes("card.dataset.etObsoleteHealthCommercialCopy = 'hidden'"), 'commercial explanation block is hidden as presentation-only cleanup');
ok(assetController.includes("base_path('public/erp-ui/erp-sidebar-prepaint.css')"), 'Laravel CSS response includes the prepaint guard');
ok(assetController.includes('file_get_contents($prepaint)."\\n".file_get_contents($base)'), 'prepaint guard is delivered before the professional stylesheet');
ok(assetController.includes("base_path('public/erp-ui/erp-professional-finalize.js')"), 'Laravel JS response includes the finalizer');
ok(assetController.includes("base_path('public/erp-ui/erp-sidebar-ready.js')"), 'Laravel JS response includes the post-finalizer ready marker');
ok(assetController.includes('file_get_contents($base)."\\n".file_get_contents($finalizer)."\\n".file_get_contents($ready)'), 'ready marker runs only after base UI and finalizer');
ok(assetController.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned authenticated UI assets are browser-cacheable');
ok(!assetController.includes('no-store'), 'professional UI assets no longer force a network refetch on every navigation');
ok(version === 'v1.1.33.237-ERP11.3.237', 'functional checkpoint does not bump release version');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
