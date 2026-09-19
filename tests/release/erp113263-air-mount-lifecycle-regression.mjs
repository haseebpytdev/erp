import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const coreSource = fs.readFileSync(new URL('../../public/erp-theme/js/dedicated-product-core.js', import.meta.url), 'utf8');
const airSource = fs.readFileSync(new URL('../../public/erp-theme/js/products/air.js', import.meta.url), 'utf8');

class FakeClassList {
  constructor(owner) { this.owner = owner; }
  add(...names) { names.forEach(n => this.owner.className = `${this.owner.className} ${n}`.trim()); }
  remove(...names) { names.forEach(n => { this.owner.className = this.owner.className.split(/\s+/).filter(x => x && x !== n).join(' '); }); }
  toggle(name, force) { const has = this.owner.className.split(/\s+/).includes(name); if (force === undefined ? !has : force) this.add(name); else this.remove(name); return force === undefined ? !has : force; }
  contains(name) { return this.owner.className.split(/\s+/).includes(name); }
}

class FakeElement {
  constructor(tag = 'div') { this.tagName = tag.toUpperCase(); this.children = []; this.parentNode = null; this.attributes = {}; this.dataset = {}; this.listeners = {}; this.className = ''; this.classList = new FakeClassList(this); this.hidden = false; this.disabled = false; this.value = ''; this.type = ''; this.textContent = ''; this.innerHTML = ''; this.isConnected = true; }
  appendChild(child) { child.parentNode = this; child.isConnected = this.isConnected; this.children.push(child); return child; }
  removeChild(child) { this.children = this.children.filter(c => c !== child); child.parentNode = null; child.isConnected = false; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'class') this.className = String(value); if (name.startsWith('data-')) this.dataset[name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  removeAttribute(name) { delete this.attributes[name]; if (name.startsWith('data-')) delete this.dataset[name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())]; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  dispatchEvent(event) { (this.listeners[event.type] || []).forEach(fn => fn.call(this, event)); if (event.bubbles !== false && this.parentNode) this.parentNode.dispatchEvent(event); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  querySelectorAll(selector) { const out = []; const selectors = selector.split(',').map(x => x.trim()); const walk = node => { for (const child of node.children) { if (selectors.some(s => child.matches(s))) out.push(child); walk(child); } }; walk(this); return out; }
  matches(selector) {
    const attr = selector.match(/^([^[]*)\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/);
    if (attr) { if (attr[1] && !this.matches(attr[1])) return false; const v = this.getAttribute(attr[2]); return v !== null && (attr[3] === undefined || v === attr[3]); }
    if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
    if (selector === 'input,select,textarea,button,[role="button"]') return ['INPUT','SELECT','TEXTAREA','BUTTON'].includes(this.tagName) || this.getAttribute('role') === 'button';
    if (selector.includes('.')) { const [tag, cls] = selector.split('.'); return (!tag || this.tagName === tag.toUpperCase()) && this.classList.contains(cls); }
    return !selector || this.tagName === selector.toUpperCase();
  }
  closest(selector) { let n = this; while (n) { if (n.matches(selector)) return n; n = n.parentNode; } return null; }
  get options() { return this.children.filter(c => c.tagName === 'OPTION'); }
  get selectedIndex() { return Math.max(0, this.options.findIndex(o => o.selected)); }
}

class FakeDocument extends FakeElement {
  constructor() { super('#document'); this.meta = new FakeElement('meta'); this.meta.setAttribute('name', 'csrf-token'); this.meta.content = 'csrf'; }
  createElement(tag) { return new FakeElement(tag); }
  querySelector(selector) { if (selector === 'meta[name="csrf-token"]') return this.meta; return super.querySelector(selector); }
}

const storage = new Map();
let putShouldFail = false;
let requests = [];
const document = new FakeDocument();
const window = { document, localStorage: { getItem: k => storage.get(k) ?? null, setItem: (k, v) => storage.set(k, String(v)), removeItem: k => storage.delete(k) }, requestAnimationFrame: fn => fn(), setTimeout, clearTimeout };
const response = data => Promise.resolve({ ok: !putShouldFail, json: async () => putShouldFail ? { ok: false, message: 'save failed' } : { ok: true, ...data } });
const context = vm.createContext({ window, document, localStorage: window.localStorage, fetch: (url, options = {}) => { requests.push({ url, method: options.method || 'GET' }); return response({ capabilities: { booking_services: true, air_ticket_details: true, booking_itinerary_segments: true, booking_currency: 'PKR' }, passengers: [], tickets: [], airlines: [], flight_numbers: [], suppliers: [], common: {}, fare_commercials: {} }); }, console, Promise, Object, Array, Number, String, Date, JSON, Math, Error, setTimeout, clearTimeout });

vm.runInContext(coreSource, context);
const core = window.etDedicatedProductCore;
vm.runInContext(airSource, context);

const makeRoot = bookingId => {
  const root = new FakeElement('main'); root.setAttribute('data-etgp-dedicated-product', '1'); root.setAttribute('data-etgp-product-key', 'air'); root.setAttribute('data-booking-id', bookingId); root.setAttribute('data-etgp-booking-locked', '0'); root.setAttribute('data-etgp-booking-status', 'DRAFT');
  const body = new FakeElement('div'); body.setAttribute('data-etgp-dedicated-product-body', '1'); root.appendChild(body); document.appendChild(root); return root;
};
const state = () => window.etDedicatedAirProduct.getState();
const wait = () => new Promise(resolve => setImmediate(resolve));

let root = makeRoot(101);
assert.equal(window.etDedicatedAirProduct.mount(root), true);
await wait();
assert.equal(JSON.stringify(state()), JSON.stringify({ saveInFlight: false, dirty: false, draftPending: false, bookingId: 101 }));
assert.equal(requests.filter(r => r.method === 'GET').length, 1);
assert.equal(requests.find(r => r.method === 'GET').url, '/system/erp-bookings/101/air-product');

const input = root.querySelector('input');
input.dispatchEvent({ type: 'input' });
assert.equal(state().dirty, true);

const save = root.querySelector('.etgp-air-save-113106');
save.dispatchEvent({ type: 'click' });
assert.equal(state().saveInFlight, true);
assert.ok(storage.has('etgp-air-product-draft-v113119:101'));
await wait();
assert.equal(state().saveInFlight, false);
assert.equal(state().dirty, false);
assert.equal(state().draftPending, false);
assert.equal(window.localStorage.getItem('etgp-air-product-draft-v113119:101'), null);
assert.ok(requests.some(r => r.method === 'PUT' && r.url.endsWith('/101/air-product')));

root.isConnected = false;
document.removeChild(root);
const dirtyRootA = makeRoot(150);
assert.equal(window.etDedicatedAirProduct.mount(dirtyRootA), true);
await wait();
dirtyRootA.querySelector('input').dispatchEvent({ type: 'input' });
assert.equal(state().dirty, true);
dirtyRootA.isConnected = false;
document.removeChild(dirtyRootA);
const rootB = makeRoot(202);
assert.equal(window.etDedicatedAirProduct.mount(rootB), true);
await wait();
assert.equal(JSON.stringify(state()), JSON.stringify({ saveInFlight: false, dirty: false, draftPending: false, bookingId: 202 }));
rootB.querySelector('input').dispatchEvent({ type: 'input' });
rootB.querySelector('.etgp-air-save-113106').dispatchEvent({ type: 'click' });
const blockedRoot = makeRoot(250);
assert.equal(window.etDedicatedAirProduct.mount(blockedRoot), false);
await wait();

const draftKey = 'etgp-air-product-draft-v113119:303';
storage.set(draftKey, JSON.stringify({ saved_at: Date.now(), payload: { common: {} } }));
const rootDraft = makeRoot(303);
assert.equal(window.etDedicatedAirProduct.mount(rootDraft), true);
await wait();
assert.equal(state().dirty, true);
assert.equal(state().draftPending, true);

putShouldFail = true;
rootDraft.querySelector('input').dispatchEvent({ type: 'change' });
rootDraft.querySelector('.etgp-air-save-113106').dispatchEvent({ type: 'click' });
await wait();
assert.equal(state().saveInFlight, false);
assert.equal(state().dirty, true);
assert.equal(state().draftPending, true);
assert.ok(requests.some(r => r.method === 'PUT' && r.url.endsWith('/303/air-product')));

console.log('PASS 12 Air lifecycle behavioral assertions');
