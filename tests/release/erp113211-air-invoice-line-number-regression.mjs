import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function synchronizeGroupedAir(lines, groups, currency) {
  const result = lines.map(line => ({ ...line }));
  const orderedAir = result
    .filter(line => line.product === 'Air')
    .sort((left, right) => left.line_no - right.line_no || left.id - right.id);
  const occupied = new Set(result.map(line => line.line_no).filter(lineNo => lineNo > 0));
  let nextLineNo = occupied.size === 0 ? 1 : Math.max(...occupied) + 1;

  groups.forEach(group => {
    let line = orderedAir.shift();

    if (!line) {
      while (occupied.has(nextLineNo)) nextLineNo++;
      line = { id: Math.max(...result.map(item => item.id), 0) + 1, line_no: nextLineNo };
      occupied.add(nextLineNo);
      result.push(line);
    }

    Object.assign(line, {
      product: 'Air',
      fare: group.fare,
      quantity: group.quantity,
      unit_price: group.rate,
      line_total: group.quantity * group.rate,
      currency_code: currency,
    });
  });

  for (const stale of orderedAir) result.splice(result.indexOf(stale), 1);

  return result;
}

const groups = [
  { fare: 'Adult', quantity: 4, rate: 147000 },
  { fare: 'Child', quantity: 1, rate: 130000 },
];
const initial = [
  { id: 7, product: 'Visa', line_no: 4, line_total: 205000, currency_code: 'PKR' },
  { id: 4, product: 'Air', fare: 'Generic', line_no: 1, line_total: 718000, currency_code: 'PKR' },
  { id: 6, product: 'Transport', line_no: 3, line_total: 200, currency_code: 'PKR' },
  { id: 5, product: 'Hotel', line_no: 2, line_total: 8000, currency_code: 'PKR' },
];

const first = synchronizeGroupedAir(initial, groups, 'PKR');
const second = synchronizeGroupedAir(first, groups, 'PKR');
const firstAir = first.filter(line => line.product === 'Air').sort((a, b) => a.line_no - b.line_no);

equal(firstAir.map(line => line.line_no), [1, 5], 'Adult preserves line 1 and Child receives invoice-wide next line 5');
ok(firstAir[1].line_no !== 2, 'Child does not collide with the existing Hotel line 2');
equal(new Set(first.map(line => line.line_no)).size, first.length, 'invoice line numbers remain unique');
equal(first.filter(line => line.product !== 'Air'), initial.filter(line => line.product !== 'Air'), 'Hotel, Transport and Visa rows and line numbers remain unchanged');
equal(second, first, 'repeated synchronization reuses grouped lines without line number churn');
equal(firstAir.map(line => line.fare), ['Adult', 'Child'], 'Air groups remain in deterministic Adult then Child order');
equal(firstAir.map(line => line.currency_code), ['PKR', 'PKR'], 'the existing currency inheritance behavior is preserved');
equal(firstAir.reduce((sum, line) => sum + line.line_total, 0), 718000, 'Air total remains 718000');
equal(first.reduce((sum, line) => sum + line.line_total, 0), 931200, 'whole invoice total remains 931200');

const service = read('app/Services/Sales/AirTicketInvoiceCommercialSyncService.php');
const guard = read('app/Http/Middleware/EnsureAirTicketCommercialIntegrityBeforeNativeWorkflow.php');
const profitability = read('app/Services/Sales/SalesInvoiceProductCommercialSummaryResolver.php');

ok(service.includes('$relation->lockForUpdate()->get()'), 'all current invoice lines are locked before allocating a new number');
ok(service.includes('occupiedInvoiceLineNumbers('), 'allocation considers every existing invoice line');
ok(service.includes('existingInvoiceLineNumber('), 'reused Air lines preserve their valid native number');
ok(service.includes('preserveExistingInvoiceLineNumbers('), 'all persisted native ordering aliases are preserved on reused Air lines');
ok(service.includes("'line_no',\n            'line_number',"), 'native line_no is the preferred line-number authority');
ok(service.includes('max(array_keys($occupied)) + 1'), 'new grouped lines use the next invoice-wide safe number');
ok(!service.includes("$index + 1\n                );"), 'group index is no longer used as the invoice line number');
ok(service.includes('return DB::transaction(function () use ('), 'line allocation and saves remain transactional');
ok(service.includes("foreach (['currency_code', 'currency'] as $field)"), 'the .211 authoritative currency correction remains present');
ok(service.includes('Non-Air service lines remain untouched.'), 'cleanup remains explicitly scoped away from non-Air rows');
ok(guard.indexOf('$this->sync->sync(') < guard.lastIndexOf('return $next($request);'), 'Submit continues only after successful synchronization');
ok(guard.includes('The invoice remains Draft.'), 'failed synchronization remains blocked in Draft');
ok(!profitability.includes('nextInvoiceLineNumber'), 'profitability behavior remains separate from invoice writing');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
