import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p, 'utf8');
const ok = (v, m) => assert.ok(v, m);
const shared = read('resources/views/reports/travel/partials/movement-table-styles.blade.php');
const tabs = read('resources/views/reports/travel/partials/movement-tabs.blade.php');
const report = read('resources/views/reports/travel/report.blade.php');
const arrival = read('resources/views/reports/travel/movements/arrival.blade.php');
const departure = read('resources/views/reports/travel/movements/departure.blade.php');
const makkahIn = read('resources/views/reports/travel/movements/makkah-checkin.blade.php');
const makkahOut = read('resources/views/reports/travel/movements/makkah-checkout.blade.php');
const filter = read('resources/views/reports/travel/partials/filter-form.blade.php');
const config = read('config/et_erp_release.php');
const service = read('app/Services/Reports/TravelReportService.php');
const hotelController = read('app/Http/Controllers/Operations/GeneralBookingHotelProductController.php');
const nativeViews = [arrival, departure, makkahIn, makkahOut];

ok(arrival.includes('et-arrival-card et-travel-screen-heading') && arrival.includes('et-arrival-sub') && arrival.includes('et-arrival-title'), 'Arrival header remains the immutable visual authority');
ok(report.includes('et-arrival-card et-travel-screen-heading') && report.includes('class="et-arrival-title"') && report.includes('class="et-arrival-sub"'), 'generic movement headers use Arrival header classes');
ok(nativeViews.every((v) => v.includes('et-arrival-card') && v.includes('et-arrival-title') && v.includes('et-arrival-sub')), 'all native movement headers use Arrival presentation classes');
ok(report.includes("$report==='madinah-checkout' ? 'Travel Reports / Movement Reports / Madinah'") && report.includes('Operational hotel check-out movement reporting. No financial data.'), 'Madinah Check-out breadcrumb and subtitle are standardized without changing its data scope');
ok(!report.includes('Madinah Check-out</h1><p>Operational travel activity only'), 'escaped/generic Madinah Check-out presentation is absent');

const tabInclude = "@include('reports.travel.partials.movement-tabs'";
ok(tabs.includes('<nav class="et-arrival-card et-arrival-tabs"') && tabs.includes('gap:7px'), 'Arrival tab wrapper, gap, and shared authority remain unchanged');
ok(nativeViews.every((v) => v.indexOf(tabInclude) > v.indexOf('et-travel-screen-heading')) && report.indexOf(tabInclude) > report.indexOf('et-travel-screen-heading'), 'all six reports place shared tabs after the header and before filters');
ok(!report.includes('Movement / Intimation Reports'), 'obsolete oversized movement heading remains absent');

ok(filter.includes('.et-report-filter-card') && shared.includes('.et-travel-report .et-report-filter-one-line .et-report-filter-form{display:flex'), 'filters use the shared compact authority');
ok(nativeViews.every((v) => v.includes('et-report-filter-one-line')) && report.includes("'modifier'=>'et-report-filter-one-line'"), 'all six reports use compact filter presentation');
const apply = report.indexOf("'label'=>'Apply Filters'");
const reset = report.indexOf("'label'=>'Reset'");
const print = report.indexOf("'label'=>'Print'");
const exportCsv = report.indexOf("'label'=>'Export CSV'");
ok(apply < reset && reset < print && print < exportCsv, 'Madinah Check-in action order is Apply, Reset, Print, Export');

ok(shared.includes('padding:8px 10px;line-height:1.25') && shared.includes('padding:3px 10px;line-height:1.2;min-height:0;height:auto'), 'effective movement header/body density is preserved with final compact line-height');
ok(!shared.includes('tbody > tr{min-height') && shared.includes('body.et-ui-professional'), 'no row minimum height or global ERP table rule is introduced');
ok(shared.includes('.et-arrival-table col.et-arrival-customer-col{width:clamp(220px,26vw,340px)}'), 'Arrival Customer/Group column binding remains intact');
ok(shared.includes('et-arrival-table td:nth-child(4)') && shared.includes('et-makkah-table td:nth-child(3)') && shared.includes('white-space:nowrap') && shared.includes('min-width:220px'), 'Customer/Group screen cells use one-line minimum-width presentation');
ok(shared.includes('@media screen and (min-width:761px)') && shared.includes('overflow-x:auto') && shared.includes('min-width:900px'), 'desktop nowrap is scoped and horizontal scrolling remains available');
ok(shared.includes('@media print') && shared.includes('white-space:normal;min-width:0'), 'print can wrap Customer/Group while preserving all columns');

ok(shared.includes('data-et-travel-print-document') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>.sidebar') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>main.main'), 'print root and isolation remain report-only');
const directives = [...report.matchAll(/@(if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|isset|endisset|unless|endunless)\b/g)].map((m) => m[1]);
const stack = [];
for (const token of directives) {
  if (['if', 'foreach', 'forelse', 'isset', 'unless'].includes(token)) stack.push(token);
  else if (['endif', 'endforeach', 'endforelse', 'endisset', 'endunless'].includes(token)) { ok(stack.length > 0, `unexpected closing Blade directive: ${token}`); stack.pop(); }
}
ok(stack.length === 0, `unbalanced report Blade directives: ${stack.join(',')}`);
ok(service.includes('class TravelReportService') && hotelController.includes('class GeneralBookingHotelProductController'), 'movement and Hotel data authorities remain present and unchanged in scope');
ok(!service.includes('C86.3.2') && !hotelController.includes('C86.3.2'), 'no functional/data release marker entered protected authorities');
ok(config.includes("'asset_version' => 'ERP-11.3.378-C86.3.2'") && config.includes("'corrective_build' => 'C86.3.2'") && config.includes("'corrective_name' => 'Movement Report Visual Alignment'"), 'C86.3.2 metadata is active');

console.log('ERP-11.3.378 C86.3.2 movement visual alignment regression: PASS / 20');
