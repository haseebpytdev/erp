import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function materializeVisa({ master, children, existing = [], links = [] }) {
  if (!master || !/(^|[^a-z0-9])visa([^a-z0-9]|$)/i.test(`${master.name} ${master.code}`)) throw new Error('master');
  const ids = children.map(row => Number(row.booking_passenger_id)).sort((a, b) => a - b);
  if (!ids.length || new Set(ids).size !== ids.length) throw new Error('passengers');
  const customerTotal = children.reduce((sum, row) => sum + Number(row.sale_pkr), 0);
  const vendorTotal = children.reduce((sum, row) => sum + Number(row.vendor_cost_pkr), 0);
  const active = existing.filter(row => row.active);
  if (active.length > 1) throw new Error('duplicate active services');
  const quantity = master.pricing_basis === 'PER_PERSON' ? ids.length
    : master.pricing_basis === 'PER_SERVICE' ? 1
      : (() => { throw new Error('pricing'); })();
  return {
    service: {
      id: active[0]?.id ?? 21,
      product_service_id: master.id,
      customer_total: customerTotal,
      vendor_total: vendorTotal,
      quantity,
      passenger_link_mode_snapshot: master.passenger_link_mode,
      pricing_basis_snapshot: master.pricing_basis,
      active: true,
    },
    links: [...ids],
    previousLinks: [...links],
  };
}

// The fixture ID is deliberately arbitrary: production identity is resolved
// from the native foreign-key target and is never encoded in source.
const master = { id: 9007, name: 'Visa', code: 'VISA', passenger_link_mode: 'MULTIPLE', pricing_basis: 'PER_PERSON' };
const children = [20, 21, 22, 23, 24].map((id, index) => ({
  booking_passenger_id: id,
  sale_pkr: [40000, 40000, 40000, 40000, 45000][index],
  vendor_cost_pkr: 30000,
}));
const first = materializeVisa({ master, children });
equal(first.links, [20, 21, 22, 23, 24], 'Visa child rows are the exact passenger authority');
equal(first.service.customer_total, 205000, 'Visa customer total is the sum of child sale_pkr');
equal(first.service.vendor_total, 150000, 'Visa vendor total is the sum of child vendor_cost_pkr');
equal(first.service.quantity, 5, 'PER_PERSON quantity equals the exact linked passenger count');
equal(materializeVisa({ master: { ...master, pricing_basis: 'PER_SERVICE' }, children }).service.quantity, 1, 'PER_SERVICE quantity is one');
const repeat = materializeVisa({ master, children, existing: [first.service], links: first.links });
equal(repeat.service.id, first.service.id, 'repeat synchronization reuses the one active service');
equal(repeat.links, first.links, 'repeat synchronization is passenger-link idempotent');
assert.throws(() => materializeVisa({ master, children, existing: [{ id: 1, active: true }, { id: 2, active: true }] }), /duplicate/); pass++;
assert.throws(() => materializeVisa({ master: { ...master, pricing_basis: 'PER_TICKET' }, children }), /pricing/); pass++;

const visaController = read('app/Http/Controllers/Operations/GeneralBookingVisaProductController.php');
const visaSync = read('app/Services/Operations/VisaBookingServiceSynchronizer.php');
const genericSync = read('app/Services/Operations/GenericServicePassengerLinkSynchronizer.php');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const verifier = read('app/Services/Operations/NativeSalesInvoiceCreationVerifier.php');

ok(!visaController.includes('syncBookingServiceBestEffort'), 'the silent free-text best-effort path is removed');
ok(visaController.includes('$this->visaServices->synchronize($booking, true)'), 'normal Visa save invokes strict native synchronization');
ok(visaController.indexOf("DB::table('booking_visa_services')->updateOrInsert(") < visaController.indexOf('$this->visaServices->synchronize($booking, true)'), 'normal save writes child authority before native service synchronization');
ok(visaController.indexOf('DB::transaction(function ()') < visaController.indexOf('$this->visaServices->synchronize($booking, true)'), 'normal Visa synchronization remains atomic');
ok(visaSync.includes("Schema::getForeignKeys(self::SERVICE_TABLE)"), 'Visa identity follows the physical booking-service foreign key');
ok(visaSync.includes("preg_match('/(^|[^a-z0-9])visa([^a-z0-9]|$)/i'"), 'master matching requires an exact Visa token');
ok(!/VISA_PRODUCT_SERVICE_ID\s*=\s*\d+/.test(visaSync), 'source does not hard-code or guess the Visa product ID');
ok(visaSync.includes("->where('product_service_id', $masterId)"), 'booking service lookup uses resolved native product identity');
ok(visaSync.includes("['selling_total', 'customer_total', 'sale_total', 'total_sale', 'customer_amount', 'selling_amount']"), 'native supported customer total fields receive authoritative Visa total');
ok(visaSync.includes("['net_supplier_cost', 'supplier_total', 'vendor_total', 'cost_total', 'total_cost', 'supplier_amount', 'vendor_amount']"), 'native supported vendor total fields receive authoritative Visa total');
ok(visaSync.includes("'PER_PERSON', 'PER_PASSENGER', 'PER_PAX' => $passengerCount"), 'Visa quantity honors native per-person pricing');
ok(visaSync.includes("'PER_SERVICE', 'FLAT', 'FIXED' => 1"), 'Visa quantity honors native per-service pricing');
ok(genericSync.includes('syncVisaFromNative('), 'Visa uses exact generic passenger-link synchronization');
ok(bridge.indexOf('$this->customerAuthority->resolve($bookingId)') < bridge.indexOf('$this->visaServices->synchronize($bookingId)'), 'historical Visa reconciliation follows locked booking/customer validation');
ok(bridge.indexOf('$this->visaServices->synchronize($bookingId)') < bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)'), 'Visa service is complete before Air/Hotel/Transport reconciliation');
ok(bridge.indexOf('reconcileDeterministicServicesForInvoice($bookingId)') < bridge.indexOf('$this->creator->create($request, $bookingId)'), 'all four native services are complete before native invoice creation');
ok(bridge.indexOf('DB::transaction(function ()') < bridge.indexOf('$this->visaServices->synchronize($bookingId)'), 'historical Visa reconciliation is inside the invoice transaction');
ok(verifier.includes('header total does not match the authoritative booking customer total'), 'native invoice total verification remains enforced');

equal(718000 + 8000 + 200 + first.service.customer_total, 931200, 'four native service totals equal the authoritative booking total');
equal(['Air', 'Hotel', 'Transport', 'Visa'].length, 4, 'expected native invoice product line count is four');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
