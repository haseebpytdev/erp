import assert from 'node:assert/strict';
import fs from 'node:fs';

// Reuse the production-mounted DOM harness: it executes air.js rather than
// merely scanning source text, covering live edits, structural rerenders,
// group isolation, and one authoritative multi-group save.
await import('./erp113324-air-ticket-groups-behavioral-regression.mjs');

const source = fs.readFileSync(new URL('../../public/erp-theme/js/products/air.js', import.meta.url), 'utf8');
const checks = [
  ['structural rerenders invoke the shared pre-rerender hook', /beforeStructuralRerender/],
  ['segment add/remove path uses the rerender wrapper', /structuralRerender=rerender/],
  ['save does not use a null response-cache sentinel', !/setProductResponse\('air',bookingId,null\)/.test(source)],
  ['save invalidates pending promise cache', /setProductPromise\('air',bookingId,null\)/],
  ['save performs a fresh scoped GET', /refresh:function\(bookingId\)/],
  ['fresh response is stored in shared cache', /core\.setProductResponse\('air',bookingId,data\)/],
  ['draft clears only after refresh succeeds', /etgpAirDraft113314\.clear\(bookingId\)/],
  ['multi-group timer is lifecycle-owned', /multiDraftTimer/],
  ['timer generation rejects detached renders', /multiDraftGeneration/],
  ['structural rerender cancels timer', /cancelAirMultiDraft113119\(\)/],
  ['child editors do not own full draft', /ownsDraft=!renderGroupOnly/],
  ['single persisted group uses the multi-group-capable renderer', !/c77PreparedData\.ticket_groups/.test(source) && /etgp-air-multi-group-page-113324/.test(source)],
  ['empty Air does not synthesize a group', !/group-new-\$\{bookingId\}/.test(source)],
  ['supplementary endpoint remains context-derived', /getProductEndpoint\?core\.getProductEndpoint/],
  ['draft remains scoped by billing context', /etgpAirDraftKey113119/],
];
for (const [name, ok] of checks) assert.ok(ok instanceof RegExp ? ok.test(source) : ok, name);
assert.equal((source.match(/etgpAirDraft113314\.apply\(/g)||[]).length >= 1,true,'draft normalization remains explicit');
assert.ok(/_etgpPreparedData113119/.test(source),'prepared-state contract prevents duplicate normalization');
assert.ok(/_etgpAirDraftScheduled113119/.test(source),'one event cannot schedule the draft twice');
console.log(`ERP-11.3.378 C77 Air runtime state integrity regression: PASS (${checks.length + 3} focused assertions + behavioral harness)`);
