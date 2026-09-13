import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const bookingCss = read('public/erp-ui/erp-booking-register-reference.css');
const legacyJs = read('public/erp-ui/erp-commercial-register-reference.js');
const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const interactions = read('public/erp-ui/erp-register-workspace.js');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(legacyJs.includes("'sales/invoices': {") && legacyJs.includes("'supplier-costing': {"), 'historical .238 commercial implementation remains traceable');
ok(!controller.includes("base_path('public/erp-ui/erp-commercial-register-reference.js')"), 'old commercial DOM reconstruction JS is no longer served');
ok(presenter.includes("'sales/invoices' => [") && presenter.includes("'supplier-costing' => ["), 'Sales Invoice and Supplier Costing are server-presented');
ok(presenter.includes("'total_label' => 'Total Invoices'") && presenter.includes("'total_label' => 'Total Costings'"), 'commercial Total KPI labels remain authoritative');
ok(presenter.includes("'pending_label' => 'Pending Approval'") && presenter.includes("'approved_label' => 'Approved'") && presenter.includes("'fourth_label' => 'Posted'"), 'commercial workflow KPI labels remain intact');
ok(presenter.includes("'field2_label' => 'Customer'") && presenter.includes("'field3_label' => 'Booking'"), 'Sales Invoice filter semantics remain intact');
ok(presenter.includes("'field2_label' => 'Supplier'") && presenter.includes("'field3_label' => 'Product'"), 'Supplier Costing filter semantics remain intact');
ok(presenter.includes("'breakdown_title' => 'Invoices by Status'") && presenter.includes("'breakdown_title' => 'Costings by Product'"), 'commercial breakdown semantics remain intact');
ok(view.includes('data-register-export-visible') && view.includes('data-register-export-selected'), 'CSV export controls are emitted server-side');
ok(view.includes('data-register-row-menu') && view.includes("$config['action_text']"), 'server markup reuses authoritative native Open links');
ok(view.includes('Quick Workflow') && view.includes('Recent Activity'), 'commercial insight panels are server-rendered');
ok(bookingCss.includes('.et-booking-ref-kpis') && bookingCss.includes('.et-booking-ref-filter-card') && bookingCss.includes('.et-booking-ref-register-card') && bookingCss.includes('.et-booking-ref-insights'), 'commercial pages reuse the approved Booking visual system');
ok(interactions.includes('var pageSize = 15;'), 'minimal interaction layer retains 15-row pagination');
ok(!/createElement\(['"](?:section|article|table|form)/.test(interactions), 'commercial interaction JS does not reconstruct register structure');
ok(!interactions.includes('fetch(') && !interactions.includes('XMLHttpRequest') && !interactions.includes('localStorage') && !interactions.includes('sessionStorage'), 'commercial interaction layer performs no network/persistent state mutation');
ok(controller.includes("base_path('public/erp-ui/erp-register-workspace.js')"), 'minimal shared interaction asset is served');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned immutable browser caching remains intact');
ok(version === 'v1.1.33.252-ERP11.3.252', 'packaged release version is current');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
