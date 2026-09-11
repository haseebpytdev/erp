import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
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
ok(finalizer.includes("heading.style.setProperty('margin', '10px 9px 0', 'important')"), 'section headings remain tight to their first child');
ok(finalizer.includes("normalize(node.textContent) === 'safe web-based application maintenance'"), 'System Health legacy maintenance title is detected');
ok(finalizer.includes("healthHeading.textContent = 'System Health'"), 'System Health page gets concise title');
ok(finalizer.includes("status.textContent = 'Database schema is up to date.'"), 'database status copy is concise');
ok(finalizer.includes('ticket-level sale, purchase and commissions are visible'), 'commercial explanation block is recognized');
ok(finalizer.includes("card.dataset.etObsoleteHealthCommercialCopy = 'hidden'"), 'commercial explanation block is hidden as presentation-only cleanup');
ok(assetController.includes("base_path('public/erp-ui/erp-professional-finalize.js')"), 'Laravel asset response includes the finalizer');
ok(assetController.includes('file_get_contents($base)."\\n".file_get_contents($finalizer)'), 'base UI runs before finalizer');
ok(version === 'v1.1.33.228-ERP11.3.228', 'functional checkpoint does not bump release version');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
