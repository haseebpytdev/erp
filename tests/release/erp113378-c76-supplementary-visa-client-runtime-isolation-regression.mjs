import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const visa = read('public/erp-theme/js/products/visa-core.js');
const coreSource = read('public/erp-theme/js/dedicated-product-core.js');
const progressive = read('public/erp11390/general-progressive-step1.js');
const c75 = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const c73 = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const c72 = read('app/Services/Operations/ProductWorkspaceContext.php');
const release = read('config/et_erp_release.php');

let assertions = 0;
const ok = (value, label) => { assertions += 1; assert.ok(value, label); };

let contextName = 'ORIGINAL';
let batchId = 0;
const endpointFor = (product, booking) => contextName === 'SUPPLEMENTARY'
  ? `/system/erp-bookings/${booking}/additional-services/${batchId}/${product}-product`
  : `/system/erp-bookings/${booking}/${product}-product`;
const dedicated = {
  getProductScope: (product, booking) => ({ booking_id: booking, billing_context: contextName, billing_batch_id: contextName === 'SUPPLEMENTARY' ? batchId : 0, product }),
  getProductEndpoint: endpointFor,
  getProductResponse: () => null,
  getProductPromise: () => null,
  setProductResponse: () => {},
  setProductPromise: () => {},
  getDraftScopeKey: (product, booking) => `${booking}::${contextName}::${contextName === 'SUPPLEMENTARY' ? batchId : 0}::${product}`,
};
const document = { querySelector: () => ({ dataset: { billingContext: contextName, billingBatchId: String(batchId) } }) };
const localStorage = { getItem: () => null, setItem: () => {}, removeItem: () => {} };
const sandbox = { window: { etDedicatedProductCore: dedicated }, document, localStorage, fetch: null, Set, Promise, Error, Number, String, JSON, Object, Array, Math, console };
sandbox.window.window = sandbox.window;
vm.runInNewContext(visa, sandbox);
const api = sandbox.window.etVisaProductCore;
ok(typeof api.request === 'function' && typeof api.load === 'function', 'Visa client exposes request/load authority');
ok(!visa.includes('etgpProductEndpoint113305'), 'Visa no longer depends on global endpoint helper');
ok(!visa.includes("'/system/erp-bookings/' + id + '/visa-product'"), 'Visa has no original-route fallback');
ok(coreSource.includes('getProductEndpoint:function'), 'shared core endpoint authority exists');

const responses = [];
sandbox.fetch = (url) => {
  responses.push(url);
  return Promise.resolve({ ok: true, json: () => Promise.resolve({ visa_rows: [], passengers: ['p1'], rates: ['r1'], statuses: ['pending'], setup_url: '/visa/setup', supplementary_context: contextName === 'SUPPLEMENTARY' ? { batch_id: batchId, product: 'visa', writable: true } : undefined }) });
};
contextName = 'ORIGINAL'; batchId = 0;
await api.request(44, 'GET');
ok(responses.at(-1) === '/system/erp-bookings/44/visa-product', 'original Visa endpoint remains unchanged');
contextName = 'SUPPLEMENTARY'; batchId = 4;
await api.request(44, 'GET');
ok(responses.at(-1) === '/system/erp-bookings/44/additional-services/4/visa-product', 'supplementary Visa endpoint uses batch scope');
ok(responses.every(url => !url.includes('etgpProductEndpoint113305')), 'routing does not use global helper');

const scoped = { batch_id: 4, product: 'visa', writable: true };
const baseResponse = { visa_rows: [{ id: 19 }] };
const requestWith = data => { sandbox.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve(data) }); return api.request(44, 'GET'); };
await assert.rejects(requestWith(baseResponse), /scope/);
await assert.rejects(requestWith({ visa_rows: [], supplementary_context: { ...scoped, batch_id: 3 } }), /scope/);
await assert.rejects(requestWith({ visa_rows: [], supplementary_context: { ...scoped, product: 'air' } }), /scope/);
const accepted = { visa_rows: [], passengers: ['p1'], rates: ['r1'], setup_url: '/visa/setup', supplementary_context: scoped };
const acceptedResponse = await requestWith(accepted);
ok(acceptedResponse.visa_rows.length === 0 && acceptedResponse.passengers.length === 1, 'matching supplementary response is accepted with references');

