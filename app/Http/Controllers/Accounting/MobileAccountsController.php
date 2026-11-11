<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashVoucherService;
use App\Services\Accounting\ChartOfAccountsWorkspaceService;
use App\Services\Accounting\ManagementAccountingReportService;
use App\Services\Operations\NativeErpLayoutResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/**
 * Read-only presentation adapter for the Mobile Accounts workspace.
 *
 * Financial values come from the existing management accounting report
 * service; this controller does not create a second calculation engine.
 */
final class MobileAccountsController extends Controller
{
    public function __construct(
        private readonly NativeErpLayoutResolver $layout,
        private readonly ManagementAccountingReportService $reports,
        private readonly CashVoucherService $vouchers,
        private readonly ChartOfAccountsWorkspaceService $chart,
    ) {}

    public function home(Request $request): View
    {
        $data = $this->financialSnapshot($request);
        return $this->page('home', 'Accounts', [
            'balances' => $data['balances'],
            'today' => $data['today'],
            'quick' => ['Receipt', 'Payment', 'Expense', 'Journal'],
            'pendingCount' => $this->pendingApprovals($request)->count(),
            'recent' => $this->recentTransactions($request),
        ]);
    }

    public function entries(Request $request): View
    {
        return $this->page('entries', 'Entries', [
            'cards' => [
                'Create Receipt', 'Create Payment', 'Quick Expense',
                'Journal Voucher', 'Customer Receipt', 'Vendor Payment',
            ],
        ]);
    }

    public function approvals(Request $request): View
    {
        return $this->page('approvals', 'Approvals', [
            'cards' => $this->pendingApprovals($request)->map(fn (object $row): array => [
                'number' => (string) ($row->voucher_no ?? 'Voucher #'.$row->id),
                'type' => $this->voucherLabel((string) ($row->voucher_type ?? '')),
                'party' => trim((string) ($row->party_name ?? '')) ?: 'Accounting voucher',
                'amount' => $this->money((float) ($row->amount ?? 0) * (float) ($row->exchange_rate ?: 1)),
                'account' => (string) ($row->cash_bank_account_name ?? 'Cash / Bank'),
                'date' => (string) ($row->voucher_date ?? ''),
                'status' => (string) ($row->status ?? 'pending_approval'),
            ])->all(),
        ]);
    }

    public function reports(Request $request): View
    {
        return $this->page('reports', 'Reports', [
            'cards' => [
                ['Cash / Bank', route('accounting.management-reports.management')],
                ['Customer Balances', '#'], ['Vendor Balances', '#'], ['Account Ledger', '#'],
                ['Trial Balance', route('accounting.management-reports.trial-balance')],
                ['Profit & Loss', route('accounting.management-reports.profit-and-loss')],
            ],
            'ledger' => null,
        ]);
    }

    public function more(Request $request): View
    {
        $accounts = [];
        try {
            $accounts = $this->chart->parentOptions(50);
        } catch (Throwable) {
            $accounts = [];
        }

        return $this->page('more', 'More', [
            'cards' => [
                ['Chart of Accounts', route('accounting.chart-of-accounts.workspace')],
                ['Profile', '#'], ['Settings', '#'], ['Desktop ERP', url('/')],
            ],
            'accounts' => $accounts,
        ]);
    }

