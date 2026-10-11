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

check(!view.includes('min-width:760px'), 'unconditional 760px table minimum is removed');
check(view.includes('.scs-table{width:100%;max-width:100%;min-width:0') && view.includes('table-layout:fixed'), 'tables use shrink-safe fixed layout');
check(view.includes('class="scs-table scs-source-table"'), 'source table has an independent fit class');
check(view.includes('class="scs-table scs-accounting-table"'), 'accounting table has an independent fit class');
check(view.includes('scs-source-table') && view.includes('scs-accounting-table'), 'both table header sets remain present');
check(view.includes('<th>#</th>') && view.includes('<th>Product</th>') && view.includes('<th>Source Identity</th>') && view.includes('<th>Reference / Detail</th>'), 'source text columns remain');
check(view.includes('<th class="scs-num">Base Cost</th>') && view.includes('<th class="scs-num">Tax</th>') && view.includes('<th class="scs-num">Other</th>') && view.includes('<th class="scs-num">Total</th>'), 'source monetary columns remain');
check(view.includes('<th>Account</th>') && view.includes('<th>Party</th>') && view.includes('<th class="scs-num">Debit</th>') && view.includes('<th class="scs-num">Credit</th>'), 'accounting columns remain');
check(view.includes('.scs-table-wrap{width:100%;max-width:100%;min-width:0;overflow-x:visible'), 'desktop wrapper does not force horizontal scrolling');
check(view.includes('.scs-source-table th:nth-child(1)') && view.includes('.scs-source-table th:nth-child(8)'), 'source table has scoped column proportions');
check(view.includes('.scs-accounting-table th:nth-child(1)') && view.includes('.scs-accounting-table th:nth-child(4)'), 'accounting table has separate proportions');
check(view.includes('overflow-wrap:anywhere') && view.includes('word-break:break-word'), 'text columns wrap safely');
check(view.includes('.scs-num{text-align:right!important;white-space:nowrap'), 'monetary values remain nowrap');
check(view.includes('padding:8px 7px'), 'table cells use compact readable spacing');
check(view.includes('@media(max-width:620px)') && view.includes('.scs-table-wrap{overflow-x:auto}'), 'mobile-only local table scrolling remains available');
check(!view.includes('overflow-x:scroll'), 'desktop does not force scrollbars');
check(!view.includes('body{overflow-x:hidden') && !view.includes('html{overflow-x:hidden') && !view.includes('body,html{overflow-x:hidden'), 'no global overflow hiding hack');
check(view.includes('grid-template-columns:minmax(0,1fr) minmax(280px,320px)') && view.includes('@media(max-width:1120px)'), 'C83.3.2 screen-fit grid is preserved');
check(view.includes('Product / Source Lines') && view.includes('Accounting Preview') && view.includes('Document Information') && view.includes('Audit Trail'), 'show content is preserved');
check(sync.includes('?int $onlySupplierId = null') && sync.includes('$onlySupplierId)'), 'C83.3.1 supplier scope closure remains present');
check(queue.includes("'action' => 'Create Draft'") && queue.includes('purchase.supplier-costing.booking-draft'), 'C83.3 Create Draft workflow remains present');
check(/'asset_version' => 'ERP-11\.3\.378-C83\.3\.[3-6]|ERP-11\.3\.378-C84\.3'/.test(release) && /'corrective_build' => 'C83\.3\.[3-6]|C84\.3'/.test(release), 'C83.3.3 metadata is active');
check(/'corrective_name' => 'Supplier Costing Table Fit|Supplier Costing Register Interaction Authority|Supplier Costing Direct Row Action|Supplier Costing Booking Link Navigation|Arrival Style Single Authority'/.test(release), 'C83.3.3 corrective name is active');

console.log(`ERP-11.3.378 C83.3.3 Supplier Costing table fit regression: PASS (${assertions} assertions)`);
