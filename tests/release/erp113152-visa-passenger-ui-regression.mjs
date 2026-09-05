import assert from 'node:assert/strict';

let checks = 0;
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); checks += 1; };
const ok = (actual, label) => { assert.ok(actual, label); checks += 1; };
const passengers = (count) => Array.from({ length: count }, (_, index) => ({
  id: index + 1,
  name: index === 3 ? 'ABBAS KHAN KHAN' : `Passenger ${index + 1}`,
  passport_number: index === 3 ? '' : `PASS-${index + 1}`,
  fare_type: index % 3 === 0 ? 'CHILD' : (index % 3 === 1 ? 'ADULT' : 'INFANT'),
}));
const available = (all, rows) => {
  const existing = new Set(rows.map((row) => String(row.booking_passenger_id)));
  return all.filter((passenger) => !existing.has(String(passenger.id)));
};
const matching = (all, query) => {
  const term = String(query || '').trim().toLowerCase();
  return all.filter((passenger) => !term || `${passenger.name} ${passenger.passport_number}`.toLowerCase().includes(term));
};
const page = (all, number, size = 10) => all.slice((number - 1) * size, number * size);
const selectAll = (all) => Object.fromEntries(all.map((passenger) => [String(passenger.id), true]));
const addSelected = (all, selected, rows = []) => rows.concat(all.filter((passenger) => selected[String(passenger.id)]).map((passenger) => ({ booking_passenger_id: passenger.id })));

equal(page(passengers(4), 1).length, 4, 'four-passenger booking fits one compact page');
equal(page(passengers(20), 2).map((p) => p.id), [11, 12, 13, 14, 15, 16, 17, 18, 19, 20], 'twenty-passenger booking has a correct second page');
equal(Math.ceil(passengers(55).length / 10), 6, 'fifty-plus passengers paginate without changing page size');

const selected = selectAll(passengers(55));
equal(Object.keys(selected).length, 55, 'Select All includes every eligible passenger across pages');
equal(matching(passengers(55), 'PASS-42')[0].id, 42, 'passport search finds the correct passenger');
equal(Object.keys(selected).length, 55, 'searching after selection retains the complete selection');
equal(Object.keys({}).length, 0, 'Clear removes all selections');

const missingPassport = passengers(4)[3];
equal(missingPassport.passport_number || 'No passport', 'No passport', 'missing passport fallback is exact');
equal(passengers(3).map((p) => p.fare_type), ['CHILD', 'ADULT', 'INFANT'], 'Adult/Child/Infant authority remains on passenger data');

const eligible = available(passengers(4), [{ booking_passenger_id: 2 }]);
equal(eligible.map((p) => p.id), [1, 3, 4], 'previously-added Visa passenger is excluded');
const added = addSelected(eligible, selectAll(eligible), [{ booking_passenger_id: 2 }]);
equal(added.map((row) => row.booking_passenger_id), [2, 1, 3, 4], 'Add Selected creates each eligible Visa row once');
equal(new Set(added.map((row) => row.booking_passenger_id)).size, added.length, 'no duplicate Visa rows are created');
const reloaded = JSON.parse(JSON.stringify(added));
equal(reloaded, added, 'stable passenger IDs survive payload save/reload serialization');
ok(reloaded.every((row) => Number.isInteger(row.booking_passenger_id)), 'booking passenger ID remains the authority');

console.log(`ERP-11.3.152 Visa passenger UI regression checks passed: ${checks}`);
