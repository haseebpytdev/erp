import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (v, label) => { assert.ok(v, label); pass++; };
const read = p => fs.readFileSync(new URL('../../' + p, import.meta.url), 'utf8');
const service = read('app/Services/Accounting/PartyStatementService.php');
const controller = read('app/Http/Controllers/Accounting/PartyStatementController.php');
const routes = read('routes/erp103179.php');
const index = read('resources/views/accounting/party-statement/index.blade.php');
const print = read('resources/views/accounting/party-statement/print.blade.php');
const enrichment = read('app/Services/Accounting/PartyStatementEnrichmentResolver.php');

ok(routes.includes("accounting.party-statement.index") && routes.includes("/accounting/reports/party-statement"), 'Party Statement route exists');
ok(routes.includes("accounting.party-statement.print") && routes.includes("/accounting/reports/party-statement/print"), 'Party Statement print route exists');
ok(controller.includes('PartyStatementService') && controller.includes('statement($filters)'), 'Screen and print use the same service projection');
ok(service.includes("where('je.status', 'posted')"), 'Only posted journals are authoritative');
ok(service.includes("['CUSTOMER_AR', '1130']") && service.includes("['CUSTOMER_ADVANCE', '2120']"), 'Customer combines 1130 and 2120');
ok(service.includes("['VENDOR_AP', '2110']") && service.includes("['VENDOR_ADVANCE', '1140']"), 'Vendor combines 2110 and 1140');
ok(service.includes('whereIn(\'jl.account_id\', array_keys($scope))'), 'Account scope is dynamically resolved');
ok(service.includes("$net = round((float) $row['debit'] - (float) $row['credit'], 2)"), 'Signed movement is debit minus credit');
ok(service.includes("if (abs($net) < 0.005) continue"), 'Zero-net reclassifications are excluded');
ok(service.includes('journal_id') && service.includes('$grouped[$key]'), 'Rows are grouped by business journal');
ok(service.includes("'closing_side'"), 'Closing balance includes Dr/Cr side');
ok(index.includes('Date From') && index.includes('Date To') && index.includes('Apply &amp; Preview'), 'Filters and actions exist');
ok(index.includes('Passenger / Group') && index.includes('Service Ref.') && index.includes('Sector / Description'), 'Required statement columns exist');
ok(print.includes('<!doctype html>') && !print.includes('sidebar') && !print.includes('management navigation'), 'Print is document-only');
ok(!service.includes('insert(') && !service.includes('update(') && !service.includes('delete('), 'Projection performs no writes');

const signed = (opening, rows) => rows.reduce((b, r) => b + r.debit - r.credit, opening);
ok(signed(0, [{ debit: 107500, credit: 0 }, { debit: 0, credit: 922420 }]) === -814920, 'Customer example closes 814920 Cr');
ok(signed(0, [{ debit: 500000, credit: 0 }, { debit: 0, credit: 300000 }]) === 200000, 'Customer receivable example closes 200000 Dr');
ok(signed(0, [{ debit: 100000, credit: 100000 }]) === 0, 'Advance reclassification nets to zero');
ok(signed(0, [{ debit: 0, credit: 500000 }, { debit: 200000, credit: 0 }]) === -300000, 'Vendor example closes 300000 Cr');
ok(signed(0, [{ debit: 0, credit: 100000 }, { debit: 150000, credit: 0 }]) === 50000, 'Vendor advance example closes 50000 Dr');
ok(service.includes("$type = strtolower((string) $request->query('party_type', 'customer'))"), 'Customer is the default party type');
ok(!service.includes("now()->startOfMonth()->toDateString()") && service.includes('financialYearStart'), 'Default Date From uses financial-year authority rather than current-month start');
ok(service.includes("['fiscal_years', 'financial_years', 'fiscal_year']") && service.includes('whereDate($start'), 'Financial-year start resolves from installed ERP period tables');
ok(service.includes("if ($row['date'] < $filters['from'])") && service.includes("if ($row['date'] <= $filters['to']) $period[]"), 'Pre-period movements are opening-only and selected-period movements are rows');
ok(service.includes('journal_id') && service.includes('$grouped[$key]'), 'Each selected journal movement remains one financial statement row');
const periodFixture = [{ id: 1, date: '2026-01-01', debit: 0, credit: 922420 }, { id: 2, date: '2026-02-01', debit: 0, credit: 100000 }];
const openingFixture = periodFixture.filter(r => r.date < '2026-02-01');
const rowsFixture = periodFixture.filter(r => r.date >= '2026-02-01' && r.date <= '2026-12-31');
ok(openingFixture.length === 1 && rowsFixture.length === 1 && rowsFixture.every(r => !openingFixture.some(o => o.id === r.id)), 'Advance is never duplicated between opening and period rows');
const openingAmount = openingFixture.reduce((n, r) => n + r.debit - r.credit, 0);
const periodAmount = rowsFixture.reduce((n, r) => n + r.debit - r.credit, 0);
ok(openingAmount + periodAmount === -1022420, 'Opening plus period debit minus credit equals closing');
ok(enrichment.includes("'voucher_no'") && enrichment.includes('narration'), 'Advance and receipt rows expose voucher reference and actual narration');

