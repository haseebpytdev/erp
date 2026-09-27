import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/products/visa-core.js', import.meta.url), 'utf8');
const routes = fs.readFileSync(new URL('../../routes/erp103179.php', import.meta.url), 'utf8');
const commercialGuard = fs.readFileSync(new URL('../../app/Http/Middleware/GuardApprovedGeneralBookingCommercials.php', import.meta.url), 'utf8');
const lockMiddleware = fs.readFileSync(new URL('../../app/Http/Middleware/EnforceGeneralBookingEditLock.php', import.meta.url), 'utf8');
let assertions = 0;
const ok = (condition, label) => { assert.ok(condition, label); assertions++; };

class FakeElement {
  constructor(tag, document) { this.tagName = String(tag).toUpperCase(); this.document = document; this.children = []; this.parentNode = null; this.attributes = {}; this.listeners = {}; this.className = ''; this.textContent = ''; this.value = ''; this.type = ''; this.disabled = false; this.checked = false; this.selectionStart = 0; this.selectionEnd = 0; if (this.tagName === 'SELECT') this.options = this.children; }
  appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
  dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn.call(this, event); return true; }
  click() { this.dispatchEvent({ type: 'click', target: this }); }
  focus() { this.document.activeElement = this; }
  setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  matches(selector) {
    const attr = selector.match(/^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/);
    if (attr) return Object.prototype.hasOwnProperty.call(this.attributes, attr[1]) && (!attr[2] || this.attributes[attr[1]] === attr[2]);
    if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1));
    return this.tagName.toLowerCase() === selector.toLowerCase();
  }
  querySelectorAll(selector) { const selectors = selector.split(',').map(item => item.trim()); const found = []; const visit = node => { for (const child of node.children) { if (selectors.some(item => child.matches(item))) found.push(child); visit(child); } }; visit(this); return found; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  set innerHTML(value) { this.children = []; }
}

