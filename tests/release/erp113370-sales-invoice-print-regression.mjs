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
ok(middleware.includes('data-et-sales-invoice-print-v2="ERP-11.3.373"'), 'Transformation is idempotently marked');
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

console.log(`PASS ${pass} ERP-11.3.370 Sales Invoice Print assertions`);
