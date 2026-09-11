import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const css = read('public/erp-ui/erp-professional.css');
const js = read('public/erp-ui/erp-professional.js');
const releaseMiddleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const dashboardPresenter = read('app/Http/Middleware/PresentDashboardFinancialSnapshot.php');
const dashboardService = read('app/Services/Dashboard/NativeFinancialSnapshotService.php');
const routes = read('routes/erp103179.php');
const version = read('VERSION.txt').trim();

ok(routes.includes("'/system/erp-assets/erp-professional.css'"), 'professional stylesheet has a Laravel asset route');
ok(routes.includes("'/system/erp-assets/erp-professional.js'"), 'professional script has a Laravel asset route');
ok(routes.includes("system.erp-assets.erp-professional-css"), 'professional stylesheet route is named');
ok(routes.includes("system.erp-assets.erp-professional-js"), 'professional script route is named');
ok(routes.includes("Route::middleware(['auth'])->group") && routes.includes('ErpProfessionalUiAssetController::class'), 'professional asset routes remain in the authenticated route group');
ok(assetController.includes("base_path('public/erp-ui/erp-professional.css')"), 'stylesheet controller uses the fixed professional CSS path');
ok(assetController.includes("base_path('public/erp-ui/erp-professional.js')"), 'script controller uses the fixed professional JS path');
ok(assetController.includes("'text/css; charset=UTF-8'"), 'stylesheet response has the correct MIME contract');
ok(assetController.includes("'application/javascript; charset=UTF-8'"), 'script response has the correct MIME contract');
ok(assetController.includes("'X-Content-Type-Options' => 'nosniff'"), 'professional asset responses prevent MIME sniffing');
ok(assetController.includes("'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0'"), 'professional assets use the required cache contract');
ok(assetController.includes("'Pragma' => 'no-cache'"), 'professional assets use the legacy no-cache contract');
ok(assetController.includes('abort_unless(is_file($path), 404)'), 'missing professional assets return 404');
ok(releaseMiddleware.includes("route('system.erp-assets.erp-professional-css')"), 'middleware uses the named Laravel stylesheet route');
ok(releaseMiddleware.includes("route('system.erp-assets.erp-professional-js')"), 'middleware uses the named Laravel script route');
ok(!releaseMiddleware.includes("asset('erp-ui/erp-professional.css')"), 'old public-path stylesheet authority is removed');
ok(!releaseMiddleware.includes("asset('erp-ui/erp-professional.js')"), 'old public-path script authority is removed');
ok((releaseMiddleware.match(/data-et-professional-ui=/g) || []).length >= 2, 'shared UI has a single-injection sentinel');
ok(releaseMiddleware.includes("str_contains($html, 'data-et-professional-ui=')"), 'duplicate shared injection is blocked');

