import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/products/visa-core.js', import.meta.url), 'utf8');
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
  matches(selector) { if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1)); const attr = selector.match(/^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/); if (attr) return Object.prototype.hasOwnProperty.call(this.attributes, attr[1]) && (!attr[2] || this.attributes[attr[1]] === attr[2]); return this.tagName.toLowerCase() === selector.toLowerCase(); }
  querySelectorAll(selector) { const found = []; const visit = node => { for (const child of node.children) { if (child.matches(selector)) found.push(child); visit(child); } }; visit(this); return found; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  set innerHTML(value) { this.children = []; }
}

const document = { activeElement: null, readyState: 'complete', createElement: tag => new FakeElement(tag, document), querySelector: () => null };
const drafts = new Map();
const responses = new Map();
const cached = new Map();
const putBodies = [];
const cacheWrites = [];
const localStorage = { getItem: key => drafts.get(key) ?? null, setItem: (key, value) => drafts.set(key, value), removeItem: key => drafts.delete(key) };
const baseRow = (id = 11) => ({ booking_passenger_id: id, passenger_name: 'Ayesha Khan', passport_number: 'AY123456', visa_rate_card_id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', vendor_name: 'KSA Vendor', sale_pkr: 150000, vendor_cost_pkr: 120000, margin_pkr: 30000, status: 'pending', notes: '' });
const completeResponse = (id = 11) => ({ booking_id: id, setup_url: '/visa-management', currency: 'PKR', statuses: ['pending', 'approved'], passengers: [{ id, name: 'Ayesha Khan', passport_number: 'AY123456' }, { id: 12, name: 'Bilal Ahmed', passport_number: 'BA654321' }], rates: [{ id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', vendor_name: 'KSA Vendor', default_sale_pkr: 150000, vendor_cost_pkr: 120000 }], visa_rows: [baseRow(id)], summary: { customer_total: 150000, vendor_total: 120000, margin: 30000 } });
const response = id => responses.get(Number(id)) || completeResponse(Number(id));
const cache = { getProductResponse: (key, id) => cached.get(Number(id)) || null, getProductPromise: () => null, setProductResponse: (key, id, value) => { cached.set(Number(id), value); cacheWrites.push(value); }, setPassengerData() {} };
const window = { localStorage, etDedicatedProductCore: cache, addEventListener() {} };
delete window.data;
const context = { window, document, localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: (url, options = {}) => { const id = Number(String(url).match(/bookings\/(\d+)/)[1]); if (options.method === 'PUT') { putBodies.push(JSON.parse(options.body)); const saved = Object.assign({}, response(id), { visa_rows: [Object.assign({}, baseRow(id), { notes: 'saved note', sale_pkr: 160000, margin_pkr: 40000 })], summary: { customer_total: 160000, vendor_total: 120000, margin: 40000 }, message: 'saved' }); responses.set(id, saved); return Promise.resolve({ ok: true, json: () => Promise.resolve(saved) }); } return Promise.resolve({ ok: true, json: () => Promise.resolve(response(id)) }); } };
vm.runInNewContext(source, context);
const nextTick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
const makeRoot = id => { const root = new FakeElement('div', document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', id); root.setAttribute('data-billing-context', 'ORIGINAL'); root.setAttribute('data-billing-batch-id', '0'); const host = new FakeElement('div', document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host); return { root, host }; };
const treeText = node => node ? [node.textContent || '', ...node.children.map(treeText)].join(' ') : '';
const button = (host, label) => host.querySelectorAll('button').find(item => item.textContent === label);

// Clean response starts clean; details edit must enable the existing button without redraw.
cached.clear(); drafts.clear(); const clean = makeRoot(1); const cleanFacade = context.window.etVisaProductCore.mount({ root: clean.root, bookingId: 1 }); await nextTick();
ok(button(clean.host, 'Save Visa Data').disabled, 'clean initial Save is disabled');
button(clean.host, 'Details').click(); const notes = clean.host.querySelectorAll('textarea')[0]; notes.value = 'client note'; notes.dispatchEvent({ type: 'input', target: notes });
ok(!button(clean.host, 'Save Visa Data').disabled, 'detail edit enables Save immediately'); ok(notes.value === 'client note', 'detail edit value is retained');
button(clean.host, 'Save Visa Data').click(); await nextTick();
ok(putBodies[0] && !('passenger_name' in putBodies[0].visas[0]) && !('provider_name' in putBodies[0].visas[0]), 'server payload remains minimal');
const merged = cached.get(1); ok(merged && merged.passengers && merged.rates && merged.statuses && merged.setup_url && merged.currency && merged.booking_id, 'cache stores complete merged response');
ok(merged.passengers.length === 2 && merged.statuses.includes('approved') && merged.rates[0].vendor_name === 'KSA Vendor', 'cache retains GET-only fields after save');
cleanFacade.destroy(); const remount = makeRoot(1); context.window.etVisaProductCore.mount({ root: remount.root, bookingId: 1 }); await nextTick(); ok(button(remount.host, '+ Add Visa'), 'Add Visa remains available after cached remount'); button(remount.host, '+ Add Visa').click(); ok(remount.host.querySelectorAll('input').some(input => input.type === 'checkbox') && remount.host.querySelectorAll('input').some(input => input.type === 'radio'), 'cached remount retains passenger and rate choices');

const draftRoot = makeRoot(2); const draftFacade = context.window.etVisaProductCore.mount({ root: draftRoot.root, bookingId: 2 }); await nextTick(); button(draftRoot.host, 'Details').click(); const draftNotes = draftRoot.host.querySelectorAll('textarea')[0]; draftNotes.value = 'draft note'; draftNotes.dispatchEvent({ type: 'input', target: draftNotes }); const stored = JSON.parse(drafts.get('etgp-visa-product-draft-v113142:2::ORIGINAL::0::visa'));
ok(stored.visas[0].passenger_name === 'Ayesha Khan' && stored.visas[0].provider_name === 'KSA Chain' && stored.visas[0].vendor_cost_pkr === 120000 && stored.visas[0].margin_pkr === 30000, 'full draft snapshot retains display fields'); ok(stored.visas[0].notes === 'draft note', 'full draft snapshot retains edited value');
draftFacade.destroy(); const draftRemount = makeRoot(2); context.window.etVisaProductCore.mount({ root: draftRemount.root, bookingId: 2 }); await nextTick(); ok(treeText(draftRemount.host).includes('Ayesha Khan') && treeText(draftRemount.host).includes('KSA Chain'), 'full draft remount retains passenger and provider display');

const sparse = { visas: [{ booking_passenger_id: 3, visa_rate_card_id: 21, sale_pkr: 150000, status: 'approved', notes: 'legacy note' }] }; drafts.set('etgp-visa-product-draft-v113142:3', JSON.stringify(sparse)); responses.set(3, Object.assign(completeResponse(3), { visa_rows: [] })); const legacy = makeRoot(3); context.window.etVisaProductCore.mount({ root: legacy.root, bookingId: 3 }); await nextTick(); ok(!treeText(legacy.host).includes('legacy note'), 'legacy unscoped draft is not restored after C68'); legacy.querySelector;
ok(!Object.prototype.hasOwnProperty.call(window, 'data'), 'global data is not required');
console.log(`STATE_CACHE_DRAFT_REGRESSION=PASS (${assertions} assertions)`);
console.log(`CACHE_WRITES=${cacheWrites.length}`);
console.log(`DRAFT_PASSENGER_DISPLAY_RETAINED=${stored.visas[0].passenger_name === 'Ayesha Khan' ? 'YES' : 'NO'}`);
console.log(`LEGACY_SPARSE_DRAFT_HYDRATED=${treeText(legacy.host).includes('Ayesha Khan') ? 'YES' : 'NO'}`);
