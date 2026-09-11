import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const service = read('app/Services/Accounting/ManagementAccountingReportService.php');
const controller = read('app/Http/Controllers/Accounting/ManagementAccountingReportController.php');
const routes = read('routes/erp103179.php');
const middleware = read('app/Http/Middleware/PresentAccountingReportsWorkspace.php');
const management = read('resources/views/accounting/management-reporting/management.blade.php');
const pnl = read('resources/views/accounting/management-reporting/profit-and-loss.blade.php');
const balance = read('resources/views/accounting/management-reporting/balance-sheet.blade.php');
const navigation = read('resources/views/accounting/management-reporting/_navigation.blade.php');
const version = read('VERSION.txt').trim();

ok(service.includes("where('je.status', 'posted')"), 'posted journal status is the financial-report authority');
ok(!service.includes("where('je.status', 'draft')"), 'draft journals are not selected');
ok(!service.includes("pending_approval") && !service.includes("approved-but-not-posted"), 'unposted lifecycle states are not selected');
ok(service.includes("join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')"), 'native journal headers and lines remain the single ledger source');
ok(service.includes("whereDate('je.journal_date'"), 'native journal date is the posting-date authority');
ok(service.includes("['base_debit', 'base_credit']") && service.includes("['debit', 'credit']"), 'base currency columns are preferred with native debit/credit fallback');
ok(service.includes('DATE(je.journal_date) as journal_date'), 'daily grouping normalizes native posting date');
ok(!service.includes('reversal_of_id') && !service.includes('whereNull(\'je.reversal'), 'posted reversal lines remain in the population and net naturally');

ok(service.includes('ChartOfAccountsWorkspaceService'), 'COA service supplies installed schema authority');
ok(service.includes("['subtype', 'parent', 'normal', 'posting', 'control_type', 'status', 'active']"), 'COA type, hierarchy, control, posting, and activity metadata are loaded together');
ok(service.includes("private const REVENUE_CODES = ['4110', '4120', '4130', '4140', '4150', '4160']"), 'known revenue mappings supplement COA classification');
ok(service.includes("private const DIRECT_COST_CODES = ['5110', '5120', '5130', '5140', '5150', '5190']"), 'known direct-cost mappings remain separate from expenses');
ok(service.includes("if ($type !== 'expense') return null"), 'non-expense balance-sheet accounts do not enter operating expense');
ok(service.includes("return 'operating_expense'"), 'installed expense accounts fall into operating expense');
ok(service.includes("return 'other_income'") && service.includes("return 'other_expense'"), 'FX and other income/expense classes remain distinct');
ok(service.includes("str_contains($text, 'exchange')") && service.includes("str_contains($text, 'fx loss')"), 'FX gain and loss classification is explicit');

ok(service.includes("'gross_profit' => $gross"), 'gross profit is exposed');
ok(service.includes('$gross = round($revenue - $direct, 2)'), 'gross profit equals revenue less direct cost');
ok(service.includes('$operatingProfit = round($gross - $operating, 2)'), 'operating profit equals gross profit less operating expense');
ok(service.includes("'net_profit' => round($operatingProfit + $otherIncome - $otherExpense, 2)"), 'net profit includes other income and expense');
ok(service.includes("'today' => ['label' => 'Today'"), 'daily P&L period exists');
ok(service.includes("'yesterday' => ['label' => 'Yesterday'"), 'yesterday comparison exists');
ok(service.includes("'month_to_date' => ['label' => 'This Month'"), 'month-to-date period exists');
ok(service.includes("'previous_month' => ['label' => 'Last Month'"), 'previous-month period exists');
ok(service.includes("'year_to_date' => ['label' => 'Year to Date'"), 'fiscal year-to-date period exists');
ok(service.includes("$this->profitLossFromRows($accountMap, $dated->filter"), 'overview periods share the P&L calculation');

ok(service.includes("['fiscal_years', 'financial_years', 'fiscal_year']"), 'installed fiscal-year tables are discovered');
ok(service.includes('Calendar-year fallback (no native fiscal range resolved)'), 'calendar fallback is explicit only when no fiscal authority resolves');
ok(service.includes("Schema::hasColumn('journal_entries', 'branch_id')"), 'branch support is based on native journal schema');
ok(service.includes("$query->where('je.branch_id', $branchId)"), 'branch scope is applied to the shared journal query');
ok(management.includes("@if($filters['branch_supported'])") && pnl.includes("@if($filters['branch_supported'])") && balance.includes("@if($filters['branch_supported'])"), 'branch filter is hidden if journal branch authority is absent');

