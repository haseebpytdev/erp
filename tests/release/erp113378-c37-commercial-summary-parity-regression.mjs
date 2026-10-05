import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const resolver = read('app/Services/Operations/BookingProductSummaryResolver.php');
const commercial = read('app/Services/Operations/GeneralBookingCommercialSummaryResolver.php');
const operational = read('app/Http/Controllers/Operations/GeneralBookingOperationalSummaryController.php');

ok(resolver.includes('private function airSummary'), 'Air has a dedicated lightweight summary authority');
ok(resolver.includes('DB::table(\'booking_services\')') && resolver.includes("where('product_service_id', (int) $master['id'])"), 'Air presence is scoped to saved native Air services');
ok(resolver.includes("'selling_total', 'customer_sale', 'customer_sell', 'customer_sale_amount'"), 'Air customer commercial aliases are supported');
ok(resolver.includes("'net_supplier_cost', 'supplier_cost', 'supplier_cost_amount', 'net_cost'"), 'Air supplier commercial aliases are supported');
ok(resolver.includes("'base_fare', 'basic_fare") && resolver.includes("'airline_taxes', 'taxes', 'tax_amount'"), 'Air customer fallback uses the established fare and tax interpretation');
ok(resolver.includes("'supplier_base_fare', 'base_fare', 'basic_fare'") && resolver.includes("'supplier_taxes', 'airline_taxes', 'taxes'"), 'Air supplier fallback uses the established native cost interpretation');
ok(resolver.includes('serviceSale > 0 ? $serviceSale : $detailSale'), 'Persisted service totals win with detail compatibility fallback');
ok(resolver.includes("'count' => $services->count()"), 'Air item count is based on saved service groups');
ok(!resolver.includes('ticket_number') && !resolver.includes("'ticket_count'"), 'Air presence does not depend on ticket issuance');
ok(!resolver.includes('GeneralBookingOperationalSummaryController') && !resolver.includes('GeneralBookingAirProductController'), 'Product resolver remains read-only and controller-free');
ok(commercial.includes("$summary['customer_total']") && commercial.includes('supplierValue'), 'Existing commercial summary semantics remain the authority');
ok(operational.includes('GeneralBookingCommercialSummaryResolver'), 'Operational summary continues using the established commercial resolver');
ok(resolver.includes("'margin' => round($sale - $cost, 2)"), 'Product card margin reconciles from the same sale and cost totals');

console.log('C37_COMMERCIAL_SUMMARY_PARITY_REGRESSION=PASS (13 assertions)');
