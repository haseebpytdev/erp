import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function groupedAirSync(lines, groups, templateCurrency) {
  const result = lines.map(line => ({ ...line }));
  groups.forEach((group, index) => {
    const payload = {
      product: 'Air',
      fare: group.fare,
      quantity: group.quantity,
      unit_price: group.rate,
      line_total: group.quantity * group.rate,
      currency_code: templateCurrency,
    };
    if (result[index]?.product === 'Air') result[index] = { ...result[index], ...payload };
    else result.splice(index, 0, payload);
  });
  return result.filter((line, index) => line.product !== 'Air' || index < groups.length);
}

const groups = [
  { fare: 'Adult', quantity: 4, rate: 147000 },
  { fare: 'Child', quantity: 1, rate: 130000 },
];
const initial = [
  { product: 'Air', fare: 'Adult', currency_code: 'AED' },
  { product: 'Hotel', line_total: 8000, currency_code: 'AED' },
  { product: 'Transport', line_total: 200, currency_code: 'AED' },
  { product: 'Visa', line_total: 205000, currency_code: 'AED' },
];
const first = groupedAirSync(initial, groups, 'AED');
const second = groupedAirSync(first, groups, 'AED');

equal(first.filter(line => line.product === 'Air').map(line => line.currency_code), ['AED', 'AED'], 'Adult and Child inherit the same template currency');
equal(first.filter(line => line.product === 'Air').reduce((sum, line) => sum + line.line_total, 0), 718000, 'Air grouped total remains 718000');
equal(first.reduce((sum, line) => sum + Number(line.line_total || 0), 0), 931200, 'whole reference invoice total remains 931200');
equal(first.filter(line => line.product !== 'Air'), initial.filter(line => line.product !== 'Air'), 'non-Air lines remain untouched');
equal(second, first, 'repeated grouped synchronization is idempotent');

const service = read('app/Services/Sales/AirTicketInvoiceCommercialSyncService.php');
const guard = read('app/Http/Middleware/EnsureAirTicketCommercialIntegrityBeforeNativeWorkflow.php');
const profitability = read('app/Services/Sales/SalesInvoiceProductCommercialSummaryResolver.php');

ok(service.includes('preserveNativeStructuralFields('), 'Air sync applies native structural preservation before line save');
ok(service.indexOf('preserveNativeStructuralFields(') < service.indexOf('$line->saveQuietly();'), 'required structure is resolved before the first grouped line save');
ok(service.includes("foreach (['currency_code', 'currency'] as $field)"), 'currency aliases use existing native line/header authority');
ok(service.includes('foreach ([$template, $invoice] as $authority)'), 'template currency precedes invoice header fallback');
ok(!service.includes("'currency_code' => 'PKR'") && !service.includes("'currency_code', 'PKR'"), 'Air line currency is not hard-coded to PKR');
ok(service.includes('Sales Invoice line currency could not be resolved from the native invoice.'), 'missing native currency raises controlled validation');
ok(service.includes('columnMetadata($lineTable)') && service.includes('columnCanBeOmitted('), 'all physical non-null/no-default line fields are audited');
ok(service.includes('Required native Sales Invoice line field(s) could not be resolved from the template or invoice:'), 'other unresolved required fields stop before raw SQL');
ok(service.includes("['id', 'created_at', 'updated_at', 'deleted_at']"), 'generated and framework-maintained fields are safely omitted from manual requirements');
ok(service.includes("$airLines->get($index)") && service.includes('! in_array($lineId, $usedLineIds, true)'), 'group synchronization reuses expected lines and removes only stale Air lines');
ok(service.includes('Non-Air service lines remain untouched.'), 'stale cleanup remains scoped to Air lines');
ok(service.includes('return DB::transaction(function () use ('), 'commercial synchronization remains transactional');
ok(guard.indexOf('$this->sync->sync(') < guard.lastIndexOf('return $next($request);'), 'Submit continues only after successful pre-sync');
ok(guard.includes("if ($workflow==='submit'") && guard.includes('commercial synchronization failed before Submit for Approval'), 'failed pre-sync is blocked before native workflow transition');
ok(guard.includes('The invoice remains Draft.'), 'failed synchronization explicitly preserves Draft workflow state');
ok(!profitability.includes('preserveNativeStructuralFields'), 'profitability resolver remains outside synchronization behavior');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