const activeBlock = css.match(/html body\.et-ui-professional \.sidebar a\.nav-item\.active,[\s\S]*?box-shadow:none!important;\s*}/)?.[0] || '';
ok(activeBlock.length > 0, 'approved active-navigation override exists');
ok(activeBlock.includes('rgba(16,85,176,.72)'), 'active navigation matches the approved selected blue');
ok(activeBlock.includes('border-left:3px solid #60a5fa'), 'active navigation has a restrained left marker');
ok(!activeBlock.includes('background:#fff'), 'active navigation is not a white pill');
ok(!activeBlock.includes('linear-gradient'), 'active navigation does not render as a large blue CTA');
ok(activeBlock.includes('border-radius:6px') && activeBlock.includes('font-weight:650'), 'active navigation keeps compact corners and restrained emphasis');
ok(js.includes("link.style.setProperty('background', 'rgba(16,85,176,.72)'"), 'runtime active state matches the approved selected blue');
ok(js.includes("link.style.setProperty('color', '#fff'"), 'active navigation retains strong label contrast');
ok(js.includes("link.style.setProperty('border-left-color', '#60a5fa'"), 'runtime active state uses the thin approved marker');
ok(css.includes('height:37px!important') && css.includes('max-height:37px!important'), 'sidebar clickable rows match the approved height');
ok(!css.includes('html body.et-ui-professional .sidebar .nav-item,\nhtml body.et-ui-professional .sidebar a'), 'expandable nav containers are not assigned the fixed clickable-row height');
ok(css.includes('scrollbar-gutter:auto!important'), 'sidebar avoids premature scrollbar gutter');
ok(css.includes('scrollbar-color:transparent transparent') && css.includes('.nav:hover::-webkit-scrollbar-thumb'), 'sidebar scrollbar remains quiet until interaction');
ok(css.includes('width:21px!important') && css.includes('height:21px!important') && css.includes('color-mix(in srgb,var(--et-nav-accent) 22%,transparent)'), 'sidebar icons use approved coloured tiles');
ok(css.includes('color:#a8bfd9!important') && css.includes('letter-spacing:.11em!important'), 'sidebar section headings match the reference hierarchy');
ok(css.includes('width:42px!important') && css.includes('height:42px!important'), 'sidebar logo area matches the reference');
ok(css.includes('min-height:42px!important') && css.includes('opacity:.58'), 'sidebar footer is compact and restrained');
ok(css.includes('min-height:72px!important') && css.includes('margin:14px 9px 3px!important'), 'logo and section rhythm match the reference');
for (const heading of ['OPERATIONS','ACCOUNTING','MASTER DATA','ADMINISTRATION']) {
  ok(js.includes(`'${heading}'`), `sidebar grouping includes ${heading}`);
}
ok(js.includes("document.createElement('div')") && js.includes("heading.classList.add('nav-section', 'et-ui-nav-section')"), 'sidebar grouping adds presentation headings only');
ok(js.includes('sidebarNav.appendChild(row)') && !js.includes("document.createElement('a')"), 'sidebar grouping moves existing rows without creating links');
ok(js.indexOf("'OPERATIONS'") < js.indexOf("'ACCOUNTING'") && js.indexOf("'ACCOUNTING'") < js.indexOf("'MASTER DATA'") && js.indexOf("'MASTER DATA'") < js.indexOf("'ADMINISTRATION'"), 'sidebar sections follow the approved reference order');
ok(js.includes("['bookings'], ['sales invoices'], ['supplier costing'], ['vouchers'], ['advances']"), 'Operations preserves Supplier Costing and grouped operational rows');
ok(js.includes("['chart of accounts'], ['account mappings'], ['journals'], ['ledgers'], ['reports']"), 'Accounting follows the approved reference order');
ok(js.includes("['party master'], ['travel masters'], ['products & services']"), 'Master Data follows the approved reference order');
ok(js.includes("['organization'], ['currency rates'], ['financial years'], ['health & updates', 'system health & updates', 'system settings']"), 'Administration preserves the system link in reference order');
ok(js.includes("node.remove()") && js.includes("sidebarNav.dataset.etSidebarGrouped = 'reference'"), 'legacy headings are replaced once without duplicate section labels');
ok(js.includes("row.querySelectorAll('a[href]').length > 1") && js.includes("controlledMenu.querySelector('a[href]')"), 'chevrons are limited to rows with real rendered children');
ok(js.includes("document.createElement('span')") && js.includes("chevron.className = 'et-ui-nav-chevron'"), 'real parent rows receive the reference chevron treatment');
ok(js.includes('const existingChevron') && js.includes("existingChevron.classList.add('et-ui-nav-chevron')"), 'native chevrons are reused without duplication');
ok(js.includes("liveBadge.className = 'et-ui-live-badge'") && css.includes('background:#159957!important'), 'footer includes the approved green Live badge');
for (const module of ['dashboard','administration','organization','master-data','travel','operations','sales','purchase','accounting','reports','system']) {
  ok(css.includes(`href*=\"${module}\"`), `module accent remains available for ${module}`);
}

