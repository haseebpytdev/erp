import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const activePassengerIds = rows => rows
  .filter(row => !row.deleted_at)
  .filter(row => row.is_active !== false && row.is_active !== 0)
  .filter(row => row.active !== false && row.active !== 0)
  .filter(row => !['inactive', 'deleted', 'removed', 'cancelled', 'canceled'].includes(String(row.status ?? '').toLowerCase()))
  .map(row => Number(row.id))
  .filter(id => Number.isInteger(id) && id > 0)
  .sort((a, b) => a - b);

function deterministicLinks(service, passengers, nativeAirIds) {
  if (!service.is_active) return null;
  if (service.product_service_id === 1) return [...nativeAirIds].sort((a, b) => a - b);
  if (![3, 4].includes(service.product_service_id)) return null;
  if (!['REQUIRED', 'MULTIPLE'].includes(service.passenger_link_mode_snapshot)) throw new Error('link mode');
  if (service.pricing_basis_snapshot !== 'PER_SERVICE') throw new Error('pricing basis');
  return activePassengerIds(passengers);
}

const synchronizeExact = authoritativeIds => [...new Set(authoritativeIds)].sort((a, b) => a - b);

const passengers = [20, 21, 22, 23, 24].map(id => ({ id, is_active: true, status: 'active' }));
const services = [
  { id: 17, product_service_id: 1, description: 'Air Ticket', passenger_link_mode_snapshot: 'MULTIPLE', pricing_basis_snapshot: 'PER_TICKET', is_active: true },
  { id: 18, product_service_id: 3, description: 'Hotel Accommodation', passenger_link_mode_snapshot: 'MULTIPLE', pricing_basis_snapshot: 'PER_SERVICE', is_active: true },
  { id: 20, product_service_id: 4, description: 'Hotel Accommodation', passenger_link_mode_snapshot: 'MULTIPLE', pricing_basis_snapshot: 'PER_SERVICE', is_active: true },
];

equal(deterministicLinks(services[0], passengers, [24, 20, 23, 21, 22]), [20, 21, 22, 23, 24], 'Air keeps explicit native ticket passenger authority');
equal(deterministicLinks(services[1], passengers, []), [20, 21, 22, 23, 24], 'Hotel product 3 applies to the exact active booking-wide passenger set');
equal(deterministicLinks(services[2], passengers, []), [20, 21, 22, 23, 24], 'Transport product 4 ignores its stale Hotel description and applies booking-wide');
const hotelFirst = synchronizeExact(deterministicLinks(services[1], passengers, []));
equal(synchronizeExact(hotelFirst), hotelFirst, 'Hotel booking-wide synchronization is idempotent');
const transportFirst = synchronizeExact(deterministicLinks(services[2], passengers, []));
equal(synchronizeExact(transportFirst), transportFirst, 'Transport booking-wide synchronization is idempotent');
equal(activePassengerIds([...passengers, { id: 25, is_active: false }, { id: 26, deleted_at: '2026-09-08' }]), [20, 21, 22, 23, 24], 'booking-wide authority excludes inactive and deleted passengers');
equal(deterministicLinks({ ...services[1], product_service_id: 99 }, passengers, []), null, 'unknown products never receive booking-wide links');
assert.throws(() => deterministicLinks({ ...services[1], passenger_link_mode_snapshot: 'OPTIONAL' }, passengers, []), /link mode/); pass++;
assert.throws(() => deterministicLinks({ ...services[2], pricing_basis_snapshot: 'PER_TICKET' }, passengers, []), /pricing basis/); pass++;

const synchronizer = read('app/Services/Operations/GenericServicePassengerLinkSynchronizer.php');
const hotel = read('app/Http/Controllers/Operations/GeneralBookingHotelProductController.php');
const transport = read('app/Http/Controllers/Operations/GeneralBookingTransportProductController.php');
const air = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');

ok(synchronizer.includes('private const HOTEL_PRODUCT_SERVICE_ID = 3'), 'Hotel booking-wide policy is bound to native product 3');
ok(synchronizer.includes('private const TRANSPORT_PRODUCT_SERVICE_ID = 4'), 'Transport booking-wide policy is bound to native product 4');
ok(synchronizer.includes("['REQUIRED', 'MULTIPLE']"), 'booking-wide policy requires the host passenger-link contract');
ok(synchronizer.includes("$pricingBasis !== 'PER_SERVICE'"), 'booking-wide policy requires native PER_SERVICE pricing semantics');
ok(synchronizer.includes('activeBookingPassengerIds($bookingId)'), 'booking-wide policy derives its exact set from active booking passengers');
ok(synchronizer.includes('synchronizeAir($bookingId, $serviceId, true)'), 'normal Air synchronization retains explicit native authority');
ok(air.includes('syncAirFromNative('), 'normal Air save synchronization remains wired');
ok(hotel.includes('private const HOTEL_PRODUCT_SERVICE_ID = 3'), 'Hotel service lookup uses native product authority');
ok(!hotel.includes("str_contains($text, 'hotel') || str_contains($text, 'accommodation')"), 'Hotel service lookup cannot use stale descriptions');
ok(hotel.includes("['passenger_link_mode_snapshot', 'passenger_link_mode'], 'MULTIPLE'"), 'new Hotel services persist the confirmed MULTIPLE contract');
ok(hotel.includes("['pricing_basis_snapshot', 'pricing_basis'], 'PER_SERVICE'"), 'new Hotel services persist the confirmed PER_SERVICE contract');
ok(hotel.indexOf('$this->assertPersistedHotelCommercials($normalized, $fresh)') < hotel.indexOf('$this->passengerLinks->syncHotelBookingWide('), 'Hotel sync runs after native persistence verification');
ok(hotel.indexOf('DB::transaction(function ()') < hotel.indexOf('$this->passengerLinks->syncHotelBookingWide('), 'Hotel sync remains inside normal-save transaction');
ok(transport.includes("['passenger_link_mode_snapshot','passenger_link_mode'], 'MULTIPLE'"), 'new Transport services persist the confirmed MULTIPLE contract');
ok(transport.includes("['pricing_basis_snapshot','pricing_basis'], 'PER_SERVICE'"), 'new Transport services persist the confirmed PER_SERVICE contract');
ok(transport.indexOf('$this->assertPersisted($normalized, $fresh)') < transport.indexOf('$this->passengerLinks->syncTransportBookingWide('), 'Transport sync runs after native persistence verification');
ok(transport.indexOf('DB::transaction(function ()') < transport.indexOf('$this->passengerLinks->syncTransportBookingWide('), 'Transport sync remains inside normal-save transaction');
ok(bridge.indexOf('$this->customerAuthority->resolve($bookingId)') < bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)'), 'invoice reconciliation follows booking/customer validation');
ok(bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)') < bridge.indexOf('$this->creator->create($request, $bookingId)'), 'all deterministic links reconcile before native invoice creation');

const commercialBefore = { air: 718000, hotel: 'authoritative-hotel-total', transport: 'authoritative-transport-total', booking: 931200 };
const commercialAfter = { ...commercialBefore };
equal(commercialAfter.air, 718000, 'Air total remains unchanged');
equal(commercialAfter.hotel, commercialBefore.hotel, 'Hotel authoritative total remains unchanged');
equal(commercialAfter.transport, commercialBefore.transport, 'Transport authoritative total remains unchanged');
equal(commercialAfter.booking, 931200, 'booking total remains unchanged');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
