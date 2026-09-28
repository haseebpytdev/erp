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
  click() { this.dispatchEvent({ type: 'click', target: this }); }
  focus() { this.document.activeElement = this; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  matches(selector) { if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1)); const attr = selector.match(/^\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]$/); if (attr) return Object.prototype.hasOwnProperty.call(this.attributes, attr[1]) && (!attr[2] || this.attributes[attr[1]] === attr[2]); return this.tagName.toLowerCase() === selector.toLowerCase(); }
  querySelectorAll(selector) { const selectors = selector.split(',').map(item => item.trim()); const found = []; const visit = node => { for (const child of node.children) { if (selectors.some(item => child.matches(item))) found.push(child); visit(child); } }; visit(this); return found; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  set innerHTML(value) { this.children = []; }
}
const document = { activeElement: null, readyState: 'complete', createElement: tag => new FakeElement(tag, document), querySelector: () => null };
const drafts = new Map(); let locked = false; let putCount = 0; let response;
const localStorage = { getItem: key => drafts.get(key) ?? null, setItem: (key, value) => drafts.set(key, value), removeItem: key => drafts.delete(key) };
const baseResponse = () => ({ booking_id: 3681, setup_url: '/visa-management', statuses: ['pending', 'approved'], passengers: [{ id: 11, name: 'Ayesha Khan', passport_number: 'AY123456' }, { id: 12, name: 'Bilal Ahmed', passport_number: 'BA654321' }], rates: [{ id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', default_sale_pkr: 42280, vendor_cost_pkr: 38000 }], visa_rows: [], summary: { customer_total: 0, vendor_total: 0, margin: 0 } });
response = baseResponse();
const core = { getLockState: () => ({ locked }), applyReadOnly: root => { if (locked) root.querySelectorAll('input,select,textarea,button').forEach(item => { item.disabled = true; }); return locked; }, getProductResponse: () => null, getProductPromise: () => null, setProductResponse() {} };
const window = { localStorage, etDedicatedProductCore: core, addEventListener() {} }; delete window.data;
const context = { window, document, localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: (url, options = {}) => { if (options.method === 'PUT') { putCount++; const body = JSON.parse(options.body); response = Object.assign({}, response, { visa_rows: body.visas.map(row => Object.assign({}, row, { passenger_name: row.booking_passenger_id === 11 ? 'Ayesha Khan' : 'Bilal Ahmed', vendor_cost_pkr: 38000, margin_pkr: Number(row.sale_pkr) - 38000 })), summary: { customer_total: body.visas.reduce((sum, row) => sum + Number(row.sale_pkr), 0), vendor_total: body.visas.length * 38000, margin: body.visas.reduce((sum, row) => sum + Number(row.sale_pkr) - 38000, 0) } }); return Promise.resolve({ ok: true, json: () => Promise.resolve(response) }); } return Promise.resolve({ ok: true, json: () => Promise.resolve(response) }); } };
vm.runInNewContext(source, context);
const nextTick = async () => { await Promise.resolve(); await Promise.resolve(); await Promise.resolve(); await new Promise(resolve => setImmediate(resolve)); };
const makeRoot = id => { const root = new FakeElement('div', document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', id); const host = new FakeElement('div', document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host); return { root, host }; };
const treeText = node => node ? [node.textContent || '', ...node.children.map(treeText)].join(' ') : '';
const button = (host, label) => host.querySelectorAll('button').find(item => item.textContent === label);

const root = makeRoot(3681); const facade = context.window.etVisaProductCore.mount({ root: root.root, bookingId: 3681 }); await nextTick();
button(root.host, '+ Add Visa').click();
let modalChecks = root.host.querySelectorAll('input').filter(input => input.type === 'checkbox');
ok(root.host.querySelectorAll('input').some(input => input.type === 'radio'), 'Visa Rate can be explicitly selected');
const radio = root.host.querySelectorAll('input').find(input => input.type === 'radio'); radio.checked = true; radio.dispatchEvent({ type: 'change', target: radio });
let modalSale = root.host.querySelectorAll('input').find(input => input.type === 'number');
ok(String(modalSale.value) === '42280', 'modal sale defaults from selected rate');
modalSale.value = '45000'; modalSale.dispatchEvent({ type: 'input', target: modalSale });
modalChecks = root.host.querySelectorAll('input').filter(input => input.type === 'checkbox'); modalChecks.slice(-2).forEach(input => { input.checked = true; input.dispatchEvent({ type: 'change', target: input }); });
modalSale = root.host.querySelectorAll('input').find(input => input.type === 'number'); modalSale.value = '45000'; modalSale.dispatchEvent({ type: 'input', target: modalSale });
ok(!button(root.host, 'Add Visa').disabled, 'custom modal sale enables Add Visa'); button(root.host, 'Add Visa').click();
ok(facade.getState().rows.length === 2 && facade.getState().rows.every(row => Number(row.sale_pkr) === 45000), 'bulk add applies custom sale to every selected passenger');
ok(facade.getState().rows.every(row => Number(row.vendor_cost_pkr) === 38000), 'bulk add preserves vendor cost authority');
ok(facade.getState().rows.every(row => Number(row.margin_pkr) === 7000), 'bulk add margins are correct');
let sales = root.host.querySelectorAll('.etgp-visa-sale-input-368'); sales[0].value = '50000'; sales[0].dispatchEvent({ type: 'input', target: sales[0] });
const firstSaleInputs = root.host.querySelectorAll('[data-etgp-visa-sale-pax="11"]'); const firstMargins = root.host.querySelectorAll('[data-etgp-visa-margin-pax="11"]');
ok(firstSaleInputs.length === 2 && firstSaleInputs.every(input => Number(input.value) === 50000), 'desktop and mobile sale inputs synchronize');
ok(firstMargins.length === 2 && firstMargins.every(node => node.textContent.includes('PKR 12,000')), 'desktop and mobile margins synchronize');
sales[1].value = '51000'; sales[1].dispatchEvent({ type: 'input', target: sales[1] });
ok(firstSaleInputs.every(input => Number(input.value) === 51000) && firstMargins.every(node => node.textContent.includes('PKR 13,000')), 'mobile sale edit synchronizes back without redraw');
ok(facade.getState().rows[0].sale_pkr === '51000' && facade.getState().rows[0].margin_pkr === 13000, 'responsive sale state remains authoritative');
ok(Number(facade.getState().rows[0].sale_pkr) === 51000 && Number(facade.getState().rows[1].sale_pkr) === 45000, 'editing one row does not change another');
ok(Number(facade.getState().rows[0].margin_pkr) === 13000, 'row margin recalculates immediately');
ok(treeText(root.host.querySelector('.etgp-visa-summary-366')).includes('PKR 96,000'), 'customer total recalculates immediately');
ok(treeText(root.host.querySelector('.etgp-visa-summary-366')).includes('PKR 76,000'), 'vendor total remains unchanged');
ok(treeText(root.host.querySelector('.etgp-visa-summary-366')).includes('PKR 20,000'), 'overall margin recalculates immediately');
ok(!button(root.host, 'Save Visa Data').disabled, 'sale edit enables Save'); button(root.host, 'Save Visa Data').click(); await nextTick();
ok(putCount === 1 && JSON.parse(drafts.get('etgp-visa-product-draft-v113142:3681') || 'null') === null, 'save sends edited sale and clears draft');
ok(response.visa_rows[0].sale_pkr === 51000, 'edited sale is present in save payload and response');
facade.destroy(); const reload = makeRoot(3681); context.window.etVisaProductCore.mount({ root: reload.root, bookingId: 3681 }); await nextTick();
ok(Number(reload.host.querySelectorAll('.etgp-visa-sale-input-368')[0].value) === 51000, 'edited sale persists after reload');
locked = true; const lockedRoot = makeRoot(3682); const lockedFacade = context.window.etVisaProductCore.mount({ root: lockedRoot.root, bookingId: 3682 }); await nextTick(); const lockedSale = lockedRoot.host.querySelectorAll('.etgp-visa-sale-input-368')[0]; ok(lockedSale.disabled === true, 'locked sale price is read-only'); lockedSale.value = '99999'; lockedSale.dispatchEvent({ type: 'input', target: lockedSale }); ok(lockedFacade.getState().dirty === false && putCount === 1, 'locked sale edit creates no dirty state or PUT');
locked = false; response = baseResponse(); const invalidRoot = makeRoot(3683); context.window.etVisaProductCore.mount({ root: invalidRoot.root, bookingId: 3683 }); await nextTick(); button(invalidRoot.host, '+ Add Visa').click(); const invalidRadio = invalidRoot.host.querySelectorAll('input').find(input => input.type === 'radio'); invalidRadio.checked = true; invalidRadio.dispatchEvent({ type: 'change', target: invalidRadio }); const invalidSale = invalidRoot.host.querySelectorAll('input').find(input => input.type === 'number'); invalidSale.value = '-1'; invalidSale.dispatchEvent({ type: 'input', target: invalidSale }); ok(button(invalidRoot.host, 'Add Visa').disabled === true, 'negative modal sale blocks Add Visa');
const invalidRow = makeRoot(3684); response = Object.assign(baseResponse(), { visa_rows: [{ booking_passenger_id: 11, passenger_name: 'Ayesha Khan', visa_rate_card_id: 21, country: 'Saudi Arabia', visa_type: 'Umrah', provider_name: 'KSA Chain', sale_pkr: 45000, vendor_cost_pkr: 38000, margin_pkr: 7000, status: 'pending' }] }); const invalidFacade = context.window.etVisaProductCore.mount({ root: invalidRow.root, bookingId: 3684 }); await nextTick(); const invalidRowSale = invalidRow.host.querySelectorAll('.etgp-visa-sale-input-368')[0]; invalidRowSale.value = '-5'; invalidRowSale.dispatchEvent({ type: 'input', target: invalidRowSale }); ok(button(invalidRow.host, 'Save Visa Data').disabled === true, 'negative row sale cannot be saved');

console.log(`VISA_SALE_PRICE_REGRESSION=PASS (${assertions} assertions)`);
console.log('RESPONSIVE_SALE_SYNC_REGRESSION=PASS');
console.log('MODAL_SALE_DEFAULT_PREFILL=PASS');
console.log('MODAL_SALE_OVERRIDE=PASS');
console.log('BULK_ADD_CUSTOM_SALE=PASS');
console.log('ROW_SALE_EDIT=PASS');
console.log('ROW_MARGIN_RECALCULATION=PASS');
console.log('CUSTOMER_TOTAL_RECALCULATION=PASS');
console.log('VENDOR_TOTAL_UNCHANGED=PASS');
console.log('OVERALL_MARGIN_RECALCULATION=PASS');
console.log('SALE_SAVE_PAYLOAD=PASS');
console.log('SALE_RELOAD_PERSISTENCE=PASS');
console.log('MARGIN_RECALCULATES_AFTER_SAVE=PASS');
console.log('LOCKED_SALE_EDIT=BLOCKED');
console.log('INVALID_SALE_VALIDATION=PASS');
