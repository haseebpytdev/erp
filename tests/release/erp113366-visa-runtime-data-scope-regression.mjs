import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const read = p => fs.readFileSync(new URL('../../' + p, import.meta.url), 'utf8');
const source = read('public/erp-theme/js/products/visa-core.js');
let assertions = 0;
const ok = (condition, label) => { assert.ok(condition, label); assertions++; };
ok(source.includes('responseData = {}'), 'mount owns one loaded response object');
ok(source.includes('statuses(responseData)') && source.includes('addModal(responseData, overlay)') && !source.includes('data.summary'), 'draw uses the mount-scoped response object');

class FakeElement {
  constructor(tag) {
    this.tagName = String(tag).toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.attributes = {};
    this.listeners = {};
    this.className = '';
    this.textContent = '';
    this.value = '';
    this.type = '';
    this.disabled = false;
    this.checked = false;
    this.options = this.tagName === 'SELECT' ? this.children : undefined;
  }
  appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
  dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn.call(this, event); return true; }
  click() { this.dispatchEvent({ type: 'click', target: this }); }
  get innerHTML() { return this.children.map(child => child.textContent || '').join(''); }
  set innerHTML(value) { this.children = []; }
  matches(selector) {
    if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1));
    const attr = selector.match(/^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/);
    if (attr) return Object.prototype.hasOwnProperty.call(this.attributes, attr[1]) && (!attr[2] || this.attributes[attr[1]] === attr[2]);
    return this.tagName.toLowerCase() === selector.toLowerCase();
  }
  querySelectorAll(selector) {
    const found = [];
    const visit = node => { for (const child of node.children) { if (child.matches(selector)) found.push(child); visit(child); } };
    visit(this);
    return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

const document = {
  createElement: tag => new FakeElement(tag),
  querySelector: () => null,
  readyState: 'complete',
};
const root = new FakeElement('div');
root.setAttribute('data-etgp-product-key', 'visa');
root.setAttribute('data-booking-id', '366');
const host = new FakeElement('div');
host.setAttribute('data-etgp-dedicated-product-host', '1');
root.appendChild(host);
const response = {
  statuses: ['pending', 'submitted'],
  passengers: [{ id: 11, name: 'Aisha Khan', passport_number: 'AB123456' }],
  rates: [{ id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', default_sale_pkr: 150000, vendor_cost_pkr: 120000 }],
  visa_rows: [{ booking_passenger_id: 11, passenger_name: 'Aisha Khan', country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', sale_pkr: 150000, vendor_cost_pkr: 120000, status: 'pending' }],
  summary: { customer_total: 150000, vendor_total: 120000, margin: 30000 },
};
const errors = [];
const window = {
  etDedicatedProductCore: {},
  addEventListener() {},
  localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
};
delete window.data;
const context = { window, document, fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(response) }), console, Set, Number, String, Object, Array, Promise, Error, Math, JSON };
vm.runInNewContext(source, context);
let facade;
try {
  facade = context.window.etVisaProductCore.mount({ root, bookingId: 366 });
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
  await new Promise(resolve => setImmediate(resolve));
} catch (error) {
  errors.push(error);
}

ok(facade && !errors.length, 'mount completes without a runtime error');
ok(window.data === undefined, 'runtime has no global data dependency');
ok(host.querySelector('h2')?.textContent === 'Visa', 'Visa heading renders');
ok(host.querySelector('.etgp-visa-toolbar-366'), 'Visa toolbar renders');
ok(host.querySelectorAll('.etgp-visa-row-366').length === 1, 'Visa rows render');
ok(host.querySelector('.etgp-visa-summary-366'), 'Visa summary renders');
const addButton = host.querySelectorAll('button').find(button => button.textContent === '+ Add Visa');
ok(addButton, 'Add Visa action renders');
try { addButton.click(); } catch (error) { errors.push(error); }
ok(host.querySelector('[role="dialog"]'), 'Add Visa can open after loaded render');
ok(!errors.some(error => error instanceof ReferenceError || /data is not defined/.test(String(error))), 'no out-of-scope data ReferenceError occurs');

console.log(`GLOBAL_DATA_REQUIRED=NO`);
console.log(`PREVIOUS_FIXTURE_FALSE_PASS_ROOT_CAUSE=the previous ERP-11.3.366 regression was static source-contract coverage and never executed draw() through a real GET/render cycle with window.data absent`);
console.log(`RUNTIME_SCOPE_REGRESSION=PASS (${assertions} assertions)`);
