import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function needsSync({ expectedAirTotal, lines, headerTotal }) {
  const airLines = lines.filter(line => line.product === 'Air');
  const currentAirTotal = airLines.reduce((sum, line) => sum + line.line_total, 0);
  const currentInvoiceLineTotal = lines.reduce((sum, line) => sum + line.line_total, 0);

  if (Math.abs(currentAirTotal - expectedAirTotal) > 0.01) return true;
  if (headerTotal != null && Math.abs(headerTotal - currentInvoiceLineTotal) > 0.01) return true;

  return false;
}

const multiProductLines = [
  { product: 'Air', fare: 'Adult', line_no: 1, line_total: 588000, currency_code: 'PKR' },
  { product: 'Hotel', line_no: 2, line_total: 8000, currency_code: 'PKR' },
  { product: 'Transport', line_no: 3, line_total: 200, currency_code: 'PKR' },
  { product: 'Visa', line_no: 4, line_total: 205000, currency_code: 'PKR' },
  { product: 'Air', fare: 'Child', line_no: 5, line_total: 130000, currency_code: 'PKR' },
];
const expectedAirTotal = 718000;
const currentAirTotal = multiProductLines.filter(line => line.product === 'Air').reduce((sum, line) => sum + line.line_total, 0);
const currentInvoiceLineTotal = multiProductLines.reduce((sum, line) => sum + line.line_total, 0);

equal(expectedAirTotal, 718000, 'authoritative Air source total is 718000');
equal(currentAirTotal, 718000, 'native Air invoice-line total is 718000');
equal(currentInvoiceLineTotal, 931200, 'all native invoice-line total is 931200');
ok(!needsSync({ expectedAirTotal, lines: multiProductLines, headerTotal: 931200 }), 'multi-product invoice is synchronized when both scopes match');
ok(!needsSync({ expectedAirTotal, lines: multiProductLines.filter(line => line.product === 'Air'), headerTotal: 718000 }), 'Air-only invoice remains supported by the same scope-safe logic');
ok(!needsSync({ expectedAirTotal, lines: [{ product: 'Air', line_total: 718000 }], headerTotal: 718000 }), 'aggregate-equivalent native Air grouping remains accepted');
ok(needsSync({ expectedAirTotal: 718001, lines: multiProductLines, headerTotal: 931200 }), 'a real Air aggregate mismatch still requires synchronization');
ok(needsSync({ expectedAirTotal, lines: multiProductLines, headerTotal: 931201 }), 'a real whole-invoice header mismatch still requires synchronization');
equal(multiProductLines.filter(line => line.product !== 'Air').map(line => line.line_total), [8000, 200, 205000], 'Hotel, Transport and Visa values remain untouched');
equal(multiProductLines.filter(line => line.product === 'Air').map(line => line.line_no), [1, 5], 'existing grouped Air lines remain collision-free and idempotent');

const service = read('app/Services/Sales/AirTicketInvoiceCommercialSyncService.php');
const guard = read('app/Http/Middleware/EnsureAirTicketCommercialIntegrityBeforeNativeWorkflow.php');
const profitability = read('app/Services/Sales/SalesInvoiceProductCommercialSummaryResolver.php');

ok(service.includes('$expectedAirTotal'), 'Air booking authority has an explicitly scoped name');
ok(service.includes('$currentAirTotal'), 'native Air aggregate has an explicitly scoped name');
ok(service.includes('$currentInvoiceLineTotal'), 'whole native invoice aggregate has an explicitly scoped name');
ok(service.includes('$headerTotal'), 'native invoice header remains validated');
ok(service.includes('$this->lineTotal($line),\n                    $currentInvoiceLines'), 'whole-invoice authority sums every native invoice line');
ok(service.includes('$currentAirTotal\n                - $expectedAirTotal'), 'Air source is compared only with native Air lines');
ok(service.includes('$headerTotal\n                - $currentInvoiceLineTotal'), 'header is compared only with the all-line aggregate');
ok(!service.includes('$headerTotal\n                - $expectedAirTotal'), 'whole invoice header is never compared with Air-only authority');
ok(service.includes("foreach (['currency_code', 'currency'] as $field)"), 'the authoritative currency correction remains present');
ok(service.includes('occupiedInvoiceLineNumbers(') && service.includes('max(array_keys($occupied)) + 1'), 'invoice-wide line-number allocation remains present');
ok(service.includes('preserveExistingInvoiceLineNumbers('), 'existing grouped Air line numbers remain preserved');
ok(service.includes('return DB::transaction(function () use ('), 'synchronization and integrity repair remain transactional');
ok(guard.includes('$after=$this->sync->snapshot($invoiceId);'), 'Submit retains post-sync verification');
ok(guard.indexOf("if ((bool)($after['needs_sync']??true))") < guard.lastIndexOf('return $next($request);'), 'native Submit continues only after post-sync integrity passes');
ok(guard.includes('The invoice remains Draft.'), 'a genuine mismatch remains blocked in Draft');
ok(!profitability.includes('currentInvoiceLineTotal'), 'profitability calculations remain separate from workflow integrity');
ok(service.includes('$this->synchronizeHeaderTotals('), 'customer receivable header remains sourced from the native all-line total');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
