<?php

namespace App\Services\Accounting;

use App\Models\FiscalYear;
use App\Models\NumberSequence;
use App\Models\NumberSequenceCounter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentNumberService
{
    public function next(string $documentType, int $companyId, ?int $branchId, FiscalYear $fiscalYear, ?int $accountingPeriodId = null): string
    {
        return DB::transaction(function () use ($documentType,$companyId,$branchId,$fiscalYear,$accountingPeriodId): string {
            $sequence = NumberSequence::query()
                ->where('company_id', $companyId)
                ->where('document_type', $documentType)
                ->where('is_active', true)
                ->where(function ($q) use ($branchId): void {
                    if ($branchId) $q->where('branch_id', $branchId)->orWhereNull('branch_id');
                    else $q->whereNull('branch_id');
                })
                ->orderByRaw('branch_id IS NULL')
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw ValidationException::withMessages(['sequence' => "No active number sequence is configured for {$documentType}."]);
            }

            $fyId = $sequence->reset_policy === 'NEVER' ? null : $fiscalYear->id;
            $periodId = $sequence->reset_policy === 'ACCOUNTING_PERIOD' ? $accountingPeriodId : null;

            $counter = NumberSequenceCounter::query()
                ->where('number_sequence_id', $sequence->id)
                ->where(function ($q) use ($fyId): void { $fyId ? $q->where('fiscal_year_id',$fyId) : $q->whereNull('fiscal_year_id'); })
                ->where(function ($q) use ($periodId): void { $periodId ? $q->where('accounting_period_id',$periodId) : $q->whereNull('accounting_period_id'); })
                ->lockForUpdate()->first();

            if (! $counter) {
                $counter = NumberSequenceCounter::query()->create([
                    'number_sequence_id'=>$sequence->id,'fiscal_year_id'=>$fyId,'accounting_period_id'=>$periodId,'last_number'=>0,
                ]);
            }

            $number = $counter->last_number + 1;
            $counter->update(['last_number'=>$number]);
            $sequence->update(['next_number'=>$number + 1]); // keep legacy UI counter meaningful

            $yearToken = $fiscalYear->starts_on->format('Y');
            return $sequence->prefix.'-'.$yearToken.'-'.str_pad((string)$number, $sequence->padding, '0', STR_PAD_LEFT);
        }, 3);
    }
}
