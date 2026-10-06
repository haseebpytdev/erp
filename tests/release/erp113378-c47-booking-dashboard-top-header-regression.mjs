import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const middleware = read('app/Http/Middleware/PresentBookingFocusedWorkspace.php');
const js = read('public/erp11390/general-progressive-step1.js');
const c45 = read('tests/release/erp113378-c45-booking-dashboard-header-ui-regression.mjs');
const c46 = read('tests/release/erp113378-c46-c45-non-dashboard-scope-restoration-regression.mjs');
const air = read('app/Services/Operations/BookingProductSummaryResolver.php');
const health = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const shell = read('app/Services/Operations/NativeErpLayoutResolver.php');
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };
const mainDashboardPath = path => /^operations\/bookings\/\d+\/?$/i.test(path);
const normalizeOuterHeading = html => html.replace(
  /(<(?:header|div|section)\b[^>]*class=(?:"[^"]*\bpage-header\b[^"]*"|'[^']*\bpage-header\b[^']*')[^>]*>.*?<h[12]\b[^>]*>).*?(<\/h[12]>)/is,
  '$1Booking Dashboard$2'
);

ok(presenter.includes('isMainBookingDashboardPath'), 'server presenter owns the exact dashboard route gate');
ok(presenter.includes("preg_match('#^operations/bookings/\\d+/?$#', $path)"), 'main booking route is exact and trailing-slash safe');
ok(presenter.includes('normalizeMainBookingOuterHeading'), 'server outer-heading normalizer exists');
ok(presenter.includes('class=(?:"[^"]*\\bpage-header\\b') && presenter.includes('<h[12]'), 'normalizer targets the page-header heading markup');
ok(presenter.includes("'$1Booking Dashboard$2'"), 'authoritative outer heading becomes Booking Dashboard');
ok(!presenter.includes("str_replace('BK-"), 'no global booking-reference replacement exists');
ok(!presenter.includes("preg_replace('/BK-"), 'no broad BK reference rewrite exists');
ok(presenter.includes('BookingWorkspaceShellPresenter'), 'response presenter remains the server-side authority');
ok(middleware.includes('$this->presenter->transform($request, $response)'), 'focused-workspace middleware invokes server presenter');
ok(shell.includes("'operations.bookings.show'"), 'native operations.bookings.show view authority remains discoverable');
ok(shell.includes('fromExistingBookingViews'), 'layout resolver still derives the installed native booking view');
ok(js.includes('etgpIsMainBookingDashboardC45'), 'C45 client route guard remains present');
ok(js.includes('etgpNormalizeBookingShellTitle11390'), 'C45 client normalizer remains preserved');
ok(c45.includes("node.textContent='Booking Dashboard'"), 'C45 dashboard title behavior remains covered');
ok(c46.includes('refresh has exactly one Booking Value KPI writer'), 'C46 KPI writer contract remains covered');
ok(c46.includes('legacy invoice status is preserved'), 'C46 non-dashboard restoration remains covered');
ok(air.includes("in_array('deleted_at', $columns, true)"), 'C41 Air resolver source remains unchanged');
ok(health.includes('normalizeSystemHealthShell') && !health.includes('legacyMarkers'), 'C44 Health middleware source remains unchanged');
ok(presenter.includes("preg_match('#^operations/bookings/\\d+/products"), 'product workspace route handling remains separate');
ok(presenter.includes("preg_match('#^operations/bookings/\\d+/review" ) === false, 'no broad review-route title rewrite was added');

for (const path of ['operations/bookings/27', 'operations/bookings/27/']) {
  ok(mainDashboardPath(path), `main dashboard path is accepted: ${path}`);
}

const representativeDashboard = '<div class="page-header"><h1>BK-2026-0027</h1></div>'
  + '<section class="etgp-booking-card"><h1>BK-2026-0027</h1></section>';
const normalizedDashboard = normalizeOuterHeading(representativeDashboard);
ok(normalizedDashboard.includes('<div class="page-header"><h1>Booking Dashboard</h1></div>'), 'outer page heading is normalized');
ok((normalizedDashboard.match(/BK-2026-0027/g) || []).length === 1, 'booking identity reference remains exactly once');
ok(normalizedDashboard.includes('<section class="etgp-booking-card"><h1>BK-2026-0027</h1></section>'), 'identity card reference is outside the mutation target');

const excludedPaths = [
  ['operations/bookings/27/products', 'products'],
  ['operations/bookings/27/products/air', 'Air'],
  ['operations/bookings/27/products/hotel', 'Hotel'],
  ['operations/bookings/27/products/transport', 'Transport'],
  ['operations/bookings/27/products/visa', 'Visa'],
  ['operations/bookings/27/review', 'review'],
  ['operations/bookings/27/sales-invoice', 'sales invoice'],
];
for (const [path, label] of excludedPaths) {
  ok(!mainDashboardPath(path), `${label} route is excluded from C47 mutation`);
}

console.log('erp113378-c47-booking-dashboard-top-header-regression: ' + assertions + ' assertions passed');
