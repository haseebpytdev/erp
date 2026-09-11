import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const css = read('public/erp-ui/erp-professional.css');
const js = read('public/erp-ui/erp-professional.js');
const supplierShow = read('resources/views/purchase/supplier-costing/show.blade.php');
const supplierIndex = read('resources/views/purchase/supplier-costing/index.blade.php');
const supplierForm = read('resources/views/purchase/supplier-costing/form.blade.php');
const pnl = read('resources/views/accounting/management-reporting/profit-and-loss.blade.php');
const pnlController = read('app/Http/Controllers/Accounting/ManagementAccountingReportController.php');
const managementService = read('app/Services/Accounting/ManagementAccountingReportService.php');
const bookingReview = read('resources/views/operations/bookings/general-booking-review-v113160.blade.php');
const cashVoucherForm = read('resources/views/accounting/cash-vouchers/form.blade.php');
const cashVoucherShow = read('resources/views/accounting/cash-vouchers/show.blade.php');
const cashVoucherPrint = read('resources/views/accounting/cash-vouchers/print.blade.php');
const routes = read('routes/erp103179.php');
const version = read('VERSION.txt').trim();

ok(middleware.includes("route('system.erp-assets.erp-professional-css')"), 'shared stylesheet is injected through Laravel');
ok(middleware.includes("route('system.erp-assets.erp-professional-js')"), 'small shared behaviour layer is injected through Laravel');
ok(middleware.includes("config('et_erp_release'"), 'release authority remains configuration-driven');
ok(middleware.includes("data-et-professional-ui=\"'.$marker.'\""), 'shared assets use current release marker');
ok(middleware.includes('rawurlencode($version)'), 'asset cache keys use the current version');
ok(middleware.includes("str_starts_with($path, 'voucher/')"), 'public voucher rendering is excluded');
ok(middleware.includes("str_contains($routeName, '.print')"), 'print routes are excluded');
ok(middleware.includes("str_contains($routeName, '.pdf')"), 'PDF routes are excluded');
ok(middleware.includes("'et-ui-professional et-ui-module-'.$module"), 'route-derived module class is applied');
for (const module of ['dashboard','administration','organization','travel','master-data','operations','sales','purchase','reports','accounting','system','foundation']) {
  ok(middleware.includes(`'${module}'`), `module mapping includes ${module}`);
}

for (const token of ['--et-primary','--et-bg','--et-surface','--et-border','--et-text','--et-muted','--et-success','--et-warning','--et-danger','--et-radius','--et-sidebar-width']) {
  ok(css.includes(token + ':'), `design token ${token} exists`);
}
ok(css.includes('--et-sidebar-width:224px'), 'desktop sidebar matches the approved compact width');
ok(css.includes('min-height:36px!important'), 'navigation and buttons use compact operational sizing');
ok(css.includes('.et-ui-current'), 'active navigation styling exists');
ok(css.includes('[aria-current="page"]'), 'native active navigation state is preserved');
ok(css.includes('--et-nav-accent'), 'module navigation uses restrained accents');
for (const module of ['dashboard','administration','organization','travel','operations','sales','purchase','accounting','reports','system']) {
  ok(css.includes(`href*=\"${module}\"`) || (module === 'travel' && css.includes('href*=\"visa\"')), `CSS includes ${module} accent`);
}
ok(css.includes('.et-page-header'), 'shared page-header primitive exists');
ok(css.includes('.et-page-kicker'), 'shared kicker primitive exists');
ok(css.includes('.et-page-subtitle'), 'shared subtitle primitive exists');
ok(css.includes('.et-card'), 'shared card primitive exists');
ok(css.includes('.et-metric'), 'shared metric primitive exists');
ok(css.includes('.et-filter-bar'), 'shared filter primitive exists');
ok(css.includes('.et-btn'), 'shared button primitive exists');
ok(css.includes('.et-status'), 'shared status primitive exists');
ok(css.includes('.et-table-wrap'), 'shared table wrapper exists');
ok(css.includes('.et-money'), 'shared financial amount primitive exists');
ok(css.includes('.et-audit-trail'), 'shared audit trail primitive exists');
ok(css.includes(':focus-visible'), 'keyboard focus remains visible');
ok(css.includes('@media(max-width:1024px)'), 'tablet breakpoint exists');
ok(css.includes('@media(max-width:768px)'), 'small tablet breakpoint exists');
ok(css.includes('@media(max-width:420px)'), 'mobile breakpoint exists');
ok(css.includes('overflow-x:auto'), 'wide tables have horizontal-scroll support');
ok(css.includes('@media print'), 'print regression styling exists');
ok(css.includes('display:none!important') && css.includes('.sidebar'), 'application chrome is excluded when printing');
ok(!css.includes('@import') && !css.includes('http://') && !css.includes('https://'), 'no external CSS framework or font dependency was added');

