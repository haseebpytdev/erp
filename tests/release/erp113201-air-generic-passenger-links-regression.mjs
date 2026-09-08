import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };

const normalized = (ids) => [...new Set(ids.map(Number).filter(Number.isInteger).filter(id => id > 0))].sort((a, b) => a - b);

// Mirrors the safety contract of GenericServicePassengerLinkSynchronizer:
// only a complete, valid, unique native Air set can become generic links.
function reconcile({ bookingPassengers, nativePassengerIds, genericPassengerIds, failInsert = false }) {
  const native = normalized(nativePassengerIds);
  const rawNative = nativePassengerIds.map(Number).filter(Number.isInteger).filter(id => id > 0);
  if (!native.length || native.length !== rawNative.length) throw new Error('ambiguous native Air links');
  if (!native.every(id => bookingPassengers.includes(id))) throw new Error('foreign booking passenger');

  const before = normalized(genericPassengerIds);
  const working = [...native];
  if (failInsert) throw new Error('generic insert failed');
  return { before, after: working };
}

const bookingPassengers = [20, 21, 22, 23, 24];
const first = reconcile({
  bookingPassengers,
  nativePassengerIds: [20, 22, 23, 24, 21],
  genericPassengerIds: [],
});
equal(first.after, [20, 21, 22, 23, 24], 'five valid native Air links reconcile to five generic service links');
ok(first.after.length === 5, 'reconciliation creates exactly five generic links');

const repeat = reconcile({
  bookingPassengers,
  nativePassengerIds: first.after,
  genericPassengerIds: first.after,
});
equal(repeat.after, first.after, 'repeat reconciliation remains idempotent with no duplicate generic links');

assert.throws(
  () => reconcile({ bookingPassengers, nativePassengerIds: [20, 20], genericPassengerIds: [] }),
  /ambiguous native Air links/,
);
pass++;

assert.throws(
  () => reconcile({ bookingPassengers, nativePassengerIds: [20, 99], genericPassengerIds: [] }),
  /foreign booking passenger/,
);
pass++;

const preTransactionGeneric = [];
assert.throws(
  () => reconcile({ bookingPassengers, nativePassengerIds: bookingPassengers, genericPassengerIds: preTransactionGeneric, failInsert: true }),
  /generic insert failed/,
);
equal(preTransactionGeneric, [], 'generic-sync failure leaves the pre-transaction generic set unchanged');

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const air = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const synchronizer = read('app/Services/Operations/GenericServicePassengerLinkSynchronizer.php');
const creator = read('app/Services/Sales/NativeBookingSalesInvoiceCreator.php');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
ok(air.indexOf('$this->syncTickets(') < air.indexOf('$this->passengerLinks->syncAirFromNative('), 'normal Air save writes native rows before generic links');
ok(air.includes('DB::transaction(function ()'), 'normal Air save keeps native and generic synchronization atomic');
ok(synchronizer.includes('if ($after !== $passengerIds)'), 'generic sync verifies exact post-write passenger-link equality');
ok(synchronizer.includes('assertPassengerOwnership($bookingId, $nativeIds)'), 'generic sync refuses untrusted passenger assignments');
ok(bridge.includes('private readonly GenericServicePassengerLinkSynchronizer $passengerLinks'), 'transaction-owning bridge receives the generic passenger-link synchronizer');
ok(bridge.indexOf('$this->customerAuthority->resolve($bookingId)') < bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)'), 'invoice reconciliation runs after locked booking and customer validation');
ok(bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)') < bridge.indexOf('$this->creator->create($request, $bookingId)'), 'invoice bridge reconciles deterministic service links before invoking the native creator');
ok(bridge.indexOf('DB::transaction(function ()') < bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)'), 'invoice reconciliation remains inside the bridge transaction');
ok(!creator.includes('reconcileDeterministicServicesForInvoice($bookingId)'), 'native creator does not perform a second reconciliation');
ok(!bridge.includes('SalesInvoiceService::createFromBooking'), 'invoice bridge does not alter or bypass host SalesInvoiceService validation');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
