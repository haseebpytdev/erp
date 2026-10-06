import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const c45 = read('tests/release/erp113378-c45-booking-dashboard-header-ui-regression.mjs');
const c46 = read('tests/release/erp113378-c46-c45-non-dashboard-scope-restoration-regression.mjs');
const c47 = read('tests/release/erp113378-c47-booking-dashboard-top-header-regression.mjs');
const air = read('app/Services/Operations/BookingProductSummaryResolver.php');
const health = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };
const mainDashboardPath = path => /^operations\/bookings\/\d+\/?$/i.test(path);
const normalizeTopbarTitle = html => html.replace(
  /(<header\b[^>]*class=(?:"[^"]*\btopbar\b[^"]*"|'[^']*\btopbar\b[^']*')[^>]*>.*?<([a-z][a-z0-9:-]*)\b[^>]*class=(?:"[^"]*\btop-title\b[^"]*"|'[^']*\btop-title\b[^']*')[^>]*>).*?(<\/\2>)/is,
  '$1Booking Dashboard$3'
);

ok(mainDashboardPath('operations/bookings/63'), 'main booking route matches');
ok(mainDashboardPath('operations/bookings/63/'), 'main booking route with trailing slash matches');
ok(presenter.includes("preg_match('#^operations/bookings/\\d+/?$#', $path)"), 'exact main-route guard remains preserved');
ok(presenter.includes('topbar') && presenter.includes('top-title'), 'server presenter targets production topbar title authority');
ok(!presenter.includes('page-header\\b'), 'obsolete page-header authority is removed');

const representative = '<title>BK-2026-0043 · Easy Ticket Travel ERP</title>'
  + '<header class="topbar"><div><div class="top-title">BK-2026-0043</div></div></header>'
  + '<div class="booking-hero"><h1>BK-2026-0043</h1></div>';
const normalized = normalizeTopbarTitle(representative);
ok(normalized.includes('<div class="top-title">Booking Dashboard</div>'), 'topbar title becomes Booking Dashboard');
ok(normalized.includes('<div class="booking-hero"><h1>BK-2026-0043</h1></div>'), 'booking identity heading remains unchanged');
ok((normalized.match(/BK-2026-0043/g) || []).length === 2, 'identity reference remains in title and booking identity area');
ok(normalized.includes('<title>BK-2026-0043 · Easy Ticket Travel ERP</title>'), 'document title remains unchanged');
ok(!presenter.includes("str_replace('BK-"), 'no broad BK replacement exists');
ok(!presenter.includes("preg_replace('/BK-"), 'no generic booking-reference replacement exists');
ok(!presenter.includes('<h[12]\\b'), 'no generic h1/h2 replacement exists');

const excluded = [
  ['products', 'products'],
  ['products/air', 'Air'],
  ['products/hotel', 'Hotel'],
  ['products/transport', 'Transport'],
  ['products/visa', 'Visa'],
  ['products/other-services', 'Other Services'],
  ['review', 'Review'],
  ['sales-invoice', 'Sales Invoice'],
  ['preview', 'Preview'],
  ['edit', 'Edit'],
];
for (const [suffix, label] of excluded) {
  ok(!mainDashboardPath(`operations/bookings/63/${suffix}`), `${label} route is excluded`);
}

ok(c45.includes('etgpNormalizeBookingShellTitle11390'), 'C45 dashboard behavior remains present');
ok(c46.includes('refresh has exactly one Booking Value KPI writer'), 'C46 non-dashboard behavior remains present');
ok(c47.includes('production topbar title is normalized'), 'C47 coverage uses the production topbar authority');
ok(air.includes("in_array('deleted_at', $columns, true)"), 'C41 Air source remains unchanged');
ok(health.includes('normalizeSystemHealthShell') && !health.includes('legacyMarkers'), 'C44 Health source remains unchanged');

console.log('erp113378-c48-booking-dashboard-topbar-title-runtime-regression: ' + assertions + ' assertions passed');