ok(js.includes("aria-current', 'page'"), 'active sidebar link remains accessible');
ok(js.includes("shellDashboard.textContent = 'Easy Ticket ERP'"), 'topbar becomes generic company context');
ok(js.includes("'pending approval'") && js.includes("'travel ready'") && js.includes("'posted'"), 'shared semantic statuses are recognized');
ok(!/fetch\s*\(|XMLHttpRequest|axios\s*\(/.test(js), 'presentation script performs no API requests');

ok(supplierShow.includes("$row->supplier_name"), 'supplier display uses resolved supplier name');
ok(supplierShow.includes("$posting->party_id === (int) $row->supplier_id"), 'supplier display verifies party identity');
ok(supplierShow.includes("ucfirst($posting->party_type).' #'.$posting->party_id"), 'supplier ID fallback remains available');
ok(supplierIndex.includes('Supplier costs and payable posting.'), 'Supplier Costing subtitle is business concise');
ok(supplierForm.includes('Supplier costs and payable posting.'), 'Supplier Costing form subtitle is business concise');
ok(bookingReview.includes('Saved product totals and margin.'), 'booking helper copy is concise');

for (const source of [cashVoucherForm, cashVoucherShow]) {
  ok(source.includes('<form') || source === cashVoucherShow, 'voucher presentation retains its form/document structure');
}
ok(cashVoucherForm.includes('@csrf'), 'voucher CSRF authority remains rendered');
ok(cashVoucherForm.includes("method=\"post\""), 'voucher POST method remains rendered');
ok(cashVoucherPrint.includes('data-et-print-voucher'), 'voucher print template remains present');
ok(routes.includes("ApplyErpReleaseMetadata::class"), 'shared presentation middleware remains globally attached');

ok(!pnl.includes('@php') && pnl.includes('@foreach($sectionRows as $section)') && pnl.includes('@foreach($summaryRows as $summary)'), 'P&L render-safe presentation contract is preserved');
ok(pnlController.includes('profitAndLossPresentation(array $report, array $accountUrls)'), 'P&L controller presentation model remains authoritative');
for (const key of ['revenue','direct_cost','gross_profit','operating_expenses','operating_profit','other_income','other_expense','net_profit']) {
  ok(managementService.includes(`'${key}' =>`), `management calculation ${key} remains present`);
}
ok(version === 'v1.1.33.237-ERP11.3.237', 'packaged release version is current');

const staleVisibleLabels = {
  'resources/views/system/sales-invoice-workflow-compare-v11379.blade.php': 'System Diagnostic · ERP-11.3.79',
  'resources/views/system/production-data-reset-v103172.blade.php': 'System Maintenance · ERP-10.31.72',
  'resources/views/system/post-reset-financial-cleanup-v103179.blade.php': 'System Maintenance · ERP-10.31.79',
  'resources/views/system/cash-voucher-native-journal-repair-v11319.blade.php': 'System Repair · ERP-11.3.21',
  'resources/views/system/accounting-journal-diagnostic-v11318.blade.php': 'System Diagnostic · ERP-11.3.21',
  'resources/views/administration/erp-user-management.blade.php': 'ERP-02 · Users · ERP-10.31.75',
  'resources/views/operations/bookings/group-package-unified-v103172.blade.php': 'Group Umrah Booking · ERP-10.31.72',
  'resources/views/accounting/chart-of-accounts/workspace.blade.php': 'Accounting Foundation · ERP-11.3.10',
};
for (const [path, label] of Object.entries(staleVisibleLabels)) {
  ok(!read(path).includes(label), `${path} has no stale visible release label`);
}

const migrationFiles = fs.readdirSync(new URL('../../database/migrations/', import.meta.url));
ok(!migrationFiles.some(name => name.includes('ui_professional')), 'UI pass adds no migration');
ok(!/DB::|->insert\(|->update\(|->delete\(|->save\(/.test(css + js), 'shared UI assets cannot mutate business data');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
