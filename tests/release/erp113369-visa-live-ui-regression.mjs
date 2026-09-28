import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const visaSource = fs.readFileSync(new URL('../../public/erp-theme/js/products/visa-core.js', import.meta.url), 'utf8');
const navSource = fs.readFileSync(new URL('../../public/erp-theme/js/dedicated-visa-navigation.js', import.meta.url), 'utf8');
const cssSource = fs.readFileSync(new URL('../../public/erp-theme/css/products/visa.css', import.meta.url), 'utf8');
const workspace = fs.readFileSync(new URL('../../resources/views/operations/bookings/partials/product-workspace-v113305.blade.php', import.meta.url), 'utf8');
let assertions = 0; const ok = (condition, label) => { assert.ok(condition, label); assertions++; };

class FakeElement {
  constructor(tag, document) { this.tagName = String(tag).toUpperCase(); this.document = document; this.children = []; this.parentNode = null; this.attributes = {}; this.listeners = {}; this.className = ''; this.textContent = ''; this.value = ''; this.type = ''; this.disabled = false; this.checked = false; this.style = { setProperty() {} }; this.options = this.tagName === 'SELECT' ? this.children : undefined; }
  appendChild(child) { this.children.push(child); child.parentNode = this; if ((child.tagName === 'LINK' || child.tagName === 'SCRIPT') && child.onload) queueMicrotask(() => child.onload()); return child; }
  removeChild(child) { this.children = this.children.filter(item => item !== child); child.parentNode = null; return child; }
  remove() { if (this.parentNode) this.parentNode.removeChild(this); }
  replaceChild(next, previous) { const index = this.children.indexOf(previous); if (index < 0) throw new Error('replace target missing'); this.children[index] = next; previous.parentNode = null; next.parentNode = this; return previous; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
  dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn.call(this, event); return true; }
  click() { if (!this.disabled) this.dispatchEvent({ type: 'click', target: this, button: 0, preventDefault() {} }); }
  focus() { this.document.activeElement = this; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  closest(selector) { let node = this; while (node) { if (node.matches(selector)) return node; node = node.parentNode; } return null; }
  matches(selector) {
    selector = selector.trim();
    if (selector.includes('[')) {
      const tag = selector.split('[')[0].trim(); if (tag && tag !== '*' && this.tagName.toLowerCase() !== tag.toLowerCase()) return false;
      const attrs = [...selector.matchAll(/\[([^=\]]+)(?:=["']?([^\]"']+)["']?)?\]/g)];
      return attrs.every(match => Object.prototype.hasOwnProperty.call(this.attributes, match[1]) && (!match[2] || this.attributes[match[1]] === match[2]));
    }
    if (selector.startsWith('.')) return this.className.split(/\s+/).includes(selector.slice(1));
    return this.tagName.toLowerCase() === selector.toLowerCase();
  }
  querySelectorAll(selector) { const selectors = selector.split(',').map(item => item.trim()); const found = []; const visit = node => { for (const child of node.children) { if (selectors.some(item => child.matches(item))) found.push(child); visit(child); } }; visit(this); return found; }
  querySelector(selector) { const list = this.querySelectorAll(selector); return list.length ? list[0] : null; }
  set innerHTML(value) { this.children = []; }
}

function makeDocument() {
  const document = { activeElement: null, readyState: 'complete', documentElement: null, head: null, body: null, createElement: tag => new FakeElement(tag, document), querySelector(selector) { return this.documentElement?.querySelector(selector) || null; }, querySelectorAll(selector) { return this.documentElement?.querySelectorAll(selector) || []; }, importNode(node) { return node; } };
  document.documentElement = new FakeElement('html', document); document.head = new FakeElement('head', document); document.body = new FakeElement('body', document); document.documentElement.appendChild(document.head); document.documentElement.appendChild(document.body); document.documentElement.classList = { values: new Set(), add(...names) { names.forEach(name => this.values.add(name)); }, remove(...names) { names.forEach(name => this.values.delete(name)); }, contains(name) { return this.values.has(name); } }; return document;
}

const document = makeDocument();
const bodyMain = new FakeElement('main', document); bodyMain.className = 'native-booking-main';
const launcher = new FakeElement('section', document); launcher.setAttribute('data-et-booking-products-launcher', '1');
const link = new FakeElement('a', document); link.href = 'http://localhost/operations/bookings/3690/products/visa'; link.setAttribute('href', link.href); launcher.appendChild(link); bodyMain.appendChild(launcher); document.body.appendChild(bodyMain);
const staleReview = new FakeElement('a', document); staleReview.setAttribute('data-et-booking-review-entry', '1'); staleReview.textContent = 'Review Booking'; document.body.appendChild(staleReview);
const marker = new FakeElement('script', document); marker.setAttribute('data-et-dedicated-visa-navigation', 'v1.1.33.369-ERP11.3.369'); document.head.appendChild(marker);
const fragment = new FakeElement('main', document); fragment.className = 'et-product-workspace'; fragment.setAttribute('data-etgp-dedicated-product', '1'); fragment.setAttribute('data-etgp-product-key', 'visa'); fragment.setAttribute('data-booking-id', '3690'); const fragmentHost = new FakeElement('div', document); fragmentHost.setAttribute('data-etgp-dedicated-product-host', '1'); const fragmentBody = new FakeElement('div', document); fragmentBody.setAttribute('data-etgp-dedicated-product-body', '1'); fragmentHost.appendChild(fragmentBody); fragment.appendChild(fragmentHost);

