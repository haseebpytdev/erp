import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/erp11390/general-progressive-step1.js', import.meta.url), 'utf8');

class ClassList {
  constructor() { this.values = new Set(); }
  contains(value) { return this.values.has(value); }
  add(value) { this.values.add(value); }
  remove(value) { this.values.delete(value); }
}

function harness() {
  const html = { classList: new ClassList() };
  html.classList.add('et-general-progressive-step1-11390');
  const airRoot = { dataset: { etgpProductKey: 'air' } };
  const listeners = {};
  const timers = [];
  const fetches = [];
  const document = {
    documentElement: html,
    readyState: 'loading',
    addEventListener(type, fn) { (listeners[type] ??= []).push(fn); },
    querySelector(selector) {
      if (selector === '[data-etgp-dedicated-product="1"][data-etgp-product-key="air"]') {
        return airRoot.present ? airRoot : null;
      }
      return null;
    },
    querySelectorAll() { return []; },
    createElement() { return { classList: new ClassList(), style: {}, setAttribute() {}, appendChild() {}, querySelector() { return null; }, querySelectorAll() { return []; } }; },
  };
  const window = {
    location: { pathname: '/operations/bookings/31/products/air' },
    setTimeout(fn, ms) { timers.push({ fn, ms }); return timers.length; },
    clearTimeout() {},
  };
  const context = vm.createContext({
    window, document, MutationObserver: class { observe() {} disconnect() {} },
    Promise, Array, String, Number, Boolean, Object, Error, Date, Math,
    URL, DOMParser: class {}, fetch: (url, options) => { fetches.push({ url, options }); return Promise.resolve({ ok: true, json: async () => ({ ok: true }) }); },
    console,
  });
  vm.runInContext(source, context);
  return { context, html, airRoot, listeners, timers, fetches };
}

let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };
const equal = (actual, expected, message) => { assert.equal(actual, expected, message); assertions += 1; };

const h = harness();
h.airRoot.present = false;
equal(h.context.window.etGeneralProgressiveStep1Sync11390({}, []), false, 'native sync remains marker-gated');

h.airRoot.present = true;
h.html.classList.add('et-booking-products-prepaint');
equal(h.context.window.etGeneralProgressiveStep1Sync11390({}, []), false, 'dedicated Air sync is inert');
check(h.listeners['et:booking-product-saved']?.length === 1, 'product-saved listener remains installed');
h.listeners['et:booking-product-saved'][0]({ detail: { bookingId: 31 } });
await Promise.resolve();
equal(h.fetches.length, 0, 'dedicated Air receives no progressive GET');

h.airRoot.present = false;
const nativeSync = h.context.window.etGeneralProgressiveStep1Sync11390({}, []);
equal(nativeSync, false, 'native sync still respects the normal live marker');

h.airRoot.present = true;
const fallback = h.timers.find(timer => timer.ms === 6000);
check(fallback, 'native reveal fallback timer exists');
fallback.fn();
equal(h.html.classList.contains('etgp-step1-fallback-11390'), false, 'fallback is suppressed for dedicated Air');

const staticChecks = [
  'etgpRefreshPersistedBookingState113153',
  'etgpTicketKpiGuard113126',
  'etgpBindPassengerFareAirSync113137',
  'requestReuseAutoLoad',
  'reconcileQuickPassenger113105',
  'etGeneralProgressiveStep1Sync11390',
  'etgpDedicatedAirActive113318',
];
for (const name of staticChecks) check(source.includes(name), `guarded callback remains present: ${name}`);

console.log(`PASS ${assertions} progressive dedicated-Air isolation assertions`);
