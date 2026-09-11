import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const bookingCss = read('public/erp-ui/erp-booking-register-reference.css');
const js = read('public/erp-ui/erp-commercial-register-reference.js');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(js.includes("'sales/invoices': {") && js.includes("'supplier-costing': {"), 'both target registers are exact-path configured');
ok(!js.includes("'operations/bookings': {"), 'Booking Register is not reimplemented by the commercial reference layer');
ok(js.includes("html.classList.add('et-booking-register-reference'"), 'commercial registers intentionally reuse approved Booking reference visual system');
ok(js.includes("totalLabel: 'Total Invoices'") && js.includes("totalLabel: 'Total Costings'"), 'reference first KPI is Total for both registers');
ok(js.includes("pendingLabel: 'Pending Approval'") && js.includes("approvedLabel: 'Approved'") && js.includes("postedLabel: 'Posted'"), 'workflow KPI status set is present');
ok(js.includes('Quick Filters:') && js.includes('data-quick="draft"') && js.includes('data-quick="pending_approval"') && js.includes('data-quick="approved"') && js.includes('data-quick="posted"'), 'quick workflow filters are present');
ok(js.includes("field2Label: 'Customer'") && js.includes("field3Label: 'Booking'"), 'Sales Invoice customer and booking filters are present');
ok(js.includes("field2Label: 'Supplier'") && js.includes("field3Label: 'Product'"), 'Supplier Costing supplier and product filters are present');
ok(js.includes('Date From') && js.includes('Date To') && js.includes('All Statuses'), 'date range and status filters are present');
ok(js.includes('data-commercial-count'), 'register headings expose dynamic record counts');
ok(js.includes('Export visible CSV') && js.includes('Export selected CSV'), 'safe client-side CSV exports are present');
ok(js.includes('et-booking-select-all') && js.includes('et-booking-row-select'), 'row selection uses approved reference controls');
ok(js.includes('et-booking-row-menu-button') && js.includes("actionText: 'Open Invoice'") && js.includes("actionText: 'Open Costing'"), 'compact action menus reuse existing open links');
ok(js.includes('pageSize: 15'), 'reference pagination uses 15 rows per page');
ok(js.includes("breakdownTitle: 'Invoices by Status'") && js.includes("breakdownTitle: 'Costings by Product'"), 'business-appropriate breakdown panels are present');
ok(js.includes('Quick Workflow') && js.includes('Recent Activity') && js.includes('conic-gradient'), 'workflow, donut and recent activity panels are present');
ok(js.includes("node.textContent = 'Supplier Costing'"), 'Supplier Costing topbar fallback title is normalized');
ok(js.includes("'et-booking-native-superseded'"), 'superseded native register presentation is hidden using approved Booking reference mechanism');
ok(bookingCss.includes('.et-booking-ref-kpis') && bookingCss.includes('.et-booking-ref-filter-card'), 'approved KPI/filter visual system remains available');
ok(bookingCss.includes('.et-booking-ref-register-card') && bookingCss.includes('.et-booking-ref-insights'), 'approved table/insight visual system remains available');
ok(!js.includes('fetch(') && !js.includes('XMLHttpRequest'), 'commercial reference enhancement performs no network fetch');
ok(!js.includes('localStorage') && !js.includes('sessionStorage'), 'commercial reference enhancement adds no browser persistence');
ok(!/\.submit\(|requestSubmit\(|FormData\(|DB::|->insert\(|->update\(|->delete\(/.test(js), 'commercial reference script contains no business-data mutation mechanism');
ok(controller.includes("base_path('public/erp-ui/erp-commercial-register-reference.js')"), 'commercial reference JS is served by professional asset controller');
ok(controller.indexOf('file_get_contents($bookingRegisterUi)') < controller.indexOf('file_get_contents($commercialRegisterUi)'), 'commercial reference JS loads after Booking reference JS');
ok(controller.indexOf('file_get_contents($commercialRegisterUi)') < controller.lastIndexOf('file_get_contents($base)'), 'commercial reference JS executes before base/finalizer sequence');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned immutable browser caching remains intact');
ok(version === 'v1.1.33.238-ERP11.3.238', 'functional ERP-11.3.238 checkpoint does not bump production release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
