import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const css = read('public/erp-ui/erp-booking-register-reference.css');
const legacyJs = read('public/erp-ui/erp-booking-register-reference.js');
const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const interactions = read('public/erp-ui/erp-register-workspace.js');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(css.includes('ERP-11.3.237 — Booking Register reference-match presentation layer'), 'approved .237 visual stylesheet remains the shared register design');
ok(legacyJs.includes("path !== 'operations/bookings'"), 'historical .237 client implementation remains traceable');
ok(!controller.includes("base_path('public/erp-ui/erp-booking-register-reference.js')"), 'old Booking DOM reconstruction JS is no longer served');
ok(presenter.includes("'operations/bookings' => ["), 'Booking Register is configured server-side');
ok(presenter.includes("'total_label' => 'Total Bookings'") && presenter.includes("'pending_label' => 'Pending Confirmation'") && presenter.includes("'approved_label' => 'Confirmed'") && presenter.includes("'fourth_label' => 'Cancelled'"), 'approved Booking KPI set is server-defined');
ok(presenter.includes("['pending_approval', 'Pending']") && presenter.includes("['confirmed', 'Confirmed']") && presenter.includes("['cancelled', 'Cancelled']"), 'Booking quick filters use server-normalized status keys');
ok(view.includes('Search &amp; Filter') && view.includes('Travel Date From') && view.includes('Travel Date To'), 'approved Booking filter structure is server-rendered');
ok(view.includes('data-register-count') && view.includes('data-register-export-visible') && view.includes('data-register-row-select'), 'count/export/selection controls are server-rendered');
ok(view.includes('Quick Workflow') && view.includes("$config['breakdown_title']") && view.includes('Recent Activity'), 'approved insight panels are server-rendered');
ok(interactions.includes('var pageSize = 15;'), 'minimal interaction layer keeps approved 15-row client page size');
ok(!/createElement\(['"](?:section|article|table|form)/.test(interactions), 'interaction JS does not rebuild page structure');
ok(!interactions.includes('fetch(') && !interactions.includes('XMLHttpRequest') && !interactions.includes('localStorage') && !interactions.includes('sessionStorage'), 'interaction layer performs no network/persistent state mutation');
ok(controller.includes("base_path('public/erp-ui/erp-booking-register-reference.css')"), 'approved shared register CSS remains served');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'immutable browser cache policy remains intact');
ok(version === 'v1.1.33.245-ERP11.3.245', 'functional consolidation checkpoint does not bump release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
