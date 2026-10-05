import assert from 'node:assert/strict';
import fs from 'node:fs';

const resolver = fs.readFileSync(new URL('../../app/Services/Operations/BookingProductSummaryResolver.php', import.meta.url), 'utf8');
const air = resolver.slice(resolver.indexOf('private function airServiceRows'), resolver.indexOf('private function airSummary'));
const summary = resolver.slice(0, resolver.indexOf('private function transportTable'));
const querySetup = air.slice(0, air.indexOf("if (in_array('deleted_at', $columns, true))"));
let assertions = 0;
const ok = (value, message) => { assert.ok(value, message); assertions++; };

ok(air.includes("DB::table('booking_services')->where('booking_id', $booking)"), 'Air booking services query is booking scoped');
ok(air.includes("in_array('deleted_at', $columns, true)"), 'deleted_at is checked against physical schema');
ok(air.includes("$query->where(function ($q): void") && air.indexOf("in_array('deleted_at', $columns, true)") < air.indexOf("$query->where(function ($q): void"), 'deleted rows are filtered only after schema guard');
ok(!querySetup.includes("whereNull('deleted_at')") && !querySetup.includes("orWhere('deleted_at'"), 'booking_services without deleted_at remains supported');
ok(air.includes("where('product_service_id', (int) $master['id'])"), 'native Air master identity remains authoritative');
ok(air.includes('where(\'booking_id\', $booking)'), 'cross-booking Air services cannot enter the summary');
ok(!air.includes('ticket_number') && !air.includes('issued'), 'Air presence does not depend on ticket issuance');
ok(summary.includes('$this->airServiceRows($booking, $master)') && summary.includes('$this->airSummary($serviceRows, $serviceIds)'), 'existing booking service determines Air presence');
ok(summary.includes('air_ticket_details') && summary.includes('$detailSale') && summary.includes('$detailCost'), 'Air detail commercial fallback remains intact');
ok(!resolver.includes('GeneralBookingAirProductController::show') && !resolver.includes('GeneralBookingAirProductController'), 'Air summary remains controller-free');
ok(summary.includes('serviceSalePresent ? $serviceSale : $detailSale') && summary.includes('serviceCostPresent ? $serviceCost : $detailCost'), 'service snapshots retain commercial priority');
ok(summary.includes("'margin' => round($sale - $cost, 2)"), 'Air pricing formula remains sale less cost');
ok(summary.includes("'count' => $services->count()") && summary.includes("'customer_total' => round($sale, 2)") && summary.includes("'supplier_total' => round($cost, 2)"), 'Air card output supports expected item and totals');
console.log(`erp113378-c41-air-booking-service-schema-compatibility-regression: ${assertions} assertions passed`);
