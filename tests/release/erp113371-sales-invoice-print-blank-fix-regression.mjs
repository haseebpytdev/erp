import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const middleware = read('app/Http/Middleware/PresentSalesInvoicePrintV2.php');
const sourceStyleOpen = '<style id="et-sales-invoice-print-v2-372">';
const sourceStyleClose = '</style>';

ok(middleware.includes(sourceStyleOpen), '371 style opening tag is present');
ok(middleware.includes(sourceStyleClose), '371 style closing tag is present');
ok(middleware.indexOf(sourceStyleOpen) < middleware.indexOf(sourceStyleClose), 'style closes after opening');
ok((middleware.match(/<style\b/gi) || []).length === (middleware.match(/<\/style>/gi) || []).length, 'style tags are balanced');
ok(middleware.indexOf(sourceStyleClose) < middleware.indexOf('CSS;'), 'style closes before heredoc terminator');
ok(middleware.includes('data-et-sales-invoice-print-v2="ERP-11.3.372"'), '372 response marker is present');
ok(middleware.includes('str_contains($html, \'data-et-sales-invoice-print-v2="ERP-11.3.372"\')'), '372 idempotence marker is authoritative');
ok(middleware.includes('$html = $this->markBody($html);'), 'body marker transformation remains in the pipeline');
ok(middleware.includes('$html = $this->injectStyle($html);'), 'style transformation remains in the pipeline');
ok(middleware.includes("preg_replace('/<\\/head>/i', $style.'</head>'"), 'style is injected before the native head close');

const native = `<!DOCTYPE html><html><head><title>Test Sales Invoice</title><style>native styles</style></head><body><div class="actions">Print</div><div class="sheet"><div class="doc-header">Header</div><div class="info-grid">Info</div><table class="invoice-table"><tbody><tr><td>Item</td></tr></tbody></table><div class="totals">Totals</div><section class="notes">Notes</section><div class="foot">Foot</div></div></body></html>`;
const injected = native.replace(/<\/head>/i, sourceStyleOpen + 'body.et-si-print-370{background:#edf3f8}' + sourceStyleClose + '</head>');
ok(injected.includes(sourceStyleOpen + 'body.et-si-print-370'), 'representative transform contains the injected style');
ok(injected.includes(sourceStyleClose + '</head><body>'), 'style closes before head and body boundaries');
ok(injected.includes('<body>'), 'body remains parseable in representative output');
ok(injected.includes('<div class="sheet">'), 'native sheet survives representative transform');
ok((injected.match(/<style\b/gi) || []).length === (injected.match(/<\/style>/gi) || []).length, 'representative style boundary is balanced');
ok(injected.indexOf('<div class="sheet">') > injected.indexOf('<body>'), 'sheet remains inside body');
ok(injected.includes('</head><body>'), 'native head/body boundary remains intact');
ok(middleware.includes('$response = $next($request);'), 'native response is still obtained from the host');
ok(middleware.includes('! $response instanceof Response'), 'non-HTML response guard remains');
ok(middleware.includes('$response->getStatusCode() >= 400'), 'error response guard remains');
ok(middleware.includes("$response->headers->remove('Content-Length')"), 'content length is cleared after mutation');
ok(middleware.includes('class="sheet"'), 'native sheet marker remains required');

console.log(`PASS ${pass} ERP-11.3.371 Sales Invoice Print Blank Fix assertions`);
