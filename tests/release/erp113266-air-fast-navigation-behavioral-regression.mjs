import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp-theme/js/dedicated-product-navigation.js', import.meta.url), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));

class Node {
  constructor(tag = 'div') { this.tagName = tag.toUpperCase(); this.children = []; this.parentNode = null; this.attrs = {}; this.dataset = new Proxy({}, { set: (target, key, value) => { target[key] = String(value); this.attrs['data-' + String(key).replace(/[A-Z]/g, c => '-' + c.toLowerCase())] = String(value); return true; } }); this.listeners = {}; this.href = ''; this.target = ''; this.rel = ''; this.src = ''; this._onload = null; Object.defineProperty(this, 'onload', { get: () => this._onload, set: fn => { const prior = this._onload; this._onload = () => { if (prior) prior(); if (fn) fn(); }; } }); }
  appendChild(node) { node.parentNode = this; this.children.push(node); if (node._onload) node._onload(); return node; }
  remove() { if (this.parentNode) { this.parentNode.children = this.parentNode.children.filter(child => child !== this); this.parentNode = null; } }
  set innerHTML(value) { this.children.forEach(child => { child.parentNode = null; }); this.children = []; }
  get innerHTML() { return ''; }
  setAttribute(key, value) { this.attrs[key] = String(value); if (key === 'href') this.href = String(value); if (key === 'src') this.src = String(value); if (key === 'target') this.target = String(value); if (key === 'rel') this.rel = String(value); if (key.startsWith('data-')) this.dataset[key.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = String(value); }
  removeAttribute(key) { delete this.attrs[key]; }
  getAttribute(key) { return this.attrs[key] ?? null; }
  hasAttribute(key) { return this.getAttribute(key) !== null; }
  addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); }
  dispatchEvent(event) { for (const fn of this.listeners[event.type] || []) fn.call(this, event); }
  closest(selector) { let node = this; while (node) { if (node.matches(selector)) return node; node = node.parentNode; } return null; }
  matches(selector) { const attr = selector.match(/^([\w-]+)?\[([^=\]]+)(?:="?([^\]"]+)"?)?\]$/); if (attr) return (!attr[1] || this.tagName === attr[1].toUpperCase()) && this.getAttribute(attr[2]) !== null && (attr[3] === undefined || this.getAttribute(attr[2]) === attr[3]); return this.tagName === selector.toUpperCase(); }
  querySelectorAll(selector) { const result = []; const walk = node => { for (const child of node.children) { if (child.matches(selector)) result.push(child); walk(child); } }; walk(this); return result; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}

function fragment(mode, bookingId) {
  if (mode === 'invalid') return { querySelectorAll: () => [], querySelector: () => null };
  const roots = [];
  const root = id => { const r = new Node('main'); r.setAttribute('data-etgp-dedicated-product', '1'); r.setAttribute('data-etgp-product-key', 'air'); r.setAttribute('data-booking-id', id); const host = new Node(); host.setAttribute('data-etgp-dedicated-product-host', '1'); const body = new Node(); body.setAttribute('data-etgp-dedicated-product-body', '1'); host.appendChild(body); r.appendChild(host); return r; };
  roots.push(root(mode === 'mismatch' ? '999' : bookingId)); if (mode === 'multiple') roots.push(root(bookingId));
  return { querySelectorAll: s => s === '[data-etgp-dedicated-product="1"]' ? roots : [], querySelector: s => s === '[data-etgp-dedicated-product="1"]' ? roots[0] : null };
}

function harness(options = {}) {
  const booking = String(options.booking || '31'); const body = new Node('body'); const head = new Node('head'); const main = new Node('main'); const launcher = new Node('section'); launcher.setAttribute('data-et-booking-products-launcher', '1'); const links = {};
  for (const [name, path] of [['air', 'air'], ['hotel', 'hotel'], ['transport', 'transport'], ['visa', 'visa'], ['other', 'other-services']]) { const a = new Node('a'); a.setAttribute('href', `/operations/bookings/${booking}/products/${path}`); links[name] = a; launcher.appendChild(a); }
  const review = new Node('a'); review.setAttribute('data-et-booking-review-entry', '1'); body.appendChild(review); const sidebar = new Node('aside'); sidebar.setAttribute('data-sidebar-sentinel', '1'); body.appendChild(sidebar); const outer = new Node(); outer.setAttribute('data-outer-shell-sentinel', '1'); body.appendChild(outer); main.appendChild(launcher); body.appendChild(main); const doc = new Node('#document'); doc.head = head; doc.body = body; doc.appendChild(head); doc.appendChild(body);
  const fetches = [], assets = [], pushed = [], assigned = [], replaced = [], popstates = []; let mounts = 0; let state = { saveInFlight: false, dirty: false, draftPending: false }; const mountResult = options.mountResult ?? true;
  const api = options.preloaded ? { getState: () => state, mount: root => { mounts++; replaced.push(root); return mountResult; } } : undefined;
  const location = { origin: 'https://erp.test', href: `https://erp.test/operations/bookings/${booking}`, assign: u => assigned.push(u) }; const window = { location, etDedicatedAirProduct: api, etDedicatedProductCore: options.preloaded ? {} : undefined, history: { state: null, pushState: (_, __, u) => pushed.push(u), replaceState: (_, __, u) => pushed.push(`replace:${u}`) }, confirm: () => true, addEventListener: (t, fn) => { if (t === 'popstate') popstates.push(fn); } };
  const document = { ...doc, head, querySelector: s => { if (s === '[data-et-booking-products-launcher="1"]') return launcher; if (s === 'script[data-et-dedicated-product-navigation]') { const n = new Node('script'); n.setAttribute('data-et-dedicated-product-navigation', options.version || 'v1.1.33.TEST'); return n; } return doc.querySelector(s); }, querySelectorAll: s => doc.querySelectorAll(s), createElement: type => { const n = new Node(type); if (type === 'link' || type === 'script') { n.onload = () => { assets.push({ type, href: n.href, src: n.src }); if (type === 'script' && n.getAttribute('data-et-fast-nav-asset') === 'dedicated-product-core') window.etDedicatedProductCore = {}; if (type === 'script' && n.getAttribute('data-et-fast-nav-asset') === 'products-air') window.etDedicatedAirProduct = { getState: () => state, mount: root => { mounts++; replaced.push(root); return mountResult; } }; }; } return n; }, importNode: n => n };
  window.document = document; const mode = options.mode || 'valid'; const ctx = vm.createContext({ window, document, DOMParser: class { parseFromString() { return fragment(mode, booking); } }, URL, AbortController, fetch: (url, opts) => { fetches.push({ url, opts }); return Promise.resolve({ ok: options.fetchOk !== false, text: async () => '<fragment />' }); }, Promise, Array, String, Number, Error, encodeURIComponent, setTimeout, clearTimeout });
  return { ctx, page: { body, head, main, launcher, review, sidebar, outer }, links, fetches, assets, pushed, assigned, replaced, popstates, mounts: () => mounts, run: () => vm.runInContext(source, ctx), setState: v => { state = v; } };
}

async function click(h, link, extra = {}) { const e = { type: 'click', button: 0, defaultPrevented: false, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, prevented: false, preventDefault() { this.prevented = true; }, ...extra }; link.dispatchEvent(e); await tick(); await tick(); return e; }
async function navigate(h) { h.run(); await click(h, h.links.air); }
let n = 0; const eq = (a, b) => { assert.deepEqual(a, b); n++; }; const yes = value => { assert.ok(value); n++; };

let h = harness(); await navigate(h); eq(h.fetches[0].url, '/operations/bookings/31/products/air/fragment'); eq(h.fetches[0].opts.credentials, 'same-origin'); eq(h.fetches[0].opts.headers.Accept, 'text/html'); eq(h.fetches[0].opts.headers['X-Requested-With'], 'XMLHttpRequest'); eq(h.pushed[0], '/operations/bookings/31/products/air'); eq(h.replaced.length, 1); yes(h.page.sidebar.parentNode === h.page.body); yes(h.page.outer.parentNode === h.page.body); eq(h.page.review.parentNode, null); yes(h.page.main.querySelector('[data-etgp-dedicated-product="1"]'));
for (const event of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) { h = harness(); h.run(); const e = await click(h, h.links.air, event); eq(e.prevented, false); eq(h.fetches.length, 0); eq(h.assigned.length, 0); }
for (const name of ['hotel', 'transport', 'visa', 'other']) { h = harness(); h.run(); const e = await click(h, h.links[name]); eq(e.prevented, false); eq(h.fetches.length, 0); eq(h.assigned.length, 0); }
for (const mode of ['invalid', 'multiple', 'mismatch']) { h = harness({ mode }); h.run(); await click(h, h.links.air); eq(h.pushed.length, 0); eq(h.assigned[0], '/operations/bookings/31/products/air'); yes(h.page.launcher.parentNode === h.page.main); eq(h.mounts(), 0); }
h = harness({ mountResult: false }); await navigate(h); eq(h.pushed.length, 0); eq(h.assigned[0], '/operations/bookings/31/products/air');
h = harness({ version: 'v1.1.33.TEST' }); await navigate(h); eq(h.assets.map(a => a.type), ['link', 'script', 'script']); for (const a of h.assets) { const url = a.href || a.src; yes(url.includes('v=v1.1.33.TEST')); yes(!url.includes('general-progressive-step1')); } eq(h.assets[1].src, '/system/erp-assets/dedicated-product-core.js?v=v1.1.33.TEST'); eq(h.assets[2].src, '/system/erp-assets/products-air.js?v=v1.1.33.TEST');
h = harness({ preloaded: true }); await navigate(h); yes(h.assets.length <= 1); eq(h.mounts(), 1);
h = harness(); await navigate(h); h.setState({ dirty: true, draftPending: true, saveInFlight: false }); h.ctx.window.location.href = 'https://erp.test/operations/bookings/31/products/hotel'; h.ctx.window.confirm = () => false; h.popstates[0](); eq(h.pushed.at(-1), 'replace:/operations/bookings/31/products/air'); eq(h.assigned.length, 0); yes(h.page.main.querySelector('[data-etgp-dedicated-product="1"]'));
h = harness(); await navigate(h); h.setState({ dirty: false, draftPending: false, saveInFlight: true }); h.ctx.window.location.href = 'https://erp.test/operations/bookings/31/products/hotel'; h.popstates[0](); eq(h.pushed.at(-1), 'replace:/operations/bookings/31/products/air');
h = harness(); await navigate(h); h.setState({ dirty: true, draftPending: true, saveInFlight: false }); h.ctx.window.confirm = () => true; h.ctx.window.location.href = 'https://erp.test/operations/bookings/31/products/hotel'; h.popstates[0](); eq(h.assigned[0], 'https://erp.test/operations/bookings/31/products/hotel');
h = harness(); await navigate(h); h.setState({ dirty: false, draftPending: false, saveInFlight: false }); h.ctx.window.location.href = 'https://erp.test/operations/bookings/31/products/hotel'; h.popstates[0](); eq(h.assigned[0], 'https://erp.test/operations/bookings/31/products/hotel');
console.log(`PASS ${n} Air fast-navigation behavioral assertions`);
