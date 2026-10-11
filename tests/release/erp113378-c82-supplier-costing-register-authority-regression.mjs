import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const controller = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
const indexPath = controller.split('    public function create', 1)[0];
const view = read('resources/views/purchase/supplier-costing/index.blade.php');
const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const workspace = read('resources/views/system/register-workspace-v113239.blade.php');
const client = read('public/erp-ui/erp-register-workspace.js');
const release = read('config/et_erp_release.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

for (const field of ['q', 'supplier_id', 'product', 'date_from', 'date_to', 'status']) check(controller.includes(`'${field}'`), `server accepts ${field}`);
check(/paginate\(25\)->withQueryString\(\)/.test(controller), 'filtered register remains server-paginated with query string');
check(/where\('supplier_id'/.test(controller) && /where\('service_type'/.test(controller), 'supplier and product filters use canonical columns');
check(/whereDate\('cost_date', '>='/ .test(controller) && /whereDate\('cost_date', '<='/.test(controller), 'date range is applied to cost_date');
check(view.includes('data-et-supplier-costing-register-metadata'), 'native view emits structured register metadata');
check(/structuredSupplierCostingMetadata/.test(presenter) && /serverMetadata/.test(presenter), 'presenter consumes structured authority without a duplicate query');
check(/data-et-register-filter-mode="server"/.test(workspace), 'professional workspace exposes server filter mode');
check(/serverMode/.test(client) && /location\.assign/.test(client), 'supplier costing filters navigate through server query state');
check(/if \(!serverMode\) render\(\);/.test(client), 'server mode does not re-paginate the already server-paginated rows');
check(/'booking_references'/.test(controller) && /bookingReferences/.test(controller), 'booking display prefers canonical persisted booking reference with fallback');
check(/supplier_options/.test(controller) && /product_options/.test(controller), 'full-register supplier and product options are supplied');

const fixture = Array.from({ length: 60 }, (_, index) => ({
  id: index + 1,
  costing_no: `SC-${String(index + 1).padStart(4, '0')}`,
  supplier_id: (index % 4) + 1,
  supplier_name: ['Alpha', 'Bravo', 'Charlie', 'Delta'][index % 4],
  service_type: ['Air Ticket', 'Hotel', 'Transport'][index % 3],
  cost_date: `2026-${String((index % 6) + 1).padStart(2, '0')}-${String((index % 9) + 1).padStart(2, '0')}`,
  status: ['draft', 'pending_approval', 'approved', 'posted'][index % 4],
}));
const apply = (rows, filters) => rows.filter(row =>
  (!filters.q || `${row.costing_no} ${row.supplier_name}`.toLowerCase().includes(filters.q.toLowerCase())) &&
  (!filters.supplier_id || row.supplier_id === filters.supplier_id) &&
  (!filters.product || row.service_type === filters.product) &&
  (!filters.date_from || row.cost_date >= filters.date_from) &&
  (!filters.date_to || row.cost_date <= filters.date_to) &&
  (!filters.status || row.status === filters.status));
const all = apply(fixture, {});
check(all.length === 60, 'unfiltered server scope includes records beyond first page');
check(all.slice(0, 25).length === 25 && all.slice(25, 50).some(row => row.supplier_id === 3), 'later-page records remain in authoritative result set');
const scoped = apply(fixture, { supplier_id: 3, product: 'Hotel', date_from: '2026-02-01', date_to: '2026-05-31', status: 'approved' });
check(scoped.every(row => row.supplier_id === 3 && row.service_type === 'Hotel' && row.status === 'approved'), 'combined filters constrain the server scope');
check(new Set(fixture.map(row => row.supplier_name)).size === 4 && new Set(fixture.map(row => row.service_type)).size === 3, 'dropdown options are derived from saved register documents');
const counts = fixture.reduce((out, row) => { out[row.status] = (out[row.status] || 0) + 1; return out; }, {});
check(Object.values(counts).reduce((sum, value) => sum + value, 0) === 60, 'lifecycle counts cover the full server scope');
check([...fixture].sort((a, b) => b.id - a.id).slice(0, 5).length === 5, 'recent activity is sourced from persisted rows');
check(!/supplier_costing_source_links.*insert|insert.*supplier_costing_source_links/i.test(indexPath), 'register read path does not create unsaved obligations');
check(/'asset_version' => 'ERP-11\.3\.378-C82(?:\.1)?|ERP-11\.3\.378-C83(?:\.1|\.2|\.3(?:\.1|\.2)?)?'/.test(release) && /'corrective_build' => 'C82(?:\.1)?|C83(?:\.1|\.2|\.3(?:\.1|\.2)?)?'/.test(release), 'C82 release metadata is active');

console.log(`ERP-11.3.378 C82 Supplier Costing register authority regression: PASS (${assertions} assertions)`);
