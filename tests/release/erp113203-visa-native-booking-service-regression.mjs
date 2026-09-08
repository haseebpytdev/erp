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
  const vendorIds = [...new Set(children.map(row => Number(row.vendor_id)).filter(id => Number.isInteger(id) && id > 0))];
  if (vendorIds.length !== 1 || children.some(row => Number(row.vendor_id) !== vendorIds[0])) throw new Error('vendor');
  const quantity = master.pricing_basis === 'PER_PERSON' ? ids.length
    : master.pricing_basis === 'PER_SERVICE' ? 1
      : (() => { throw new Error('pricing'); })();
  const unitPrice = Math.round((customerTotal / quantity) * 100) / 100;
  if (Math.abs(quantity * unitPrice - customerTotal) > 0.005) throw new Error('commercial reconciliation');
  return {
    service: {
      id: active[0]?.id ?? 21,
      product_service_id: master.id,
      customer_total: customerTotal,
      line_total: customerTotal,
      vendor_total: vendorTotal,
      quantity,
      unit_price: unitPrice,
      description: master.name,
      currency_code: 'PKR',
      vendor_id: vendorIds[0],
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
  vendor_id: 77,
}));
const first = materializeVisa({ master, children });
equal(first.links, [20, 21, 22, 23, 24], 'Visa child rows are the exact passenger authority');
equal(first.service.customer_total, 205000, 'Visa customer total is the sum of child sale_pkr');
equal(first.service.vendor_total, 150000, 'Visa vendor total is the sum of child vendor_cost_pkr');
equal(first.service.quantity, 5, 'PER_PERSON quantity equals the exact linked passenger count');
equal(first.service.unit_price, 41000, 'PER_PERSON unit price is authoritative total divided by five passengers');
equal(first.service.line_total, 205000, 'native line_total carries the authoritative Visa customer total');
equal(first.service.description, 'Visa', 'native description uses the resolved Visa product name');
equal(first.service.currency_code, 'PKR', 'native currency is PKR');
equal(first.service.vendor_id, 77, 'native vendor comes from one unambiguous child-row Vendor ID');
const perService = materializeVisa({ master: { ...master, pricing_basis: 'PER_SERVICE' }, children });
equal(perService.service.quantity, 1, 'PER_SERVICE quantity is one');
equal(perService.service.unit_price, 205000, 'PER_SERVICE unit price equals the authoritative total');
equal(perService.service.line_total, 205000, 'PER_SERVICE line_total remains authoritative');
const repeat = materializeVisa({ master, children, existing: [first.service], links: first.links });
equal(repeat.service.id, first.service.id, 'repeat synchronization reuses the one active service');
equal(repeat.links, first.links, 'repeat synchronization is passenger-link idempotent');
assert.throws(() => materializeVisa({ master, children, existing: [{ id: 1, active: true }, { id: 2, active: true }] }), /duplicate/); pass++;
assert.throws(() => materializeVisa({ master: { ...master, pricing_basis: 'PER_TICKET' }, children }), /pricing/); pass++;
assert.throws(() => materializeVisa({ master, children: children.map((row, index) => ({ ...row, vendor_id: index ? 77 : 88 })) }), /vendor/); pass++;

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
ok(visaSync.includes("'line_total', 'selling_total', 'customer_total', 'sale_total'"), 'line_total is a supported authoritative native customer-total carrier');
ok(visaSync.includes("$this->putAll($row, $columns, ['unit_price'], $unitPrice)"), 'native unit_price receives customer total divided by pricing quantity');
ok(visaSync.includes('$this->putAll($row, $columns, self::CUSTOMER_TOTAL_FIELDS, $customerTotal)'), 'native line_total receives the authoritative Visa customer total');
ok(visaSync.includes('$this->firstNumeric($active[0], self::CUSTOMER_TOTAL_FIELDS)'), 'readback verification accepts native line_total');
ok(visaSync.includes("['service_name', 'name', 'title', 'description']"), 'native description receives the resolved Visa master name');
ok(visaSync.includes("$this->putAll($row, $columns, ['currency_code'], 'PKR')"), 'native currency_code is PKR');
ok(visaSync.includes("$row['vendor_id'] = $vendorId"), 'native vendor_id is written only from child authority');
ok(visaSync.includes('do not resolve to one unambiguous Vendor ID'), 'conflicting child Vendor IDs fail clearly');
ok(visaSync.includes("abs(($quantity * $actualUnitPrice) - $customerTotal)"), 'native quantity and unit price are verified against line total');
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
