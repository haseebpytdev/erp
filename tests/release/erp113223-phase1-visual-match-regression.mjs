import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const css = read('public/erp-ui/erp-professional.css');
const js = read('public/erp-ui/erp-professional.js');
const releaseMiddleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const dashboardPresenter = read('app/Http/Middleware/PresentDashboardFinancialSnapshot.php');
const dashboardService = read('app/Services/Dashboard/NativeFinancialSnapshotService.php');
const routes = read('routes/erp103179.php');
const version = read('VERSION.txt').trim();

ok(releaseMiddleware.includes("asset('erp-ui/erp-professional.css')"), 'shared stylesheet still loads');
ok(releaseMiddleware.includes("asset('erp-ui/erp-professional.js')"), 'shared script still loads');
ok((releaseMiddleware.match(/data-et-professional-ui=/g) || []).length >= 2, 'shared UI has a single-injection sentinel');
ok(releaseMiddleware.includes("str_contains($html, 'data-et-professional-ui=')"), 'duplicate shared injection is blocked');

const activeBlock = css.match(/html body\.et-ui-professional \.sidebar \.nav-item\.active,[\s\S]*?box-shadow:none!important;\s*}/)?.[0] || '';
ok(activeBlock.length > 0, 'approved active-navigation override exists');
ok(activeBlock.includes('rgba(23,105,210,.62)'), 'active navigation uses blue tint');
ok(activeBlock.includes('border-left:3px solid #79b5ff'), 'active navigation has restrained left marker');
ok(!activeBlock.includes('background:#fff'), 'active navigation is not a white pill');
ok(js.includes("link.style.setProperty('background', 'linear-gradient"), 'runtime active state defeats legacy white inline styling');
ok(js.includes("link.style.setProperty('color', '#fff'"), 'active navigation retains strong label contrast');
ok(css.includes('height:35px!important') && css.includes('max-height:35px!important'), 'sidebar rows are compact');
ok(css.includes('scrollbar-gutter:auto!important'), 'sidebar avoids premature scrollbar gutter');
ok(css.includes('min-height:52px!important') && css.includes('opacity:.72'), 'sidebar footer is compact and restrained');
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
ok(version === 'v1.1.33.223-ERP11.3.223', 'version is not incremented');
const migrations = fs.readdirSync(new URL('../../database/migrations/', import.meta.url));
ok(!migrations.some(file => file.includes('visual_match') || file.includes('phase1')), 'Phase 1 adds no migration');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
