import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (p) => fs.readFileSync(p, 'utf8');
const report = read('resources/views/reports/travel/report.blade.php');
const arrival = read('resources/views/reports/travel/movements/arrival.blade.php');
const shared = read('resources/views/reports/travel/partials/movement-table-styles.blade.php');
const config = read('config/et_erp_release.php');
const ok = (v, m) => assert.ok(v, m);

const directives = [...report.matchAll(/@(if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse|isset|endisset|unless|endunless)\b/g)].map((m) => m[1]);
const stack = [];
for (const token of directives) {
  if (['if', 'foreach', 'forelse', 'isset', 'unless'].includes(token)) stack.push(token);
  else if (['endif', 'endforeach', 'endforelse', 'endisset', 'endunless'].includes(token)) {
    ok(stack.length > 0, `unexpected closing Blade directive: ${token}`);
    stack.pop();
  }
}
ok(stack.length === 0, `unclosed Blade directives: ${stack.join(',')}`);
ok(report.includes('@if($movement)<div class="et-travel-print-document" data-et-travel-print-document="1"') && report.includes('</div>@if(is_object($rows) && method_exists($rows,\'links\'))') && report.includes('@endif@endif</div>'), 'generic movement report has a balanced printable root');
ok(report.includes("$report==='madinah-checkin'") && report.includes("route('travel-reports.group-umrah.madinah-checkin')"), 'Madinah Check-in route and branch remain present');
ok(arrival.includes('<colgroup>') && arrival.includes('class="et-arrival-customer-col"'), 'Arrival table exposes an explicit customer column');
ok(shared.includes('.et-arrival-table col.et-arrival-customer-col{width:clamp(220px,26vw,340px)}'), 'Arrival width rule targets the explicit customer column');
ok(shared.includes('body:has([data-et-travel-print-document]) .app-shell>.sidebar') && shared.includes('body:has([data-et-travel-print-document]) .app-shell>main.main'), 'print shell selectors remain preserved');
ok(shared.includes('body:has([data-et-travel-print-document]) .navbar') && shared.includes('body:has([data-et-travel-print-document]) .app-header') && shared.includes('body:has([data-et-travel-print-document]) .et-report-filter-card'), 'print header/filter selectors remain preserved');
ok(config.includes("'asset_version' => 'ERP-11.3.378-C86.2'") && config.includes("'corrective_build' => 'C86.2'") && config.includes("'corrective_name' => 'Travel Report Blade Recovery'"), 'C86.2 release identity is present');

console.log('ERP-11.3.378 C86.2 travel report Blade recovery regression: PASS / 8');
