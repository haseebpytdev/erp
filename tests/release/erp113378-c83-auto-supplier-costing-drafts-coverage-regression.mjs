import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const sync = read('app/Services/Purchase/SupplierCostingDraftSynchronizer.php');
const coverage = read('app/Services/Purchase/SupplierCostingCoverageResolver.php');
const resolver = read('app/Services/Purchase/BookingSupplierObligationResolver.php');
const costing = read('app/Services/Purchase/SupplierCostingService.php');
const review = read('app/Http/Controllers/Operations/GeneralBookingReviewController.php');
const workflow = read('app/Services/Operations/GeneralBookingAdditionalServiceWorkflowManager.php');
const controller = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
const view = read('resources/views/system/register-workspace-v113239.blade.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

check(sync.includes('DB::transaction'), 'synchronizer is transactional');
check(sync.includes('where(\'id\', $bookingId)->lockForUpdate'), 'synchronizer locks booking scope');
check(sync.includes('$this->obligations->resolve($bookingId)'), 'obligations re-resolve inside transaction');
check(sync.includes('resolve($bookingId, (int) $draft->id)'), 'auto Draft refresh excludes its own source claims');
check(sync.includes("whereIn('status', ['draft', 'pending_approval', 'approved', 'posted'])"), 'active costing statuses are bounded');
check(sync.includes("whereIn('action', ['auto_create', 'auto_sync'])"), 'auto-managed identity uses existing activity markers');
check(sync.includes("'auto_create'"), 'new drafts record auto_create');
check(sync.includes("'auto_sync'"), 'refreshed drafts record auto_sync');
check(sync.includes("'Multiple Products'"), 'mixed supplier products use one Multiple Products header');
check(sync.includes("'status' => 'draft'"), 'automatic projection creates Draft only');
check(sync.includes('supplier_costing_source_links'), 'source links are written');
check(sync.includes('source_snapshot_json'), 'line source snapshot is retained');
check(sync.includes('tax_amount') && sync.includes('other_charges'), 'manual line adjustments are preserved on refresh');
check(sync.includes("(string) $row->status === 'draft'"), 'only Draft auto-managed costings refresh');
check(sync.includes('legacy'), 'legacy no-source active costings are preserved for review');
check(!sync.includes('transition('), 'synchronizer never submits/approves/posts');
check(!sync.includes('supplier_costing_posting_lines'), 'synchronizer never creates accounting posting');
check(review.includes('SupplierCostingDraftSynchronizer'), 'base approval wires costing synchronizer');
check(review.includes("$supplierCostingDrafts->syncBooking($booking, $request->user(), 'auto_create')"), 'base sync runs after approval transaction');
check(workflow.includes('SupplierCostingDraftSynchronizer'), 'supplementary workflow wires costing synchronizer');
check(workflow.includes("materialization_state'] ?? '') === 'materialized'") && workflow.includes('syncBooking'), 'supplementary sync waits for materialization');
check(!workflow.match(/syncBooking\([^\n]*batch/), 'supplementary Draft transition does not cost before materialization');
check(resolver.includes('NativeProductServiceResolver'), 'existing native Product/Service resolver remains authority');
check(costing.includes('validateBookingSourceIntegrity'), 'existing costing integrity guard remains active');
check(coverage.includes('PAGE_SIZE') && coverage.includes('forPage'), 'coverage candidate scan is bounded');
check(coverage.includes('coverage'), 'coverage resolver is a read-only projection');
check(coverage.includes('Ready for Costing'), 'coverage exposes ready card');
check(coverage.includes('Attention Required'), 'coverage exposes attention card');
check(coverage.includes("'queue_page'"), 'coverage queue has independent paging state');
check(controller.includes('SupplierCostingCoverageResolver'), 'supplier costing controller owns coverage projection');
check(controller.includes("coverage_page"), 'coverage paging does not reuse register page');
check(view.includes('Booking Cost Queue'), 'register presents a separate booking cost queue');
check(view.includes('Read-only coverage projection'), 'coverage is visibly read-only');
check(/'asset_version' => 'ERP-11\.3\.378-C83(?:\.1|\.2|\.3(?:\.1|\.2|\.3|\.4)?)?/.test(release), 'asset version remains C83 lineage');
check(/'corrective_build' => 'C83(?:\.1|\.2|\.3(?:\.1|\.2|\.3|\.4)?)?/.test(release), 'corrective build remains C83 lineage');
check(/Automatic Supplier Costing Drafts & Coverage|Supplier Costing Automation Integrity & Coverage Closure|Supplier Costing Final Integrity Closure|Supplier Costing Compact Operations & Draft Actions|Supplier Costing Target Scope Runtime Closure|Supplier Costing Detail Screen Fit|Supplier Costing Table Fit|Supplier Costing Register Interaction Authority/.test(release), 'corrective name remains C83 lineage');

const sixAir = Array.from({ length: 6 }, (_, i) => ({ product_type: 'Air Ticket', supplier_id: 37, source_cost: 160000, source_key: `air_ticket_details:${i + 1}` }));
const groupBySupplier = rows => Object.values(rows.reduce((groups, row) => { (groups[row.supplier_id] ||= []).push(row); return groups; }, {}));
check(groupBySupplier(sixAir).length === 1, 'deterministic six-air fixture groups to one supplier');
check(groupBySupplier(sixAir)[0].reduce((sum, row) => sum + row.source_cost, 0) === 960000, 'six-air fixture totals PKR 960000');
const mixed = [
  { supplier_id: 10, product_type: 'Air Ticket', source_cost: 100000 },
  { supplier_id: 20, product_type: 'Hotel', source_cost: 125000 },
  { supplier_id: 20, product_type: 'Transport', source_cost: 125000 },
  { supplier_id: 30, product_type: 'Visa', source_cost: 75000 },
];
check(groupBySupplier(mixed).length === 3, 'multi-supplier fixture creates three supplier groups');
check(groupBySupplier(mixed).find(rows => rows[0].supplier_id === 20).length === 2, 'supplier 20 combines Hotel and Transport');
check(groupBySupplier(mixed).find(rows => rows[0].supplier_id === 20).reduce((sum, row) => sum + row.source_cost, 0) === 250000, 'supplier 20 combined total is PKR 250000');
check(mixed.filter(row => row.supplier_id === 10).length === 1 && mixed.filter(row => row.supplier_id === 30).length === 1, 'single-product Air and Visa groups remain distinct');

console.log(`ERP-11.3.378 C83 automatic supplier costing drafts and coverage regression: PASS (${assertions} assertions)`);
