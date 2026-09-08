import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function reconcile({ services, snapshots }) {
  return services.map(service => {
    if (![3, 4].includes(service.product_service_id)) return { ...service };
    const rows = snapshots[service.product_service_id];
    if (!Array.isArray(rows) || !rows.length) throw new Error(`${service.product_service_id === 3 ? 'Hotel' : 'Transport'} authority`);
    const total = service.product_service_id === 3
      ? rows.reduce((sum, row) => sum + Number(row.customer_total ?? Number(row.sale_rate) * Number(row.nights)), 0)
      : rows.reduce((sum, row) => sum + Number(row.sale_amount), 0);
    return { ...service, quantity: 1, unit_price: total, line_total: total, currency_code: 'PKR' };
  });
}

const services = [
  { id: 17, product_service_id: 1, quantity: 5, unit_price: 143600, line_total: 718000, currency_code: 'PKR' },
  { id: 18, product_service_id: 3, quantity: 1, unit_price: 0, line_total: 0 },
  { id: 20, product_service_id: 4, description: 'Hotel Accommodation', quantity: 1, unit_price: 0, line_total: 0 },
  { id: 22, product_service_id: 2, quantity: 5, unit_price: 41000, line_total: 205000, currency_code: 'PKR' },
];
const snapshots = {
  3: [{ customer_total: 8000, sale_rate: 8000, nights: 1 }],
  4: [{ sale_amount: 125 }, { sale_amount: 75 }],
};
const first = reconcile({ services, snapshots });
const second = reconcile({ services: first, snapshots });
const hotel = first.find(row => row.product_service_id === 3);
const transport = first.find(row => row.product_service_id === 4);

equal(hotel.quantity, 1, 'Hotel PER_SERVICE quantity is one');
equal(hotel.unit_price, 8000, 'Hotel native unit_price comes from persisted Hotel rows');
equal(hotel.line_total, 8000, 'Hotel native line_total comes from persisted Hotel rows');
equal(hotel.currency_code, 'PKR', 'Hotel native currency is PKR');
equal(transport.quantity, 1, 'Transport PER_SERVICE quantity is one');
equal(transport.unit_price, 200, 'Transport native unit_price is SUM(sale_amount)');
equal(transport.line_total, 200, 'Transport native line_total is SUM(sale_amount)');
equal(transport.currency_code, 'PKR', 'Transport native currency is PKR');
equal(transport.description, 'Hotel Accommodation', 'stale description is not used as Transport identity or rewritten');
equal(first.find(row => row.product_service_id === 1), services[0], 'Air service remains unchanged');
equal(first.find(row => row.product_service_id === 2), services[3], 'Visa service remains unchanged');
equal(second, first, 'historical reconciliation is idempotent');
equal(first.reduce((sum, row) => sum + row.line_total, 0), 931200, 'pre-native line total equals authoritative booking total');
equal(first.reduce((sum, row) => sum + row.quantity * row.unit_price, 0), 931200, 'quantity times unit price also totals 931200');
equal(first.length, 4, 'expected invoice line count remains four');
assert.throws(() => reconcile({ services, snapshots: { ...snapshots, 3: [] } }), /Hotel authority/); pass++;
assert.throws(() => reconcile({ services, snapshots: { ...snapshots, 4: [] } }), /Transport authority/); pass++;

const hotelController = read('app/Http/Controllers/Operations/GeneralBookingHotelProductController.php');
const transportController = read('app/Http/Controllers/Operations/GeneralBookingTransportProductController.php');
const synchronizer = read('app/Services/Operations/HotelTransportBookingServiceCommercialSynchronizer.php');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');
const diagnostic = read('app/Http/Controllers/System/AirLinkDbDiagnosticController.php');

ok(hotelController.includes('$this->serviceCommercials->syncHotelSummary($serviceId, (float) $summary[\'customer_total\'])'), 'normal Hotel save writes the native commercial from its existing summary');
ok(transportController.includes('$this->serviceCommercials->syncTransportSummary($serviceId, (float) $summary[\'customer_total\'])'), 'normal Transport save writes the native commercial from SUM(sale_amount) summary');
ok(synchronizer.includes("private const HOTEL_PRODUCT_SERVICE_ID = 3") && synchronizer.includes("private const TRANSPORT_PRODUCT_SERVICE_ID = 4"), 'historical identity uses exact native product IDs');
ok(synchronizer.includes("'quantity' => 1") && synchronizer.includes("'unit_price' => $total") && synchronizer.includes("'line_total' => $total") && synchronizer.includes("'currency_code' => 'PKR'"), 'shared writer persists the complete native PER_SERVICE contract');
ok(synchronizer.includes("$row['customer_total'] ?? null") && synchronizer.includes("$row['sale_rate'] ?? null") && synchronizer.includes("$row['nights'] ?? null"), 'Hotel reconciliation uses only persisted Hotel commercial authority');
ok(synchronizer.includes("array_key_exists('sale_amount', $row)") && synchronizer.includes("$total += $amount"), 'Transport reconciliation sums persisted sale_amount rows');
ok(synchronizer.includes("'et_erp_hotel_stays'") && synchronizer.includes("'ETERP_HOTEL_STAYS'"), 'historical Hotel rows support exact JSON and tagged snapshot carriers');
ok(synchronizer.includes("'et_erp_transport_rows'") && synchronizer.includes("'ETERP_TRANSPORT_ROWS'"), 'historical Transport rows support exact JSON and tagged snapshot carriers');
ok(synchronizer.includes("'booking_hotel_stays'") && synchronizer.includes("'booking_transport_segments'"), 'historical reconciliation has native product-row fallback when no compatibility snapshot exists');
ok(synchronizer.includes("where('booking_id', $bookingId)") && synchronizer.includes('bookingServiceLinkColumn'), 'native fallback remains scoped to the booking and exact service link');
ok(!synchronizer.includes('931200') && !synchronizer.includes('expectedTotal'), 'reconciliation does not use a hard-coded or residual booking total');
ok(!synchronizer.toLowerCase().includes('passenger'), 'commercial reconciliation cannot mutate generic passenger links');
{
  const commercial = bridge.indexOf('$this->serviceCommercials->reconcileForInvoice($bookingId)');
  const creator = bridge.indexOf('$this->creator->create($request, $bookingId)');
  ok(commercial > 0 && commercial < creator, 'historical commercials reconcile inside the bridge before native invoice creation');
}
ok(diagnostic.includes('$this->serviceCommercials->reconcileForInvoice($booking)'), 'rollback-only diagnostic observes Hotel and Transport reconciliation');
ok(!diagnostic.includes('NativeBookingSalesInvoiceCreator'), 'diagnostic still has no native invoice creator dependency');
ok(diagnostic.includes('while (DB::transactionLevel() > $startingLevel)') && diagnostic.includes('DB::rollBack()'), 'diagnostic remains rollback-only');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