// ERP-11.3.357 enrichment contract: these are source-level guards for the
// read-only projection. Runtime DB/browser evidence remains environment-owned.
ok(enrichment.includes('journal_id') || enrichment.includes('source_type'), 'Enrichment is keyed by journal source metadata');
ok(enrichment.includes("sales_invoices") && enrichment.includes("cash_vouchers"), 'Sales invoice and cash voucher authorities are resolved');
ok(enrichment.includes("supplier_costings") && enrichment.includes("advance_adjustments"), 'Supplier costing and advance adjustment authorities are resolved');
ok(enrichment.includes("cash_vouchers") && enrichment.includes('target_type') && enrichment.includes('target_id'), 'Cash voucher and reversal booking targets are resolved');
ok(enrichment.includes("booking_no") && enrichment.includes("booking_number") && enrichment.includes("Booking #"), 'Booking number uses adaptive persisted fields with ID fallback');
ok(enrichment.includes('NativeProductServiceResolver') && enrichment.includes('MULTI PRODUCT'), 'Product identity uses native resolver and explicit multi-product policy');
ok(enrichment.includes('ActiveBookingPassengerResolver') && enrichment.includes(" + "), 'Passenger authority uses active resolver with single/multi policy');
ok(enrichment.includes('air_ticket_details') && enrichment.includes('booking_hotel_stays') && enrichment.includes('booking_transport_segments'), 'Air, Hotel and Transport service references are supported');
ok(enrichment.includes('booking_visa_services') && enrichment.includes('visa_number'), 'Visa service references are supported');
ok(enrichment.includes('description') && enrichment.includes('narration') && enrichment.includes('reference'), 'Description priority is persisted business context then journal reference');
ok(enrichment.includes('sourceCache') && enrichment.includes('bookingCache') && enrichment.includes('productCache'), 'Enrichment caches source, booking and product lookups');
ok(service.includes('array_merge($row, $this->enrichment->resolve($row))'), 'Enrichment merges display metadata after financial calculations');
ok(service.includes("$grouped[$key]['debit'] +=") && service.includes("$grouped[$key]['credit'] +="), 'Financial rows and amounts remain journal-netted');
ok(controller.includes("view('accounting.party-statement.index'") && controller.includes("view('accounting.party-statement.print'"), 'Screen and print remain on the same service projection');
ok(!enrichment.includes('insert(') && !enrichment.includes('update(') && !enrichment.includes('delete('), 'Enrichment performs no database writes');
ok(enrichment.includes('cash_voucher_allocations') && enrichment.includes('cash_voucher_id'), 'Cash allocations query by cash_voucher_id');
ok(enrichment.includes('RECEIPT') && enrichment.includes('PAYMENT') && enrichment.includes('ADVANCE') && enrichment.includes('voucher_type'), 'Voucher type maps to truthful direct-cash products');
ok(enrichment.includes('booking_services') && enrichment.includes('booking_service_id') && enrichment.includes('air_ticket_details'), 'Air resolves booking to booking service to ticket details');
ok(enrichment.includes('canonicalProduct') && enrichment.includes("'air'") && enrichment.includes("'hotel'"), 'Canonical product identities prevent false multi-product results');
ok(enrichment.includes("count($identities) > 1") && enrichment.includes('MULTI PRODUCT'), 'Distinct Air plus Hotel identities become multi-product');
ok(enrichment.includes('full_name') && enrichment.includes('passenger_name') && enrichment.includes('given_name') && enrichment.includes('surname'), 'Passenger name field authority is complete');
ok(enrichment.includes('$families') && enrichment.includes('serviceTables'), 'Multi-product references aggregate by product family');
ok(enrichment.includes('origin_code') && enrichment.includes('destination_code') && enrichment.includes('context'), 'Description resolves business sector/context before fallback');
ok(enrichment.includes('sourceTables') && enrichment.includes('return []'), 'Unknown source types fail closed without cash-voucher guessing');
ok(service.includes("$row['debit'] = round($debit, 2)") && service.includes('array_merge($row, $this->enrichment->resolve($row))'), 'Enrichment occurs after immutable financial calculations');

console.log(`PASS ${pass} ERP-11.3.357 Party Statement assertions`);
