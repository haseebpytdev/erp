import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const css = read('public/erp-ui/erp-booking-register-reference.css');
const js = read('public/erp-ui/erp-booking-register-reference.js');
const assetController = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(css.includes('ERP-11.3.237 — Booking Register reference-match presentation layer'), 'reference-match layer is explicitly scoped');
ok(js.includes("path !== 'operations/bookings'"), 'reference-match enhancement is exact-path Booking Register only');
ok(js.includes('Total Bookings'), 'reference KPI replaces Draft-only first metric with Total Bookings');
ok(js.includes('Pending Confirmation') && js.includes('Confirmed') && js.includes('Cancelled'), 'reference KPI status set is present');
ok(js.includes('Quick Filters:') && js.includes('data-quick="all"'), 'quick status filters are present');
ok(js.includes('All Customers') && js.includes('All Types'), 'customer and travel-type filters are present');
ok(js.includes('Travel Date From') && js.includes('Travel Date To'), 'travel date range filters are present');
ok(js.includes('Bookings (<span data-booking-count>'), 'register header exposes dynamic booking count');
ok(js.includes('Export visible CSV') && js.includes('Export selected CSV'), 'safe client-side export options are present');
ok(js.includes('et-booking-select-all') && js.includes('et-booking-row-select'), 'row selection controls are present');
ok(js.includes('et-booking-row-menu-button') && js.includes('Open Booking'), 'compact row action menu is present');
ok(js.includes('pageSize: 15'), 'reference pagination uses 15 rows per page');
ok(js.includes('Quick Workflow'), 'Quick Workflow reference panel is present');
ok(js.includes('Bookings by Type') && js.includes('conic-gradient'), 'booking type donut is generated from rendered rows');
ok(js.includes('Recent Activity'), 'Recent Activity panel is present');
ok(!js.includes('fetch(') && !js.includes('XMLHttpRequest'), 'reference enhancement performs no server/network mutation');
ok(!js.includes('localStorage') && !js.includes('sessionStorage'), 'reference enhancement adds no persistent browser state');
ok(!/\.submit\(|requestSubmit\(|FormData\(|DB::|->insert\(|->update\(|->delete\(/.test(js + css), 'reference assets contain no business-data mutation mechanism');
ok(assetController.includes("base_path('public/erp-ui/erp-booking-register-reference.css')"), 'reference CSS is served through professional asset controller');
ok(assetController.includes("base_path('public/erp-ui/erp-booking-register-reference.js')"), 'reference JS is served through professional asset controller');
ok(assetController.indexOf('file_get_contents($registerUi)') < assetController.indexOf('file_get_contents($bookingRegisterUi)'), 'reference assets load after shared register layer');
ok(assetController.indexOf('file_get_contents($bookingRegisterUi)') < assetController.lastIndexOf('file_get_contents($base)'), 'reference JS executes before base/finalizer sequence');
ok(assetController.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned immutable browser cache policy remains intact');
ok(version === 'v1.1.33.238-ERP11.3.238', 'functional reference-match checkpoint does not bump production release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
