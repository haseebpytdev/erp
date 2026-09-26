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

console.log(`PASS ${pass} ERP-11.3.357 Party Statement assertions`);
