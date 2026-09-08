import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function verifyTotals({ expected, headerColumn = 'grand_total', headerTotal, lineCount, lineTotal, minimumLines = 4 }) {
  const mismatch = () => [
    'Sales Invoice total mismatch:',
    `expected=${expected.toFixed(2)};`,
    `header_column=${headerColumn};`,
    `header_total=${headerTotal.toFixed(2)};`,
    `line_count=${lineCount};`,
    `line_total=${lineTotal.toFixed(2)}`,
  ].join(' ');
  if (Math.abs(headerTotal - expected) >= 0.01) throw new Error(mismatch());
  if (lineCount < Math.max(1, minimumLines)) throw new Error('required product lines');
  if (Math.abs(lineTotal - expected) >= 0.01) throw new Error(mismatch());
  return { headerTotal, lineCount, lineTotal };
}

assert.throws(
  () => verifyTotals({ expected: 931200, headerTotal: 726200, lineCount: 4, lineTotal: 931200 }),
  error => error.message.includes('expected=931200.00;')
    && error.message.includes('header_total=726200.00;')
    && error.message.includes('line_count=4;')
    && error.message.includes('line_total=931200.00'),
);
pass++;
assert.throws(
  () => verifyTotals({ expected: 931200, headerTotal: 726200, lineCount: 3, lineTotal: 726200 }),
  error => error.message.includes('line_count=3;') && error.message.includes('line_total=726200.00'),
);
pass++;
equal(
  verifyTotals({ expected: 931200, headerTotal: 931200, lineCount: 4, lineTotal: 931200 }),
  { headerTotal: 931200, lineCount: 4, lineTotal: 931200 },
  'matching header and lines still pass unchanged validation',
);

const verifier = read('app/Services/Operations/NativeSalesInvoiceCreationVerifier.php');
const diagnostic = read('app/Http/Controllers/System/AirLinkDbDiagnosticController.php');
const bridge = read('app/Services/Operations/NativeSalesInvoiceRuntimeBridge.php');

ok(verifier.indexOf('$lineSummary=$this->lineSummary($table,$invoiceId)') < verifier.indexOf('if(!$headerAmountColumn||!$this->same('), 'line evidence is collected before header mismatch failure');
ok(verifier.includes(".'expected='.number_format($expected,2,'.','').'; '"), 'mismatch message includes expected total');
ok(verifier.includes(".'header_total='.number_format($headerTotal,2,'.','').'; '"), 'mismatch message includes actual header total');
ok(verifier.includes(".'line_count='.(int)$line['count'].'; '"), 'mismatch message includes line count');
ok(verifier.includes(".'line_total='.number_format((float)$line['total'],2,'.','')"), 'mismatch message includes calculated line total');
ok(verifier.includes("'amount_column'=>$amount"), 'line summary identifies its amount column');
ok(verifier.includes("'quantity_column'=>$quantity"), 'line summary identifies its quantity column');
ok(verifier.includes("'rate_column'=>$rate"), 'line summary identifies its rate column');
ok(verifier.includes("'table'=>$table"), 'line summary identifies its selected table');
ok(verifier.includes('if(!$headerAmountColumn||!$this->same('), 'header mismatch still fails verification');
ok(verifier.includes('if(!$this->same($lineTotal,$expectedTotal))$this->fail('), 'line mismatch still fails verification');
ok(bridge.includes('Validation runs before DB::transaction commits'), 'verification failure remains transaction rollback authority');

ok(diagnostic.includes("'PRE_NATIVE_SERVICE_COMMERCIAL_AUDIT' => $preNativeAudit"), 'Super-Admin endpoint exposes the pre-native commercial audit');
ok(diagnostic.includes("'ROLLBACK_ONLY' => true"), 'pre-native audit declares rollback-only behavior');
ok(diagnostic.includes("'NATIVE_INVOICE_CREATOR_CALLED' => false"), 'pre-native audit reports that no invoice creator is called');
ok(diagnostic.includes('DB::beginTransaction()'), 'pre-native audit opens an isolated transaction');
ok(diagnostic.includes('while (DB::transactionLevel() > $startingLevel)') && diagnostic.includes('DB::rollBack()'), 'pre-native audit always rolls back to its starting transaction level');
ok(!diagnostic.includes('DB::commit()'), 'diagnostic has no transaction commit path');
ok(diagnostic.includes('$this->visaServices->synchronize($booking)') && diagnostic.includes('$this->passengerLinks->reconcileDeterministicServicesForInvoice($booking)'), 'audit observes all temporary service reconciliation before reading');
ok(diagnostic.includes("'ACTIVE_SERVICE_COUNT'"), 'audit returns active service count');
ok(diagnostic.includes("'SUM_LINE_TOTAL'"), 'audit returns native line-total sum');
ok(diagnostic.includes("'SUM_QUANTITY_X_UNIT_PRICE'"), 'audit returns native quantity-times-rate sum');
for (const field of ['id', 'product_service_id', 'description', 'quantity', 'unit_price', 'line_total', 'currency_code', 'passenger_link_mode_snapshot', 'pricing_basis_snapshot', 'generic_linked_passenger_count']) {
  ok(diagnostic.includes(`'${field}' =>`), `audit returns service field ${field}`);
}
ok(!diagnostic.includes('NativeBookingSalesInvoiceCreator'), 'diagnostic has no native invoice creator dependency');
ok(!diagnostic.includes('$this->creator->'), 'diagnostic never invokes an invoice creator');

ok(diagnostic.includes("'HOST_SALES_INVOICE_METHOD_FILE'"), 'diagnostic exposes host method file');
ok(diagnostic.includes("'HOST_SALES_INVOICE_METHOD_START_LINE'"), 'diagnostic exposes host method start line');
ok(diagnostic.includes("'HOST_SALES_INVOICE_METHOD_END_LINE'"), 'diagnostic exposes host method end line');
ok(diagnostic.includes("'HOST_SALES_INVOICE_METHOD_SOURCE'"), 'diagnostic exposes full host method source');
ok(diagnostic.includes('for ($line = $start; $line <= $end; $line++)'), 'host source capture spans the full reflected method');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
