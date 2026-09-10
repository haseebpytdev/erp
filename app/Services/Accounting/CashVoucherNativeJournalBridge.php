<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * ERP-11.3.30
 *
 * Bridges the controlled cash-voucher posting lines into the ERP's native
 * journal_entries / journal_lines subsystem so existing Ledger and Report
 * controllers see Receipt, Payment and Advance activity.
 *
 * Source of truth for debit/credit is cash_voucher_posting_lines. This bridge
 * never reconstructs accounting from UI fields.
 */
final class CashVoucherNativeJournalBridge
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
    ) {
    }

    public function postVoucher(int $voucherId, mixed $user = null): int
    {
        return DB::transaction(function () use ($voucherId, $user): int {
            $voucher = DB::table('cash_vouchers')
                ->where('id', $voucherId)
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                throw new RuntimeException('Cash voucher not found for native journal posting.');
            }

            $existing = $this->findJournal('cash_voucher', $voucherId, (string) $voucher->voucher_no);
            if ($existing) {
                return $existing;
            }

            $lines = DB::table('cash_voucher_posting_lines')
                ->where('cash_voucher_id', $voucherId)
                ->where('entry_type', 'original')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('Cash voucher native-journal posting stopped because source posting lines are missing.');
            }

            return $this->createNativeJournal(
                voucher: $voucher,
                postingLines: $lines,
                sourceType: 'cash_voucher',
                sourceId: $voucherId,
                journalNo: (string) ($voucher->posting_reference ?: $voucher->voucher_no),
                descriptionPrefix: (string) $voucher->voucher_type === 'expense'
                    ? 'Expense voucher'
                    : 'Cash voucher',
                user: $user,
                reversalOfId: null,
            );
        });
    }

    public function postReversal(int $voucherId, mixed $user = null): int
    {
        return DB::transaction(function () use ($voucherId, $user): int {
            $voucher = DB::table('cash_vouchers')
                ->where('id', $voucherId)
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                throw new RuntimeException('Cash voucher not found for native journal reversal.');
            }

            $existing = $this->findJournal(
                'cash_voucher_reversal',
                $voucherId,
                (string) ($voucher->reversal_reference ?: '')
            );
            if ($existing) {
                return $existing;
            }

            $lines = DB::table('cash_voucher_posting_lines')
                ->where('cash_voucher_id', $voucherId)
                ->where('entry_type', 'reversal')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new RuntimeException('Cash voucher native-journal reversal stopped because reversal posting lines are missing.');
            }

            $originalId = $this->findJournal(
                'cash_voucher',
                $voucherId,
                (string) $voucher->voucher_no
            );

            if (! $originalId) {
                // Historical safety: create the missing original first so the
                // reversal always has a real native source entry.
                $originalId = $this->postVoucher($voucherId, $user);
            }

            return $this->createNativeJournal(
                voucher: $voucher,
                postingLines: $lines,
                sourceType: 'cash_voucher_reversal',
                sourceId: $voucherId,
                journalNo: (string) ($voucher->reversal_reference ?: ('CVREV-'.$voucherId)),
                descriptionPrefix: 'Cash voucher reversal',
                user: $user,
                reversalOfId: $originalId,
            );
        });
    }

    public function postSupplierCosting(int $costingId, mixed $user = null, ?string $postingReference = null): int
    {
        return DB::transaction(function () use ($costingId, $user, $postingReference): int {
            $row=DB::table('supplier_costings')->where('id',$costingId)->lockForUpdate()->first();
            if(!$row)throw new RuntimeException('Supplier costing not found for native journal posting.');
            $existing=$this->findJournal('supplier_costing',$costingId,(string)$row->costing_no);if($existing)return $existing;
            $lines=DB::table('supplier_costing_posting_lines')->where('supplier_costing_id',$costingId)->orderBy('id')->lockForUpdate()->get();if($lines->isEmpty())throw new RuntimeException('Supplier costing native-journal posting stopped because source posting lines are missing.');
            $doc=(object)['voucher_no'=>$row->costing_no,'voucher_date'=>$row->cost_date,'voucher_type'=>'supplier_costing','booking_id'=>$row->booking_id,'party_name'=>$row->supplier_name,'currency_code'=>$row->currency_code,'exchange_rate'=>$row->exchange_rate,'narration'=>$row->remarks,'created_by'=>$row->created_by,'approved_by'=>$row->approved_by,'approved_at'=>$row->approved_at,'posted_by'=>$row->posted_by,'posted_at'=>$row->posted_at,'created_at'=>$row->created_at];
            return $this->createNativeJournal($doc,$lines,'supplier_costing',$costingId,(string)($postingReference ?: $row->posting_reference ?: $row->costing_no),'Supplier costing',$user,null,'supplier_costing','supplier_costing_posting_line');
        });
    }

    public function postAdvanceAdjustment(int $adjustmentId, mixed $user = null, ?string $postingReference = null): int
    {
        return DB::transaction(function () use ($adjustmentId,$user,$postingReference): int {
            $row=DB::table('advance_adjustments')->where('id',$adjustmentId)->lockForUpdate()->first();if(!$row)throw new RuntimeException('Advance adjustment not found for native journal posting.');$existing=$this->findJournal('advance_adjustment',$adjustmentId,(string)$row->adjustment_no);if($existing)return $existing;
            $lines=DB::table('advance_adjustment_posting_lines')->where('advance_adjustment_id',$adjustmentId)->where('entry_type','original')->orderBy('id')->lockForUpdate()->get();if($lines->isEmpty())throw new RuntimeException('Advance adjustment native-journal posting stopped because source posting lines are missing.');
            $doc=$this->adjustmentDocument($row);return $this->createNativeJournal($doc,$lines,'advance_adjustment',$adjustmentId,(string)($postingReference ?: $row->posting_reference ?: $row->adjustment_no),'Advance adjustment',$user,null,'advance_adjustment','advance_adjustment_posting_line');
        });
    }

    public function postAdvanceAdjustmentReversal(int $adjustmentId, mixed $user = null): int
    {
        return DB::transaction(function () use ($adjustmentId,$user): int {
            $row=DB::table('advance_adjustments')->where('id',$adjustmentId)->lockForUpdate()->first();if(!$row)throw new RuntimeException('Advance adjustment not found for native journal reversal.');$existing=$this->findJournal('advance_adjustment_reversal',$adjustmentId,(string)($row->reversal_reference?:''));if($existing)return $existing;
            $lines=DB::table('advance_adjustment_posting_lines')->where('advance_adjustment_id',$adjustmentId)->where('entry_type','reversal')->orderBy('id')->lockForUpdate()->get();if($lines->isEmpty())throw new RuntimeException('Advance adjustment reversal source lines are missing.');
            $original=$this->findJournal('advance_adjustment',$adjustmentId,(string)$row->adjustment_no);if(!$original)$original=$this->postAdvanceAdjustment($adjustmentId,$user);$doc=$this->adjustmentDocument($row);return $this->createNativeJournal($doc,$lines,'advance_adjustment_reversal',$adjustmentId,(string)($row->reversal_reference?:('AAREV-'.$adjustmentId)),'Advance adjustment reversal',$user,$original,'advance_adjustment','advance_adjustment_posting_line');
        });
    }

    private function adjustmentDocument(object $row): object
    {
        return (object)['voucher_no'=>$row->adjustment_no,'voucher_date'=>$row->adjustment_date,'voucher_type'=>'advance_adjustment','booking_id'=>$row->booking_id,'party_name'=>$row->party_name,'currency_code'=>$row->currency_code,'exchange_rate'=>$row->exchange_rate,'narration'=>$row->remarks,'created_by'=>$row->created_by,'approved_by'=>$row->approved_by,'approved_at'=>$row->approved_at,'posted_by'=>$row->posted_by,'posted_at'=>$row->posted_at,'created_at'=>$row->created_at];
    }


    public function backfillPostedVouchers(mixed $user = null): array
    {
        $result = [
            'scanned' => 0,
            'created' => 0,
            'skipped' => 0,
            'failed' => 0,
            'rows' => [],
        ];

        DB::table('cash_vouchers')
            ->where('status', 'posted')
            ->orderBy('id')
            ->chunkById(50, function ($rows) use (&$result, $user): void {
                foreach ($rows as $voucher) {
                    $result['scanned']++;

                    try {
                        $existing = $this->findJournal(
                            'cash_voucher',
                            (int) $voucher->id,
                            (string) $voucher->voucher_no
                        );

                        if ($existing) {
                            $result['skipped']++;
                            $result['rows'][] = [
                                'voucher' => $voucher->voucher_no,
                                'result' => 'Already linked',
                                'journal_id' => $existing,
                            ];
                            continue;
                        }

                        $journalId = $this->postVoucher((int) $voucher->id, $user);
                        $result['created']++;
                        $result['rows'][] = [
                            'voucher' => $voucher->voucher_no,
                            'result' => 'Native journal created',
                            'journal_id' => $journalId,
                        ];
                    } catch (Throwable $e) {
                        report($e);
                        $result['failed']++;
                        $result['rows'][] = [
                            'voucher' => $voucher->voucher_no,
                            'result' => 'FAILED: '.$e->getMessage(),
                            'journal_id' => null,
                        ];
                    }
                }
            });

        return $result;
    }

    public function previewMissing(): array
    {
        $rows = [];

        if (! Schema::hasTable('cash_vouchers')) {
            return $rows;
        }

        foreach (
            DB::table('cash_vouchers')
                ->where('status', 'posted')
                ->orderBy('id')
                ->get([
                    'id', 'voucher_no', 'voucher_type', 'party_type', 'party_id',
                    'party_name', 'voucher_date', 'amount', 'currency_code',
                    'cash_bank_account_code', 'posting_reference',
                ])
            as $voucher
        ) {
            $journalId = $this->findJournal(
                'cash_voucher',
                (int) $voucher->id,
                (string) $voucher->voucher_no
            );

            $rows[] = [
                'id' => (int) $voucher->id,
                'voucher_no' => (string) $voucher->voucher_no,
                'voucher_type' => (string) $voucher->voucher_type,
                'party_type' => (string) $voucher->party_type,
                'party_id' => $voucher->party_id ? (int) $voucher->party_id : null,
                'party_name' => (string) ($voucher->party_name ?? ''),
                'voucher_date' => (string) $voucher->voucher_date,
                'amount' => (float) $voucher->amount,
                'currency_code' => (string) $voucher->currency_code,
                'bank' => (string) $voucher->cash_bank_account_code,
                'posting_reference' => (string) ($voucher->posting_reference ?? ''),
                'native_journal_id' => $journalId,
                'needs_backfill' => ! $journalId,
            ];
        }

        return $rows;
    }

    private function createNativeJournal(
        object $voucher,
        iterable $postingLines,
        string $sourceType,
        int $sourceId,
        string $journalNo,
        string $descriptionPrefix,
        mixed $user,
        ?int $reversalOfId,
        string $origin = 'cash_voucher',
        string $sourceLineType = 'cash_voucher_posting_line',
    ): int {
        $this->assertNativeSchema();

        $accountSchema = $this->chart->schema();

        $lineRows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $totalDebitBase = 0.0;
        $totalCreditBase = 0.0;

        foreach ($postingLines as $index => $line) {
            $accountId = DB::table($accountSchema['table'])
                ->where($accountSchema['code'], (string) $line->account_code)
                ->value($accountSchema['id']);

            if (! $accountId) {
                throw new RuntimeException(
                    'Native journal posting stopped: Chart account '.$line->account_code.' was not found.'
                );
            }

            $debit = round((float) $line->debit, 2);
            $credit = round((float) $line->credit, 2);
            $rate = (float) ($line->exchange_rate ?: $voucher->exchange_rate ?: 1);
            $baseDebit = round($debit * $rate, 2);
            $baseCredit = round($credit * $rate, 2);

            $totalDebit += $debit;
            $totalCredit += $credit;
            $totalDebitBase += $baseDebit;
            $totalCreditBase += $baseCredit;

            $lineRows[] = [
                'line_no' => $index + 1,
                'account_id' => (int) $accountId,
                'description' => trim((string) ($voucher->narration ?? '')) !== ''
                    ? trim((string) $voucher->narration)
                    : (string) ($line->narration ?: ($descriptionPrefix.' '.$voucher->voucher_no)),
                'debit' => $debit,
                'credit' => $credit,
                'base_debit' => $baseDebit,
                'base_credit' => $baseCredit,
                // Native accounting uses "vendor" for supplier subledgers.
                // The cash-voucher module uses "supplier" operationally, so
                // normalize only at the native-journal boundary.
                'party_type' => $this->nativePartyType($line->party_type ?: null),
                'party_id' => $line->party_id ?: null,
                'source_line_type' => $sourceLineType,
                'source_line_id' => (int) $line->id,
            ];
        }

        $totalDebit = round($totalDebit, 2);
        $totalCredit = round($totalCredit, 2);
        $totalDebitBase = round($totalDebitBase, 2);
        $totalCreditBase = round($totalCreditBase, 2);

        if ($lineRows === [] || abs($totalDebit - $totalCredit) > 0.005) {
            throw new RuntimeException('Native journal posting stopped: source posting lines are not balanced.');
        }

        if (abs($totalDebitBase - $totalCreditBase) > 0.005) {
            throw new RuntimeException('Native journal posting stopped: base-currency source lines are not balanced.');
        }

        $context = $this->resolveContext($voucher, $user);
        $now = now();
        $createdBy = $this->positiveInt($voucher->created_by ?? null)
            ?: $this->positiveInt($user?->id ?? null)
            ?: $this->positiveInt($voucher->posted_by ?? null);
        $approvedBy = $this->positiveInt($voucher->approved_by ?? null)
            ?: $this->positiveInt($user?->id ?? null);
        $postedBy = $this->positiveInt($voucher->posted_by ?? null)
            ?: $this->positiveInt($user?->id ?? null);

        $header = [
            'company_id' => $context['company_id'],
            'branch_id' => $context['branch_id'],
            'fiscal_year_id' => $context['fiscal_year_id'],
            'accounting_period_id' => $context['accounting_period_id'],
            'journal_no' => $journalNo,
            'journal_date' => (string) $voucher->voucher_date,
            'journal_type' => $this->journalType((string) $voucher->voucher_type, $sourceType),
            'origin' => $origin,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reference' => (string) $voucher->voucher_no,
            'description' => trim((string) ($voucher->narration ?? '')) !== ''
                ? trim((string) $voucher->narration)
                : $descriptionPrefix.' '.$voucher->voucher_no
                    .($voucher->party_name ? ' · '.$voucher->party_name : ''),
            'currency_code' => strtoupper((string) ($voucher->currency_code ?: 'PKR')),
            'exchange_rate' => (float) ($voucher->exchange_rate ?: 1),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'total_debit_base' => $totalDebitBase,
            'total_credit_base' => $totalCreditBase,
            'status' => 'posted',
            'created_by' => $createdBy,
            'approved_by' => $approvedBy,
            'approved_at' => $voucher->approved_at ?? $voucher->posted_at ?? $now,
            'posted_by' => $postedBy,
            'posted_at' => $voucher->posted_at ?? $now,
            'reversal_of_id' => $reversalOfId,
            'created_at' => $voucher->created_at ?? $now,
            'updated_at' => $now,
        ];

        // Only pass columns that physically exist on this live native table.
        $headerColumns = Schema::getColumnListing('journal_entries');
        $header = array_intersect_key($header, array_flip($headerColumns));

        // ERP-11.3.30: fail with a readable diagnostic before MySQL's generic
        // "doesn't have a default value" error if another required native
        // header field is ever introduced.
        $this->assertRequiredNativeHeaderValues($header);

        $journalId = (int) DB::table('journal_entries')->insertGetId($header);

        $lineColumns = Schema::getColumnListing('journal_lines');
        foreach ($lineRows as &$lineRow) {
            $lineRow['journal_entry_id'] = $journalId;
            $lineRow['created_at'] = $now;
            $lineRow['updated_at'] = $now;
            $lineRow = array_intersect_key($lineRow, array_flip($lineColumns));
        }
        unset($lineRow);

        DB::table('journal_lines')->insert($lineRows);

        return $journalId;
    }

    private function findJournal(string $sourceType, int $sourceId, string $reference): ?int
    {
        if (! Schema::hasTable('journal_entries')) {
            return null;
        }

        $columns = Schema::getColumnListing('journal_entries');

        try {
            if (in_array('source_type', $columns, true) && in_array('source_id', $columns, true)) {
                $id = DB::table('journal_entries')
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->value('id');

                if ($id) {
                    return (int) $id;
                }
            }

            if ($reference !== '') {
                if (in_array('reference', $columns, true)) {
                    $id = DB::table('journal_entries')
                        ->where('reference', $reference)
                        ->where(function ($q) use ($columns, $sourceType): void {
                            if (in_array('source_type', $columns, true)) {
                                $q->where('source_type', $sourceType);
                            }
                        })
                        ->value('id');

                    if ($id) {
                        return (int) $id;
                    }
                }

                if (in_array('journal_no', $columns, true)) {
                    $id = DB::table('journal_entries')
                        ->where('journal_no', $reference)
                        ->value('id');

                    if ($id) {
                        return (int) $id;
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    private function resolveContext(object $voucher, mixed $user): array
    {
        $booking = null;
        if (! empty($voucher->booking_id) && Schema::hasTable('bookings')) {
            try {
                $booking = DB::table('bookings')->where('id', $voucher->booking_id)->first();
            } catch (Throwable $e) {
                report($e);
            }
        }

        $branchId = $this->firstPositive([
            $booking?->branch_id ?? null,
            $booking?->office_id ?? null,
            $this->userAttribute($user, 'primary_branch_id'),
            $this->userAttribute($user, 'branch_id'),
            $this->userAttribute($user, 'home_branch_id'),
        ]);

        if (! $branchId) {
            $branchId = $this->branchFromUserPivot($this->positiveInt($user?->id ?? null));
        }

        if (! $branchId) {
            $branchId = $this->singleId(
                ['branches', 'branch_master', 'branch_masters', 'offices', 'office_master', 'office_masters'],
                ['id', 'branch_id', 'office_id']
            );
        }

        $companyId = $this->firstPositive([
            $booking?->company_id ?? null,
            $this->userAttribute($user, 'company_id'),
        ]);

        if (! $companyId && $branchId) {
            $companyId = $this->companyFromBranch($branchId);
        }

        if (! $companyId) {
            $companyId = $this->singleId(
                ['companies', 'company_master', 'company_masters', 'legal_entities'],
                ['id', 'company_id']
            );
        }

        $fiscalYearId = $this->fiscalYearId((string) $voucher->voucher_date);

        if (! $companyId) {
            throw new RuntimeException('Native journal posting stopped: company context could not be resolved.');
        }
        if (! $branchId) {
            throw new RuntimeException('Native journal posting stopped: branch context could not be resolved.');
        }
        if (! $fiscalYearId) {
            throw new RuntimeException('Native journal posting stopped: fiscal year could not be resolved for '.$voucher->voucher_date.'.');
        }

        $accountingPeriodId = $this->accountingPeriodId(
            (string) $voucher->voucher_date,
            $fiscalYearId
        );

        if (! $accountingPeriodId) {
            throw new RuntimeException(
                'Native journal posting stopped: accounting period could not be resolved for '
                .$voucher->voucher_date.' in fiscal year #'.$fiscalYearId.'.'
            );
        }

        return [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'fiscal_year_id' => $fiscalYearId,
            'accounting_period_id' => $accountingPeriodId,
        ];
    }

    private function userAttribute(mixed $user, string $key): mixed
    {
        if (! $user) {
            return null;
        }

        try {
            if (method_exists($user, 'getAttribute')) {
                $value = $user->getAttribute($key);
                if ($value !== null) {
                    return $value;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        try {
            return $user->{$key} ?? null;
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    private function branchFromUserPivot(?int $userId): ?int
    {
        if (! $userId) {
            return null;
        }

        foreach (['user_branches', 'user_branch', 'user_allowed_branches'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
                $userColumn = $this->first($columns, ['user_id', 'staff_user_id']);
                $branchColumn = $this->first($columns, ['branch_id', 'office_id']);

                if (! $userColumn || ! $branchColumn) {
                    continue;
                }

                $query = DB::table($table)->where($userColumn, $userId);

                foreach (['is_primary', 'primary', 'is_default', 'default'] as $primary) {
                    if (in_array($primary, $columns, true)) {
                        $primaryId = (int) ((clone $query)->where($primary, 1)->value($branchColumn) ?? 0);
                        if ($primaryId > 0) {
                            return $primaryId;
                        }
                    }
                }

                $ids = (clone $query)->limit(2)->pluck($branchColumn)
                    ->map(static fn ($value): int => (int) $value)
                    ->filter(static fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();

                if ($ids->count() === 1) {
                    return (int) $ids->first();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    private function companyFromBranch(int $branchId): ?int
    {
        foreach ([
            'branches', 'branch_master', 'branch_masters',
            'offices', 'office_master', 'office_masters',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
                $id = $this->first($columns, ['id', 'branch_id', 'office_id']);

                if (! $id || ! in_array('company_id', $columns, true)) {
                    continue;
                }

                $companyId = (int) (
                    DB::table($table)->where($id, $branchId)->value('company_id') ?? 0
                );

                if ($companyId > 0) {
                    return $companyId;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    private function fiscalYearId(string $date): ?int
    {
        foreach (['fiscal_years', 'financial_years', 'fiscal_year'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
                $id = $this->first($columns, ['id', 'fiscal_year_id', 'financial_year_id']);

                if (! $id) {
                    continue;
                }

                $query = DB::table($table);
                $start = $this->first($columns, [
                    'start_date', 'date_from', 'from_date', 'starts_on',
                    'year_start', 'period_start', 'start_on',
                ]);
                $end = $this->first($columns, [
                    'end_date', 'date_to', 'to_date', 'ends_on',
                    'year_end', 'period_end', 'end_on',
                ]);

                if ($start && $end) {
                    $matched = (int) (
                        (clone $query)
                            ->where($start, '<=', $date)
                            ->where($end, '>=', $date)
                            ->value($id)
                        ?? 0
                    );

                    if ($matched > 0) {
                        return $matched;
                    }
                }

                foreach (['is_current', 'current', 'active', 'is_active'] as $field) {
                    if (! in_array($field, $columns, true)) {
                        continue;
                    }

                    $matched = (int) ((clone $query)->where($field, 1)->value($id) ?? 0);
                    if ($matched > 0) {
                        return $matched;
                    }
                }

                if (in_array('status', $columns, true)) {
                    $matched = (int) (
                        (clone $query)
                            ->whereRaw('LOWER(status) IN (?, ?, ?)', ['open', 'active', 'current'])
                            ->value($id)
                        ?? 0
                    );
                    if ($matched > 0) {
                        return $matched;
                    }
                }

                // Same final compatibility rule already used by the native
                // Sales Invoice integration: prefer the latest configured FY
                // rather than failing just because older closed years exist.
                $matched = (int) ((clone $query)->orderByDesc($id)->value($id) ?? 0);
                if ($matched > 0) {
                    return $matched;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    /**
     * Resolve the native accounting period for a journal date without
     * hard-coding a period table name or database ID.
     *
     * Resolution order:
     * 1. Existing native journal on the exact date / fiscal year.
     * 2. FK target table discovered from information_schema.
     * 3. Conventional accounting-period table names.
     * 4. Existing native journal in the same calendar month / fiscal year.
     * 5. A single unambiguous period row for the fiscal year.
     */
    private function accountingPeriodId(string $date, int $fiscalYearId): ?int
    {
        if (
            ! Schema::hasTable('journal_entries')
            || ! in_array('accounting_period_id', Schema::getColumnListing('journal_entries'), true)
        ) {
            return null;
        }

        try {
            $sameDate = (int) (
                DB::table('journal_entries')
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->whereDate('journal_date', $date)
                    ->whereNotNull('accounting_period_id')
                    ->value('accounting_period_id')
                ?? 0
            );

            if ($sameDate > 0) {
                return $sameDate;
            }
        } catch (Throwable $e) {
            report($e);
        }

        $tables = [];
        $fkTable = $this->accountingPeriodReferencedTable();

        if ($fkTable) {
            $tables[] = $fkTable;
        }

        foreach ([
            'accounting_periods',
            'financial_periods',
            'fiscal_periods',
            'accounting_period',
            'financial_period',
        ] as $candidate) {
            if (! in_array($candidate, $tables, true)) {
                $tables[] = $candidate;
            }
        }

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
                $id = $this->first($columns, [
                    'id', 'accounting_period_id', 'period_id',
                ]);

                if (! $id) {
                    continue;
                }

                $query = DB::table($table);

                $fyColumn = $this->first($columns, [
                    'fiscal_year_id', 'financial_year_id',
                ]);
                if ($fyColumn) {
                    $query->where($fyColumn, $fiscalYearId);
                }

                if (in_array('deleted_at', $columns, true)) {
                    $query->whereNull('deleted_at');
                }

                $start = $this->first($columns, [
                    'start_date', 'date_from', 'from_date', 'starts_on',
                    'period_start', 'start_on', 'period_start_date',
                ]);
                $end = $this->first($columns, [
                    'end_date', 'date_to', 'to_date', 'ends_on',
                    'period_end', 'end_on', 'period_end_date',
                ]);

                if ($start && $end) {
                    $matched = (int) (
                        (clone $query)
                            ->whereDate($start, '<=', $date)
                            ->whereDate($end, '>=', $date)
                            ->value($id)
                        ?? 0
                    );

                    if ($matched > 0) {
                        return $matched;
                    }
                }

                $dateColumn = $this->first($columns, [
                    'period_date', 'accounting_date',
                ]);

                if ($dateColumn) {
                    $matched = (int) (
                        (clone $query)->whereDate($dateColumn, $date)->value($id) ?? 0
                    );

                    if ($matched > 0) {
                        return $matched;
                    }
                }

                $yearColumn = $this->first($columns, [
                    'year', 'period_year', 'calendar_year',
                ]);
                $monthColumn = $this->first($columns, [
                    'month', 'period_month', 'month_no', 'month_number',
                ]);

                if ($yearColumn && $monthColumn) {
                    $matched = (int) (
                        (clone $query)
                            ->where($yearColumn, (int) substr($date, 0, 4))
                            ->where($monthColumn, (int) substr($date, 5, 2))
                            ->value($id)
                        ?? 0
                    );

                    if ($matched > 0) {
                        return $matched;
                    }
                }

                $ids = (clone $query)->limit(2)->pluck($id)
                    ->map(static fn ($value): int => (int) $value)
                    ->filter(static fn (int $value): bool => $value > 0)
                    ->unique()
                    ->values();

                if ($ids->count() === 1) {
                    return (int) $ids->first();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // Last safe compatibility fallback: reuse the period already used by
        // another native journal in the same calendar month and fiscal year.
        try {
            $sameMonth = (int) (
                DB::table('journal_entries')
                    ->where('fiscal_year_id', $fiscalYearId)
                    ->whereYear('journal_date', (int) substr($date, 0, 4))
                    ->whereMonth('journal_date', (int) substr($date, 5, 2))
                    ->whereNotNull('accounting_period_id')
                    ->orderByDesc('journal_date')
                    ->value('accounting_period_id')
                ?? 0
            );

            if ($sameMonth > 0) {
                return $sameMonth;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }

    private function accountingPeriodReferencedTable(): ?string
    {
        try {
            $row = DB::selectOne(
                <<<'SQL'
SELECT REFERENCED_TABLE_NAME AS table_name
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'journal_entries'
  AND COLUMN_NAME = 'accounting_period_id'
  AND REFERENCED_TABLE_NAME IS NOT NULL
LIMIT 1
SQL
            );

            $table = trim((string) ($row->table_name ?? ''));

            return $table !== '' ? $table : null;
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    private function singleId(array $tables, array $idCandidates): ?int
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            try {
                $columns = Schema::getColumnListing($table);
                $id = $this->first($columns, $idCandidates);

                if (! $id) {
                    continue;
                }

                $query = DB::table($table);

                if (in_array('deleted_at', $columns, true)) {
                    $query->whereNull('deleted_at');
                }

                foreach (['is_active', 'active'] as $active) {
                    if (in_array($active, $columns, true)) {
                        $query->where($active, 1);
                        break;
                    }
                }

                $ids = $query->limit(2)->pluck($id)
                    ->map(static fn ($v): int => (int) $v)
                    ->filter(static fn (int $v): bool => $v > 0)
                    ->unique()
                    ->values();

                if ($ids->count() === 1) {
                    return (int) $ids->first();
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    private function journalType(string $voucherType, string $sourceType): string
    {
        if (str_contains($sourceType, 'reversal')) {
            return 'reversal';
        }

        if (str_contains($sourceType, 'supplier_costing')) { return 'supplier_costing'; }
        if (str_contains($sourceType, 'advance_adjustment')) { return 'advance_adjustment'; }

        return match ($voucherType) {
            'receipt' => 'receipt',
            'payment' => 'payment',
            'expense' => 'expense',
            'customer_advance' => 'customer_advance',
            'supplier_advance' => 'supplier_advance',
            default => 'cash_voucher',
        };
    }

    private function nativePartyType(?string $partyType): ?string
    {
        $partyType = strtolower(trim((string) $partyType));

        if ($partyType === '') {
            return null;
        }

        return match ($partyType) {
            'supplier' => 'vendor',
            'vendor' => 'vendor',
            'customer' => 'customer',
            'agent' => 'agent',
            default => $partyType,
        };
    }

    private function assertRequiredNativeHeaderValues(array $header): void
    {
        try {
            $rows = DB::select(
                <<<'SQL'
SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'journal_entries'
ORDER BY ORDINAL_POSITION
SQL
            );

            $missing = [];

            foreach ($rows as $row) {
                $column = (string) ($row->COLUMN_NAME ?? '');
                $nullable = strtoupper((string) ($row->IS_NULLABLE ?? 'YES'));
                $default = $row->COLUMN_DEFAULT ?? null;
                $extra = strtolower((string) ($row->EXTRA ?? ''));

                if (
                    $column === ''
                    || $column === 'id'
                    || $nullable !== 'NO'
                    || $default !== null
                    || str_contains($extra, 'auto_increment')
                    || str_contains($extra, 'generated')
                ) {
                    continue;
                }

                if (! array_key_exists($column, $header) || $header[$column] === null) {
                    $missing[] = $column;
                }
            }

            if ($missing !== []) {
                throw new RuntimeException(
                    'Native journal posting stopped: required native header value(s) missing: '
                    .implode(', ', $missing).'.'
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Do not make information_schema access a new posting dependency.
            // The actual insert will still be transactionally protected.
            report($e);
        }
    }

    private function assertNativeSchema(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_lines')) {
            throw new RuntimeException('Native journal tables are unavailable.');
        }

        $header = Schema::getColumnListing('journal_entries');
        $lines = Schema::getColumnListing('journal_lines');

        foreach ([
            'id', 'company_id', 'branch_id', 'fiscal_year_id',
            'accounting_period_id', 'journal_no', 'journal_date', 'status',
        ] as $required) {
            if (! in_array($required, $header, true)) {
                throw new RuntimeException('Native journal header column '.$required.' is missing.');
            }
        }

        foreach ([
            'journal_entry_id', 'line_no', 'account_id', 'debit', 'credit',
            'base_debit', 'base_credit',
        ] as $required) {
            if (! in_array($required, $lines, true)) {
                throw new RuntimeException('Native journal line column '.$required.' is missing.');
            }
        }
    }

    private function first(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = (int) ($value ?? 0);
        return $value > 0 ? $value : null;
    }

    private function firstPositive(array $values): ?int
    {
        foreach ($values as $value) {
            $value = $this->positiveInt($value);
            if ($value) {
                return $value;
            }
        }

        return null;
    }
}
