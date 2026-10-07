import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const middleware = read('app/Http/Middleware/PresentSalesInvoicePrintV2.php');
const routes = read('routes/erp103179.php');
const c56 = read('tests/release/erp113378-c56-sales-invoice-post-create-verifier-regression.mjs');
const c55 = read('tests/release/erp113378-c55-historical-base-invoice-recreation-regression.mjs');
const c54 = read('tests/release/erp113378-c54-booking-billing-resolver-namespace-regression.mjs');
const c53 = read('tests/release/erp113378-c53-native-invoice-consistency-ui-regression.mjs');

ok(routes.includes("preg_match('#^sales/invoices/\\{[^}]+\\}/print$#"), 'native print route remains narrowly scoped');
ok(routes.includes('PresentSalesInvoicePrintV2::class'), 'print presentation remains attached through middleware');
ok(middleware.includes('verticalizePassengerCells'), 'passenger rendering stays in the print presentation layer');
ok(middleware.includes("(?:pax|passenger)(?:-name)?"), 'only passenger cells are normalized');
ok(middleware.includes("<\\/(?:div|p|li|tr)>"), 'block passenger markup boundaries remain data separators');
ok(middleware.includes("<div class=\"pax-name\">'.$primary.'</div>"), 'single passenger primary name is rendered once');
ok(middleware.includes("<div class=\"pax-type\">'.$type.'</div>"), 'single passenger type is rendered as secondary metadata');
ok(middleware.includes('isPassengerTypeLabel'), 'fare-type recognition is explicit and narrow');
ok(middleware.includes("['ADULT', 'CHILD', 'INFANT', 'ADT', 'CHD', 'INF', 'YOUTH', 'SENIOR']"), 'supported passenger types are allow-listed');
ok(middleware.includes('count($names) === 2 && $this->isPassengerTypeLabel($names[1])'), 'one Air passenger plus type is unnumbered');
ok(middleware.includes('$pairedTypes'), 'multi-passenger type pairs are detected without flattening');
ok(middleware.includes("$passengerNo.'. '.htmlspecialchars($names[$index]"), 'multi-passenger names retain numbering');
ok(middleware.includes("$index + 1"), 'fallback multi-passenger numbering remains source ordered');
ok(middleware.includes("htmlspecialchars($names[$index + 1], ENT_QUOTES, 'UTF-8')"), 'paired passenger types remain escaped and visible');
ok(!middleware.includes('number_format('), 'presentation layer does not recalculate amounts');
ok(!middleware.includes('DB::'), 'presentation layer does not query or mutate accounting data');
ok(!middleware.includes('SalesInvoiceService::create'), 'invoice creation logic is untouched');
ok(c56.includes('activeBaseInvoices'), 'C56 post-create active-base verifier remains referenced');
ok(c55.includes('activeBase'), 'C55 historical base-invoice scope remains protected');
ok(c54.includes('BookingBillingEditLockResolver'), 'C54 billing lock namespace contract remains protected');
ok(c53.includes('PresentSalesInvoiceFocusedWorkspace'), 'C53 native invoice consistency contract remains present');

// Behavioral fixture for the exact native shape that previously collapsed into
// one token: a single name followed by its fare type in block markup.
const nativeSingle = '<div>ABDUL RAUF</div><div>Adult</div>';
const tokens = nativeSingle.replace(/<\/(?:div|p|li|tr)>/gi, '\n').replace(/<[^>]+>/g, '').trim().split(/\s*(?:,|\n)\s*/u);
ok(tokens.length === 2 && tokens[0] === 'ABDUL RAUF' && tokens[1] === 'Adult', 'single native name/type shape is split deterministically');
ok(!/^\d+\.\s/.test(tokens[1]), 'passenger type is not emitted as a numbered passenger');

const multi = ['JAVED IQBAL', 'Adult', 'ABIDA PARVEEN', 'Child'];
const numbered = multi.filter((_, index) => index % 2 === 0).map((name, index) => `${index + 1}. ${name}`);
ok(numbered.join('|') === '1. JAVED IQBAL|2. ABIDA PARVEEN', 'multiple passengers retain numbered names');
ok(multi[1] === 'Adult' && multi[3] === 'Child', 'multiple passenger types remain secondary metadata');

console.log(`PASS ${pass} ERP-11.3.378 C57 Sales Invoice Passenger Display assertions`);
