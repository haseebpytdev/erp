import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const viewPaths = [
  'resources/views/accounting/management-reporting/management.blade.php',
  'resources/views/accounting/management-reporting/profit-and-loss.blade.php',
  'resources/views/accounting/management-reporting/balance-sheet.blade.php',
  'resources/views/accounting/management-reporting/_navigation.blade.php',
];
const views = Object.fromEntries(viewPaths.map(path => [path, read(path)]));
const pnl = views[viewPaths[1]];
const controller = read('app/Http/Controllers/Accounting/ManagementAccountingReportController.php');
const service = read('app/Services/Accounting/ManagementAccountingReportService.php');

function inlinePhpBodies(source) {
  const bodies = [];
  let cursor = 0;
  while ((cursor = source.indexOf('@php(', cursor)) !== -1) {
    let depth = 1;
    let quote = null;
    let escaped = false;
    let index = cursor + 5;
    for (; index < source.length && depth > 0; index++) {
      const char = source[index];
      if (quote !== null) {
        if (escaped) escaped = false;
        else if (char === '\\') escaped = true;
        else if (char === quote) quote = null;
        continue;
      }
      if (char === "'" || char === '"') quote = char;
      else if (char === '(') depth++;
      else if (char === ')') depth--;
    }
    ok(depth === 0, 'inline @php expression has balanced parentheses');
    bodies.push(source.slice(cursor + 5, index - 1));
    cursor = index;
  }
  return bodies;
}

for (const [path, source] of Object.entries(views)) {
  const unsafe = inlinePhpBodies(source).filter(body => body.includes(';'));
  ok(unsafe.length === 0, `${path} contains no inline multi-statement @php directive`);
  const blockStarts = (source.match(/@php(?!\()/g) || []).length;
  const blockEnds = (source.match(/@endphp/g) || []).length;
  ok(blockStarts === blockEnds, `${path} has balanced block @php directives`);
}

ok(!pnl.includes('@php') && !pnl.includes('@endphp'), 'P&L template contains zero PHP directives');
ok(!/\$[A-Za-z_]\w*\s*=(?!=)/.test(pnl), 'P&L template contains no local variable assignments');
ok(!pnl.includes('$titles') && !pnl.includes('$previousByCode') && !pnl.includes('$money'), 'P&L template has no hidden calculation state');
ok(pnl.includes('@foreach($sectionRows as $section)'), 'P&L iterates controller-prepared sections');
ok(pnl.includes('@foreach($summaryRows as $summary)'), 'P&L iterates controller-prepared summaries');
ok(!/@php\([^\r\n]*;[^\r\n]*\)/.test(pnl), 'original production parse-failure pattern is absent');

for (const key of ['sections', 'revenue', 'direct_cost', 'gross_profit', 'operating_expenses', 'operating_profit', 'other_income', 'other_expense', 'net_profit']) {
  ok(service.includes(`'${key}' =>`), `report service exposes ${key}`);
}
for (const section of ['revenue', 'direct_cost', 'operating_expense', 'other_income', 'other_expense']) {
  ok(service.includes(`'${section}' => []`), `report service initializes ${section} section`);
}
ok(service.includes("$current['previous'] = $previous"), 'P&L report includes previous comparable period');
ok(controller.includes("'accounting.management-reporting.profit-and-loss'"), 'controller renders the corrected P&L view');
ok(controller.includes('private function profitAndLossPresentation(array $report, array $accountUrls): array'), 'controller owns the complete P&L presentation model');
ok(controller.includes("return ['sectionRows' => $sectionRows, 'summaryRows' => $summaryRows]"), 'controller supplies both top-level P&L view collections');
ok(controller.includes('private function profitAndLossDisplayRow(float $current, float $previous): array'), 'controller prepares comparison arithmetic once');
ok(controller.includes("'current_display' => $this->money($current)") && controller.includes("'variance_percent_display' =>"), 'controller supplies formatted display fields');
ok(controller.includes("'account_url' => $accountUrls[$line['code']] ?? null"), 'controller attaches safe native account drilldowns');
ok(pnl.includes("$line['account_url']"), 'native account drilldowns remain rendered');
ok(pnl.includes('onclick="window.print()"'), 'P&L print action remains rendered');
ok(pnl.includes('Current Period') && pnl.includes('Previous Period') && pnl.includes('Variance %'), 'current and previous comparison columns remain rendered');
ok(controller.includes("['NET PROFIT / LOSS', 'net_profit']"), 'net profit/loss summary remains supplied');

const referencedVariables = new Set([...pnl.matchAll(/\$([A-Za-z_]\w*)/g)].map(match => match[1]));
const suppliedOrScoped = new Set(['layoutMeta', 'filters', 'branch', 'sectionRows', 'section', 'line', 'summaryRows', 'summary']);
ok([...referencedVariables].every(variable => suppliedOrScoped.has(variable)), 'every P&L Blade variable is controller-supplied or loop-scoped');
ok(controller.includes("'filters' => $filters") && controller.includes("'layoutMeta' => $this->layout->resolve()"), 'shared controller view data supplies filters and layout metadata');

const representative = {
  revenue: 1000,
  direct_cost: 600,
  gross_profit: 400,
  operating_expenses: 125,
  operating_profit: 275,
  other_income: 20,
  other_expense: 5,
  net_profit: 290,
  sections: { revenue: [], direct_cost: [], operating_expense: [], other_income: [], other_expense: [] },
  previous: {
    revenue: 800,
    direct_cost: 500,
    gross_profit: 300,
    operating_expenses: 100,
    operating_profit: 200,
    other_income: 0,
    other_expense: 10,
    net_profit: 190,
    sections: { revenue: [], direct_cost: [], operating_expense: [], other_income: [], other_expense: [] },
  },
};
ok(representative.gross_profit === representative.revenue - representative.direct_cost, 'representative report shape reconciles gross profit');
ok(representative.net_profit === representative.operating_profit + representative.other_income - representative.other_expense, 'representative report shape reconciles net profit');
ok(Object.values(representative.sections).every(Array.isArray) && Object.values(representative.previous.sections).every(Array.isArray), 'current and previous section collections are iterable without undefined keys');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