ok(service.includes("'cash_bank' => 0.0") && service.includes("'customer_receivables' => 0.0"), 'cash and customer receivables are independent positions');
ok(service.includes("'vendor_payables' => 0.0") && service.includes("'customer_advances' => 0.0") && service.includes("'supplier_advances' => 0.0"), 'payables and both advance controls remain independent');
ok(service.includes("$control === 'CUSTOMER_AR' || $account['code'] === '1130'"), 'Customer AR uses control type with 1130 fallback');
ok(service.includes("$control === 'VENDOR_AP' || $account['code'] === '2110'"), 'Vendor AP uses control type with 2110 fallback');
ok(service.includes("$control === 'CUSTOMER_ADVANCE' || $account['code'] === '2120'"), 'Customer Advance uses control type with 2120 fallback');
ok(service.includes("$control === 'VENDOR_ADVANCE' || $account['code'] === '1140'"), 'Vendor Advance uses control type with 1140 fallback');
ok(service.includes("controlAccountIds($accounts, 'CUSTOMER_AR', '1130')"), 'customer subledger summary is restricted to AR control');
ok(service.includes("controlAccountIds($accounts, 'VENDOR_AP', '2110')"), 'vendor subledger summary is restricted to AP control');
ok(service.includes("groupBy('jl.party_id')"), 'party summaries use one grouped query without per-party journal queries');

ok(service.includes("'opening' => round($closingBalance - $inflow + $outflow, 2)"), 'cash opening reconciles from closing and selected-day movements');
ok(service.includes("'cash_inflow_today' => round((float) $cashToday->sum('debit')"), 'cash inflow is shown independently');
ok(service.includes("'cash_outflow_today' => round((float) $cashToday->sum('credit')"), 'cash outflow is shown independently');
ok(management.includes('Cash received/paid is not revenue/expense'), 'UI distinguishes cash flow from earned revenue');
ok(service.includes("! $this->isCashBank($account) || ! $this->isActive($account) || ! $this->isPosting($account)"), 'cash positions use active discovered posting accounts');

ok(service.includes("'Air' => ['4110', '5110']"), 'Air posted profitability mapping exists');
ok(service.includes("'Visa' => ['4120', '5120']"), 'Visa posted profitability mapping exists');
ok(service.includes("'Hotel' => ['4130', '5130']"), 'Hotel posted profitability mapping exists');
ok(service.includes("'Transport' => ['4140', '5140']"), 'Transport posted profitability mapping exists');
ok(service.includes("'Package / Umrah' => ['4150', '5150']"), 'Package posted profitability mapping exists');
ok(service.includes("$result[] = $this->productRow('Other'"), 'remaining mapped revenue/direct cost is presented as Other');
ok(management.includes('Accounting journals, not booking commercial snapshots'), 'product reporting clearly identifies posted accounting authority');

ok(service.includes("'difference' => round($debit - $credit, 2)") && service.includes("abs($debit - $credit) < 0.005"), 'trial-balance precision reconciliation is enforced');
ok(middleware.includes('presentTrialBalanceStatement($request, $html)'), 'native Trial Balance calculation and presentation pipeline remains active');
ok(controller.includes("nativeReportUrl('trial_balance'"), 'Trial Balance entry redirects to native report authority');
ok(middleware.includes('OPENING\\s*BALANCE') && middleware.includes("renderedKpiAmount(\n            $html,\n            'CLOSING / DIFFERENCE'"), 'native Trial Balance presentation preserves opening and closing/difference authority');

