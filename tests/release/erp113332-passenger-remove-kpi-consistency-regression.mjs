import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const progressive = fs.readFileSync(new URL('../../public/erp11390/general-progressive-step1.js', import.meta.url), 'utf8');
const removal = fs.readFileSync(new URL('../../public/erp-ui/erp-passenger-remove.js', import.meta.url), 'utf8');

let assertions = 0;
const ok = (value, message) => { assert.equal(Boolean(value), true, message); assertions += 1; };
const equal = (actual, expected, message) => { assert.equal(actual, expected, message); assertions += 1; };

class Row {
  constructor(text, status = '') { this.textContent = text; this.status = status; }
  getAttribute(name) { return name === 'data-status' ? this.status : null; }
  querySelectorAll() { return []; }
}

class Body {
  constructor(rows) { this.rows = rows; }
  querySelectorAll(selector) { return selector === 'tr' ? this.rows : []; }
}

class Panel {
  constructor(rows, table = true) { this.body = new Body(rows); this.table = table; }
  querySelector(selector) {
    if (selector === '.passenger-table tbody' || selector === '.etgp-current-passenger-table tbody') return this.table ? this.body : null;
    return null;
  }
}

const countStart = progressive.indexOf('var etgpPassengerRowIsRemoved113290=');
const helperStart = progressive.indexOf('/* ERP-11.3.332');
const countEnd = progressive.indexOf('var storageKey=', countStart);
const countSource = progressive.slice(countStart, helperStart);
const helperSource = progressive.slice(helperStart, countEnd);
const countContext = { norm: value => String(value ?? '').trim().toLowerCase(), window: {}, etgpAirSetKpi113124: (...args) => { countContext.kpi = args; } };
vm.runInNewContext(`${countSource}\n${helperSource}\nthis.passengerCount = passengerCount;`, countContext);

equal(countContext.passengerCount(new Panel([new Row('Adult A')], true)), 1, 'one active passenger is counted');
equal(countContext.passengerCount(new Panel([], true)), 0, 'authoritative table with zero rows reports zero');
equal(countContext.passengerCount(new Panel([new Row('Adult A', 'REMOVED')], true)), 0, 'removed booking passenger is excluded');
equal(countContext.passengerCount(new Panel([new Row('No passenger')], true)), 0, 'empty passenger placeholder is excluded');
equal(countContext.passengerCount(new Panel([new Row('stale label only')], false)), 0, 'missing table does not invent a count from unrelated text');

const mixPanel = new Panel([new Row('Adult A'), new Row('Adult B'), new Row('Child C')], true);
countContext.etgpApplyPassengerTableKpi113332(mixPanel);
equal(countContext.kpi[0], 'Passengers', 'passenger table KPI writer targets Passengers');
equal(countContext.kpi[1], '3', 'three active passengers reach the KPI');
equal(countContext.kpi[2], 'Adult 2 · Child 1 · Infant 0', 'Adult/Child/Infant mix follows active rows');
countContext.etgpApplyPassengerTableKpi113332(new Panel([], true));
equal(countContext.kpi[1], '0', 'zero-row table overwrites stale KPI with zero');
equal(countContext.kpi[2], 'Adult 0 · Child 0 · Infant 0', 'zero-row table clears the fare-mix note');

const removalContext = { window: {}, document: { body: null }, console };
vm.runInNewContext(removal, removalContext);
const remove = removalContext.window.ETBookingPassengerRemoval.removeBookingPassenger;
const event = { prevented: 0, stopped: 0, immediate: 0, preventDefault() { this.prevented += 1; }, stopPropagation() { this.stopped += 1; }, stopImmediatePropagation() { this.immediate += 1; } };
const button = { disabled: false, textContent: 'Remove' };
let refreshed = 0;
let reloaded = 0;
const success = await remove({
  event,
  button,
  confirmRemoval: () => true,
  isLocked: () => false,
  feedback: () => { throw new Error('unexpected success feedback'); },
  refresh: async () => { refreshed += 1; },
  reload: () => { reloaded += 1; },
  request: async () => ({ ok: true, json: async () => ({ ok: true }) })
});
ok(success.ok && refreshed === 1 && reloaded === 0, 'successful DELETE reconciles through same-page refresh without reload');
equal(event.prevented, 1, 'remove event is prevented');
ok(button.disabled, 'remove button remains disabled after successful request');

let failureFeedback = '';
const failed = await remove({
  event: { preventDefault() {}, stopPropagation() {}, stopImmediatePropagation() {} },
  button: { disabled: false, textContent: 'Remove' },
  confirmRemoval: () => true,
  isLocked: () => false,
  feedback: message => { failureFeedback = message; },
  refresh: async () => { throw new Error('must not refresh after failed DELETE'); },
  reload: () => { throw new Error('must not reload after failed DELETE'); },
  request: async () => ({ ok: false, json: async () => ({ message: 'delete failed' }) })
});
ok(!failed.ok && failureFeedback === 'delete failed', 'failed DELETE preserves UI and reports server error');

ok(removal.includes("cache: 'no-store'"), 'passenger removal refresh disables cache');
ok(removal.includes("window.etGeneralProgressiveStep1Sync11390(freshDoc, ['metrics', 'passengers'])"), 'passenger removal uses authoritative metrics and passenger sync');
ok(progressive.includes('return rows.length;') && !progressive.includes('if(rows.length>0){\n      return rows.length;\n    }'), 'authoritative passenger table zero state cannot fall back to stale label');
ok(progressive.includes('etgpApplyPassengerTableKpi113332'), 'same-page sync reapplies passenger table KPI authority');

console.log(`ERP-11.3.332 passenger remove/KPI consistency regression: PASS (${assertions} assertions)`);
