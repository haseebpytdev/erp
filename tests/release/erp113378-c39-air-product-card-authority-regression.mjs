import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const resolver = read('app/Services/Operations/BookingProductSummaryResolver.php');
const air = resolver.slice(resolver.indexOf('private function airServiceRows'), resolver.indexOf('private function airSummary'));
const summary = resolver.slice(resolver.indexOf('private function summary'), resolver.indexOf('private function airServiceRows'));

ok(summary.includes("$product === 'air'") && summary.includes('$this->airServiceRows($booking, $master)'), 'Air summary uses the dedicated persisted-service authority');
ok(air.includes("where('booking_id', $booking)") && air.includes("where('product_service_id', (int) $master['id'])"), 'Air master and booking scope remain exact');
ok(air.includes('service_name') && air.includes('service_type') && air.includes("str_contains($identity, 'air')") && air.includes("str_contains($identity, 'flight')") && air.includes("str_contains($identity, 'ticket')"), 'Air legacy identity fallback matches native fields');
ok(air.includes("whereNull('deleted_at')") && air.includes("orWhere('deleted_at', '')"), 'Deleted Air services are excluded');
ok(!resolver.includes('GeneralBookingAirProductController::show') && !resolver.includes('GeneralBookingAirProductController'), 'Air summary has no controller fan-out');
ok(resolver.includes("'count' => $services->count()") && resolver.includes('serviceSalePresent ? $serviceSale : $detailSale') && resolver.includes('hasCommercialField'), 'Air card uses saved service presence and commercial snapshot fallback');
console.log('C39_AIR_PRODUCT_CARD_REGRESSION=PASS (6 assertions)');
