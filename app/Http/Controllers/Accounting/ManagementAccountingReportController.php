<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\ManagementAccountingReportService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

final class ManagementAccountingReportController extends Controller
{
    public function __construct(
        private readonly ManagementAccountingReportService $reports,
        private readonly NativeErpLayoutResolver $layout,
    ) {}

    public function management(Request $request)
    {
        $filters = $this->reports->filters($request, 'management');
        $report = $this->reports->management($filters);
        return view('accounting.management-reporting.management', $this->viewData($filters) + [
            'report' => $report,
            'accountUrls' => $this->accountUrls($this->reports->accounts()),
            'customerLedgerUrl' => $this->nativeReportUrl('customer', $filters),
            'vendorLedgerUrl' => $this->nativeReportUrl('vendor', $filters),
            'trialBalanceUrl' => route('accounting.management-reports.trial-balance', $this->query($filters)),
        ]);
    }

    public function profitAndLoss(Request $request)
    {
        $filters = $this->reports->filters($request, 'profit-and-loss');
        $report = $this->reports->profitAndLoss($filters);
        $presentation = $this->profitAndLossPresentation(
            $report,
            $this->accountUrls($this->reports->accounts())
        );

        return view(
            'accounting.management-reporting.profit-and-loss',
            $this->viewData($filters) + $presentation
        );
    }

    public function balanceSheet(Request $request)
    {
        $filters = $this->reports->filters($request, 'balance-sheet');
        return view('accounting.management-reporting.balance-sheet', $this->viewData($filters) + [
            'report' => $this->reports->balanceSheet($filters),
            'accountUrls' => $this->accountUrls($this->reports->accounts()),
        ]);
    }

    public function trialBalance(Request $request)
    {
        $filters = $this->reports->filters($request, 'trial-balance');
        $url = $this->nativeReportUrl('trial_balance', $filters);
        abort_unless($url, 404, 'Native Trial Balance route is unavailable.');
        return redirect()->to($url);
    }

    private function viewData(array $filters): array
    {
        return [
            'filters' => $filters,
            'layoutMeta' => $this->layout->resolve(),
            'reportCenterUrl' => $this->nativeReportUrl(null, $filters),
        ];
    }

    private function accountUrls(array $accounts): array
    {
        $urls = [];
        try {
            $route = Route::getRoutes()->getByName('accounting.ledgers.account');
            if (! $route || ! in_array('GET', $route->methods(), true) || count($route->parameterNames()) !== 1) return $urls;
            $parameter = $route->parameterNames()[0];
            $binding = method_exists($route, 'bindingFieldFor') ? $route->bindingFieldFor($parameter) : null;
            foreach ($accounts as $account) {
                $value = in_array($binding, ['code', 'account_code'], true) ? $account['code'] : $account['id'];
                $urls[$account['code']] = route('accounting.ledgers.account', [$parameter => $value]);
            }
        } catch (Throwable) {
            return [];
        }
        return $urls;
    }

    private function nativeReportUrl(?string $type, array $filters): ?string
    {
        try {
            $route = Route::getRoutes()->getByName('accounting.reports.index');
            if (! $route || ! in_array('GET', $route->methods(), true)) return null;
            $query = $this->query($filters);
            if ($type) $query['report_type'] = $type;
            return route('accounting.reports.index', $query);
        } catch (Throwable) {
            return null;
        }
    }

    private function query(array $filters): array
    {
        return array_filter([
            'as_of' => $filters['as_of'], 'date_from' => $filters['from'], 'date_to' => $filters['to'],
            'branch_id' => $filters['branch_id'],
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function profitAndLossPresentation(array $report, array $accountUrls): array
    {
        $sectionDefinitions = [
            'revenue' => 'Revenue',
            'direct_cost' => 'Less: Direct Travel / Supplier Cost',
            'operating_expense' => 'Less: Operating Expenses',
            'other_income' => 'Other Income',
            'other_expense' => 'Other Expense',
        ];
        $sectionRows = [];

        foreach ($sectionDefinitions as $key => $title) {
            $previousByCode = collect($report['previous']['sections'][$key])->keyBy('code');
            $lines = [];

            foreach ($report['sections'][$key] as $line) {
                $previous = (float) ($previousByCode[$line['code']]['amount'] ?? 0);
                $lines[] = $this->profitAndLossDisplayRow(
                    (float) $line['amount'],
                    $previous
                ) + [
                    'code' => (string) $line['code'],
                    'name' => (string) $line['name'],
                    'account_url' => $accountUrls[$line['code']] ?? null,
                ];
            }

            $sectionRows[] = ['key' => $key, 'title' => $title, 'lines' => $lines];
        }

        $summaryDefinitions = [
            ['TOTAL REVENUE', 'revenue'],
            ['TOTAL DIRECT COST', 'direct_cost'],
            ['GROSS PROFIT', 'gross_profit'],
            ['TOTAL OPERATING EXPENSES', 'operating_expenses'],
            ['OPERATING PROFIT', 'operating_profit'],
            ['OTHER INCOME', 'other_income'],
            ['OTHER EXPENSE', 'other_expense'],
            ['NET PROFIT / LOSS', 'net_profit'],
        ];
        $summaryRows = [];

        foreach ($summaryDefinitions as [$label, $key]) {
            $current = (float) $report[$key];
            $summaryRows[] = $this->profitAndLossDisplayRow(
                $current,
                (float) $report['previous'][$key]
            ) + [
                'label' => $label,
                'key' => $key,
                'row_class' => $key === 'net_profit' ? 'grand' : 'total',
                'current_class' => str_contains($key, 'profit')
                    ? ($current >= 0 ? 'good' : 'bad')
                    : '',
            ];
        }

        return ['sectionRows' => $sectionRows, 'summaryRows' => $summaryRows];
    }

    private function profitAndLossDisplayRow(float $current, float $previous): array
    {
        $variance = round($current - $previous, 2);
        $variancePercent = abs($previous) > 0.005
            ? round($variance / abs($previous) * 100, 2)
            : null;

        return [
            'current' => round($current, 2),
            'previous' => round($previous, 2),
            'variance' => $variance,
            'variance_percent' => $variancePercent,
            'current_display' => $this->money($current),
            'previous_display' => $this->money($previous),
            'variance_display' => $this->money($variance),
            'variance_percent_display' => $variancePercent === null
                ? '—'
                : number_format($variancePercent, 2).'%',
        ];
    }

    private function money(float $value): string
    {
        return 'PKR '.number_format($value, 2);
    }
}
