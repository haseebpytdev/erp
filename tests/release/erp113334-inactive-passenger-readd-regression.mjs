import assert from 'node:assert/strict';
import fs from 'node:fs';

const controller = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/GeneralBookingPassengerQuickController.php', import.meta.url), 'utf8');
const resolver = fs.readFileSync(new URL('../../app/Services/Operations/ActiveBookingPassengerResolver.php', import.meta.url), 'utf8');
const air = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/GeneralBookingAirProductController.php', import.meta.url), 'utf8');
let assertions = 0;
const ok = (value, message) => { assert.equal(Boolean(value), true, message); assertions += 1; };

// Execute the same active/current authority semantics against a controlled
// booking-snapshot fixture. Historical rows must never block a re-add.
const inactiveStatuses = new Set(['inactive', 'deleted', 'removed', 'cancelled', 'canceled']);
const isActive = row => row.deleted_at == null
  && (row.is_active === undefined || Boolean(row.is_active))
  && (row.active === undefined || Boolean(row.active))
  && !inactiveStatuses.has(String(row.status ?? '').trim().toLowerCase());
const duplicateId = (rows, candidate) => {
  const current = rows.filter(isActive);
  const same = row => (candidate.master_id > 0 && row.master_id === candidate.master_id)
    || (candidate.passport && String(row.passport).toLowerCase() === String(candidate.passport).toLowerCase())
    || (candidate.name && String(row.name).toLowerCase() === String(candidate.name).toLowerCase()
      && candidate.dob && row.dob === candidate.dob);
  return current.find(same)?.id ?? null;
};

const active = { id: 1, master_id: 101, passport: 'P-ACTIVE', name: 'Active Passenger', dob: '1990-01-01', status: 'ACTIVE' };
const historicalRows = [
  { id: 2, master_id: 102, passport: 'P-REMOVED', name: 'Removed Passenger', dob: '1980-01-01', status: 'REMOVED' },
  { id: 3, master_id: 103, passport: 'P-INACTIVE', name: 'Inactive Passenger', dob: '1981-01-01', status: 'INACTIVE' },
  { id: 4, master_id: 104, passport: 'P-DELETED', name: 'Deleted Passenger', dob: '1982-01-01', status: 'DELETED' },
  { id: 5, master_id: 105, passport: 'P-CANCELLED', name: 'Cancelled Passenger', dob: '1983-01-01', status: 'CANCELLED' },
  { id: 6, master_id: 106, passport: 'P-CANCELED', name: 'Canceled Passenger', dob: '1984-01-01', status: 'CANCELED' },
  { id: 7, master_id: 107, passport: 'P-DELETED-AT', name: 'Deleted At', dob: '1985-01-01', status: 'ACTIVE', deleted_at: '2026-01-01' },
  { id: 8, master_id: 108, passport: 'P-IS-ACTIVE', name: 'Is Active False', dob: '1986-01-01', status: 'ACTIVE', is_active: 0 },
  { id: 9, master_id: 109, passport: 'P-ACTIVE-FALSE', name: 'Active False', dob: '1987-01-01', status: 'ACTIVE', active: 0 },
];
const rows = [active, ...historicalRows];

ok(duplicateId(rows, { master_id: 101 }) === 1, 'active same master blocks duplicate');
ok(duplicateId(rows, { master_id: 102 }) === null, 'REMOVED same master allows re-add');
ok(duplicateId(rows, { master_id: 103 }) === null, 'INACTIVE same master allows re-add');
ok(duplicateId(rows, { master_id: 104 }) === null, 'DELETED same master allows re-add');
ok(duplicateId(rows, { master_id: 105 }) === null, 'CANCELLED same master allows re-add');
ok(duplicateId(rows, { master_id: 106 }) === null, 'CANCELED same master allows re-add');
ok(duplicateId(rows, { master_id: 107 }) === null, 'deleted_at snapshot allows re-add');
ok(duplicateId(rows, { master_id: 108 }) === null, 'is_active false snapshot allows re-add');
ok(duplicateId(rows, { master_id: 109 }) === null, 'active false snapshot allows re-add');
ok(duplicateId(rows, { passport: 'P-ACTIVE' }) === 1, 'active same passport blocks duplicate');
ok(duplicateId(rows, { passport: 'P-INACTIVE' }) === null, 'inactive same passport allows re-add');
ok(duplicateId(rows, { name: 'Active Passenger', dob: '1990-01-01' }) === 1, 'active same name and DOB blocks duplicate');
ok(duplicateId(rows, { name: 'Inactive Passenger', dob: '1981-01-01' }) === null, 'inactive same name and DOB allows re-add');

const oldIssued = { ...historicalRows[1], fare_as: 'ADULT', ticket_number: 'SV-OLD-1', issue_date: '2025-01-02', status: 'INACTIVE' };
const readded = { ...oldIssued, id: 20, fare_as: 'CHILD', status: 'ACTIVE', ticket_number: null, issue_date: null };
ok(oldIssued.master_id === readded.master_id, 'Passenger Master identity is reused on re-add');
ok(oldIssued.fare_as === 'ADULT' && readded.fare_as === 'CHILD', 'inactive ADULT can be re-added as CHILD');
ok(oldIssued.ticket_number === 'SV-OLD-1' && oldIssued.issue_date === '2025-01-02', 'issued ticket history remains preserved');
ok(readded.ticket_number == null && readded.issue_date == null, 'new snapshot does not resurrect old ticket evidence');

ok(controller.includes('use App\\Services\\Operations\\ActiveBookingPassengerResolver;'), 'quick controller imports active passenger authority');
ok(controller.includes('ActiveBookingPassengerResolver $activePassengerResolver'), 'active resolver is constructor-injected');
ok(controller.includes('$this->activePassengerResolver->ids($booking)'), 'duplicate detection reads active snapshot ids');
ok(/where\('booking_id', \$booking\)\s*->whereIn\('id', \$activeIds\)/.test(controller), 'duplicate query is restricted to active ids');
ok(!controller.includes("UPPER(status) <> ?"), 'REMOVED-only duplicate filter is removed');
ok(resolver.includes("whereNull('deleted_at')"), 'shared resolver excludes deleted snapshots');
ok(resolver.includes("where('is_active', true)"), 'shared resolver excludes inactive snapshots');
ok(resolver.includes("where('active', true)"), 'shared resolver excludes inactive active-flag snapshots');
ok(inactiveStatuses.size === 5 && [...inactiveStatuses].every(status => resolver.includes(status)), 'shared resolver excludes all inactive status variants');
ok(controller.includes('$this->passengerWriter->resolve'), 'existing Passenger Master writer path remains');
ok(air.includes('reconcileRemovedPassengerAirRows'), 'historical Air reconciliation remains separately guarded');

console.log(`ERP-11.3.334 inactive passenger re-add regression: PASS (${assertions} assertions)`);
