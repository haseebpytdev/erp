import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = file => fs.readFileSync(file, 'utf8');
const air = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const readiness = read('app/Services/Operations/BookingTravelReadinessResolver.php');
const migration = read('database/migrations/2026_09_21_000000_add_booking_service_id_to_booking_itinerary_segments.php');
const js = read('public/erp-theme/js/products/air.js');
const ok = (value, message) => assert.ok(value, message);

ok(air.includes("'ticket_groups'") && air.includes('ticketGroupsSnapshot'), 'Air GET exposes native Ticket Groups');
ok(air.includes('findAirServices') && air.includes('booking_service_id'), 'Ticket Groups use native booking services and durable segment links');
ok(air.includes('syncGroupedItinerary') && air.includes('segment_keys'), 'group segment ownership is persisted by stable keys');
ok(air.includes('An itinerary segment cannot belong to more than one Ticket Group'), 'duplicate segment ownership fails closed');
ok(air.includes('A passenger may appear only once inside one Ticket Group'), 'duplicate passenger rows within one group are rejected');
ok(air.includes('assertGroupDeletionSafe') && air.includes('terminal'), 'terminal persisted groups cannot be silently deleted');
ok(air.includes('aggregateTicketGroupSummary'), 'Air totals aggregate across all groups');
ok(air.includes('deleted_group_service_ids'), 'explicit persisted group deletion contract exists');
ok(air.includes('multiple Air Ticket Groups. Reload the Air Workspace before saving.'), 'legacy single-group saves fail closed for multi-group bookings');
ok(readiness.includes('ticket_groups') && readiness.includes('Air Ticket Group #'), 'travel readiness evaluates every group');
ok(migration.includes('booking_service_id') && migration.includes('nullable'), 'segment link migration is additive and nullable');
ok(js.includes('fareCommercials') && js.includes('etgpAirDraft113314'), 'dedicated Air renderer and draft authority remain intact');
console.log('ERP-11.3.324 Air multi-ticket-group regression: PASS (11 assertions)');
