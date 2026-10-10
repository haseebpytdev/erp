import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/products/visa-core.js', import.meta.url), 'utf8');
let assertions = 0;
const ok = (condition, label) => { assert.ok(condition, label); assertions++; };

class FakeElement {
  constructor(tag, document) { this.tagName = String(tag).toUpperCase(); this.document = document; this.children = []; this.parentNode = null; this.attributes = {}; this.listeners = {}; this.className = ''; this.textContent = ''; this.value = ''; this.selectionStart = 0; this.selectionEnd = 0; this.disabled = false; this.checked = false; this.options = this.tagName === 'SELECT' ? this.children : undefined; }
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
    if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1));
    const attr = selector.match(/^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/);
    if (attr) return Object.prototype.hasOwnProperty.call(this.attributes, attr[1]) && (!attr[2] || this.attributes[attr[1]] === attr[2]);
    return this.tagName.toLowerCase() === selector.toLowerCase();
  }
  querySelectorAll(selector) { const found = []; const visit = node => { for (const child of node.children) { if (child.matches(selector)) found.push(child); visit(child); } }; visit(this); return found; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  set innerHTML(value) { this.children = []; }
}

const document = { activeElement: null, readyState: 'complete', createElement: tag => new FakeElement(tag, document), querySelector: () => null };
const window = { localStorage: { getItem: () => null, setItem() {}, removeItem() {} }, etDedicatedProductCore: { getProductEndpoint: (product, booking) => '/system/erp-bookings/' + booking + '/' + product + '-product', getProductScope: (product, booking) => ({ booking_id: Number(booking), billing_context: 'ORIGINAL', billing_batch_id: 0, product }) }, addEventListener() {} };
delete window.data;
const errors = [];
const response = {
  statuses: ['pending', 'submitted'],
  passengers: [{ id: 11, name: 'Ayesha Khan', passport_number: 'AY123456' }],
  rates: [{ id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', default_sale_pkr: 150000, vendor_cost_pkr: 120000 }],
  visa_rows: [], summary: { customer_total: 0, vendor_total: 0, margin: 0 },
};
const saved = { ...response, visa_rows: [{ booking_passenger_id: 11, passenger_name: 'Ayesha Khan', country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', sale_pkr: 160000, vendor_cost_pkr: 120000, margin_pkr: 40000, status: 'pending' }], summary: { customer_total: 160000, vendor_total: 120000, margin: 40000 }, message: 'saved' };
const context = { window, document, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: (url, options = {}) => Promise.resolve({ ok: true, json: () => Promise.resolve(options.method === 'PUT' ? saved : response) }) };
vm.runInNewContext(source, context);
const treeText = node => [node.textContent || '', ...node.children.map(treeText)].join(' ');
const nextTick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
const typeSequentially = (host, input, value) => { const kind = input.getAttribute('data-etgp-visa-search'); for (let i = 1; i <= value.length; i++) { input.value = value.slice(0, i); input.selectionStart = i; input.selectionEnd = i; input.focus(); input.dispatchEvent({ type: 'input', target: input }); input = host.querySelector(`[data-etgp-visa-search="${kind}"]`); } return input; };
const makeRoot = id => { const root = new FakeElement('div', document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', id); const host = new FakeElement('div', document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host); return { root, host }; };

const first = makeRoot(3661);
let facade;
try { facade = context.window.etVisaProductCore.mount({ root: first.root, bookingId: 3661 }); await nextTick(); } catch (error) { errors.push(error); }
ok(facade && !errors.length, 'initial GET/render completes');
const initialTotal = treeText(first.host.querySelector('.etgp-visa-summary-366'));
let main = first.host.querySelector('[data-etgp-visa-search="main"]');
main = typeSequentially(first.host, main, 'AYESHA');
ok(main.value === 'AYESHA', 'main search retains the complete typed value');
ok(document.activeElement === main, 'main search focus is retained');
first.host.querySelectorAll('button').find(button => button.textContent === '+ Add Visa').click();
let passenger = first.host.querySelector('[data-etgp-visa-search="passenger"]');
passenger = typeSequentially(first.host, passenger, 'AYESHA');
ok(passenger.value === 'AYESHA', 'passenger search retains the complete typed value');
ok(document.activeElement === passenger, 'passenger search focus is retained');
let rate = first.host.querySelector('[data-etgp-visa-search="rate"]');
rate = typeSequentially(first.host, rate, 'UMRAH');
ok(rate.value === 'UMRAH', 'rate search retains the complete typed value');
ok(document.activeElement === rate, 'rate search focus is retained');
const passengerCheck = first.host.querySelectorAll('input').filter(input => input.type === 'checkbox')[1];
let rateRadio = first.host.querySelectorAll('input').find(input => input.type === 'radio');
const confirm = () => first.host.querySelectorAll('button').find(button => button.textContent === 'Add Visa');
ok(confirm().disabled, 'no passenger and no rate keeps Add Visa disabled');
passengerCheck.checked = true; passengerCheck.dispatchEvent({ type: 'change', target: passengerCheck });
ok(confirm().disabled, 'passenger without rate keeps Add Visa disabled');
rateRadio = first.host.querySelectorAll('input').find(input => input.type === 'radio');
rateRadio.checked = true; rateRadio.dispatchEvent({ type: 'change', target: rateRadio });
ok(!confirm().disabled, 'Passenger then Rate enables Add Visa');
confirm().click();
const addedTotal = treeText(first.host.querySelector('.etgp-visa-summary-366'));
ok(addedTotal.includes('PKR 150,000'), 'dirty summary reflects added row immediately');
const save = first.host.querySelectorAll('button').find(button => button.textContent === 'Save Visa Data');
save.click(); await nextTick();
const savedTotal = treeText(first.host.querySelector('.etgp-visa-summary-366'));
ok(savedTotal.includes('PKR 160,000'), 'successful save refreshes authoritative summary');

const second = makeRoot(3662); let secondFacade;
try { secondFacade = context.window.etVisaProductCore.mount({ root: second.root, bookingId: 3662 }); await nextTick(); second.host.querySelectorAll('button').find(button => button.textContent === '+ Add Visa').click(); } catch (error) { errors.push(error); }
let secondPassenger = second.host.querySelectorAll('input').filter(input => input.type === 'checkbox')[1];
let secondRate = second.host.querySelectorAll('input').find(input => input.type === 'radio');
const secondConfirm = () => second.host.querySelectorAll('button').find(button => button.textContent === 'Add Visa');
ok(secondFacade && secondConfirm().disabled, 'second mount starts with Add Visa disabled');
secondRate.checked = true; secondRate.dispatchEvent({ type: 'change', target: secondRate });
ok(secondConfirm().disabled, 'rate without passenger keeps Add Visa disabled');
secondPassenger = second.host.querySelectorAll('input').filter(input => input.type === 'checkbox')[1];
secondPassenger.checked = true; secondPassenger.dispatchEvent({ type: 'change', target: secondPassenger });
ok(!secondConfirm().disabled, 'Rate then Passenger enables Add Visa');
secondPassenger.checked = false; secondPassenger.dispatchEvent({ type: 'change', target: secondPassenger });
ok(secondConfirm().disabled, 'deselecting the final passenger disables Add Visa');
ok(!errors.length, 'interaction path has zero uncaught errors');

console.log(`INTERACTION_RUNTIME_REGRESSION=PASS (${assertions} assertions)`);
console.log(`INITIAL_TOTAL=${initialTotal.includes('PKR 0') ? 'PKR 0' : initialTotal}`);
console.log(`ADD_ROW_TOTAL=${addedTotal.includes('PKR 150,000') ? 'PKR 150,000' : addedTotal}`);
console.log(`AFTER_SAVE_TOTAL=${savedTotal.includes('PKR 160,000') ? 'PKR 160,000' : savedTotal}`);
