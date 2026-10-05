import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');
const ok = (condition, label) => { if (!condition) throw new Error(label); console.log(`PASS ${label}`); };

const resolver = read('app/Services/Operations/BookingProductSummaryResolver.php');
const commercial = read('app/Services/Operations/GeneralBookingCommercialSummaryResolver.php');
const operational = read('app/Http/Controllers/Operations/GeneralBookingOperationalSummaryController.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const progressive = read('public/erp11390/general-progressive-step1.js');
const release = read('config/et_erp_release.php');

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
ok(!presenter.includes('>Open Products</a>'), 'Main Booking no longer exposes Open Products');
ok(!presenter.includes('$reviewEntry') && !presenter.includes("preg_replace('/</body>/i'"), 'Review Booking is not injected as a body-level action');
ok(presenter.includes('et-c36-product-summary-actions') && presenter.includes("url('/operations/bookings/'.$bookingId.'/review')"), 'Review Booking remains in the Products footer');
ok(resolver.includes('transportSnapshotSummary') && resolver.includes("'transports'"), 'Transport snapshot carrier fallback is implemented');
ok(resolver.includes("'sale_pkr','customer_total") && resolver.includes("'vendor_cost_pkr','supplier_total") && resolver.includes("'margin_pkr'"), 'Visa native commercial columns are supported');
ok(progressive.includes('data-et-server-booking-lock="1"') && progressive.includes('insertAdjacentElement') && progressive.includes('etgp-toolbar'), 'Existing lock notice is adopted into etgp-step1 near the toolbar');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C37'") && release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'C37 asset revision advances without changing application version');

console.log('C37_CORRECTIVE1_REGRESSION=PASS (20 assertions)');
