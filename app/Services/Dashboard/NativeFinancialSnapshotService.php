<?php

namespace App\Services\Dashboard;

use App\Services\Accounting\ChartOfAccountsWorkspaceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class NativeFinancialSnapshotService
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
    ) {
    }

    public function get(Request $request): array
    {
        $empty = [
            'today_sales' => 0.0,
            'month_sales' => 0.0,
            'receivables' => 0.0,
            'payables' => 0.0,
            'cash_bank' => 0.0,
            'gross_profit' => 0.0,
            'today_collection' => 0.0,
            'today_payments' => 0.0,
            'period_supplier_cost' => 0.0,
            'vendor_advances' => 0.0,
            'customer_advances' => 0.0,
            'pending_cash_vouchers' => 0,
            'recent_voucher_refs' => [],
        ];

        if (
            ! Schema::hasTable('journal_entries')
            || ! Schema::hasTable('journal_lines')
        ) {
            return $empty;
        }

        try {
            $schema = $this->chart->schema();
            $accounts = DB::table($schema['table'])
                ->select([
                    $schema['id'].' as id',
                    $schema['code'].' as code',
                    $schema['type'].' as type',
                    ...($schema['subtype'] ? [$schema['subtype'].' as subtype'] : []),
                    ...($schema['control_type'] ? [$schema['control_type'].' as control_type'] : []),
                ])
                ->get();

            $idsByControl = [];
            $cashBankIds = [];
            $incomeIds = [];
            $directCostIds = [];

            foreach ($accounts as $account) {
                $id = (int) $account->id;
                $code = trim((string) $account->code);
                $type = strtolower(trim((string) $account->type));
                $subtype = strtolower(trim((string) ($account->subtype ?? '')));
                $control = strtoupper(trim((string) ($account->control_type ?? '')));

                if ($control !== '') {
                    $idsByControl[$control][] = $id;
                }

                if (
                    in_array($subtype, ['bank', 'cash'], true)
                    || $code === '1010'
                    || $code === '1020'
                    || preg_match('/^102\d+$/', $code)
                ) {
                    $cashBankIds[] = $id;
                }

                if ($type === 'income') {
                    $incomeIds[] = $id;
                }

                if (
                    $type === 'expense'
                    && (
                        str_starts_with($code, '51')
                        || str_contains($subtype, 'direct')
                        || str_contains($subtype, 'cost')
                    )
                ) {
                    $directCostIds[] = $id;
                }
            }

            $today = Carbon::today()->toDateString();
            $monthStart = Carbon::today()->startOfMonth()->toDateString();
            $monthEnd = Carbon::today()->endOfMonth()->toDateString();

            $branchId = $this->branchId($request);

            $snapshot = $empty;

            $snapshot['today_sales'] = $this->naturalCredit(
                $incomeIds,
                $today,
                $today,
                $branchId
            );

            $snapshot['month_sales'] = $this->naturalCredit(
                $incomeIds,
                $monthStart,
                $monthEnd,
                $branchId
            );

            $snapshot['period_supplier_cost'] = $this->naturalDebit(
                $directCostIds,
                $monthStart,
                $monthEnd,
                $branchId
            );

            $snapshot['gross_profit'] =
                $snapshot['month_sales'] - $snapshot['period_supplier_cost'];

            $snapshot['receivables'] = $this->naturalDebit(
                $idsByControl['CUSTOMER_AR'] ?? [],
                null,
                null,
                $branchId
            );

            $snapshot['payables'] = $this->naturalCredit(
                $idsByControl['VENDOR_AP'] ?? [],
                null,
                null,
                $branchId
            );

            $snapshot['vendor_advances'] = $this->naturalDebit(
                $idsByControl['VENDOR_ADVANCE'] ?? [],
                null,
                null,
                $branchId
            );

            $snapshot['customer_advances'] = $this->naturalCredit(
                $idsByControl['CUSTOMER_ADVANCE'] ?? [],
                null,
                null,
                $branchId
            );

            // Asset-side signed balance. Positive = Dr; negative = Cr.
            $snapshot['cash_bank'] = $this->signedDebitMinusCredit(
                $cashBankIds,
                null,
                null,
                $branchId
            );

            if (Schema::hasTable('cash_vouchers')) {
                $snapshot['today_collection'] = (float) DB::table('cash_vouchers')
                    ->where('status', 'posted')
                    ->whereIn('voucher_type', ['receipt', 'customer_advance'])
                    ->whereDate('voucher_date', $today)
                    ->selectRaw('COALESCE(SUM(amount * COALESCE(exchange_rate,1)),0) as total')
                    ->value('total');

                $snapshot['today_payments'] = (float) DB::table('cash_vouchers')
                    ->where('status', 'posted')
                    ->whereIn('voucher_type', ['payment', 'supplier_advance'])
                    ->whereDate('voucher_date', $today)
                    ->selectRaw('COALESCE(SUM(amount * COALESCE(exchange_rate,1)),0) as total')
                    ->value('total');

                $snapshot['pending_cash_vouchers'] = (int) DB::table('cash_vouchers')
                    ->where('status', 'pending_approval')
                    ->count();

                $snapshot['recent_voucher_refs'] = DB::table('cash_vouchers')
                    ->whereNotNull('posting_reference')
                    ->where('posting_reference', '!=', '')
                    ->orderByDesc('id')
                    ->limit(30)
                    ->pluck('voucher_no', 'posting_reference')
                    ->mapWithKeys(static fn ($voucherNo, $postingRef): array => [
                        (string) $postingRef => (string) $voucherNo,
                    ])
                    ->all();
            }

            return $snapshot;
        } catch (Throwable $e) {
            report($e);
            return $empty;
        }
    }

    private function signedDebitMinusCredit(
        array $accountIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $branchId
    ): float {
        if ($accountIds === []) {
            return 0.0;
        }

        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereIn('jl.account_id', array_values(array_unique($accountIds)))
            ->whereIn('je.status', ['posted', 'reversed']);

        if ($dateFrom) {
            $query->whereDate('je.journal_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('je.journal_date', '<=', $dateTo);
        }
        if ($branchId && Schema::hasColumn('journal_entries', 'branch_id')) {
            $query->where('je.branch_id', $branchId);
        }

        return (float) (
            $query->selectRaw(
                'COALESCE(SUM(COALESCE(jl.debit,0) - COALESCE(jl.credit,0)),0) as balance'
            )->value('balance') ?? 0
        );
    }

    private function naturalDebit(
        array $accountIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $branchId
    ): float {
        return $this->signedDebitMinusCredit(
            $accountIds,
            $dateFrom,
            $dateTo,
            $branchId
        );
    }

    private function naturalCredit(
        array $accountIds,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $branchId
    ): float {
        return -1 * $this->signedDebitMinusCredit(
            $accountIds,
            $dateFrom,
            $dateTo,
            $branchId
        );
    }

    private function branchId(Request $request): ?int
    {
        foreach (['branch_id', 'branch'] as $key) {
            $value = (int) $request->query($key, 0);

            if ($value > 0) {
                return $value;
            }
        }

        return null;
    }
}
