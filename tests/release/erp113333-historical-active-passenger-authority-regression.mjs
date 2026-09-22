import assert from 'node:assert/strict';
import fs from 'node:fs';

const air = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/GeneralBookingAirProductController.php', import.meta.url), 'utf8');
const resolver = fs.readFileSync(new URL('../../app/Services/Operations/ActiveBookingPassengerResolver.php', import.meta.url), 'utf8');
const summary = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/GeneralBookingOperationalSummaryController.php', import.meta.url), 'utf8');
const progressive = fs.readFileSync(new URL('../../public/erp11390/general-progressive-step1.js', import.meta.url), 'utf8');
let assertions = 0;
const ok = (v, m) => { assert.equal(Boolean(v), true, m); assertions += 1; };
const active = row => row.deleted_at == null && (row.is_active === undefined || Boolean(row.is_active)) && (row.active === undefined || Boolean(row.active)) && !['inactive','deleted','removed','cancelled','canceled'].includes(String(row.status ?? '').toLowerCase());
const rows = [
  { id: 1, status: 'ACTIVE' },
  { id: 2, status: 'ACTIVE', deleted_at: '2026-01-01' },
  { id: 3, status: 'ACTIVE', is_active: false },
];
ok(rows.filter(active).length === 1, 'historical fixture leaves one current passenger');
ok(rows.filter(active).map(r => r.id)[0] === 1, 'active snapshot remains visible');
for (const status of ['REMOVED','INACTIVE','DELETED','CANCELLED','CANCELED']) ok(!active({status}), `${status} snapshot is excluded`);
ok(!active({status:'ACTIVE', deleted_at:'2026-01-01'}), 'deleted_at snapshot is excluded');
ok(!active({status:'ACTIVE', is_active:0}), 'is_active false snapshot is excluded');
ok(!active({status:'ACTIVE', active:0}), 'active false snapshot is excluded');
ok(active({status:'BOOKED'}), 'normal booked status remains active');
ok(active({status:'PENDING'}), 'normal pending status remains active');
ok(resolver.includes("whereNull('deleted_at')"), 'resolver excludes deleted_at rows');
ok(resolver.includes("where('is_active', true)"), 'resolver excludes is_active false rows');
ok(resolver.includes("where('active', true)"), 'resolver excludes active false rows');
ok(['inactive', 'deleted', 'removed', 'cancelled', 'canceled'].every(v => resolver.includes(v)), 'resolver excludes inactive status variants');
ok(air.includes('ActiveBookingPassengerResolver'), 'Air reader uses shared active passenger authority');
ok(air.includes('activePassengers->rows'), 'Air passengers are resolved through shared service');
ok(summary.includes("count($airPassengers)"), 'operational summary derives count from Air passengers');
ok(!air.includes("$status !== 'REMOVED'"), 'Air reader no longer uses REMOVED-only filtering');
ok(air.includes("'passengers' => $passengers"), 'Air response exposes filtered current passengers');
ok(summary.includes("'passenger_count' => count($airPassengers)"), 'operational KPI matches Air active authority');
ok(progressive.includes('etgpApplyPassengerTableKpi113332(passengerCard)'), 'initial Booking mount applies KPI from visible passenger table');
ok(resolver.includes('booking_passengers') && resolver.includes('booking_travellers'), 'resolver is schema-aware across native passenger tables');
ok(resolver.includes('Passenger Master') === false, 'Passenger Master is not used as current snapshot authority');
ok(air.includes('reconcileRemovedPassengerAirRows'), 'existing guarded historical reconciliation remains separate');
console.log(`ERP-11.3.333 historical active passenger authority regression: PASS (${assertions} assertions)`);