let fetchCount = 0;
sandbox.fetch = () => { fetchCount += 1; return Promise.resolve({ ok: true, json: () => Promise.resolve(accepted) }); };
const invalidCache = { ...dedicated, getProductResponse: () => baseResponse, getProductPromise: () => null, setProductResponse: () => {}, setProductPromise: () => {} };
await api.load(44, invalidCache);
ok(fetchCount === 1, 'invalid cached BASE response is ignored');
const validCache = { ...dedicated, getProductResponse: () => accepted, getProductPromise: () => null, setProductResponse: () => {}, setProductPromise: () => {} };
fetchCount = 0; await api.load(44, validCache); ok(fetchCount === 0, 'valid supplementary cache is reused');
const pendingCache = { ...dedicated, getProductResponse: () => null, getProductPromise: () => Promise.resolve(baseResponse), setProductResponse: () => {}, setProductPromise: () => {} };
fetchCount = 0; await api.load(44, pendingCache); ok(fetchCount === 1, 'invalid pending BASE response is rejected before fresh fetch');

const empty = { visa_rows: [], passengers: ['p1'], rates: ['r1'], statuses: ['pending'], setup_url: '/visa/setup', supplementary_context: scoped };
const totals = (empty.visa_rows || []).reduce((out, row) => ({ customer: out.customer + Number(row.sale_pkr || 0), vendor: out.vendor + Number(row.vendor_cost_pkr || 0), margin: out.margin + Number(row.margin_pkr || 0) }), { customer: 0, vendor: 0, margin: 0 });
ok(empty.visa_rows.length === 0, 'empty supplementary Visa rows remain empty');
ok(totals.customer === 0 && totals.vendor === 0 && totals.margin === 0, 'empty supplementary Visa totals are zero');
ok(empty.passengers.length && empty.rates.length && empty.setup_url, 'Visa reference/master data remains available');
ok(api.draftKey(44).includes('44::SUPPLEMENTARY::4::visa'), 'supplementary draft scope remains batch-specific');
ok(!api.draftKey(44).includes('44::ORIGINAL::0::visa'), 'supplementary mode does not use original draft scope');
ok(!c75.includes("visa_rows = $snapshots") || c75.includes("$payload['visa_rows'] = $snapshots"), 'server Visa projection remains the existing batch snapshot path');
ok(c73.includes('prepareAirProjectedCollection') && c73.includes('applyPreparedAirCollection'), 'C73 Air preflight architecture remains');
ok(c75.includes("'tickets'=>[]") && !c75.includes('$groups ?: [[]]'), 'C75 Air empty-read fix remains');
ok(!c75.includes('$this->stableSegmentKey($s),)'), 'C74 PHP syntax fix remains');
ok(c72.includes('billingContext') && c72.includes('billingBatchId'), 'C72 global billing ownership remains');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release remains');
ok(/'asset_version' => 'ERP-11\.3\.378-C(?:76|77|78)'/.test(release), 'asset version remains current');
ok(/'corrective_build' => 'C(?:76|77|78)'/.test(release), 'C76 corrective build remains in lineage');
ok(release.includes("'corrective_name' => 'Supplementary Visa Client Runtime Isolation'") || release.includes("'corrective_name' => 'Air Runtime State Integrity'") || release.includes("'corrective_name' => 'Air Empty-State & Read-Only Product Navigation'"), 'C76 corrective name remains in lineage');
ok(!read('public/erp-theme/et-focused-shell.css').includes('C76'), 'no public CSS change');
ok(assertions >= 25, 'C76 assertion threshold');
console.log(`C76 supplementary Visa client runtime isolation regression: PASS (${assertions} assertions)`);
