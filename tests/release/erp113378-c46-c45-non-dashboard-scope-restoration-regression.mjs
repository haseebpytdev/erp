import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const js = read('public/erp11390/general-progressive-step1.js');
const css = read('public/erp11390/general-progressive-step1.css');
const c41 = read('app/Services/Operations/BookingProductSummaryResolver.php');
const c44 = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const refreshBlock = js.slice(js.indexOf('var etgpRefreshPersistedBookingState113153='), js.indexOf('window.etgpRefreshPersistedBookingState113153='));
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };

ok(js.includes('var c45=etgpIsMainBookingDashboardC45();'), 'C45 route helper is used for KPI scoping');
ok(js.includes("c45?'':'Resolved from saved booking commercial authority'"), 'non-dashboard Booking Value helper is restored');
ok((refreshBlock.match(/etgpAirSetKpi113124\('Booking Value'/g) || []).length === 1, 'refresh has exactly one Booking Value KPI writer');
ok(!refreshBlock.includes("toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}),'');"), 'refresh has no unconditional empty Booking Value pre-write');
ok(js.includes("etgpAirSetKpi113124('Travel Status'") && js.includes("c45?'':(blockers.length?blockers[0]:'All selected travel services are ready')"), 'Travel Status remains C45-conditioned');
ok(js.includes("if(!c45){") && js.includes("info.setAttribute('data-etgp-invoice-status','1')"), 'legacy invoice branch is non-dashboard scoped');
ok(js.includes("invoice.status||'Draft'"), 'legacy invoice status is preserved');
ok(js.includes('Open Sales Invoice'), 'legacy invoice link text is preserved');
ok(js.includes("root.insertBefore(info,root.firstChild)"), 'legacy invoice placement is preserved');
ok(js.includes("margin:8px 0;padding:8px 12px;border:1px solid #dce7f3;border-radius:8px;background:#fff;font-size:11px"), 'legacy C44 invoice style is preserved');
ok(js.includes("if(c45&&invoice&&data&&data.sales_invoice_url"), 'C45 invoice merge remains scoped');
ok(js.includes('data-etgp-lock-invoice'), 'C45 merged invoice marker remains');
ok(js.includes('data.sales_invoice_url'), 'C45 invoice URL authority remains');
ok(!js.includes('Sales Invoice status unavailable.'), 'incorrect replacement invoice text is absent');
ok(css.includes('html.et-c45-booking-dashboard'), 'C45 CSS remains route scoped');
ok(js.includes("/^\\/operations\\/bookings\\/\\d+\\/?$/i"), 'C45 exact route remains enforced');
ok(c41.includes("in_array('deleted_at', $columns, true)"), 'C41 Air resolver is unchanged');
ok(c44.includes('normalizeSystemHealthShell') && !c44.includes('legacyMarkers'), 'C44 Health middleware is unchanged');

console.log('erp113378-c46-c45-non-dashboard-scope-restoration-regression: ' + assertions + ' assertions passed');
