import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const equal = (actual, expected, label) => { assert.deepEqual(actual, expected, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

function commercialSummary({ grandTotal, lines, costs }) {
  const names = { 1: 'Air Ticket', 3: 'Hotel', 4: 'Transport', 2: 'Visa' };
  const products = new Map();
  for (const line of lines) {
    const id = Number(line.product_service_id);
    if (!products.has(id)) products.set(id, { id, name: names[id], sale: 0 });
    products.get(id).sale += Number(line.line_total);
  }
  const rows = [...products.values()].map(product => {
    const cost = costs[product.id];
    const resolved = cost !== null && cost !== undefined;
    return { ...product, cost: resolved ? Number(cost) : null, margin: resolved ? product.sale - Number(cost) : null, resolved };
  });
  const complete = rows.every(row => row.resolved);
  const totalCost = complete ? rows.reduce((sum, row) => sum + row.cost, 0) : null;
  return {
    invoiceTotal: grandTotal,
    products: rows,
    productCount: rows.length,
    productSale: rows.reduce((sum, row) => sum + row.sale, 0),
    totalCost,
    grossMargin: complete ? grandTotal - totalCost : null,
    complete,
  };
}

const fixture = commercialSummary({
  grandTotal: 931200,
  lines: [
    { product_service_id: 1, line_total: 588000 },
    { product_service_id: 1, line_total: 130000 },
    { product_service_id: 3, line_total: 8000 },
    { product_service_id: 4, line_total: 200 },
    { product_service_id: 2, line_total: 205000 },
  ],
  costs: { 1: 730000, 3: 7200, 4: 7600, 2: 193800 },
});

equal(fixture.invoiceTotal, 931200, 'top total uses native invoice grand_total');
equal(fixture.productCount, 4, 'product count uses distinct invoice products');
equal(fixture.products.filter(product => product.id === 1).length, 1, 'Adult and Child Air lines remain one invoice product');
equal(fixture.products.find(product => product.id === 1).sale, 718000, 'Air sale groups native invoice lines');
equal(fixture.products.find(product => product.id === 3).sale, 8000, 'Hotel sale comes from native invoice line');
equal(fixture.products.find(product => product.id === 4).sale, 200, 'Transport sale comes from native invoice line');
equal(fixture.products.find(product => product.id === 2).sale, 205000, 'Visa sale comes from native invoice line');
equal(fixture.productSale, fixture.invoiceTotal, 'product sale reconciles to invoice total');
equal(fixture.products.find(product => product.id === 1).margin, -12000, 'reference Air margin remains sale less cost');
equal(fixture.products.find(product => product.id === 3).margin, 800, 'reference Hotel margin remains sale less cost');
equal(fixture.products.find(product => product.id === 4).margin, -7400, 'reference Transport negative margin is retained');
equal(fixture.products.find(product => product.id === 2).margin, 11200, 'reference Visa margin remains sale less cost');
equal(fixture.totalCost, 938600, 'reference complete cost remains unchanged');
equal(fixture.grossMargin, -7400, 'reference gross margin remains unchanged');

const incomplete = commercialSummary({
  grandTotal: 931200,
  lines: [{ product_service_id: 1, line_total: 718000 }, { product_service_id: 3, line_total: 8000 }],
  costs: { 1: 0, 3: null },
});
equal(incomplete.products.find(product => product.id === 1).cost, 0, 'authoritative explicit zero remains resolved zero');
equal(incomplete.products.find(product => product.id === 3).cost, null, 'unresolved cost is not converted to zero');
equal(incomplete.totalCost, null, 'top cost is incomplete when any product cost is unresolved');
equal(incomplete.grossMargin, null, 'top margin is incomplete when any product cost is unresolved');

const resolver = read('app/Services/Sales/SalesInvoiceProductCommercialSummaryResolver.php');
const presenter = read('app/Http/Middleware/PresentAirTicketSalesInvoice.php');
const styleMatch = presenter.match(/<style id="et-si11-style-103179">([\s\S]*?)<\/style>/);

ok(resolver.includes("foreach (['grand_total', 'total_amount'"), 'invoice header grand_total is the first top-total authority');
ok(resolver.includes("['source_booking_service_id', 'booking_service_id']"), 'invoice lines retain source booking-service identity');
ok(resolver.includes("['product_service_id']"), 'invoice product identity prefers product_service_id');
ok(resolver.includes("'product_count' => count($products)"), 'product count is distinct grouped products, not fare groups');
ok(resolver.includes("Schema::hasTable('booking_passengers')") && resolver.includes("'passenger_count' => $passengers['total']"), 'top passenger count uses the source booking passenger authority');
ok(resolver.includes('physicalInvoiceLines($invoice)'), 'invoice sale has a schema-aware native line-table fallback');
ok(resolver.includes("'sale_reconciles' => abs($productSaleTotal - $invoiceTotal) < 0.01"), 'product sale is explicitly reconciled to native invoice total');
ok(resolver.includes("in_array('net_supplier_cost', $columns, true)"), 'Air cost requires native net_supplier_cost authority');
ok(resolver.includes("array_key_exists('vendor_total', $row)"), 'Hotel cost prefers persisted vendor_total');
ok(resolver.includes("$row['cost_rate'] * (int) $row['nights']"), 'Hotel cost has exact rate-times-nights fallback');
ok(resolver.includes("'cost_amount' => $this->firstNullableNumber"), 'Transport cost resolves persisted PKR cost_amount semantics');
ok(resolver.includes("in_array('vendor_cost_pkr', $columns, true)"), 'Visa cost requires vendor_cost_pkr');
ok(resolver.includes("'total_cost_complete' => $costComplete"), 'resolver distinguishes complete from incomplete total cost');
ok(resolver.includes("? round((float) $product['sale_total'] - (float) $product['cost_total'], 2)"), 'resolved row margin is sale less cost');
for (const mutation of ['->insert(', '->insertGetId(', '->update(', '->updateOrInsert(', '->delete(', '->save(']) {
  ok(!resolver.includes(mutation), `read-only resolver contains no ${mutation} mutation`);
}

ok(presenter.includes("summaryCard('Invoice Total',money(invoiceTotal)"), 'new summary uses invoice-wide total');
ok(presenter.includes("'invoice_sale_total' => $invoice instanceof Model") && presenter.includes('nativeInvoiceTotal($invoice)'), 'presentation fallback retains the native invoice header total');
ok(!presenter.includes("setMetric('Invoice Total',money(sourceTotal)"), 'Air fare total no longer overwrites invoice total');
ok(presenter.includes("summaryCard('Total Cost'"), 'top Total Cost card exists');
ok(presenter.includes("summaryCard('Gross Margin'"), 'top Gross Margin card exists');
ok(presenter.includes("summaryCard('Passengers'"), 'top Passengers card exists');
ok(presenter.includes("summaryCard('Products'"), 'top Products card replaces Service Lines');
equal([...presenter.matchAll(/summaryCard\('([^']+)'/g)].map(match => match[1]), ['Invoice Total', 'Total Cost', 'Gross Margin', 'Passengers', 'Products'], 'desktop summary remains exactly the approved five cards');
ok(presenter.includes("norm(el.textContent)==='accounting'") && presenter.includes("norm(el.textContent)==='not posted'") && presenter.includes('commonAncestor(label,status)'), 'standalone top Accounting / Not Posted card is matched by its exact paired labels');
ok(presenter.includes('card===document.body') && presenter.includes('card.contains(reviewCard)') && presenter.includes('card.contains(accountingCard)'), 'legacy card cleanup cannot hide body, Review, or the lower Accounting Preview');
ok(presenter.includes('rect.height<=220') && presenter.includes('rect.bottom<=topBoundary+1'), 'legacy card cleanup is bounded to a compact element entirely above Review');
ok(!presenter.includes('for(let i=0;i<7&&current'), 'unsafe open-ended Accounting ancestor walk is removed');
ok(presenter.includes('Product Commercial Summary'), 'full-width product commercial summary exists');
ok(presenter.includes('Commercial visibility by product — sale, cost and margin.'), 'product summary guidance is present');
ok(presenter.includes('Product cost could not be resolved from the source booking.'), 'unresolved product costs carry an explicit warning');
ok(presenter.includes("margin>0?'positive':margin<0?'negative'"), 'margin values receive positive and negative semantic states');
ok(presenter.includes('.et-si11-product-icon-103179.air') && presenter.includes('--et-si11-blue'), 'Air uses blue icon language');
ok(presenter.includes('.et-si11-product-icon-103179.hotel') && presenter.includes('--et-si11-purple'), 'Hotel uses purple icon language');
ok(presenter.includes('.et-si11-product-icon-103179.transport') && presenter.includes('--et-si11-green'), 'Transport uses green icon language');
ok(presenter.includes('.et-si11-product-icon-103179.visa') && presenter.includes('--et-si11-orange'), 'Visa uses orange icon language');
ok(presenter.includes('Air Ticket Commercial Lines') && presenter.includes('Grouped by fare type + customer rate.'), 'Air Adult/Child commercial detail remains separate');
ok(presenter.includes('<th>Type</th><th>Count</th><th>Tickets</th>'), 'passenger summary uses Type, Count and Tickets columns');
ok(presenter.includes('et-si11-ticket-refs-103179') && presenter.includes('et-si11-ticket-ref-103179'), 'ticket numbers render as individually readable wrapping references');
ok(presenter.includes('font-size:10.5px') && presenter.includes('flex-direction:column'), 'ticket references retain readable type and clean vertical spacing');
ok(presenter.includes("accountingHeading.textContent='Accounting Preview (Journal Lines)'"), 'lower accounting section identifies its journal lines explicitly');
ok(presenter.includes('Customer receivable remains based on sale total.'), 'accounting preview explains that costs do not alter receivable');
ok(presenter.includes("accountingCard.querySelectorAll('tr')"), 'native accounting preview rows remain the displayed authority');
ok(presenter.includes("el.textContent='SALES INVOICE · ERP-11.3'"), 'existing Sales Invoice header is retained at ERP-11.3');
ok(presenter.includes('@media(max-width:760px)') && presenter.includes('@media(max-width:430px)'), 'summary and product UI has tablet/mobile breakpoints');
ok(presenter.includes('.et-si11-table-103179 td:nth-child(3){grid-column:1/-1}'), 'mobile passenger rows stack ticket references without page overflow');
ok(!presenter.includes('overflow-x:auto') && !presenter.includes('min-width:900'), 'product summary does not force a wide-table mobile overflow');
ok(presenter.includes('$this->sync->snapshot(') && !presenter.includes('$this->sync->sync('), 'invoice page presenter only reads the Air snapshot');
ok(presenter.includes("$content = $response->getContent()") && presenter.includes("$response->setContent($content)"), 'middleware preserves and returns the native response body around its injection');
ok(!presenter.includes('body.et-si11-page-103179{display:none') && !presenter.includes('.content-wrapper{display:none'), 'focused presentation CSS never hides the page root');
ok(Boolean(styleMatch), 'focused Sales Invoice stylesheet is present');
equal((styleMatch[1].match(/{/g) || []).length, (styleMatch[1].match(/}/g) || []).length, 'focused Sales Invoice stylesheet braces are balanced');

const scriptMatch = presenter.match(/<script id="et-si11-script-103179">([\s\S]*?)<\/script>/);
ok(Boolean(scriptMatch), 'focused Sales Invoice script is present');
const compilable = scriptMatch[1].replace('const data={$json};', 'const data={};');
assert.doesNotThrow(() => new Function(compilable), 'focused Sales Invoice browser script parses'); pass++;

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
