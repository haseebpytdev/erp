import fs from 'node:fs';
import vm from 'node:vm';

const core = fs.readFileSync('public/erp-theme/js/dedicated-product-core.js', 'utf8');
const air = fs.readFileSync('public/erp-theme/js/products/air.js', 'utf8');
const visa = fs.readFileSync('public/erp-theme/js/products/visa-core.js', 'utf8');
const ok = (condition, message) => {
  if (!condition) throw new Error(message);
  console.log(`ok - ${message}`);
};

ok(core.includes('r.dataset.etgpUserId') && core.includes('unknown'), 'cache scope requires authenticated user and fails closed for unknown context');
ok(core.includes('getBranchId') && core.includes('getCompanyId'), 'cache scope consumes available branch/company context');
ok(core.includes('invalidateBookingCaches'), 'booking-scoped cache invalidation exists');
ok(core.includes('Object.keys(promiseCache)'), 'pending request cache entries are invalidated');
ok(core.includes('generations[key]=(generations[key]||0)+1'), 'cache generation invalidates stale in-flight responses');
ok(core.includes("'/system/erp-bookings/'+id+'/operational-summary'"), 'authoritative booking summary endpoint is refreshed');
ok(core.includes("'/system/erp-bookings/'+id+'/operational-summary'"), 'summary refresh uses the supported read-only endpoint');
ok(!core.includes("et:booking-state-refreshed"), 'summary refresh does not claim an unsupported visible event consumer');
ok(core.includes('core.getBookingId())!==String(id)'), 'refresh result is ignored after booking navigation changes');
ok(air.includes('refreshBookingState:function(bookingId){return core.refreshBookingState(bookingId,\'air\');}'), 'Air integration uses the shared authoritative refresh');
ok(air.includes('core.invalidateBookingCaches(bookingId)'), 'Air refresh invalidates affected booking caches');
ok(air.includes('getProductCacheToken') && air.includes('setProductResponse(\'air\',bookingId,data,token)'), 'stale Air GET responses cannot repopulate invalidated cache');
ok(air.includes('bookingRefresh.catch'), 'summary refresh failure is reported separately from committed Air save');
ok(air.includes('refreshConfirmed:true'), 'C91.4 PUT/GET save confirmation contract remains');
ok(air.includes('etgpAirDraft113314.clear(bookingId)'), 'draft clears only after authoritative Air refresh');
ok(air.includes('etgpAirRequest113106(bookingId,\'PUT\',payload)'), 'Air writes retain the existing endpoint and method');
ok(core.includes('getProductResponse:function(product,booking){var key=cacheKey(product,booking);return key?(responseCache[key]||null):null;}'), 'unknown authentication context never reuses a response cache');
ok(core.includes("var scope=cacheScope();return scope?'et-dedicated-draft:'"), 'shared draft scope includes authenticated context');
ok(core.includes('var key=draftScopeKey(namespace,product,booking),raw=key?localStorage.getItem(key):null') && core.includes('if(key)localStorage.setItem(key'), 'core drafts fail closed without authenticated context');
ok(visa.includes('var key = draftKey(id), raw = key ? localStorage.getItem(key) : null'), 'Visa draft reads fail closed without authenticated context');
ok(visa.includes('getProductCacheToken') && visa.includes('setProductResponse(key, id, data, token)'), 'Visa asynchronous cache writes retain authenticated generation scope');

const root = { isConnected: true, dataset: { etgpUserId: '7', branchId: '3', companyId: '1', etgpBookingId: '44', etgpProductKey: 'air' }, querySelector: () => null, querySelectorAll: () => [] };
const document = { querySelector: () => root, createElement: () => ({}) };
const window = {};
vm.runInNewContext(core, { window, document });
const runtimeCore = window.etDedicatedProductCore;
const firstToken = runtimeCore.getProductCacheToken('air', 44);
runtimeCore.setProductResponse('air', 44, { marker: 'old' }, firstToken);
runtimeCore.invalidateBookingCaches(44);
runtimeCore.setProductResponse('air', 44, { marker: 'stale' }, firstToken);
ok(runtimeCore.getProductResponse('air', 44) === null, 'late response after invalidation cannot repopulate the cache');
root.dataset.etgpUserId = '8';
runtimeCore.setProductResponse('air', 44, { marker: 'old-user' }, firstToken);
ok(runtimeCore.getProductResponse('air', 44) === null, 'late response after user switching cannot enter the new user cache');
root.dataset.etgpUserId = '';
const legacyStorage = { getItem: () => JSON.stringify({ payload: { ticket_groups: [] } }) };
const scopedDraftWindow = {};
vm.runInNewContext(core, { window: scopedDraftWindow, document, localStorage: legacyStorage });
const scopedDraftCore = scopedDraftWindow.etDedicatedProductCore;
scopedDraftCore.setActiveRoot(root);
root.dataset.etgpUserId = '';
ok(scopedDraftCore.readDraft('air', 44, 'air') === null, 'missing authenticated identity rejects unscoped legacy drafts');

console.log('C93.2 cross-user booking consistency regression: PASS (24 assertions)');
