import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const coverage = read('app/Services/Purchase/SupplierCostingCoverageResolver.php');
const sync = read('app/Services/Purchase/SupplierCostingDraftSynchronizer.php');
const release = read('config/et_erp_release.php');
const c821 = read('tests/release/erp113378-c82-1-supplier-costing-filter-state-closure-regression.mjs');
const registerView = read('resources/views/system/register-workspace-v113239.blade.php');
const costingController = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

const PAGE_SIZE = 50;
const pageRange = (total, requested) => {
  const last = Math.max(1, Math.ceil(total / PAGE_SIZE));
  const page = Math.min(Math.max(1, requested), last);
  const from = total ? ((page - 1) * PAGE_SIZE) + 1 : 0;
  const to = total ? Math.min(total, page * PAGE_SIZE) : 0;
  return { page, last, from, to };
};
check(coverage.includes('forPage($normalizedPage, self::PAGE_SIZE)'), 'forPage receives the normalized page number');
check(!coverage.includes('forPage(($page - 1) * self::PAGE_SIZE'), 'coverage no longer passes an offset to forPage');
check(coverage.includes('normalizedPage') && coverage.includes("'coverage_page' => $normalizedPage"), 'out-of-range page is normalized in the response');
check(coverage.includes("'candidate_from' => $from") && coverage.includes("'candidate_to' => $to"), 'coverage scope is derived from returned page');
check(coverage.includes('[$ids, $total, $lastPage, $normalizedPage]'), 'candidatePage returns normalized page authority');

const p1 = pageRange(125, 1); const p2 = pageRange(125, 2); const p3 = pageRange(125, 3); const p999 = pageRange(125, 999);
check(p1.from === 1 && p1.to === 50, 'coverage page 1 range is 1-50');
check(p2.from === 51 && p2.to === 100, 'coverage page 2 range is 51-100');
check(p3.from === 101 && p3.to === 125, 'coverage page 3 range is 101-125');
check(p999.page === 3 && p999.from === 101 && p999.to === 125, 'coverage page 999 normalizes to page 3');

const latestAutomationState = activities => activities.filter(a => ['auto_create', 'auto_sync', 'auto_review_required'].includes(a.action)).at(-1)?.action;
const recovered = latestAutomationState([{ action: 'auto_create' }, { action: 'auto_review_required' }, { action: 'auto_sync' }]);
const unresolved = latestAutomationState([{ action: 'auto_create' }, { action: 'auto_review_required' }]);
check(coverage.includes("whereIn('action', ['auto_create', 'auto_sync', 'auto_review_required'])"), 'latest relevant automation activity is queried');
check(coverage.includes("(($latest->action ?? null) !== 'auto_review_required')"), 'resolved historical review is not current attention');
check(recovered !== 'auto_review_required', 'auto_review_required followed by auto_sync recovers');
check(unresolved === 'auto_review_required', 'latest unresolved review remains current attention');
check(sync.includes('auto_review_required'), 'historical review activity remains preserved');

check(sync.includes('$manualSourceDrafts'), 'manual source-linked Drafts are identified separately');
check(sync.includes('! $this->isAutoManaged((int) $row->id)'), 'manual Draft is not relabeled auto-managed');
check(sync.includes('supplier_costing_source_links'), 'source-link authority remains present');
check(sync.includes('$manualSourceDrafts->first'), 'manual Draft blocks automatic creation for the supplier');
check(sync.includes('$skipped++; $attention++; continue;'), 'manual Draft produces a safe skipped/attention result');
check(!sync.includes("'auto_create'" + " to manual"), 'manual Draft receives no auto activity');

const manual = { status: 'draft', supplier_id: 37, sourceLinks: ['air:1'] };
const available = [{ status: 'available', supplier_id: 37, source_key: 'air:2', source_cost: 100 }];
check(manual.status === 'draft' && manual.sourceLinks.length === 1 && available[0].source_key !== manual.sourceLinks[0], 'manual Draft fixture and newly available source are distinct');
check(sync.includes("(string) $row->status === 'draft'"), 'manual blocking applies only to Drafts');

check(sync.includes("if (($source['status'] ?? '') !== 'available')"), 'linked non-available sources fail closed');
check(sync.includes('not safely available for automatic refresh'), 'legacy/review linked source records auto_review_required');
check(sync.includes('already_costed') && sync.includes('claimed by another active Supplier Costing'), 'cross-costing collision remains fail-closed');
check(sync.includes('is no longer present in authoritative booking data'), 'source disappearance protection remains');
check(sync.includes('Authoritative booking vendor changed from Supplier'), 'supplier drift protection remains');

check(c821.includes('setOrDelete') && c821.includes('paginate'), 'C82.1 register filter regression remains present');
check(registerView.includes('serverFilters') && costingController.includes('withQueryString'), 'register filter state and pagination remain query-preserved');
check(!sync.includes('transition('), 'automation still never submits/approves/posts');
check(!sync.includes('supplier_costing_posting_lines'), 'automation still never creates posting lines');
check(/'asset_version' => 'ERP-11\.3\.378-C83(?:\.2|\.3(?:\.1|\.2)?)'/.test(release), 'C83.2 lineage asset version is authoritative');
check(/'corrective_build' => 'C83\.2|C83\.3(?:\.1|\.2)?'/.test(release), 'C83.2 lineage corrective build is authoritative');
check(/Supplier Costing Final Integrity Closure|Supplier Costing Compact Operations & Draft Actions|Supplier Costing Target Scope Runtime Closure|Supplier Costing Detail Screen Fit/.test(release), 'C83.2 lineage corrective name is authoritative');

console.log(`ERP-11.3.378 C83.2 supplier costing final closure regression: PASS (${assertions} assertions)`);
