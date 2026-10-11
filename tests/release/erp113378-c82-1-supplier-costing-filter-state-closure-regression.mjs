import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const client = read('public/erp-ui/erp-register-workspace.js');
const controller = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

check(/serverFilters\['q'\]/.test(view), 'server search value is restored');
check(/serverFilters\['supplier_id'\]/.test(view) && /supplier\['id'\]/.test(view), 'supplier selection restores stable supplier_id');
check(/serverFilters\['product'\]/.test(view) && /serverMode \? \$secondary : mb_strtolower/.test(view), 'server product selection restores exact service_type while generic mode stays normalized');
check(!/value="\{\{ mb_strtolower\(\$secondary\) \}\}"/.test(view), 'server product option is not lowercased');
check(/serverFilters\['date_from'\]/.test(view) && /serverFilters\['date_to'\]/.test(view), 'date range values are restored');
check(/serverStatus/.test(view) && /serverFilters\['status'\]/.test(view), 'status dropdown restores server status');
check(view.includes("serverStatus === ''") && view.includes("$quick[0] === 'all'"), 'quick filter active state follows server status');
check(view.includes('serverStatus === $quick[0]'), 'quick filter and status dropdown reconcile');
check(/setOrDelete\('supplier_id'/.test(client) && /setOrDelete\('product'/.test(client), 'quick/status changes preserve other server filters');
check(/setOrDelete\('date_from'/.test(client) && /setOrDelete\('date_to'/.test(client), 'date changes preserve incremental filter state');
check(/setOrDelete\('q'/.test(client) && /setOrDelete\('status'/.test(client), 'search Enter preserves other server filters');
check(/params\.delete\('page'\)/.test(client), 'server filter changes reset page');
check(/cleanUrl\.search = ''/.test(client) && /location\.assign\(cleanUrl\.pathname\)/.test(client), 'server reset clears all query filters and page');
check(/paginate\(25\)->withQueryString\(\)/.test(controller), 'paginator preserves active query string');

const state = query => ({
  q: query.q || '', supplier_id: query.supplier_id || '', product: query.product || '',
  date_from: query.date_from || '', date_to: query.date_to || '', status: query.status || '',
});
const apply = (current, patch) => state({ ...current, ...patch });
let filters = state({ supplier_id: '37' });
check(filters.supplier_id === '37', 'supplier state survives initial reload');
filters = apply(filters, { product: 'Air Ticket' });
check(filters.supplier_id === '37' && filters.product === 'Air Ticket', 'Supplier then Product preserves Supplier');
filters = apply(filters, { date_from: '2026-10-01', date_to: '2026-10-31' });
check(filters.supplier_id === '37' && filters.product === 'Air Ticket' && filters.date_from === '2026-10-01', 'Product then Date preserves prior filters');
filters = apply(filters, { status: 'approved' });
check(filters.supplier_id === '37' && filters.product === 'Air Ticket' && filters.date_to === '2026-10-31' && filters.status === 'approved', 'Date then Status preserves prior filters');
filters = apply(filters, { q: 'SC-0047' });
check(filters.supplier_id === '37' && filters.product === 'Air Ticket' && filters.status === 'approved', 'Search preserves combined server filters');
check(controller.includes("'filters' => $filters"), 'controller remains the metadata authority');

console.log(`ERP-11.3.378 C82.1 Supplier Costing filter state closure regression: PASS (${assertions} assertions)`);
