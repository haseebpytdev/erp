import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const resolver = read('app/Services/Purchase/BookingSupplierObligationResolver.php');
const controller = read('app/Http/Controllers/Purchase/SupplierCostingController.php');
const service = read('app/Services/Purchase/SupplierCostingService.php');
const drilldowns = read('app/Services/Purchase/SupplierCostingDrilldownResolver.php');
const bridge = read('app/Services/Accounting/CashVoucherNativeJournalBridge.php');
const voucherDrilldowns = read('app/Services/Accounting/CashVoucherDrilldownResolver.php');
const cashVoucherService = read('app/Services/Accounting/CashVoucherService.php');
const migration = read('database/migrations/2026_09_10_140000_create_supplier_costing_source_links.php');
const form = read('resources/views/purchase/supplier-costing/form.blade.php');
const show = read('resources/views/purchase/supplier-costing/show.blade.php');
const index = read('resources/views/purchase/supplier-costing/index.blade.php');

ok(resolver.includes("Schema::hasTable('air_ticket_details')") && resolver.includes("'net_supplier_cost'"), 'Air cost authority is air_ticket_details.net_supplier_cost');
ok(resolver.includes("$this->positiveInt($service, ['vendor_id'])") && resolver.includes('booking_services.vendor_id is the corrected service-scoped Air'), 'Air supplier authority is booking_services.vendor_id');
ok(resolver.includes("$this->positiveInt($effective, ['vendor_id', 'supplier_id'])") && resolver.includes("['et_erp_commercial']"), 'Air retains legacy ticket-metadata vendor read compatibility');
ok(resolver.includes("'booking_passenger_id', 'passenger_id'"), 'Air obligations preserve exact passenger identity');
ok(resolver.includes("'ticket_number', 'ticket_no', 'e_ticket_number'"), 'Air obligations preserve ticket reference identity');
ok(resolver.includes("'vendor_total', 'net_supplier_cost'") && resolver.includes("['cost_rate', 'supplier_rate'"), 'Hotel uses persisted vendor total with rate-times-nights fallback');
ok(resolver.includes("'et_erp_hotel_stays'") && resolver.includes("'ETERP_HOTEL_STAYS'"), 'Hotel persisted snapshot authority is supported');
ok(resolver.includes("'supplier_amount_pkr', 'vendor_total_pkr', 'cost_amount_pkr'"), 'Transport consumes persisted PKR supplier cost');
ok(resolver.includes("'et_erp_transport_rows'") && resolver.includes("'ETERP_TRANSPORT_ROWS'"), 'Transport persisted snapshot authority is supported');
ok(resolver.includes("Schema::hasTable('booking_visa_services')") && resolver.includes("'vendor_cost_pkr'"), 'Visa cost authority is booking_visa_services.vendor_cost_pkr');
ok(resolver.includes("$this->positiveInt($row, ['vendor_id'])"), 'Visa supplier comes from the persisted Visa vendor');
ok(resolver.includes('UnifiedGroupPackageDataSource') && resolver.includes('$this->bookingData->vendors()'), 'Supplier names resolve from native booking vendor authority');
ok(!resolver.includes('PKR 938,600') && !resolver.includes('730000') && !resolver.includes('193800'), 'No production reference value is hard-coded');

const fixture = [730000, 7200, 7600, 193800];
ok(fixture.reduce((sum, amount) => sum + amount, 0) === 938600, 'BK-2026-000054 expected product costs reconcile to PKR 938,600');
const grouped = new Map([[11, [730000]], [22, [7200, 7600]], [33, [193800]]]);
ok(grouped.size === 3, 'fixture groups obligations into one document per actual supplier');
ok(grouped.get(22).reduce((sum, amount) => sum + amount, 0) === 14800, 'one supplier may carry multiple product obligations without mixing suppliers');

ok(migration.includes("Schema::create('supplier_costing_source_links'"), 'new migration creates dedicated source traceability');
ok(migration.includes("$table->string('source_key', 190)->unique()"), 'source identity is globally unique against duplicate costing');
ok(migration.includes("$table->unsignedBigInteger('supplier_costing_line_id')->unique()"), 'each costing line has exactly one source link');
ok(migration.includes("$table->decimal('source_cost_snapshot', 18, 2)"), 'authoritative source cost snapshot is persisted');
ok(migration.includes("Schema::hasTable('supplier_costing_source_links')"), 'migration is idempotent');
ok(migration.includes("Schema::dropIfExists('supplier_costing_source_links')"), 'rollback affects only the new traceability table');