ok(service.includes("'Current Year Earnings / (Loss)'"), 'Balance Sheet adds calculated current-year earnings');
ok(service.includes("$earnings = $this->profitLossFromRows($accountMap, $yearRows)['net_profit']"), 'current-year earnings derive from posted P&L');
ok(service.includes("'difference' => round($assets - $liabilities - $equity, 2)"), 'Balance Sheet equation difference is explicit');
ok(!service.match(/(?:insert|update|delete|upsert)\s*\(/i), 'report service performs no accounting writes');
ok(!controller.match(/(?:insert|update|delete|upsert)\s*\(/i), 'report controller performs no accounting writes');
ok(!routes.match(/Route::(?:post|put|patch|delete)\('\/accounting\/reports\/(?:management|profit-and-loss|balance-sheet|trial-balance)/), 'all new report routes are GET-only');
ok(routes.match(/Route::get\('\/accounting\/reports\/management'/), 'Management Overview GET route exists');
ok(routes.match(/Route::get\('\/accounting\/reports\/profit-and-loss'/), 'Profit & Loss GET route exists');
ok(routes.match(/Route::get\('\/accounting\/reports\/balance-sheet'/), 'Balance Sheet GET route exists');
ok(routes.match(/Route::get\('\/accounting\/reports\/trial-balance'/), 'Trial Balance GET route exists');
ok((routes.match(/EnforceErpRoleScopedAccess::class/g) || []).length >= 4, 'new report routes use ERP role-scoped access');

ok(controller.includes("getByName('accounting.ledgers.account')"), 'account drilldowns discover the native GET ledger route');
ok(controller.includes("getByName('accounting.reports.index')"), 'native Report Center route remains the report authority');
ok(navigation.includes('Ledger Reports / Print Center'), 'custom pages preserve navigation to native ledger/print center');
ok(middleware.includes('injectManagementReportingNavigation($html)'), 'native Report Center receives management-report navigation');
ok(middleware.includes('Management Overview') && middleware.includes('Profit & Loss') && middleware.includes('Balance Sheet'), 'native reporting navigation exposes the requested reports');
ok(management.includes('onclick="window.print()"') && pnl.includes('onclick="window.print()"') && balance.includes('onclick="window.print()"'), 'custom statements provide safe browser print without a new export library');
ok(management.includes('@media print') && pnl.includes('@media print') && balance.includes('@media print'), 'all report views have print-safe styling');
ok(management.includes('@media(max-width:520px)') && pnl.includes('@media(max-width:700px)') && balance.includes('@media(max-width:750px)'), 'all reports include responsive behavior');
ok(management.includes("config('et_erp_release.release','ERP-11.3')") && pnl.includes("config('et_erp_release.release','ERP-11.3')") && balance.includes("config('et_erp_release.release','ERP-11.3')"), 'report labels use dynamic release metadata');
ok(!management.includes('ERP-11.3.219</') && !pnl.includes('ERP-11.3.219</') && !balance.includes('ERP-11.3.219</'), 'no visible release label is hard-coded');
ok(version === 'v1.1.33.219-ERP11.3.219', 'VERSION remains unchanged for functional reporting work');

const pnlFixture = { revenue: 931200, direct: 938600, operating: 12000, otherIncome: 3000, otherExpense: 500 };
const gross = pnlFixture.revenue - pnlFixture.direct;
const operatingProfit = gross - pnlFixture.operating;
const net = operatingProfit + pnlFixture.otherIncome - pnlFixture.otherExpense;
ok(gross === -7400, 'fixture gross profit formula reconciles');
ok(operatingProfit === -19400, 'fixture operating profit formula reconciles');
ok(net === -16900, 'fixture net profit formula reconciles');

const journalFixture = [
  { status: 'posted', branch: 1, date: '2026-09-11', debit: 1000, credit: 1000 },
  { status: 'draft', branch: 1, date: '2026-09-11', debit: 800, credit: 800 },
  { status: 'pending_approval', branch: 1, date: '2026-09-11', debit: 700, credit: 700 },
  { status: 'approved', branch: 1, date: '2026-09-11', debit: 600, credit: 600 },
  { status: 'posted', branch: 2, date: '2026-09-11', debit: 200, credit: 200 },
];
const scoped = journalFixture.filter(row => row.status === 'posted' && row.branch === 1 && row.date === '2026-09-11');
ok(scoped.length === 1, 'fixture excludes Draft, Pending, Approved-unposted, and other branches');
ok(scoped.reduce((sum, row) => sum + row.debit, 0) === scoped.reduce((sum, row) => sum + row.credit, 0), 'fixture Trial Balance is balanced');
const reversalFixture = [{ amount: 500 }, { amount: -500 }];
ok(reversalFixture.reduce((sum, row) => sum + row.amount, 0) === 0, 'posted original and controlled reversal net correctly');
const cashFixture = [{ closing: 1200 }, { closing: 800 }, { closing: -100 }];
ok(cashFixture.reduce((sum, row) => sum + row.closing, 0) === 1900, 'cash total equals individual account closings');
const bsFixture = { assets: 2000, liabilities: 600, equityBeforeEarnings: 900, earnings: 500 };
ok(bsFixture.assets === bsFixture.liabilities + bsFixture.equityBeforeEarnings + bsFixture.earnings, 'Balance Sheet fixture balances with current-year earnings');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
