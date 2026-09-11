<?php

namespace App\Services\Accounting;

use App\Services\Operations\UnifiedGroupPackageDataSource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Read-only management projections over the native posted journal population.
 * No reporting value is persisted and no document table is queried as a ledger.
 */
final class ManagementAccountingReportService
{
    private const REVENUE_CODES = ['4110', '4120', '4130', '4140', '4150', '4160'];
    private const DIRECT_COST_CODES = ['5110', '5120', '5130', '5140', '5150', '5190'];

    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
        private readonly UnifiedGroupPackageDataSource $bookingData,
    ) {}

    public function filters(Request $request, string $mode): array
    {
        $asOf = $this->date((string) $request->query('as_of', now()->toDateString()));
        $from = $this->date((string) $request->query('from', Carbon::parse($asOf)->startOfMonth()->toDateString()));
        $to = $this->date((string) $request->query('to', $asOf));
        if ($from > $to) [$from, $to] = [$to, $from];

        $branches = $this->branches();
        $branchId = (int) $request->query('branch_id', 0);
        if ($branchId > 0 && ! collect($branches)->contains(fn (array $branch): bool => (int) $branch['id'] === $branchId)) $branchId = 0;

        return [
            'mode' => $mode, 'as_of' => $asOf, 'from' => $from, 'to' => $to,
            'branch_id' => $branchId ?: null, 'branches' => $branches,
            'branch_supported' => $this->branchSupported(),
        ];
    }

    public function management(array $filters): array
    {
        $accounts = $this->accounts();
        $accountMap = collect($accounts)->keyBy('id');
        $asOf = $filters['as_of'];
        $fiscal = $this->fiscalYear($asOf);
        $periods = $this->managementPeriods($asOf, $fiscal['start']);
        $earliest = collect($periods)->min('from');
        $dated = $this->datedMovements($earliest, $asOf, $filters['branch_id']);
        $comparisons = [];
        foreach ($periods as $key => $period) {
            $comparisons[$key] = $this->profitLossFromRows($accountMap, $dated->filter(fn (object $row): bool => $row->journal_date >= $period['from'] && $row->journal_date <= $period['to']));
            $comparisons[$key]['label'] = $period['label'];
            $comparisons[$key]['from'] = $period['from'];
            $comparisons[$key]['to'] = $period['to'];
        }

        $closing = $this->closingMovements($asOf, $filters['branch_id']);
        $position = $this->financialPosition($accounts, $closing);
        $cashRows = $this->cashPosition($accounts, $closing, $dated, $asOf);
        $todayRows = $dated->filter(fn (object $row): bool => $row->journal_date === $asOf);
        $cashIds = collect($accounts)->filter(fn (array $account): bool => $this->isCashBank($account))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $cashToday = $todayRows->filter(fn (object $row): bool => in_array((int) $row->account_id, $cashIds, true));

        return [
            'periods' => $comparisons,
            'today' => $comparisons['today'],
            'position' => $position,
            'cash_rows' => $cashRows,
            'cash_inflow_today' => round((float) $cashToday->sum('debit'), 2),
            'cash_outflow_today' => round((float) $cashToday->sum('credit'), 2),
            'products' => $this->productProfitability($accountMap, $todayRows),
            'top_customers' => $this->partyBalances('customer', $this->controlAccountIds($accounts, 'CUSTOMER_AR', '1130'), $asOf, $filters['branch_id']),
            'top_vendors' => $this->partyBalances('vendor', $this->controlAccountIds($accounts, 'VENDOR_AP', '2110'), $asOf, $filters['branch_id']),
            'trial_balance' => $this->trialBalanceStatus($closing),
            'fiscal_year' => $fiscal,
            'posting_date_authority' => 'journal_entries.journal_date',
            'posted_authority' => "journal_entries.status = 'posted'",
        ];
    }

    public function profitAndLoss(array $filters): array
    {
        $accounts = collect($this->accounts())->keyBy('id');
        $days = Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) + 1;
        $previousTo = Carbon::parse($filters['from'])->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);
        $rows = $this->datedMovements($previousFrom->toDateString(), $filters['to'], $filters['branch_id']);
        $current = $this->profitLossFromRows($accounts, $rows->filter(fn (object $row): bool => $row->journal_date >= $filters['from']));
        $previous = $this->profitLossFromRows($accounts, $rows->filter(fn (object $row): bool => $row->journal_date <= $previousTo->toDateString()));
        $current['previous_from'] = $previousFrom->toDateString();
        $current['previous_to'] = $previousTo->toDateString();
        $current['previous'] = $previous;
        return $current;
    }

    public function balanceSheet(array $filters): array
    {
        $accounts = $this->accounts();
        $accountMap = collect($accounts)->keyBy('id');
        $closing = $this->closingMovements($filters['as_of'], $filters['branch_id']);
        $fiscal = $this->fiscalYear($filters['as_of']);
        $yearRows = $this->datedMovements($fiscal['start'], $filters['as_of'], $filters['branch_id']);
        $earnings = $this->profitLossFromRows($accountMap, $yearRows)['net_profit'];
        $sections = ['current_assets' => [], 'non_current_assets' => [], 'current_liabilities' => [], 'non_current_liabilities' => [], 'equity' => []];
        $movementMap = $closing->keyBy('account_id');

        foreach ($accounts as $account) {
            $movement = $movementMap->get($account['id']);
            $signed = round((float) ($movement->debit ?? 0) - (float) ($movement->credit ?? 0), 2);
            $type = $account['type_normalized'];
            if ($type === 'asset') {
                $key = $this->isCurrentAsset($account) ? 'current_assets' : 'non_current_assets';
                $sections[$key][] = $this->statementRow($account, $signed);
            } elseif ($type === 'liability') {
                $key = $this->isNonCurrent($account) ? 'non_current_liabilities' : 'current_liabilities';
                $sections[$key][] = $this->statementRow($account, -$signed);
            } elseif ($type === 'equity') {
                $sections['equity'][] = $this->statementRow($account, -$signed);
            }
        }
        $sections['equity'][] = ['account_id' => null, 'code' => '', 'name' => 'Current Year Earnings / (Loss)', 'amount' => round($earnings, 2)];
        foreach ($sections as &$rows) $rows = array_values(array_filter($rows, fn (array $row): bool => abs($row['amount']) >= 0.005 || $row['name'] === 'Current Year Earnings / (Loss)'));
        unset($rows);

        $assets = round($this->sectionTotal($sections, ['current_assets', 'non_current_assets']), 2);
        $liabilities = round($this->sectionTotal($sections, ['current_liabilities', 'non_current_liabilities']), 2);
        $equity = round($this->sectionTotal($sections, ['equity']), 2);
        return [
            'sections' => $sections, 'total_assets' => $assets, 'total_liabilities' => $liabilities,
            'total_equity' => $equity, 'liabilities_equity' => round($liabilities + $equity, 2),
            'difference' => round($assets - $liabilities - $equity, 2), 'current_year_earnings' => round($earnings, 2),
            'fiscal_year' => $fiscal,
        ];
    }

    public function accounts(): array
    {
        $schema = $this->chart->schema();
        $select = [
            $schema['id'].' as id', $schema['code'].' as code', $schema['name'].' as name', $schema['type'].' as type',
        ];
        foreach (['subtype', 'parent', 'normal', 'posting', 'control_type', 'status', 'active'] as $field) if ($schema[$field]) $select[] = $schema[$field].' as '.$field;
        $rows = DB::table($schema['table'])->select($select)->get()->map(fn (object $row): array => (array) $row)->all();
        $byId = collect($rows)->keyBy(fn (array $row): string => (string) $row['id']);
        $byCode = collect($rows)->keyBy(fn (array $row): string => trim((string) $row['code']));
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['code'] = trim((string) $row['code']);
            $row['name'] = trim((string) $row['name']);
            $row['type_normalized'] = $this->normalizeType((string) $row['type']);
            $row['lineage'] = $this->lineage($row, $byId, $byCode, $schema['parent']);
        }
        unset($row);
        return $rows;
    }

    public function branches(): array
    {
        if (! $this->branchSupported()) return [];
        foreach (['branches', 'branch_master', 'branch_masters', 'offices', 'office_master', 'office_masters'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table);
            $id = $this->first($columns, ['id', 'branch_id', 'office_id']);
            $name = $this->first($columns, ['name', 'branch_name', 'office_name', 'title']);
            if (! $id || ! $name) continue;
            return DB::table($table)->orderBy($name)->get([$id.' as id', $name.' as name'])->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all();
        }
        return DB::table('journal_entries')->whereNotNull('branch_id')->distinct()->orderBy('branch_id')->pluck('branch_id')->map(fn ($id): array => ['id' => (int) $id, 'name' => 'Branch #'.$id])->all();
    }

    public function branchSupported(): bool
    {
        return Schema::hasTable('journal_entries') && Schema::hasColumn('journal_entries', 'branch_id');
    }

    private function datedMovements(string $from, string $to, ?int $branchId): Collection
    {
        [$debit, $credit] = $this->amountColumns();
        $query = $this->postedLines($branchId)->whereDate('je.journal_date', '>=', $from)->whereDate('je.journal_date', '<=', $to);
        return $query->groupBy('jl.account_id')->groupByRaw('DATE(je.journal_date)')->orderByRaw('DATE(je.journal_date)')->get([
            'jl.account_id', DB::raw('DATE(je.journal_date) as journal_date'),
            DB::raw('COALESCE(SUM(jl.'.$debit.'),0) as debit'),
            DB::raw('COALESCE(SUM(jl.'.$credit.'),0) as credit'),
        ]);
    }

    private function closingMovements(string $asOf, ?int $branchId): Collection
    {
        [$debit, $credit] = $this->amountColumns();
        return $this->postedLines($branchId)->whereDate('je.journal_date', '<=', $asOf)->groupBy('jl.account_id')->get([
            'jl.account_id', DB::raw('COALESCE(SUM(jl.'.$debit.'),0) as debit'), DB::raw('COALESCE(SUM(jl.'.$credit.'),0) as credit'),
        ]);
    }

    private function postedLines(?int $branchId)
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_lines')) throw new RuntimeException('Native posted journal tables are unavailable.');
        $query = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')->where('je.status', 'posted');
        if ($branchId && $this->branchSupported()) $query->where('je.branch_id', $branchId);
        return $query;
    }

    private function amountColumns(): array
    {
        $columns = Schema::getColumnListing('journal_lines');
        return in_array('base_debit', $columns, true) && in_array('base_credit', $columns, true) ? ['base_debit', 'base_credit'] : ['debit', 'credit'];
    }

    private function profitLossFromRows(Collection $accounts, Collection $rows): array
    {
        $amounts = [];
        foreach ($rows as $row) $amounts[(int) $row->account_id] = ($amounts[(int) $row->account_id] ?? 0) + (float) $row->debit - (float) $row->credit;
        $sections = ['revenue' => [], 'direct_cost' => [], 'operating_expense' => [], 'other_income' => [], 'other_expense' => []];
        foreach ($amounts as $id => $signed) {
            $account = $accounts->get($id);
            if (! $account) continue;
            $class = $this->pnlClass($account);
            if (! $class) continue;
            $amount = in_array($class, ['revenue', 'other_income'], true) ? -$signed : $signed;
            $sections[$class][] = $this->statementRow($account, round($amount, 2));
        }
        foreach ($sections as &$section) usort($section, fn (array $a, array $b): int => $a['code'] <=> $b['code']);
        unset($section);
        $revenue = $this->sectionRowsTotal($sections['revenue']);
        $direct = $this->sectionRowsTotal($sections['direct_cost']);
        $operating = $this->sectionRowsTotal($sections['operating_expense']);
        $otherIncome = $this->sectionRowsTotal($sections['other_income']);
        $otherExpense = $this->sectionRowsTotal($sections['other_expense']);
        $gross = round($revenue - $direct, 2);
        $operatingProfit = round($gross - $operating, 2);
        return [
            'sections' => $sections, 'revenue' => $revenue, 'direct_cost' => $direct,
            'gross_profit' => $gross, 'operating_expenses' => $operating, 'operating_profit' => $operatingProfit,
            'other_income' => $otherIncome, 'other_expense' => $otherExpense,
            'net_profit' => round($operatingProfit + $otherIncome - $otherExpense, 2),
        ];
    }

    private function financialPosition(array $accounts, Collection $closing): array
    {
        $movements = $closing->keyBy('account_id');
        $values = ['cash_bank' => 0.0, 'customer_receivables' => 0.0, 'vendor_payables' => 0.0, 'customer_advances' => 0.0, 'supplier_advances' => 0.0];
        foreach ($accounts as $account) {
            $row = $movements->get($account['id']);
            $signed = (float) ($row->debit ?? 0) - (float) ($row->credit ?? 0);
            $control = strtoupper(trim((string) ($account['control_type'] ?? '')));
            if ($this->isCashBank($account)) $values['cash_bank'] += $signed;
            if ($control === 'CUSTOMER_AR' || $account['code'] === '1130') $values['customer_receivables'] += $signed;
            if ($control === 'VENDOR_AP' || $account['code'] === '2110') $values['vendor_payables'] += -$signed;
            if ($control === 'CUSTOMER_ADVANCE' || $account['code'] === '2120') $values['customer_advances'] += -$signed;
            if ($control === 'VENDOR_ADVANCE' || $account['code'] === '1140') $values['supplier_advances'] += $signed;
        }
        return array_map(fn (float $value): float => round($value, 2), $values);
    }

    private function cashPosition(array $accounts, Collection $closing, Collection $dated, string $asOf): array
    {
        $closingMap = $closing->keyBy('account_id');
        $today = $dated->filter(fn (object $row): bool => $row->journal_date === $asOf)->keyBy('account_id');
        $result = [];
        foreach ($accounts as $account) {
            if (! $this->isCashBank($account) || ! $this->isActive($account) || ! $this->isPosting($account)) continue;
            $close = $closingMap->get($account['id']); $move = $today->get($account['id']);
            $closingBalance = (float) ($close->debit ?? 0) - (float) ($close->credit ?? 0);
            $inflow = (float) ($move->debit ?? 0); $outflow = (float) ($move->credit ?? 0);
            $result[] = $this->statementRow($account, round($closingBalance, 2)) + ['opening' => round($closingBalance - $inflow + $outflow, 2), 'inflow' => round($inflow, 2), 'outflow' => round($outflow, 2)];
        }
        return $result;
    }

    private function productProfitability(Collection $accounts, Collection $rows): array
    {
        $amounts = [];
        foreach ($rows as $row) $amounts[(int) $row->account_id] = ($amounts[(int) $row->account_id] ?? 0) + (float) $row->debit - (float) $row->credit;
        $mapping = [
            'Air' => ['4110', '5110'], 'Visa' => ['4120', '5120'], 'Hotel' => ['4130', '5130'],
            'Transport' => ['4140', '5140'], 'Package / Umrah' => ['4150', '5150'],
        ];
        $byCode = $accounts->keyBy('code'); $result = []; $usedRevenue = []; $usedCost = [];
        foreach ($mapping as $name => [$revenueCode, $costCode]) {
            $revenueAccount = $byCode->get($revenueCode); $costAccount = $byCode->get($costCode);
            $revenue = $revenueAccount ? -($amounts[$revenueAccount['id']] ?? 0) : 0; $cost = $costAccount ? ($amounts[$costAccount['id']] ?? 0) : 0;
            $usedRevenue[] = $revenueCode; $usedCost[] = $costCode;
            $result[] = $this->productRow($name, $revenue, $cost);
        }
        $otherRevenue = 0.0; $otherCost = 0.0;
        foreach ($accounts as $account) {
            if (in_array($account['code'], $usedRevenue, true) || in_array($account['code'], $usedCost, true)) continue;
            $class = $this->pnlClass($account);
            if ($class === 'revenue') $otherRevenue += -($amounts[$account['id']] ?? 0);
            if ($class === 'direct_cost') $otherCost += ($amounts[$account['id']] ?? 0);
        }
        $result[] = $this->productRow('Other', $otherRevenue, $otherCost);
        return $result;
    }

    private function productRow(string $name, float $revenue, float $cost): array
    {
        $profit = round($revenue - $cost, 2);
        return ['name' => $name, 'revenue' => round($revenue, 2), 'direct_cost' => round($cost, 2), 'gross_profit' => $profit, 'margin_percent' => abs($revenue) > 0.005 ? round($profit / $revenue * 100, 2) : null];
    }

    private function partyBalances(string $type, array $accountIds, string $asOf, ?int $branchId): array
    {
        if ($accountIds === [] || ! Schema::hasColumns('journal_lines', ['party_type', 'party_id'])) return [];
        [$debit, $credit] = $this->amountColumns();
        $nativeType = $type === 'vendor' ? 'vendor' : 'customer';
        $rows = $this->postedLines($branchId)->whereDate('je.journal_date', '<=', $asOf)->whereIn('jl.account_id', $accountIds)->where('jl.party_type', $nativeType)->whereNotNull('jl.party_id')->groupBy('jl.party_id')->get([
            'jl.party_id', DB::raw('COALESCE(SUM(jl.'.$debit.'),0) as debit'), DB::raw('COALESCE(SUM(jl.'.$credit.'),0) as credit'),
        ]);
        $names = $this->partyMap($type);
        return $rows->map(function (object $row) use ($type, $names): array {
            $signed = (float) $row->debit - (float) $row->credit;
            $outstanding = $type === 'vendor' ? -$signed : $signed;
            return ['party_id' => (int) $row->party_id, 'party_name' => $names[(int) $row->party_id] ?? ucfirst($type).' #'.$row->party_id, 'outstanding' => round($outstanding, 2), 'side' => $outstanding >= 0 ? ($type === 'vendor' ? 'Cr' : 'Dr') : ($type === 'vendor' ? 'Dr' : 'Cr')];
        })->filter(fn (array $row): bool => abs($row['outstanding']) >= 0.005)->sortByDesc(fn (array $row): float => abs($row['outstanding']))->take(5)->values()->all();
    }

    private function partyMap(string $type): array
    {
        try {
            $rows = $type === 'vendor' ? $this->bookingData->vendors() : $this->bookingData->customers();
            return $rows->mapWithKeys(fn (array $row): array => [(int) ($row['id'] ?? 0) => (string) ($row['name'] ?? '')])->all();
        } catch (Throwable) { return []; }
    }

    private function trialBalanceStatus(Collection $closing): array
    {
        $debit = round((float) $closing->sum('debit'), 2); $credit = round((float) $closing->sum('credit'), 2);
        return ['debit' => $debit, 'credit' => $credit, 'difference' => round($debit - $credit, 2), 'balanced' => abs($debit - $credit) < 0.005];
    }

    private function pnlClass(array $account): ?string
    {
        $code = $account['code']; $text = strtolower($account['name'].' '.($account['subtype'] ?? '').' '.$account['lineage']);
        $type = $account['type_normalized'];
        if (in_array($code, self::REVENUE_CODES, true)) return 'revenue';
        if (in_array($code, self::DIRECT_COST_CODES, true)) return 'direct_cost';
        if ($type === 'income') {
            if (str_contains($text, 'exchange') || str_contains($text, 'fx gain') || str_contains($text, 'foreign currency')) return 'other_income';
            return 'revenue';
        }
        if ($type !== 'expense') return null;
        if (str_contains($text, 'direct cost') || str_contains($text, 'cost of sales')) return 'direct_cost';
        if (str_contains($text, 'fx loss') || str_contains($text, 'exchange loss')) return 'other_expense';
        return 'operating_expense';
    }

    private function isCashBank(array $account): bool
    {
        $code = $account['code']; $text = strtolower($account['name'].' '.($account['subtype'] ?? '').' '.($account['control_type'] ?? '').' '.$account['lineage']);
        return $account['type_normalized'] === 'asset' && (in_array(strtolower((string) ($account['subtype'] ?? '')), ['cash', 'bank'], true) || str_contains($text, 'cash') || str_contains($text, 'bank') || $code === '1010' || preg_match('/^102\d+$/', $code) === 1);
    }

    private function isCurrentAsset(array $account): bool
    {
        $text = strtolower($account['name'].' '.($account['subtype'] ?? '').' '.$account['lineage']);
        return $this->isCashBank($account) || str_contains($text, 'current') || str_contains($text, 'receivable') || str_contains($text, 'advance') || preg_match('/^(10|11)/', $account['code']) === 1;
    }

    private function isNonCurrent(array $account): bool
    {
        $text = strtolower($account['name'].' '.($account['subtype'] ?? '').' '.$account['lineage']);
        return str_contains($text, 'non-current') || str_contains($text, 'long term') || str_contains($text, 'long-term');
    }

    private function isActive(array $account): bool
    {
        if (! array_key_exists('active', $account)) return ! isset($account['status']) || ! in_array(strtolower((string) $account['status']), ['inactive', 'disabled', 'closed'], true);
        return (bool) $account['active'];
    }

    private function isPosting(array $account): bool
    {
        if (! array_key_exists('posting', $account)) return true;
        return ! in_array(strtolower(trim((string) $account['posting'])), ['0', 'false', 'no', 'disabled'], true);
    }

    private function controlAccountIds(array $accounts, string $control, string $fallbackCode): array
    {
        $ids = array_column(array_filter($accounts, fn (array $account): bool => strtoupper(trim((string) ($account['control_type'] ?? ''))) === $control), 'id');
        if ($ids !== []) return array_map('intval', $ids);
        return array_map('intval', array_column(array_filter($accounts, fn (array $account): bool => $account['code'] === $fallbackCode), 'id'));
    }

    private function fiscalYear(string $asOf): array
    {
        foreach (['fiscal_years', 'financial_years', 'fiscal_year'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $columns = Schema::getColumnListing($table); $start = $this->first($columns, ['start_date', 'date_from', 'from_date', 'starts_on', 'year_start', 'period_start', 'start_on']); $end = $this->first($columns, ['end_date', 'date_to', 'to_date', 'ends_on', 'year_end', 'period_end', 'end_on']);
            if (! $start || ! $end) continue;
            $row = DB::table($table)->whereDate($start, '<=', $asOf)->whereDate($end, '>=', $asOf)->first();
            if ($row) return ['start' => Carbon::parse($row->{$start})->toDateString(), 'end' => Carbon::parse($row->{$end})->toDateString(), 'authority' => $table.'.'.$start.'/'.$end];
        }
        $date = Carbon::parse($asOf);
        return ['start' => $date->copy()->startOfYear()->toDateString(), 'end' => $date->copy()->endOfYear()->toDateString(), 'authority' => 'Calendar-year fallback (no native fiscal range resolved)'];
    }

    private function managementPeriods(string $asOf, string $fiscalStart): array
    {
        $date = Carbon::parse($asOf); $lastMonth = $date->copy()->subMonthNoOverflow();
        return [
            'today' => ['label' => 'Today', 'from' => $asOf, 'to' => $asOf],
            'yesterday' => ['label' => 'Yesterday', 'from' => $date->copy()->subDay()->toDateString(), 'to' => $date->copy()->subDay()->toDateString()],
            'month_to_date' => ['label' => 'This Month', 'from' => $date->copy()->startOfMonth()->toDateString(), 'to' => $asOf],
            'previous_month' => ['label' => 'Last Month', 'from' => $lastMonth->copy()->startOfMonth()->toDateString(), 'to' => $lastMonth->copy()->endOfMonth()->toDateString()],
            'year_to_date' => ['label' => 'Year to Date', 'from' => $fiscalStart, 'to' => $asOf],
        ];
    }

    private function lineage(array $account, Collection $byId, Collection $byCode, ?string $parentField): string
    {
        if (! $parentField) return '';
        $names = []; $value = $account['parent'] ?? null; $seen = [];
        for ($depth = 0; $depth < 8 && $value !== null && $value !== ''; $depth++) {
            $parent = $byId->get((string) $value) ?? $byCode->get((string) $value);
            if (! $parent || isset($seen[(string) $parent['id']])) break;
            $seen[(string) $parent['id']] = true; $names[] = (string) $parent['name']; $value = $parent['parent'] ?? null;
        }
        return implode(' / ', $names);
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if (str_contains($type, 'asset')) return 'asset';
        if (str_contains($type, 'liab')) return 'liability';
        if (str_contains($type, 'equity') || str_contains($type, 'capital')) return 'equity';
        if (str_contains($type, 'income') || str_contains($type, 'revenue')) return 'income';
        if (str_contains($type, 'expense') || str_contains($type, 'cost')) return 'expense';
        return $type;
    }

    private function statementRow(array $account, float $amount): array
    {
        return ['account_id' => $account['id'], 'code' => $account['code'], 'name' => $account['name'], 'amount' => round($amount, 2)];
    }

    private function sectionRowsTotal(array $rows): float { return round(array_sum(array_column($rows, 'amount')), 2); }
    private function sectionTotal(array $sections, array $keys): float { return array_sum(array_map(fn (string $key): float => $this->sectionRowsTotal($sections[$key]), $keys)); }
    private function date(string $value): string { try { return Carbon::parse($value)->toDateString(); } catch (Throwable) { return now()->toDateString(); } }
    private function first(array $columns, array $candidates): ?string { foreach ($candidates as $candidate) if (in_array($candidate, $columns, true)) return $candidate; return null; }
}