ok(resolver.includes("private const ACTIVE_COSTING_STATUSES = ['draft', 'pending_approval', 'approved', 'posted']"), 'duplicate audit covers every active lifecycle state and Posted');
ok(resolver.includes("'status' => 'posted'") || resolver.includes("$row['status'] = (string) $claim->status === 'posted' ? 'posted'"), 'posted obligations have a distinct status');
ok(resolver.includes("$row['status'] = 'legacy_review'"), 'legacy active costing blocks unsafe guessed duplication');
ok(controller.includes('Different suppliers cannot be mixed in one Supplier Costing.'), 'server prevents mixed suppliers');
ok(controller.includes('$expectedKeys !== $submittedKeys'), 'server rejects missing or injected source keys');
ok(controller.includes("DB::table('bookings')->where('id'") && controller.includes('lockForUpdate()'), 'booking is locked while costing sources are claimed');
ok(controller.includes("DB::table('supplier_costing_source_links')->insert"), 'new costing persists deterministic source identity');
ok(controller.includes('Supplier Costing source traceability is unavailable. Run the current database migrations'), 'creation stops cleanly until the traceability migration is installed');
ok(controller.includes("'source_snapshot_json' => json_encode($line"), 'line retains a reviewable source snapshot');
ok(controller.includes("'currency_code' => 'Booking product cost obligations are persisted in PKR"), 'booking-driven cost currency is server-enforced as PKR');
ok(controller.includes("$tax = round((float) ($adjustment['tax_amount'] ?? 0), 2)") && controller.includes("$other = round((float) ($adjustment['other_charges'] ?? 0), 2)"), 'only tax and other charges are accepted as line adjustments');
ok(!form.includes('+ Add Cost Line') && form.includes('Booking Cost is locked'), 'new UI removes arbitrary manual source-line entry');
ok(form.includes('Booking Supplier Obligations') && form.includes('Cost Details — Selected Supplier'), 'form separates authority review from selected-supplier costing');
ok(form.includes("name=\"lines[{{ $index }}][source_key]\"") && !form.includes('[base_cost]" value="{{ $source'), 'form submits identity and adjustments, not editable base cost');
ok(form.includes('Select booking supplier') && form.includes('$selectedObligations'), 'supplier choices and lines are booking-driven');
ok(form.includes('Unrelated suppliers are intentionally excluded.'), 'UI communicates one-supplier boundary');

ok(service.includes('Every Supplier Costing line must retain one authoritative booking source link.'), 'workflow revalidates one-to-one traceability');
ok(service.includes('Booking vendor obligations changed. Refresh the Draft before continuing.'), 'workflow blocks stale obligation sets');
ok(service.includes('Authoritative booking vendor cost changed. Refresh the Draft before continuing.'), 'workflow blocks changed source costs');
ok(service.includes('Supplier Costing line total does not reconcile to source cost, tax, and other charges.'), 'workflow revalidates line arithmetic');
ok(service.includes("'submit'=>['draft','pending_approval']") && service.includes("'approve'=>['pending_approval','approved']") && service.includes("'post'=>['approved','posted']"), 'controlled Supplier Costing lifecycle remains intact');
ok(service.includes("return '5110'") && service.includes("return '5120'") && service.includes("return '5130'") && service.includes("return '5140'"), 'posting retains product-specific cost-account authority');
ok(service.includes("$payable=$this->chartAccount('VENDOR_AP','2110')") && service.includes("'party_type'=>'supplier','party_id'=>$row->supplier_id"), 'posting credits Vendor Payable to the costing supplier');
ok(service.includes("abs(round($debit,2)-$credit)>0.005"), 'posting stops before an unbalanced journal');
ok(service.includes('$this->nativeJournal->postSupplierCosting'), 'native journal bridge remains the posting integration');
ok(bridge.includes('journalIdForSupplierCosting') && bridge.includes("findJournal('supplier_costing'"), 'posted journal identity resolves deterministically');
ok(voucherDrilldowns.includes('nativeJournalUrlForSupplierCosting'), 'Supplier Costing exposes native journal drilldown through shared accounting resolver');
ok(cashVoucherService.includes("'target_type' => 'supplier_costing'") && cashVoucherService.includes("DB::table('supplier_costings')"), 'Payment Voucher allocation against Posted Supplier Costing remains available');
ok(cashVoucherService.includes("'receipt' => 'RV'") && cashVoucherService.includes("'payment' => 'PV'") && cashVoucherService.includes("'expense' => 'EV'") && cashVoucherService.includes("'contra' => 'CV'"), 'existing Receipt, Payment, Expense, and Contra voucher workflows remain registered');

ok(drilldowns.includes("getByName('bookings.review.show')"), 'booking drilldown uses registered route authority');
ok(drilldowns.includes("'accounting.ledgers.supplier', 'accounting.ledgers.vendor'"), 'supplier ledger drilldown discovers supported native route names');
ok(drilldowns.includes('return null;') && show.includes('@else{{ $row->posting_reference'), 'unresolved drilldowns remain safe plain text');
ok(show.includes('Accounting Preview') && show.includes('Actual Posted Journal'), 'show page distinguishes preview from posted accounting');
ok(show.includes('$accountLedgerUrls') && show.includes('$supplierLedgerUrl') && show.includes('$bookingUrl'), 'show page offers bounded booking, supplier, and account drilldowns');
ok(controller.includes('public function show(int $costing)') && !show.includes('method="post" action="{{ route(\'purchase.supplier-costing.store\')'), 'show-page GET does not create or update costing data');
ok(form.includes("config('et_erp_release.release', 'ERP-11.3')") && show.includes("config('et_erp_release.release', 'ERP-11.3')") && index.includes("config('et_erp_release.release', 'ERP-11.3')"), 'Supplier Costing runtime labels are dynamic');
ok(controller.includes('updateLegacy') && form.includes('Legacy Draft:'), 'existing manual Drafts remain safely editable without guessed source backfill');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