    private function financialSnapshot(Request $request): array
    {
        $empty = [
            'balances' => [
                ['label' => 'Cash Balance', 'value' => null, 'tone' => ''],
                ['label' => 'Bank Balance', 'value' => null, 'tone' => 'green'],
                ['label' => 'Receivables', 'value' => null, 'tone' => ''],
                ['label' => 'Payables', 'value' => null, 'tone' => 'red'],
            ],
            'today' => [
                ['label' => 'Receipts', 'value' => null, 'tone' => 'green'],
                ['label' => 'Payments', 'value' => null, 'tone' => 'red'],
                ['label' => 'Expenses', 'value' => null, 'tone' => ''],
            ],
        ];

        try {
            $filters = $this->reports->filters($request, 'management');
            $report = $this->reports->management($filters);
            $cash = 0.0;
            $bank = 0.0;
            foreach ((array) ($report['cash_rows'] ?? []) as $row) {
                $value = (float) ($row['amount'] ?? 0);
                $text = strtolower((string) (($row['name'] ?? '').' '.($row['subtype'] ?? '')));
                if (str_contains($text, 'bank')) $bank += $value;
                else $cash += $value;
            }
            $position = (array) ($report['position'] ?? []);
            return [
                'balances' => [
                    ['label' => 'Cash Balance', 'value' => $this->money($cash), 'tone' => ''],
                    ['label' => 'Bank Balance', 'value' => $this->money($bank), 'tone' => 'green'],
                    ['label' => 'Receivables', 'value' => $this->money((float) ($position['customer_receivables'] ?? 0)), 'tone' => ''],
                    ['label' => 'Payables', 'value' => $this->money((float) ($position['vendor_payables'] ?? 0)), 'tone' => 'red'],
                ],
                'today' => [
                    ['label' => 'Receipts', 'value' => $this->money((float) ($report['cash_inflow_today'] ?? 0)), 'tone' => 'green'],
                    ['label' => 'Payments', 'value' => $this->money((float) ($report['cash_outflow_today'] ?? 0)), 'tone' => 'red'],
                    ['label' => 'Expenses', 'value' => $this->money((float) ($report['today']['operating_expenses'] ?? 0)), 'tone' => ''],
                ],
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    private function pendingApprovals(Request $request)
    {
        if (! Schema::hasTable('cash_vouchers')) return collect();
        $types = array_values(array_filter(
            ['receipt', 'payment', 'expense', 'contra', 'customer_advance', 'supplier_advance'],
            fn (string $type): bool => $this->vouchers->canUseType($request->user(), $type, 'view')
        ));
        if ($types === []) return collect();
        $query = DB::table('cash_vouchers')->whereIn('voucher_type', $types)->where('status', 'pending_approval');
        $this->applyBranchScope($query, $request);
        return $query->orderByDesc('voucher_date')->orderByDesc('id')->limit(25)->get();
    }

    private function recentTransactions(Request $request)
    {
        if (! Schema::hasTable('cash_vouchers')) return collect();
        $types = array_values(array_filter(
            ['receipt', 'payment', 'expense', 'contra', 'customer_advance', 'supplier_advance'],
            fn (string $type): bool => $this->vouchers->canUseType($request->user(), $type, 'view')
        ));
        if ($types === []) return collect();
        $query = DB::table('cash_vouchers')->whereIn('voucher_type', $types)->whereIn('status', ['posted', 'approved']);
        $this->applyBranchScope($query, $request);
        return $query->orderByDesc('voucher_date')->orderByDesc('id')->limit(5)->get()->map(fn (object $row): array => [
            'number' => (string) ($row->voucher_no ?? 'Voucher #'.$row->id),
            'type' => $this->voucherLabel((string) ($row->voucher_type ?? '')),
            'party' => trim((string) ($row->party_name ?? '')) ?: (string) ($row->narration ?? 'Accounting transaction'),
            'amount' => $this->money((float) ($row->amount ?? 0) * (float) ($row->exchange_rate ?: 1)),
            'date' => (string) ($row->voucher_date ?? ''),
            'status' => (string) ($row->status ?? ''),
        ]);
    }

    private function applyBranchScope($query, Request $request): void
    {
        $userBranch = $request->user()?->branch_id ?? null;
        if ($userBranch && Schema::hasColumn('cash_vouchers', 'branch_id')) {
            $query->where('branch_id', (int) $userBranch);
        }
    }

    private function voucherLabel(string $type): string
    {
        return match ($type) {
            'receipt' => 'Receipt',
            'payment' => 'Payment',
            'expense' => 'Expense',
            'contra' => 'Journal / Contra',
            default => ucwords(str_replace('_', ' ', $type ?: 'Voucher')),
        };
    }

    private function money(float $value): string
    {
        return 'PKR '.number_format($value, 2);
    }

    private function page(string $page, string $title, array $data): View
    {
        return view('mobile-accounts.'.$page, array_merge($data, [
            'layoutMeta' => $this->layout->resolve(),
            'page' => $page,
            'title' => $title,
        ]));
    }
}
