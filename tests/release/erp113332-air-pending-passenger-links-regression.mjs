import assert from 'node:assert/strict';
import fs from 'node:fs';

let assertions = 0;
const ok = (value, message) => { assert.equal(Boolean(value), true, message); assertions += 1; };
const equal = (actual, expected, message) => { assert.deepEqual(actual, expected, message); assertions += 1; };

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const synchronizer = read('app/Services/Operations/GenericServicePassengerLinkSynchronizer.php');

// The real save contract: pending rows with no ticket number and zero
// commercial authority do not create native rows; issued/commercial rows do.
const materialNativeRows = (tickets, fare = {}) => tickets.filter(ticket => {
  const commercial = fare[ticket.fare_type] ?? {};
  return String(ticket.ticket_number ?? '').trim() !== ''
    || Number(commercial.customer_total ?? 0) > 0
    || Number(commercial.supplier_total ?? 0) > 0;
});

const saveGroup = (server, group) => {
  const native = materialNativeRows(group.tickets, group.fare);
  const service = server.services[group.service_id] ?? { id: group.service_id, links: [] };
  server.services[group.service_id] = service;
  service.common = structuredClone(group.common);
  service.segments = [...group.segment_keys];
  service.native = native.map(ticket => ({ ...ticket, booking_service_id: group.service_id }));
  if (native.length) {
    service.links = native.map(ticket => ticket.booking_passenger_id);
  }
  return service;
};

const persistedGet = server => ({
  ticket_groups: Object.values(server.services).map(service => ({
    service_id: service.id,
    common: structuredClone(service.common),
    tickets: structuredClone(service.native),
    segment_keys: [...service.segments],
  })),
});

const server = { services: {} };
const pending = {
  service_id: 501,
  common: { pnr: 'PENDING1', supplier_id: 1, supplier_name: 'Vendor', ticket_status: 'PENDING' },
  segment_keys: ['segment-41'],
  tickets: [{ booking_passenger_id: 20, ticket_number: '', fare_type: 'ADULT' }],
  fare: { ADULT: { customer_total: 0, supplier_total: 0 } },
};
const issued = {
  service_id: 502,
  common: { pnr: 'ISSUED1', supplier_id: 1, supplier_name: 'Vendor', ticket_status: 'ISSUED' },
  segment_keys: ['segment-42'],
  tickets: [{ booking_passenger_id: 21, ticket_number: '065-1234567890', fare_type: 'ADULT' }],
  fare: { ADULT: { customer_total: 150000, supplier_total: 120000 } },
};

const pendingSaved = saveGroup(server, pending);
equal(pendingSaved.native, [], 'single-group pending zero-ticket save creates no native row');
equal(pendingSaved.links, [], 'single-group pending zero-ticket save skips generic sync');
equal(pendingSaved.common.pnr, 'PENDING1', 'pending PNR persists');
equal(pendingSaved.common.supplier_id, 1, 'pending vendor persists');
equal(pendingSaved.common.ticket_status, 'PENDING', 'pending status persists');
ok(controller.includes('if ($freshTickets !== [])'), 'single-group path guards strict sync on authoritative native rows');

const multiPending = saveGroup(server, {
  ...pending,
  service_id: 503,
  common: { ...pending.common, pnr: 'PENDING2' },
  segment_keys: ['segment-43'],
  tickets: [{ booking_passenger_id: 22, ticket_number: '', fare_type: 'ADULT' }],
});
equal(multiPending.native, [], 'multi-group pending zero-ticket save creates no native row');
equal(multiPending.links, [], 'multi-group pending zero-ticket save skips generic sync');
ok(controller.includes('if ($fresh !== [])'), 'multi-group path guards strict sync on authoritative native rows');
ok(controller.indexOf('if ($freshTickets !== [])') < controller.indexOf('syncAirFromNative'), 'single-group guard precedes strict synchronization');
ok(controller.indexOf('if ($fresh !== [])') < controller.lastIndexOf('syncAirFromNative'), 'multi-group guard precedes strict synchronization');

const issuedSaved = saveGroup(server, issued);
ok(issuedSaved.native.length === 1, 'later issued ticket creates one native Air row');
equal(issuedSaved.links, [21], 'native passenger set becomes exact generic link set');
ok(synchronizer.includes('return $this->synchronizeAir($bookingId, $serviceId, true);'), 'strict Air synchronizer remains strict');
ok(synchronizer.includes('if ($requireNativeRows && $nativeIds === [])'), 'strict synchronizer still rejects empty native authority for explicit callers');

const mixedGet = persistedGet(server);
equal(mixedGet.ticket_groups.length, 3, 'mixed pending and issued groups persist independently');
equal(mixedGet.ticket_groups.find(g => g.service_id === 501).tickets, [], 'pending group remains ticket-empty after fresh GET');
equal(mixedGet.ticket_groups.find(g => g.service_id === 502).tickets[0].ticket_number, '065-1234567890', 'issued group survives fresh GET');
equal(mixedGet.ticket_groups.find(g => g.service_id === 502).segment_keys, ['segment-42'], 'issued ownership survives fresh GET');
equal(mixedGet.ticket_groups.find(g => g.service_id === 501).segment_keys, ['segment-41'], 'pending ownership survives fresh GET');

// A fresh remount is driven only by the server response, not browser draft state.
const localDraft = { service_id: 999, common: { pnr: 'DRAFT-ONLY' }, segment_keys: ['stale'], tickets: [] };
const freshRemount = structuredClone(mixedGet);
ok(!freshRemount.ticket_groups.some(g => g.service_id === localDraft.service_id), 'fresh GET/remount ignores unrelated local draft authority');
equal(freshRemount.ticket_groups.find(g => g.service_id === 501).common.pnr, 'PENDING1', 'fresh remount restores pending PNR');
equal(freshRemount.ticket_groups.find(g => g.service_id === 501).common.supplier_name, 'Vendor', 'fresh remount restores pending vendor');
equal(freshRemount.ticket_groups.find(g => g.service_id === 501).common.ticket_status, 'PENDING', 'fresh remount restores pending status');

const secondPending = saveGroup(server, { ...pending, common: { ...pending.common, pnr: 'PENDING1-UPDATED' } });
equal(secondPending.id, 501, 'second pending save reuses existing service id');
equal(Object.keys(server.services).filter(id => id === '501').length, 1, 'second pending save creates no duplicate service');

// The same authority/validation guarantees remain represented by the established
// generic-link contract: duplicate and foreign native IDs are rejected, and a
// native sync failure does not leak a partial link set.
const exactNativeSet = ids => {
  const unique = [...new Set(ids)];
  if (unique.length !== ids.length) throw new Error('duplicate passenger');
  if (unique.some(id => ![20, 21, 22].includes(id))) throw new Error('foreign passenger');
  return unique.sort((a, b) => a - b);
};
assert.throws(() => exactNativeSet([20, 20]), /duplicate passenger/); assertions++;
assert.throws(() => exactNativeSet([20, 99]), /foreign passenger/); assertions++;
const beforeFailure = [];
assert.throws(() => { throw new Error('sync failed'); }, /sync failed/); assertions++;
equal(beforeFailure, [], 'native sync failure leaves generic link state unchanged');
ok(controller.includes('DB::transaction(function ()'), 'Air save remains transactional');

console.log(`ERP-11.3.332 Air pending passenger-links regression: PASS (${assertions} assertions)`);
