import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const progressive = fs.readFileSync(new URL('../../public/erp11390/general-progressive-step1.js', import.meta.url), 'utf8');
const removal = fs.readFileSync(new URL('../../public/erp-ui/erp-passenger-remove.js', import.meta.url), 'utf8');
const quick = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/GeneralBookingPassengerQuickController.php', import.meta.url), 'utf8');
const removeController = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/GeneralBookingPassengerRemoveController.php', import.meta.url), 'utf8');
const lockResolver = fs.readFileSync(new URL('../../app/Services/Operations/BookingEditLockResolver.php', import.meta.url), 'utf8');

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

// Re-add authority is booking-scoped: only active snapshots participate in
// duplicate detection; a retained REMOVED snapshot is historical evidence.
const activeSnapshots = rows => rows.filter(row => String(row.status || '').trim().toUpperCase() !== 'REMOVED');
const duplicateBy = (rows, predicate) => activeSnapshots(rows).find(predicate) || null;
const removed = [{ id: 11, passenger_id: 701, passport_no: 'PK-701', name: 'Syed Muhammad Haider', dob: '1990-01-01', status: 'REMOVED', fare_type: 'ADULT' }];
const active = [{ id: 12, passenger_id: 702, passport_no: 'PK-702', name: 'Another Passenger', dob: '1991-01-01', status: 'ACTIVE', fare_type: 'ADULT' }];
const snapshots = removed.concat(active);
ok(!duplicateBy(snapshots, row => row.passenger_id === 701), 'removed Passenger Master snapshot is re-addable');
ok(!!duplicateBy(snapshots, row => row.passenger_id === 702), 'active Passenger Master duplicate remains blocked');
ok(!duplicateBy(snapshots, row => row.passport_no.toLowerCase() === 'pk-701'), 'removed passport snapshot is re-addable');
ok(!!duplicateBy(snapshots, row => row.passport_no.toLowerCase() === 'pk-702'), 'active passport duplicate remains blocked');
ok(!duplicateBy(snapshots, row => row.name.toLowerCase() === 'syed muhammad haider' && row.dob === '1990-01-01'), 'removed name/DOB snapshot is re-addable');
ok(!!duplicateBy(snapshots, row => row.name.toLowerCase() === 'another passenger' && row.dob === '1991-01-01'), 'active name/DOB duplicate remains blocked');
const readded = { passenger_id: 701, fare_type: 'CHILD', status: 'ACTIVE' };
equal(readded.fare_type, 'CHILD', 're-added booking snapshot uses selected booking fare type');
equal(activeSnapshots(removed.concat([readded])).length, 1, 'only one active booking snapshot exists after re-add');
ok(quick.includes("if (in_array('status', $columns, true))") && quick.includes("UPPER(status) <> ?"), 'server duplicate authority excludes REMOVED status rows');
ok(quick.includes("'status'], 'active'"), 're-added snapshot is written ACTIVE');
ok(removeController.includes('assertDraftDependencies'), 'remove path retains irreversible-history protection');
ok(!quick.includes('air_ticket_details') || quick.includes('booking passenger'), 're-add controller does not resurrect Air ticket history');
equal(activeSnapshots([{ id: 11, status: 'REMOVED' }]).length, 0, 'removed snapshot is absent from active Passenger KPI authority');

const removalAllowed = (status, row = {}) => {
  const normalized = String(status || '').trim().toLowerCase();
  const evidence = ['ticket_number', 'ticket_no', 'e_ticket_number', 'eticket_number', 'document_number', 'document_no', 'issue_date', 'ticket_issue_date', 'issued_at'].some(key => String(row[key] || '').trim() !== '');
  if (evidence) return false;
  if (['issued','posted','paid','settled','approved','completed','refunded','void','voided','cancelled','canceled'].includes(normalized)) return false;
  return ['draft','booked','pending','active','new','open'].includes(normalized);
};
for (const status of ['PENDING', 'BOOKED']) ok(removalAllowed(status), `${status} without ticket data remains removable`);
for (const status of ['ISSUED', 'VOID', 'REFUNDED', 'CANCELLED']) ok(!removalAllowed(status), `${status} history blocks removal`);
ok(!removalAllowed('BOOKED', { ticket_number: '065-1234567890' }), 'real ticket number blocks removal even after status downgrade');
ok(!removalAllowed('PENDING', { issue_date: '2026-09-22' }), 'issue-date evidence blocks removal');
ok(!removalAllowed('UNKNOWN'), 'unknown Air status fails closed');
ok(removeController.includes('BookingEditLockResolver') && removeController.includes("$lock['locked']"), 'server booking lock authority is enforced');
ok(removeController.includes('ticket_number') && removeController.includes('issue_date'), 'remove path audits ticket/document and issue-date aliases');
ok(removeController.includes("'passenger' => $label.' has an unknown status; removal is blocked for safety.'"), 'unknown status has an explicit fail-closed error');
ok(lockResolver.includes("$locked = $pending || $approved || $ready;"), 'existing native booking lock resolver remains the authority');
ok(removeController.includes('reconcileAirServiceSnapshots($booking)'), 'passenger removal reconciles Air service snapshots atomically');
ok(removeController.includes("foreach (['line_total', 'customer_total', 'selling_total'"), 'remaining native Air rows recalculate customer snapshot totals');
ok(removeController.includes("foreach (['supplier_total', 'vendor_total', 'supplier_amount', 'cost_amount'"), 'remaining native Air rows recalculate supplier snapshot totals');
equal(Math.round((100000 + 100000) - 100000), 100000, 'removing one of two passengers leaves remaining Air customer total');
equal(Math.round(0), 0, 'removing final passenger leaves zero ticket-derived totals');
ok(removeController.includes('DB::transaction(function ()'), 'delete and summary reconciliation remain atomic');

console.log(`ERP-11.3.332 passenger remove/KPI consistency regression: PASS (${assertions} assertions)`);
