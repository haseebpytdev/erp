import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p, 'utf8');
const ok = (v, m) => assert.ok(v, m);
const shared = read('resources/views/reports/travel/partials/movement-table-styles.blade.php');
const tabs = read('resources/views/reports/travel/partials/movement-tabs.blade.php');
const filter = read('resources/views/reports/travel/partials/filter-form.blade.php');
const report = read('resources/views/reports/travel/report.blade.php');
const arrival = read('resources/views/reports/travel/movements/arrival.blade.php');
const makkahIn = read('resources/views/reports/travel/movements/makkah-checkin.blade.php');
const makkahOut = read('resources/views/reports/travel/movements/makkah-checkout.blade.php');
const departure = read('resources/views/reports/travel/movements/departure.blade.php');
const config = read('config/et_erp_release.php');
const service = read('app/Services/Reports/TravelReportService.php');
const hotelController = read('app/Http/Controllers/Operations/GeneralBookingHotelProductController.php');

const nativeViews = [arrival, makkahIn, makkahOut, departure];
const tabIndex = (v) => v.indexOf("@include('reports.travel.partials.movement-tabs'");
const filterIndex = (v) => v.indexOf("@include('reports.travel.partials.filter-form'");
const headingIndex = (v) => v.indexOf('et-travel-screen-heading');

ok(tabs.includes('<nav class="et-arrival-card et-arrival-tabs"') && tabs.includes('aria-label="Movement Reports"'), 'one shared movement tab authority is used');
ok(nativeViews.every((v) => tabIndex(v) > headingIndex(v) && filterIndex(v) > tabIndex(v)), 'Arrival and Makkah authority order is heading, tabs, compact filters');
ok(report.includes("@include('reports.travel.partials.movement-tabs'") && report.includes("'modifier'=>'et-report-filter-one-line'"), 'generic movement reports use the same tabs/filter order');
ok(!report.includes('Movement / Intimation Reports'), 'obsolete oversized movement heading is absent');
ok(nativeViews.every((v) => v.includes('et-report-filter-one-line')) && filter.includes('.et-report-filter-card'), 'all movement filters retain shared compact authority');
ok(shared.includes('.et-travel-report .et-report-filter-one-line .et-report-filter-form{display:flex') && shared.includes('@media(max-width:760px)'), 'generic Madinah filters are compact on desktop and wrap responsively');
ok(shared.includes('body.et-ui-professional .et-travel-movement-table thead > tr > th') && shared.includes('padding:8px 10px;line-height:1.25'), 'header winning rule is scoped at 8px 10px');
ok(shared.includes('body.et-ui-professional .et-travel-movement-table tbody > tr > td') && shared.includes('padding:3px 10px;line-height:1.25;min-height:0;height:auto'), 'body winning rule defeats global table padding at 3px 10px');
ok(shared.includes('tbody > tr > td > p') && shared.includes('margin:0'), 'nested paragraph margins cannot recreate excess row height');
ok(!shared.includes('tbody > tr{min-height') && !shared.includes('tbody tr{min-height'), 'no travel row min-height forces tall rows');
ok(shared.includes('.et-arrival-table col.et-arrival-customer-col{width:clamp(220px,26vw,340px)}'), 'Arrival Customer / Group width binding remains preserved');
ok(shared.includes('overflow-x:auto') && shared.includes('min-width:900px'), 'mobile horizontal overflow remains preserved');
ok(shared.includes('data-et-travel-print-document') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>.sidebar') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>main.main'), 'report-only print root/isolation remains preserved');
const directives = [...report.matchAll(/@(if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|isset|endisset|unless|endunless)\b/g)].map((m) => m[1]);
const stack = [];
for (const token of directives) {
  if (['if', 'foreach', 'forelse', 'isset', 'unless'].includes(token)) stack.push(token);
  else if (['endif', 'endforeach', 'endforelse', 'endisset', 'endunless'].includes(token)) { ok(stack.length > 0, `unexpected closing Blade directive: ${token}`); stack.pop(); }
}
ok(stack.length === 0, `unbalanced report Blade directives: ${stack.join(',')}`);
ok(service.includes('class TravelReportService') && hotelController.includes('class GeneralBookingHotelProductController'), 'movement data and Hotel authorities remain unchanged');
ok(!hotelController.includes('C86.3.1') && !service.includes('C86.3.1'), 'no presentation release marker entered data authorities');
ok(config.includes("'asset_version' => 'ERP-11.3.378-C86.3.1'") && config.includes("'corrective_build' => 'C86.3.1'") && config.includes("'corrective_name' => 'Movement Report UI Recovery'"), 'C86.3.1 release identity is active');
console.log('ERP-11.3.378 C86.3.1 movement report UI recovery regression: PASS / 17');
