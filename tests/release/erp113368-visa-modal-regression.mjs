import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/products/visa-core.js', import.meta.url), 'utf8');
let assertions = 0;
const ok = (condition, label) => { assert.ok(condition, label); assertions++; };
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
  querySelectorAll(selector) { const selectors = selector.split(',').map(item => item.trim()); const found = []; const visit = node => { for (const child of node.children) { if (selectors.some(item => child.matches(item))) found.push(child); visit(child); } }; visit(this); return found; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  set innerHTML(value) { this.children = []; }
}
const document = { activeElement: null, readyState: 'complete', createElement: tag => new FakeElement(tag, document), querySelector: () => null };
let locked = false;
const response = { booking_id: 3685, setup_url: '/visa-management', statuses: ['pending', 'approved'], passengers: [{ id: 11, name: 'Ayesha Khan', passport_number: 'AY123456' }, { id: 12, name: 'Bilal Ahmed', passport_number: 'BA654321' }, { id: 13, name: 'Existing Visa', passport_number: 'EV000001' }], rates: [{ id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', default_sale_pkr: 42280, vendor_cost_pkr: 38000 }, { id: 22, country: 'UAE', visa_type: 'Visit', provider_name: 'UAE Provider', default_sale_pkr: 70000, vendor_cost_pkr: 60000 }], visa_rows: [{ booking_passenger_id: 13, passenger_name: 'Existing Visa', visa_rate_card_id: 21, sale_pkr: 42280, vendor_cost_pkr: 38000, margin_pkr: 4280, status: 'pending' }], summary: { customer_total: 42280, vendor_total: 38000, margin: 4280 } };
const drafts = new Map();
const localStorage = { getItem: key => drafts.get(key) ?? null, setItem: (key, value) => drafts.set(key, value), removeItem: key => drafts.delete(key) };
const core = { getLockState: () => ({ locked }), applyReadOnly: root => { if (locked) root.querySelectorAll('input,select,textarea,button').forEach(item => { item.disabled = true; }); return locked; }, getProductResponse: () => null, getProductPromise: () => null, setProductResponse() {} };
const window = { localStorage, etDedicatedProductCore: core, addEventListener() {} }; delete window.data;
const context = { window, document, localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(response) }) };
vm.runInNewContext(source, context);
const nextTick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
const makeRoot = id => { const root = new FakeElement('div', document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', id); const host = new FakeElement('div', document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host); return { root, host }; };
const button = (host, label) => host.querySelectorAll('button').find(item => item.textContent === label);
const modalInput = (host, kind) => host.querySelectorAll('input').find(input => input.getAttribute('data-etgp-visa-search') === kind);
const modal = makeRoot(3685); context.window.etVisaProductCore.mount({ root: modal.root, bookingId: 3685 }); await nextTick(); button(modal.host, '+ Add Visa').click();
ok(Boolean(modal.host.querySelector('.etgp-visa-dialog-366')), 'modal opens');
const passengerOption = modal.host.querySelector('.etgp-visa-passenger-choice-368');
ok(passengerOption.children.length === 3 && passengerOption.children[0].type === 'checkbox' && passengerOption.children[1].className.includes('etgp-visa-passenger-name-368') && passengerOption.children[2].className.includes('etgp-visa-passport-368'), 'passenger options use separate checkbox, name and passport columns');
ok(passengerOption.children[1].textContent === 'Ayesha Khan' && passengerOption.children[2].textContent === 'AY123456', 'passenger name and passport remain paired');
ok(Boolean(modal.host.querySelector('.etgp-visa-rate-identity-368')) && Boolean(modal.host.querySelector('.etgp-visa-rate-commercial-368')), 'rate identity and commercial details use readable semantic layout');
ok(modal.host.querySelectorAll('input').filter(input => input.type === 'checkbox').every(input => !input.checked), 'no passenger selected automatically');
ok(modal.host.querySelectorAll('input').filter(input => input.type === 'radio').every(input => !input.checked), 'no rate selected automatically');
button(modal.host, 'Select All Eligible').click();
ok(modal.host.querySelector('.etgp-visa-selected-count-368').textContent === '2 selected', 'Select All Eligible selects only eligible passengers');
button(modal.host, 'Clear Selection').click();
ok(modal.host.querySelector('.etgp-visa-selected-count-368').textContent === '0 selected', 'Clear Selection clears modal selection');
const passengerSearch = modalInput(modal.host, 'passenger'); passengerSearch.focus(); passengerSearch.value = 'Ayesha'; passengerSearch.dispatchEvent({ type: 'input', target: passengerSearch });
button(modal.host, 'Select All Eligible').click();
ok(modal.host.querySelector('.etgp-visa-selected-count-368').textContent === '1 selected', 'filtered Select All selects filtered eligible passenger');
const passengerSearchAfterFilter = modalInput(modal.host, 'passenger'); passengerSearchAfterFilter.focus(); passengerSearchAfterFilter.value = ''; passengerSearchAfterFilter.dispatchEvent({ type: 'input', target: passengerSearchAfterFilter });
ok(modal.host.querySelector('.etgp-visa-selected-count-368').textContent === '1 selected', 'selected passenger survives filtering');
ok(modal.host.querySelectorAll('.etgp-visa-choice-list-366')[0].querySelectorAll('input').filter(input => input.type === 'checkbox').length === 2, 'existing Visa passenger is excluded');
ok(document.activeElement === modalInput(modal.host, 'passenger'), 'passenger search focus is preserved');
const rateSearch = modalInput(modal.host, 'rate'); rateSearch.focus(); rateSearch.value = 'UAE'; rateSearch.dispatchEvent({ type: 'input', target: rateSearch });
ok(document.activeElement === modalInput(modal.host, 'rate'), 'rate search focus is preserved');
const rate = modal.host.querySelectorAll('input').find(input => input.type === 'radio'); rate.checked = true; rate.dispatchEvent({ type: 'change', target: rate });
ok(modal.host.querySelector('.etgp-visa-selected-count-368').textContent === '1 selected' && rate.checked, 'passenger to rate selection works');
const passengerSearchAfterRate = modalInput(modal.host, 'passenger'); passengerSearchAfterRate.focus(); passengerSearchAfterRate.value = ''; passengerSearchAfterRate.dispatchEvent({ type: 'input', target: passengerSearchAfterRate });
ok(modal.host.querySelectorAll('input').filter(input => input.type === 'radio').some(input => input.checked), 'rate to passenger navigation works');
let sale = modal.host.querySelector('.etgp-visa-dialog-366').querySelectorAll('input').find(input => input.type === 'number'); sale.value = '45000'; sale.dispatchEvent({ type: 'input', target: sale });
ok(String(sale.value) === '45000', 'modal custom sale is editable');
button(modal.host, 'Select All Eligible').click();
ok(modal.host.querySelector('.etgp-visa-selected-count-368').textContent === '2 selected', 'multiple passengers can use one custom sale');
sale = modal.host.querySelector('.etgp-visa-dialog-366').querySelectorAll('input').find(input => input.type === 'number'); ok(String(sale.value) === '45000', 'custom sale remains visible for bulk add');
button(modal.host, 'Add Visa').click(); await nextTick();
const rows = context.window.etVisaProductCore.mount({ root: modal.root, bookingId: 3685 }).getState().rows;
ok(rows.length === 3 && rows.filter(row => Number(row.booking_passenger_id) !== 13).every(row => Number(row.sale_pkr) === 45000), 'bulk add creates selected rows with custom sale');
ok(rows.filter(row => Number(row.booking_passenger_id) !== 13).every(row => Number(row.vendor_cost_pkr) === 60000), 'bulk add retains rate vendor cost authority');
const beforeCancel = rows.length; button(modal.host, '+ Add Visa').click(); button(modal.host, 'Cancel').click();
ok(context.window.etVisaProductCore.mount({ root: modal.root, bookingId: 3685 }).getState().rows.length === beforeCancel, 'Cancel causes no row mutation');
locked = true; button(modal.host, '+ Add Visa').click();
ok(!modal.host.querySelector('.etgp-visa-dialog-366') || button(modal.host, 'Select All Eligible')?.disabled === true, 'locked modal mutation is blocked');
console.log(`ERP368_MODAL_REGRESSION=PASS (${assertions} assertions)`);
console.log('PASSENGER_DOM_ALIGNMENT_REGRESSION=PASS');
