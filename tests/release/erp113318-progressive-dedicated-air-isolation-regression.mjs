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
  const observers = [];
  const kpiValue = { textContent: '0' };
  const kpiNote = { textContent: '' };
  const kpiLabel = { textContent: 'Tickets' };
  const kpiCard = { querySelector: selector => selector === '.etgp-kpi-label' ? kpiLabel : selector === '.etgp-kpi-value' ? kpiValue : selector === '.etgp-kpi-note' ? kpiNote : null };
  const searchButton = { clicks: 0, textContent: 'Search', value: '', click() { this.clicks += 1; } };
  const reuseForm = { action: '/operations/bookings/31/passengers/from-profile', method: 'POST', getAttribute: name => name === 'action' ? '/operations/bookings/31/passengers/from-profile' : null, querySelector: () => null, querySelectorAll: selector => selector === 'button,input[type="submit"]' ? [searchButton] : [] };
  const passengerCard = { dataset: {}, listeners: {}, querySelector: selector => selector === 'form' ? reuseForm : null, querySelectorAll: selector => selector === 'form' ? [reuseForm] : [], addEventListener(type, fn) { (this.listeners[type] ??= []).push(fn); } };
  const executableSource = source.replace(
    'var etgpRunNativeBuild11390=',
    'window.__etgpTestHooks={ticketGuard:etgpTicketKpiGuard113126,bindFare:etgpBindPassengerFareAirSync113137,reuse:requestReuseAutoLoad,forceRerender:etgpForceAirProductRerender113137,persistFare:etgpPersistPassengerFare113137};\nvar etgpRunNativeBuild11390='
  );
  const instrumentedSource = executableSource.replace(
    'var reconcileQuickPassenger113105=function(data,attempt){',
    'var reconcileQuickPassenger113105=window.__etgpTestReconcile113105=function(data,attempt){'
  );
  const document = {
    documentElement: html,
    readyState: 'loading',
    addEventListener(type, fn) { (listeners[type] ??= []).push(fn); },
    querySelector(selector) {
      if (selector === '[data-etgp-dedicated-product="1"][data-etgp-product-key="air"]') {
        return airRoot.present ? airRoot : null;
      }
      if (selector === '[data-etgp-kpis]') return { querySelectorAll: () => [kpiCard] };
      if (selector === '.etgp-passenger-card') return passengerCard;
      if (selector === '.etgp-current-passenger-table') return null;
      return null;
    },
    querySelectorAll(selector) { return selector === '.etgp-kpi' ? [kpiCard] : []; },
    createElement() { return { classList: new ClassList(), style: {}, setAttribute() {}, appendChild() {}, querySelector() { return null; }, querySelectorAll() { return []; } }; },
  };
  const window = {
    location: { pathname: '/operations/bookings/31/products/air' },
    setTimeout(fn, ms) { timers.push({ fn, ms }); return timers.length; },
    clearTimeout() {},
  };
  const context = vm.createContext({
    window, document, MutationObserver: class { constructor(callback) { this.callback = callback; observers.push(this); } observe() {} disconnect() {} },
    Promise, Array, String, Number, Boolean, Object, Error, Date, Math,
    URL, setTimeout: window.setTimeout, clearTimeout: () => {}, DOMParser: class {}, fetch: (url, options) => { fetches.push({ url, options }); return Promise.resolve({ ok: true, json: async () => ({ ok: true }) }); },
    console,
  });
  vm.runInContext(instrumentedSource, context);
  return { context, html, airRoot, listeners, timers, fetches, observers, kpiValue, kpiNote, kpiCard, passengerCard, searchButton, reuseForm };
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

/* Execute the persistent callback bodies, not only their source contracts. */
const kpi = harness();
kpi.html.classList.add('et-general-progressive-step1-11390');
kpi.airRoot.present = false;
kpi.context.window.__etgpTestHooks.ticketGuard();
check(kpi.observers.length === 1, 'KPI observer installs on native Booking');
const kpiObserver = kpi.observers.at(-1);
kpi.kpiValue.textContent = 'native-change';
kpi.airRoot.present = true;
kpi.html.classList.add('et-booking-products-prepaint');
kpiObserver.callback([]);
equal(kpi.kpiValue.textContent, 'native-change', 'KPI observer performs no dedicated-Air mutation');

const fare = harness();
fare.airRoot.present = false;
fare.context.window.__etgpTestHooks.bindFare();
check(fare.observers.length === 1, 'fare observer installs on native Booking');
fare.airRoot.present = true;
fare.html.classList.add('et-booking-products-prepaint');
fare.observers.at(-1).callback([]);
for (const timer of [...fare.timers]) timer.fn();
equal(fare.fetches.length, 0, 'dedicated Air fare observer performs no network request');

const reuse = harness();
reuse.airRoot.present = false;
reuse.context.window.__etgpTestHooks.reuse(reuse.passengerCard);
reuse.airRoot.present = true;
reuse.html.classList.add('et-booking-products-prepaint');
reuse.timers.find(timer => timer.ms === 40)?.fn();
equal(reuse.searchButton.clicks, 0, 'reuse autoload is gated at execution time');

const nativeReuse = harness();
nativeReuse.airRoot.present = false;
nativeReuse.context.window.__etgpTestHooks.reuse(nativeReuse.passengerCard);
check(nativeReuse.timers.some(timer => timer.ms === 40), 'native reuse autoload remains schedulable');

const nonAir = harness();
nonAir.airRoot.present = true;
nonAir.airRoot.dataset.etgpProductKey = 'hotel';
nonAir.html.classList.add('et-booking-products-prepaint');
equal(nonAir.context.window.etGeneralProgressiveStep1Sync11390({}, []), false, 'non-Air dedicated products do not activate Air isolation');

const delayed = harness();
delayed.airRoot.present = true;
delayed.html.classList.add('et-booking-products-prepaint');
delayed.context.window.__etgpTestHooks.forceRerender();
equal(delayed.fetches.length, 0, 'delayed progressive rerender is inert for dedicated Air');

console.log(`PASS ${assertions} progressive dedicated-Air isolation assertions`);
