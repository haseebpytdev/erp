import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const middleware = read('app/Http/Middleware/PresentSalesInvoicePrintV2.php');
const routes = read('routes/erp103179.php');

ok(routes.includes('PresentSalesInvoicePrintV2::class'), 'Print middleware is attached through RouteMatched');
ok(routes.includes("preg_match('#^sales/invoices/\\{[^}]+\\}/print$#"), 'Print hook is narrowly scoped to the native print URI');
ok(routes.includes("! in_array('GET', $route->methods(), true)"), 'Print hook is GET-only');
ok(!routes.includes("Route::get('/sales/invoices/{invoice}/print'"), 'No competing print route is registered');

ok(middleware.includes('class="sheet"'), 'Native paper boundary is required before transformation');
ok(middleware.includes('data-et-sales-invoice-print-v2="ERP-11.3.374"'), 'Transformation is idempotently marked');
ok(middleware.includes("$response->getStatusCode() >= 400"), 'Error responses are not transformed');
ok(middleware.includes('content-type'), 'Only HTML responses are transformed');
ok(middleware.includes("$response->headers->remove('Content-Length')"), 'Content-Length is cleared after transformation');
ok(middleware.includes('class="et-si-print-370"'), 'Presentation uses an invoice-scoped body class');

for (const selector of [
    '.sheet', '.actions', '.doc-header', '.logo-wrap', '.brand-name', '.brand-legal',
    '.brand-contact', '.doc-meta', '.info-grid', '.invoice-table', '.totals', '.notes', '.foot'
]) {
    ok(middleware.includes(selector), `Captured native selector is addressed: ${selector}`);
}

ok(middleware.includes('@page{size:A4 portrait;margin:10mm}'), 'A4 portrait page rule is present');
ok(middleware.includes('@media print{'), 'Print media rules are scoped');
ok(middleware.includes('.actions{display:none}'), 'Print actions are hidden in print');
ok(middleware.includes('.sheet{width:auto;min-height:0;margin:0;box-shadow:none'), 'Screen shadow and fixed paper sizing are removed in print');
ok(middleware.includes('thead{display:table-header-group}'), 'Table headers repeat across pages');
ok(middleware.includes('break-inside:avoid;page-break-inside:avoid'), 'Rows and document blocks avoid page breaks');

ok(!middleware.includes('DB::'), 'Middleware does not query the database');
ok(!middleware.includes('number_format('), 'Middleware does not recalculate financial values');
ok(!middleware.includes('grand_total'), 'Middleware does not rebuild financial totals');
ok(!middleware.includes('journal_entries'), 'Middleware does not touch journal authority');
ok(!routes.includes('PresentSalesInvoicePrintV2::class)->'), 'Print middleware is not used as a broad global route middleware');

ok(middleware.includes('verticalizePassengerCells'), 'Passenger presentation uses a dedicated print-layer normalizer');
ok(middleware.includes("(?:pax|passenger)(?:-name)?"), 'Normalizer targets only passenger/Pax cells');
ok(middleware.includes("preg_split('/\\s*(?:,|\\R)\\s*/u'"), 'Supported passenger separators are normalized deterministically');
ok(middleware.includes('htmlspecialchars($name, ENT_QUOTES, \'UTF-8\')'), 'Passenger names are HTML escaped');
ok(middleware.includes("$index + 1"), 'Passenger numbering preserves source order');
ok(middleware.includes('<div class="pax-name">'), 'Multiple passengers render as separate visual lines');
ok(middleware.includes('count($names) <= 1'), 'Single passenger cells remain unchanged');
ok(middleware.includes('break-inside:avoid;page-break-inside:avoid'), 'Passenger-containing invoice rows remain print-safe');
const passengerFixture = ['JAVED IQBAL', 'ABIDA PARVEEN', 'RUMAN UN NISA', 'HAMID RAZA'];
const passengerMarkup = passengerFixture.map((name, index) => `<div class="pax-name">${index + 1}. ${name}</div>`).join('');
ok(passengerMarkup.indexOf('1. JAVED IQBAL') < passengerMarkup.indexOf('2. ABIDA PARVEEN') && passengerMarkup.indexOf('2. ABIDA PARVEEN') < passengerMarkup.indexOf('3. RUMAN UN NISA') && passengerMarkup.indexOf('3. RUMAN UN NISA') < passengerMarkup.indexOf('4. HAMID RAZA'), 'Passenger order remains stable');
ok(!passengerMarkup.includes(', '), 'Passenger fixture is not comma-packed');
ok(passengerMarkup.split('<div class="pax-name">').length - 1 === 4, 'One visual line is emitted per passenger');
ok(middleware.includes('invoiceDataRowCount($table)') && middleware.includes('$cells[$descriptionIndex]'), 'One commercial invoice row remains the accounting presentation unit');
ok(middleware.includes('.grand-value') && middleware.includes('grand'), 'Grand total presentation remains untouched');

console.log(`PASS ${pass} ERP-11.3.370 Sales Invoice Print assertions`);
