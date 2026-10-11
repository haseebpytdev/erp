import fs from 'node:fs';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const view = fs.readFileSync(path.join(root, 'resources/views/purchase/supplier-costing/show.blade.php'), 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const queue = fs.readFileSync(path.join(root, 'tests/release/erp113378-c83-3-booking-cost-queue-action-regression.mjs'), 'utf8');
const sync = fs.readFileSync(path.join(root, 'app/Services/Purchase/SupplierCostingDraftSynchronizer.php'), 'utf8');

let assertions = 0;
const check = (condition, message) => { assertions += 1; assert.ok(condition, message); };

check(view.includes('grid-template-columns:minmax(0,1fr) minmax(280px,320px)'), 'desktop detail grid is fluid');
check(view.includes('min-width:0') && view.includes('.scs-grid>div,.scs-grid>aside'), 'detail columns allow shrinking');
check(view.includes('.scs-table-wrap{width:100%;max-width:100%;min-width:0;overflow-x:auto'), 'tables scroll only inside local wrappers');
check(view.includes('@media(max-width:1120px){.scs-grid{grid-template-columns:minmax(0,1fr)}'), 'medium layout stacks supporting panels');
check(view.includes('@media(max-width:760px){.scs-kpis{grid-template-columns:repeat(2,minmax(0,1fr))'), 'medium summary cards use two columns');
check(view.includes('@media(max-width:620px)') && view.includes('.scs-kpis{grid-template-columns:minmax(0,1fr)}'), 'mobile summary cards use one column');
check(view.includes('.scs-actions{display:flex') && view.includes('flex-wrap:wrap'), 'header actions wrap');
check(view.includes('class="scs-table-wrap"'), 'source and accounting tables use local wrappers');
check(view.includes('Product / Source Lines') && view.includes('Accounting Preview'), 'main costing content preserved');
check(view.includes('Document Information') && view.includes('Status & Workflow') && view.includes('Audit Trail'), 'supporting panels preserved');
check(view.includes('Edit Draft') && view.includes('Submit for Approval') && view.includes('Booking #'), 'header controls and booking link preserved');
check(view.includes('overflow-wrap:anywhere'), 'long detail text can wrap');
check(!view.includes('body{overflow-x:hidden') && !view.includes('html{overflow-x:hidden') && !view.includes('body,html{overflow-x:hidden'), 'no global overflow hiding hack');
check(view.includes('max-width:1500px') && view.includes('width:100%'), 'show page is width constrained');
check(view.includes('grid-template-columns:repeat(4,minmax(0,1fr))'), 'desktop summary cards remain four columns');
check(view.includes('minmax(280px,320px)'), 'right column has fluid safe bounds');
check(queue.includes("'action' => 'Create Draft'") && queue.includes('purchase.supplier-costing.booking-draft'), 'C83.3 Create Draft behavior remains present');
check(sync.includes('?int $onlySupplierId = null') && sync.includes('$onlySupplierId)'), 'C83.3.1 supplier scope closure remains present');
check(release.includes("'asset_version' => 'ERP-11.3.378-C83.3.2'") && release.includes("'corrective_build' => 'C83.3.2'"), 'C83.3.2 metadata is active');
check(release.includes("'corrective_name' => 'Supplier Costing Detail Screen Fit'"), 'C83.3.2 corrective name is active');

console.log(`ERP-11.3.378 C83.3.2 Supplier Costing detail screen fit regression: PASS (${assertions} assertions)`);
