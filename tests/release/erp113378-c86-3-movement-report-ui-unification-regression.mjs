import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p, 'utf8');
const ok = (v, m) => assert.ok(v, m);
const shared = read('resources/views/reports/travel/partials/movement-table-styles.blade.php');
const tabs = read('resources/views/reports/travel/partials/movement-tabs.blade.php');
const filter = read('resources/views/reports/travel/partials/filter-form.blade.php');
const report = read('resources/views/reports/travel/report.blade.php');
const views = ['arrival', 'departure', 'makkah-checkin', 'makkah-checkout'].map((n) => read(`resources/views/reports/travel/movements/${n}.blade.php`));
const config = read('config/et_erp_release.php');
const service = read('app/Services/Reports/TravelReportService.php');
const hotelController = read('app/Http/Controllers/Operations/GeneralBookingHotelProductController.php');

ok(tabs.includes('body.et-ui-professional .et-arrival-tabs a') && tabs.includes('body.et-ui-professional .et-arrival-tabs a.active'), 'all movement tabs use the approved shared tab authority');
ok(report.includes("@include('reports.travel.partials.movement-tabs'") && !report.includes('Movement / Intimation Reports'), 'generic Madinah movement navigation uses compact shared tabs');
ok(views.every((v) => v.includes("@include('reports.travel.partials.movement-tabs'")), 'native movement views use shared movement tabs');
ok(report.includes("'modifier'=>'et-report-filter-one-line'") && views.every((v) => v.includes('et-report-filter-one-line')), 'all six reports use the shared compact filter modifier');
ok(filter.includes('.et-report-filter-card') && filter.includes('.et-report-filter-form') && filter.includes('.et-report-filter-actions'), 'filters remain owned by the shared travel filter partial');
ok(shared.includes('.et-travel-movement-table th,.et-arrival-table th') && shared.includes('padding:8px 10px'), 'movement table headers retain 8px 10px padding');
ok(shared.includes('.et-travel-movement-table td,.et-arrival-table td') && shared.includes('padding:3px 10px'), 'movement table bodies use 3px 10px padding');
ok(shared.includes('.et-arrival-table col.et-arrival-customer-col{width:clamp(220px,26vw,340px)}'), 'Arrival Customer / Group column binding remains explicit and wide');
ok(shared.includes('overflow-x:auto') && shared.includes('min-width:900px'), 'mobile table horizontal scrolling remains preserved');
ok(shared.includes('data-et-travel-print-document') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>.sidebar') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>main.main'), 'print root and real ERP shell isolation selectors remain preserved');
ok(shared.includes('body:has([data-et-travel-print-document]) .navbar') && shared.includes('body:has([data-et-travel-print-document]) .app-header') && shared.includes('body:has([data-et-travel-print-document]) .et-report-filter-card'), 'print header and filter isolation remains preserved');
const directives = [...report.matchAll(/@(if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|isset|endisset|unless|endunless)\b/g)].map((m) => m[1]);
const stack = [];
for (const token of directives) {
  if (['if', 'foreach', 'forelse', 'isset', 'unless'].includes(token)) stack.push(token);
  else if (['endif', 'endforeach', 'endforelse', 'endisset', 'endunless'].includes(token)) { ok(stack.length > 0, `unexpected closing Blade directive: ${token}`); stack.pop(); }
}
ok(stack.length === 0, `unbalanced report Blade directives: ${stack.join(',')}`);
ok(service.includes('class TravelReportService') && hotelController.includes('class GeneralBookingHotelProductController'), 'report data and Hotel lifecycle authorities remain available and untouched by presentation code');
ok(config.includes("'asset_version' => 'ERP-11.3.378-C86.3'") && config.includes("'corrective_build' => 'C86.3'") && config.includes("'corrective_name' => 'Movement Report UI Unification'"), 'C86.3 release identity is present');

console.log('ERP-11.3.378 C86.3 movement report UI unification regression: PASS / 14');
