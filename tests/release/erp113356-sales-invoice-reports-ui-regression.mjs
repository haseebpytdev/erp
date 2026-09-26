import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const air = read('app/Http/Middleware/PresentAirTicketSalesInvoice.php');
const reports = read('app/Http/Middleware/PresentAccountingReportsWorkspace.php');
const routes = read('routes/erp103179.php');

ok(air.includes('private function isAirOnlyInvoice'), 'Air presenter has an explicit product-identity eligibility guard');
ok(air.includes("$this->commercialSummary->resolve($invoice, $bookingId)"), 'Air eligibility resolves the native invoice product summary');
ok(air.includes('if (! $this->isAirOnlyInvoice($snapshot, $bookingId))'), 'unsupported multi-product invoices stay with native presentation');
ok(!air.includes("stripos($content, 'AIR_TICKET') !== false"), 'generic AIR_TICKET text no longer grants Air presenter ownership');
ok(!air.includes("stripos($content, 'Air Ticket') !== false"), 'generic Air Ticket line text no longer grants Air presenter ownership');
ok(air.includes("str_contains($identity, 'air')") && air.includes("str_contains($identity, 'ticket')"), 'true Air product identity remains supported');
ok(air.includes('catch (Throwable)'), 'Air eligibility fails closed when product resolution is unavailable');

ok(reports.includes('private function routeMode(Request $request)'), 'Accounting reports middleware has route-aware presentation modes');
ok(reports.includes("return 'preview';"), 'Preview route receives a distinct presentation contract');
ok(reports.includes("return 'print';"), 'Print route is recognized separately from screen/index');
ok(reports.includes("if ($mode === 'preview')"), 'Preview bypasses index workspace transformations');
ok(reports.includes('$this->extractPreviewDocument($html)'), 'Preview uses a structural document-boundary extraction');
ok(reports.includes('private function extractPreviewDocument(string $html)'), 'Preview extraction is isolated and deterministic');
ok(reports.includes('$this->reconcilePartyControlLedger($request, $html)'), 'Preview retains party-ledger reconciliation');
ok(reports.includes('$this->presentTrialBalanceStatement($request, $html)'), 'Preview retains report-specific reconciliation');
const previewBranch = reports.split("if ($mode === 'preview')", 2)[1]?.split("$html = $this->enrichCashVoucherRows", 1)[0] ?? '';
ok(!previewBranch.includes('replaceNativeFilter('), 'Preview does not replace the native index filter');
ok(!previewBranch.includes('injectManagementReportingNavigation('), 'Preview does not inject management navigation');
ok(routes.includes("'accounting.reports.index'"), 'native report index route remains authoritative');
ok(routes.includes("'accounting.reports.preview'"), 'native report preview route remains authoritative');
ok(reports.includes("$name === 'accounting.reports.print'"), 'native report print route remains recognized');
ok(routes.includes('PresentAccountingReportsWorkspace::class'), 'report presentation remains attached through the native route hook');

console.log(`PASS ${pass} ERP-11.3.356 Sales Invoice and Reports UI assertions`);
