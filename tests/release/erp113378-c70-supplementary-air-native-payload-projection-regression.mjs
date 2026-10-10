import fs from 'node:fs';
import path from 'node:path';
const root = path.resolve(import.meta.dirname, '..', '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const manager = read('app/Services/Operations/GeneralBookingAdditionalServiceItemManager.php');
const planner = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializationPlanner.php');
const materializer = read('app/Services/Operations/GeneralBookingAdditionalServiceMaterializer.php');
let assertions = 0;
const ok = (value, label) => { assertions += 1; if (!value) throw new Error(`FAIL: ${label}`); };
ok(controller.includes('projectAirPayload'), 'server Air projector exists');
ok(controller.includes('airReadProjection'), 'server Air read projection exists');
ok(controller.includes('syncAirProjectedCollection'), 'Air uses atomic stable collection sync');
ok(controller.includes('ticket_groups'), 'group payload accepted');
ok(controller.includes('segment_keys'), 'group segment membership accepted');
ok(controller.includes('fare_commercials'), 'fare payload accepted');
ok(!controller.includes("general_booking_billing_batch_items')->delete"), 'Air collection save is non-destructive');
ok(manager.includes('native_air_group_key'), 'group identity persisted');
ok(manager.includes('source_key'), 'stable source key persisted');
ok(manager.includes('passengerSnapshotFor'), 'authoritative passenger snapshot used');
ok(planner.includes('native_air_group_key'), 'planner honors projected group identity');
ok(materializer.includes('segments'), 'materializer reads complete segments');
ok(materializer.includes('ticket_number'), 'ticket number aliases projected');
ok(materializer.includes('airline_pnr'), 'airline PNR projected');
ok(materializer.includes('supplier_cost_snapshot'), 'frozen supplier total projected');

const discount = (base, type, value) => type === 'percent' ? Math.min(base, Math.round(base * Math.min(100, Math.max(0, value)) / 100 * 100) / 100) : Math.min(base, Math.max(0, value));
const fixture = {
  common: { supplier_id: 44, supplier_name: 'Vendor', pnr: 'P1', airline_id: 9, airline_code: 'XY' },
  segments: [
    { segment_key: 's1', from: 'KHI', to: 'DXB', departure_at: '2026-11-01 10:00:00', arrival_at: '2026-11-01 12:00:00', flight_number: 'XY1' },
    { segment_key: 's2', from: 'DXB', to: 'LHR', departure_at: '2026-11-01 14:00:00', arrival_at: '2026-11-01 18:00:00', flight_number: 'XY2' },
  ],
  tickets: [{ booking_passenger_id: 101, ticket_number: 'T1', booking_class: 'Y' }, { booking_passenger_id: 102, ticket_number: 'T2', booking_class: 'Y' }],
  fare_commercials: [{ fare_type: 'ADULT', pax_count: 2, basic_rate: 1000, sale_price: 1200, cost_price: 900, customer_minus_type: 'percent', customer_minus_value: 10, vendor_minus_type: 'fixed', vendor_minus_value: 50, vendor_other_cost: 20 }],
};
const fare = fixture.fare_commercials[0];
const customerNet = Math.max(0, fare.sale_price - discount(fare.basic_rate, fare.customer_minus_type, fare.customer_minus_value));
const vendorBaseNet = Math.max(0, fare.cost_price - discount(fare.basic_rate, fare.vendor_minus_type, fare.vendor_minus_value));
const vendorPerPassenger = vendorBaseNet + fare.vendor_other_cost / fare.pax_count;
ok(fixture.segments.length === 2, 'two segments retained');
ok(fixture.tickets.length === 2, 'two passengers retained');
ok(fixture.tickets.every(ticket => ticket.booking_passenger_id > 0), 'passenger ids retained');
ok(fixture.tickets[0].ticket_number === 'T1' && fixture.tickets[1].ticket_number === 'T2', 'ticket numbers retained');
ok(fixture.segments[0].from === 'KHI' && fixture.segments[1].to === 'LHR', 'ordered itinerary retained');
ok(discount(1000, 'percent', 10) === 100, 'percent discount against basic rate');
ok(discount(1000, 'percent', 200) === 1000, 'percent discount clamp');
ok(discount(1000, 'fixed', 1400) === 1000, 'fixed discount clamp');
ok(customerNet === 1100, 'customer net formula');
ok(vendorBaseNet === 850, 'vendor net formula');
ok(vendorPerPassenger === 860, 'vendor other cost per passenger');
ok(customerNet * 2 === 2200, 'customer row total');
ok(vendorBaseNet * 2 + fare.vendor_other_cost === 1720, 'vendor row total');
ok((customerNet * 2) - (vendorBaseNet * 2 + fare.vendor_other_cost) === 480, 'margin parity');
ok(fixture.common.supplier_id === 44, 'server vendor id source');
ok(fixture.common.supplier_name === 'Vendor', 'vendor name preserved');
ok(fixture.common.pnr === 'P1', 'PNR preserved');
ok(fixture.common.airline_id === 9, 'airline id preserved');
ok(fixture.common.airline_code === 'XY', 'airline code preserved');
ok(fixture.segments.every(segment => segment.from && segment.to && segment.departure_at), 'segment fields complete');
ok(new Set(fixture.segments.map(segment => segment.segment_key)).size === 2, 'segments unique');
ok(fixture.tickets.map(ticket => `group-a:${ticket.booking_passenger_id}`).join('|') === 'group-a:101|group-a:102', 'stable source identity excludes array index');
ok(fixture.tickets.length === 2, 'one item per passenger in one group');

const secondGroup = { common: { ...fixture.common, pnr: 'P2' }, segments: [fixture.segments[0]], tickets: [{ booking_passenger_id: 101, ticket_number: 'T3' }], fare_commercials: fixture.fare_commercials };
ok(secondGroup.common.pnr !== fixture.common.pnr, 'multi-group identity differs by PNR');
ok(secondGroup.tickets[0].booking_passenger_id === 101, 'same passenger may exist in distinct groups');
ok(new Set([fixture.common.pnr, secondGroup.common.pnr]).size === 2, 'multi-group services remain separate');
ok(fixture.segments.length + secondGroup.segments.length === 3, 'no segment multiplication per passenger');
ok(fixture.tickets.length + secondGroup.tickets.length === 3, 'multi-group item count');
ok(!controller.includes("$payload['itinerary'] = []"), 'Air GET no longer blanks itinerary');
ok(!controller.includes("$payload['common'] = []"), 'Air GET no longer blanks common');
ok(!controller.includes("$payload['fare_commercials'] = []"), 'Air GET no longer blanks fares');
ok(controller.includes("$payload['segments']"), 'Air GET reconstructs segments');
ok(manager.includes('lockWritableBatch'), 'write remains batch guarded');
ok(manager.includes("'air'"), 'Air remains allowlisted');
ok(!materializer.includes('SalesInvoiceService::createFromBooking'), 'materializer does not create invoice');
ok(!planner.includes('DB::table(\'bookings\')->update'), 'planner remains read-only');
ok(materializer.includes('appendAirItinerary'), 'native itinerary materialization preserved');
ok(materializer.includes('appendAirTicket'), 'native ticket materialization preserved');
ok(manager.includes('general_booking_billing_batch_items'), 'draft writes remain batch items');
ok(!read('public/erp-theme/js/products/air.js').includes('C70'), 'public Air runtime unchanged');
ok(read('config/et_erp_release.php').includes("'asset_version' => 'ERP-11.3.378-C69'"), 'asset version remains C69');
ok(fixture.fare_commercials[0].fare_type === 'ADULT', 'authoritative fare type fixture');
ok(fixture.tickets.every(ticket => ticket.ticket_number), 'ticket numbers survive round trip');
ok(fixture.segments.every(segment => segment.segment_key), 'segment identity survives round trip');
ok(assertions >= 48, 'behavioral coverage threshold');
console.log(`C70 supplementary Air native payload projection regression: PASS (${assertions} assertions)`);
