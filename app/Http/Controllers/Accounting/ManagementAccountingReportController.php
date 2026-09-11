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
        return view('accounting.management-reporting.profit-and-loss', $this->viewData($filters) + [
            'report' => $this->reports->profitAndLoss($filters),
            'accountUrls' => $this->accountUrls($this->reports->accounts()),
        ]);
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
}