const document = { activeElement: null, readyState: 'complete', createElement: tag => new FakeElement(tag, document), querySelector: () => null };
const drafts = new Map();
let locked = true;
let putCount = 0;
let applyReadOnlyCalls = 0;
const localStorage = { getItem: key => drafts.get(key) ?? null, setItem: (key, value) => drafts.set(key, value), removeItem: key => drafts.delete(key) };
const response = {
  booking_id: 3671, setup_url: '/visa-management', statuses: ['pending', 'approved'],
  passengers: [{ id: 11, name: 'Ayesha Khan', passport_number: 'AY123456' }, { id: 12, name: 'Bilal Ahmed', passport_number: 'BA654321' }],
  rates: [{ id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', default_sale_pkr: 150000, vendor_cost_pkr: 120000 }],
  visa_rows: [{ booking_passenger_id: 11, passenger_name: 'Ayesha Khan', passport_number: 'AY123456', visa_rate_card_id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', sale_pkr: 150000, vendor_cost_pkr: 120000, margin_pkr: 30000, status: 'pending', notes: '' }],
  summary: { customer_total: 150000, vendor_total: 120000, margin: 30000 },
};
const dedicatedCore = {
  getLockState: () => ({ locked, status: locked ? 'approved' : 'draft', reason: locked ? 'approved' : '' }),
  applyReadOnly: root => { applyReadOnlyCalls++; if (locked) root.querySelectorAll('input,select,textarea,button,[role="button"]').forEach(element => { element.disabled = true; element.setAttribute('aria-disabled', 'true'); }); return locked; },
  getProductResponse: () => null, getProductPromise: () => null, setProductResponse() {},
};
const window = { localStorage, etDedicatedProductCore: dedicatedCore, addEventListener() {} };
delete window.data;
const context = { window, document, localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: (url, options = {}) => { if (options.method === 'PUT') { putCount++; return Promise.resolve({ ok: true, json: () => Promise.resolve(response) }); } return Promise.resolve({ ok: true, json: () => Promise.resolve(response) }); } };
vm.runInNewContext(source, context);
const nextTick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
const makeRoot = id => { const root = new FakeElement('div', document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', id); const host = new FakeElement('div', document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host); return { root, host }; };
const treeText = node => node ? [node.textContent || '', ...node.children.map(treeText)].join(' ') : '';
const button = (host, label) => host.querySelectorAll('button').find(item => item.textContent === label);

const lockedRoot = makeRoot(3671);
const lockedFacade = context.window.etVisaProductCore.mount({ root: lockedRoot.root, bookingId: 3671 });
await nextTick();
ok(lockedFacade && treeText(lockedRoot.host).includes('Ayesha Khan'), 'locked mount keeps Visa data visible');
ok(treeText(lockedRoot.host).includes('Visa') && treeText(lockedRoot.host).includes('PKR 150,000'), 'locked mount renders heading and totals');
ok(button(lockedRoot.host, '+ Add Visa')?.disabled === true, 'locked Add Visa is not actionable');
ok(button(lockedRoot.host, 'Save Visa Data')?.disabled === true, 'locked Save is not actionable');
ok(lockedRoot.host.querySelectorAll('input').filter(input => input.type === 'checkbox').every(input => input.disabled), 'locked row selection is disabled');
const lockedDetails = button(lockedRoot.host, 'Details');
lockedDetails.click();
ok(!lockedRoot.host.querySelector('textarea'), 'locked details cannot open for mutation');
const lockedCheck = lockedRoot.host.querySelectorAll('input').find(input => input.type === 'checkbox');
lockedCheck.checked = true; lockedCheck.dispatchEvent({ type: 'change', target: lockedCheck });
ok(lockedFacade.getState().dirty === false && lockedFacade.getState().draftPending === false, 'locked state remains clean after mutation attempts');
ok(drafts.size === 0 && putCount === 0, 'locked state creates no draft and sends no PUT');
ok(applyReadOnlyCalls > 0, 'Visa delegates read-only control state to dedicated core');
lockedFacade.destroy();

locked = false;
const openRoot = makeRoot(3672);
const openFacade = context.window.etVisaProductCore.mount({ root: openRoot.root, bookingId: 3672 });
await nextTick();
button(openRoot.host, '+ Add Visa').click();
const passengerCheck = openRoot.host.querySelectorAll('input').find(input => input.type === 'checkbox');
const rateRadio = openRoot.host.querySelectorAll('input').find(input => input.type === 'radio');
passengerCheck.checked = true; passengerCheck.dispatchEvent({ type: 'change', target: passengerCheck });
const refreshedRate = openRoot.host.querySelectorAll('input').find(input => input.type === 'radio');
refreshedRate.checked = true; refreshedRate.dispatchEvent({ type: 'change', target: refreshedRate });
button(openRoot.host, 'Add Visa').click();
ok(openFacade.getState().dirty === true, 'unlocked Add Visa still creates a draft');
button(openRoot.host, 'Details').click();
const notes = openRoot.host.querySelectorAll('textarea')[0]; notes.value = 'editable note'; notes.dispatchEvent({ type: 'input', target: notes });
ok(openFacade.getState().dirty === true, 'unlocked details remain editable');
button(openRoot.host, 'Save Visa Data').click(); await nextTick();
ok(putCount === 1 && openFacade.getState().dirty === false, 'unlocked Save still submits exactly one PUT');
ok(!Object.prototype.hasOwnProperty.call(window, 'data'), 'Visa runtime does not require global data');

ok(routes.includes("'/system/erp-bookings/{booking}/visa-product'") && routes.includes('GuardApprovedGeneralBookingCommercials::class'), 'Visa PUT route uses approved-commercial guard');
ok(commercialGuard.includes('BookingEditLockResolver') && commercialGuard.includes('EnforceGeneralBookingEditLock::class'), 'approved-commercial guard delegates to booking lock resolver');
ok(lockMiddleware.includes("'error'=>'booking_locked'") && lockMiddleware.includes('], 423'), 'locked JSON save returns HTTP 423 booking_locked');

console.log(`VISA_LOCK_RUNTIME_REGRESSION=PASS (${assertions} assertions)`);
console.log('VISA_LOCK_AUTHORITY_PRESENT=YES');
console.log('LOCKED_VISA_CAN_EDIT_CONTROLS=NO');
console.log('LOCKED_VISA_SAVE_SERVER_GUARDED=YES');
console.log('GLOBAL_DATA_REQUIRED=NO');
console.log('VISA_PUT_LOCK_MIDDLEWARE_PRESENT=YES');
console.log('LOCK_RESOLVER_AUTHORITY=BookingEditLockResolver');
console.log('LOCKED_JSON_HTTP_STATUS=423');
console.log('LOCKED_JSON_ERROR=booking_locked');
