import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/products/visa-core.js', import.meta.url), 'utf8');
let assertions = 0; const ok = (condition, label) => { assert.ok(condition, label); assertions++; };
class BrowserNodeList {
  constructor(items) { this.length = items.length; items.forEach((item, index) => { this[index] = item; }); }
  forEach(fn, thisArg) { for (let index = 0; index < this.length; index++) fn.call(thisArg, this[index], index, this); }
  [Symbol.iterator]() { let index = 0; return { next: () => index < this.length ? { value: this[index++], done: false } : { value: undefined, done: true } }; }
}
class FakeElement {
  constructor(tag, document) { this.tagName = String(tag).toUpperCase(); this.document = document; this.children = []; this.parentNode = null; this.attributes = {}; this.listeners = {}; this.className = ''; this.textContent = ''; this.value = ''; this.type = ''; this.disabled = false; this.checked = false; this.options = this.tagName === 'SELECT' ? this.children : undefined; }
  appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
  dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn.call(this, event); return true; }
  click() { if (!this.disabled) this.dispatchEvent({ type: 'click', target: this }); }
  focus() { this.document.activeElement = this; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  matches(selector) { if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1)); const attr = selector.match(/^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/); if (attr) return Object.prototype.hasOwnProperty.call(this.attributes, attr[1]) && (!attr[2] || this.attributes[attr[1]] === attr[2]); return this.tagName.toLowerCase() === selector.toLowerCase(); }
  querySelectorAll(selector) { const selectors = selector.split(',').map(item => item.trim()); const found = []; const visit = node => { for (const child of node.children) { if (selectors.some(item => child.matches(item))) found.push(child); visit(child); } }; visit(this); return new BrowserNodeList(found); }
  querySelector(selector) { const list = this.querySelectorAll(selector); return list.length ? list[0] : null; }
  set innerHTML(value) { this.children = []; }
}
const document = { activeElement: null, readyState: 'complete', createElement: tag => new FakeElement(tag, document), querySelector: () => null };
const response = { booking_id: 3686, passengers: [{ id: 11, name: 'Ayesha Khan', passport_number: 'AY123456' }], rates: [], visa_rows: [{ booking_passenger_id: 11, passenger_name: 'Ayesha Khan', passport_number: 'AY123456', sale_pkr: 42280, vendor_cost_pkr: 38000, margin_pkr: 4280, status: 'pending' }], summary: { customer_total: 42280, vendor_total: 38000, margin: 4280 } };
let locked = false;
const core = { getLockState: () => ({ locked }), applyReadOnly: root => false, getProductResponse: () => null, getProductPromise: () => null, setProductResponse() {} };
const localStorage = { getItem: () => null, setItem() {}, removeItem() {} }; const window = { localStorage, etDedicatedProductCore: core, addEventListener() {} }; delete window.data;
const context = { window, document, localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(response) }) };
vm.runInNewContext(source, context);
const nextTick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
const root = new FakeElement('div', document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', '3686'); const host = new FakeElement('div', document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host);
const facade = context.window.etVisaProductCore.mount({ root, bookingId: 3686 }); await nextTick();
const all = (node, selector) => Array.from(node.querySelectorAll(selector)); const sales = () => all(host, '.etgp-visa-sale-input-368'); const margins = () => all(host, '[data-etgp-visa-margin-pax="11"]');
const treeText = node => node ? [node.textContent || '', ...node.children.map(treeText)].join(' ') : '';
let uncaught = 0; try { sales()[0].value = '45000'; sales()[0].dispatchEvent({ type: 'input', target: sales()[0] }); } catch (error) { uncaught++; }
ok(uncaught === 0, 'desktop sale edit has no uncaught NodeList exception');
ok(sales().length === 2 && sales().every(input => Number(input.value) === 45000), 'desktop sale synchronizes to mobile Sale input');
ok(margins().length === 2 && margins().every(node => node.textContent.includes('PKR 7,000')), 'desktop sale synchronizes both Margin displays');
ok(facade.getState().dirty === true, 'sale edit marks workspace dirty');
ok(host.querySelectorAll('button').forEach && all(host, 'button').some(button => button.textContent === 'Save Visa Data' && button.disabled === false), 'Save remains enabled');
ok(treeText(all(host, '.etgp-visa-summary-366')[0]).includes('PKR 45,000'), 'totals update after desktop edit');
try { sales()[1].value = '46000'; sales()[1].dispatchEvent({ type: 'input', target: sales()[1] }); } catch (error) { uncaught++; }
ok(uncaught === 0, 'mobile sale edit has no uncaught NodeList exception');
ok(sales().every(input => Number(input.value) === 46000), 'mobile sale synchronizes back to desktop');
ok(margins().every(node => node.textContent.includes('PKR 8,000')), 'mobile sale synchronizes both margins');
ok(facade.getState().rows[0].sale_pkr === '46000' && facade.getState().rows[0].margin_pkr === 8000, 'row state remains synchronized');
console.log(`NATIVE_NODELIST_COMPATIBILITY_REGRESSION=PASS (${assertions} assertions)`);
console.log('SALE_EDIT_UNCAUGHT_ERRORS=0');
console.log('DESKTOP_MOBILE_SALE_SYNC=YES');
console.log('DESKTOP_MOBILE_MARGIN_SYNC=YES');