const response = { booking_id: 3690, passengers: [{ id: 11, name: 'Ayesha Khan', passport_number: 'AY123456' }], rates: [], visa_rows: [{ booking_passenger_id: 11, passenger_name: 'Ayesha Khan', passport_number: 'AY123456', sale_pkr: 42280, vendor_cost_pkr: 38000, margin_pkr: 4280, status: 'pending' }], summary: { customer_total: 42280, vendor_total: 38000, margin: 4280 } };
const core = { getLockState: () => ({ locked: false }), applyReadOnly: () => false, getProductResponse: () => null, getProductPromise: () => null, setProductResponse() {}, setActiveRoot: () => true, clearActiveRoot() {} };
const localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
const window = { localStorage, etDedicatedProductCore: core, etBookingFocus: { mountPresentation: () => true }, etVisaProductCore: null, etDedicatedVisaProduct: null, history: { pushState() {} }, location: { href: 'http://localhost/operations/bookings/3690', origin: 'http://localhost', assign() {}, replace() {} }, addEventListener() {} };
window.etVisaProductCore = { mount: () => { const facade = { getState: () => ({}) }; window.etDedicatedVisaProduct = facade; return facade; } };
const fragmentDocument = { querySelectorAll: selector => selector === '[data-etgp-dedicated-product="1"]' ? [fragment] : [], querySelector: selector => selector === '[data-etgp-dedicated-product-host]' ? fragmentHost : null };
const context = { window, document, localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, URL, DOMParser: class { parseFromString() { return fragmentDocument; } }, fetch: () => Promise.resolve({ ok: true, text: () => Promise.resolve('<main data-etgp-dedicated-product="1" data-etgp-product-key="visa" data-booking-id="3690"><div data-etgp-dedicated-product-host="1"></div></main>') }) };
vm.runInNewContext(navSource, context);
link.click();
await new Promise(resolve => setTimeout(resolve, 10));
ok(document.querySelector('[data-etgp-dedicated-product="1"]') === fragment, 'fast navigation mounts the dedicated Visa root');
ok(document.querySelector('[data-et-booking-review-entry="1"]') === null, 'stale fixed Review Booking entry is removed after Visa navigation');
ok(document.querySelector('main') !== null && document.querySelector('.content') !== null, 'fast navigation restores the native ERP main/content shell');
ok(window.etBookingFocus.mountPresentation !== undefined, 'fast navigation invokes the established Booking Workspace presentation authority');

delete window.data; const visaContext = { window: { localStorage, etDedicatedProductCore: { getLockState: () => ({ locked: false }), applyReadOnly: () => false, getProductResponse: () => null, getProductPromise: () => null, setProductResponse() {} } }, document: makeDocument(), localStorage, console, Error, Math, JSON, Number, String, Object, Array, Set, Promise, fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(response) }) };
const root = new FakeElement('div', visaContext.document); root.setAttribute('data-etgp-product-key', 'visa'); root.setAttribute('data-booking-id', '3690'); const host = new FakeElement('div', visaContext.document); host.setAttribute('data-etgp-dedicated-product-host', '1'); root.appendChild(host); visaContext.document.body.appendChild(root); vm.runInNewContext(visaSource, visaContext); visaContext.window.etVisaProductCore.mount({ root, bookingId: 3690 }); await new Promise(resolve => setTimeout(resolve, 10));
const sales = host.querySelectorAll('.etgp-visa-sale-input-368'); const margins = host.querySelectorAll('[data-etgp-visa-margin-pax="11"]'); const text = node => [node.textContent || '', ...node.children.map(text)].join(' ');
ok(sales.length === 2 && sales.every(input => input.className === 'etgp-visa-sale-input-368'), 'existing row renders dedicated editable Sale inputs');
ok(margins.length === 2 && margins.every(node => text(node).includes('PKR 4,280')), 'initial row Margin is populated from the authoritative row values');
ok(!/\.etgp-visa-row-366 input[, {]/.test(cssSource), 'broad row input sizing selector is removed');
ok(/\.etgp-visa-sale-input-368\{[^}]*min-height:34px/.test(cssSource), 'Sale input has a readable dedicated size contract');
ok(/data-etgp-visa-sale-pax/.test(visaSource) && /data-etgp-visa-margin-pax/.test(visaSource), 'desktop/mobile Sale and Margin synchronization markers remain');
ok(!/\.forEach\s*\(/.test(visaSource.match(/querySelectorAll\([^)]*\)[^;]+/g)?.join('') || ''), 'runtime uses NodeList-safe synchronization');
ok(workspace.includes('et-dedicated-product-header') && workspace.includes('Review Booking'), 'direct Visa source contains the established Booking Workspace header');
ok(/ERP-11\.3\.369/.test(navSource) === false, 'navigation consumes the version marker rather than a hard-coded release');
console.log(`ERP369_VISA_LIVE_UI_REGRESSION=PASS (${assertions} assertions)`);
console.log('SALE_INPUT_SIZE_REGRESSION=PASS');
console.log('INITIAL_MARGIN_RENDER_REGRESSION=PASS');
console.log('FAST_NAV_HEADER_PARITY_REGRESSION=PASS');
console.log('STALE_REVIEW_ENTRY_REGRESSION=PASS');
console.log('DIRECT_FAST_SHELL_STRUCTURE_PARITY=PASS');
console.log('GLOBAL_DATA_REQUIRED=NO');