ok(js.includes("shell.classList.add('et-ui-utility-topbar')"), 'topbar receives utility authority');
ok(js.includes("shellDashboard.textContent = 'Easy Ticket ERP'"), 'topbar no longer duplicates Dashboard page identity');
ok(!/placeholder\s*=\s*['\"]Search|notification|branch selector/i.test(js), 'no fake topbar controls are introduced');
ok(css.includes('.et-ui-utility-topbar') && css.includes('height:54px!important'), 'topbar is slim');

ok(js.includes("body.dataset.etDashboardPhase1 = 'approved-reference'"), 'Dashboard receives bounded Phase 1 treatment');
ok(js.includes("companyTitle.textContent = 'Dashboard'"), 'Dashboard title moves to page canvas');
ok(js.includes("overview.textContent = 'Overview'"), 'duplicate Management Overview identity is reduced to a kicker');
ok(js.includes("headerCanvas.dataset.etDashboardHeader = 'true'"), 'Dashboard page-header authority is annotated');
ok(css.includes('[data-et-dashboard-header="true"]'), 'Dashboard page header has shared styling');

for (const label of ['Today Sales','Month Sales','Receivables','Payables','Cash & Bank','Gross Profit']) {
  ok(js.includes(`'${label}'`), `Dashboard KPI annotation includes ${label}`);
}
ok(js.includes("card.classList.add('et-dashboard-kpi')"), 'KPI cards receive bounded shared class');
ok(js.includes("kpiGrid[0].classList.add('et-dashboard-kpi-grid')"), 'KPI collection receives responsive grid');
ok(css.includes('repeat(auto-fit,minmax(190px,1fr))'), 'desktop KPI grid is responsive');
ok(css.includes('grid-template-columns:repeat(2,minmax(0,1fr))'), 'tablet KPI grid uses two readable columns');
ok(css.includes('grid-template-columns:1fr!important'), 'mobile KPI grid stacks safely');
ok(css.includes('.et-dashboard-money'), 'financial values have explicit shared treatment');
ok(css.includes('text-overflow:clip!important'), 'financial values do not use ellipsis');
ok(css.includes('overflow:visible!important') && css.includes('white-space:nowrap!important'), 'full KPI values remain visible');
ok(css.includes('font-variant-numeric:tabular-nums!important'), 'KPI values use tabular numerals');
ok(css.includes('[data-et-dashboard-section="trend"] canvas{max-height:270px'), 'financial chart height is bounded');
ok(css.includes('[data-et-dashboard-section="mix"] canvas{max-height:230px'), 'product mix chart height is bounded');

for (const phrase of ['no-ssh maintenance','this page exists because the hosting has no ssh or terminal','erp-10.1 ticket commercial boundary','no gl yet','future vendor bill/ap workflow','2110 later']) {
  ok(js.includes(`'${phrase}'`), `System Health cleanup recognizes ${phrase}`);
}
ok(js.includes('legacyReleaseCopy'), 'short legacy ERP-10 health labels are suppressed');
ok(js.includes("maintenanceCopy.textContent = 'Application and database status.'"), 'System Health subtitle becomes concise');
ok(js.includes("bounded.dataset.etObsoleteHealthCopy = 'hidden'"), 'obsolete copy removal is bounded to text containers');
ok(js.includes("target.closest('a,button,form')"), 'System Health cleanup never hides actions or forms');
for (const label of ['Application','Database','Report Header','Runtime']) {
  ok(js.includes(`'${label}'`), `System Health status annotation includes ${label}`);
}
ok(js.includes("card.classList.add('et-health-status')"), 'real health cards receive shared status treatment');
ok(js.includes("healthGrid[0].classList.add('et-health-status-grid')"), 'real health cards receive responsive grid treatment');
ok(css.includes('.et-health-status-grid') && css.includes('.et-health-status{'), 'System Health status primitives exist');
ok(releaseMiddleware.includes('data-et-dangerous-actions="true"'), 'dangerous actions are explicitly isolated');
ok(releaseMiddleware.includes('Advanced') && releaseMiddleware.includes('Dangerous Actions'), 'dangerous actions have approved warning hierarchy');
ok(releaseMiddleware.includes('Production Transaction Reset'), 'production reset action remains present');
ok(releaseMiddleware.includes('Post-Reset Financial Cleanup'), 'financial cleanup action remains present');
ok(css.includes('border-left:4px solid var(--et-danger)'), 'dangerous actions use warning treatment');

ok(releaseMiddleware.includes("str_starts_with($path, 'voucher/')"), 'public voucher exclusion remains');
ok(releaseMiddleware.includes("str_contains($routeName, '.print')"), 'print exclusion remains');
ok(releaseMiddleware.includes("str_contains($routeName, '.pdf')"), 'PDF exclusion remains');
ok(css.includes('@media print'), 'browser print safeguard remains');
ok(routes.includes('ApplyErpReleaseMetadata::class'), 'presentation middleware remains registered');

for (const key of ['today_sales','month_sales','receivables','payables','cash_bank','gross_profit','today_collection','today_payments','period_supplier_cost']) {
  ok(dashboardPresenter.includes(`$data['${key}']`), `Dashboard presenter preserves ${key}`);
  ok(dashboardService.includes(`'${key}'`), `Dashboard service still supplies ${key}`);
}
ok(!/DB::|->insert\(|->update\(|->delete\(|->save\(/.test(css + js), 'Phase 1 browser assets cannot mutate data');
ok(version === 'v1.1.33.238-ERP11.3.238', 'version is not incremented');
const migrations = fs.readdirSync(new URL('../../database/migrations/', import.meta.url));
ok(!migrations.some(file => file.includes('visual_match') || file.includes('phase1')), 'Phase 1 adds no migration');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
