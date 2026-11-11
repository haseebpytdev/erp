<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class PeriodGuard
{
    /** @return array{0:FiscalYear,1:AccountingPeriod} */
    public function resolveOpen(int $companyId, CarbonInterface|string $date): array
    {
        $dateString = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
        $fiscalYear = FiscalYear::query()
            ->where('company_id', $companyId)
            ->whereDate('starts_on', '<=', $dateString)
            ->whereDate('ends_on', '>=', $dateString)
            ->first();

        if (! $fiscalYear) {
            throw ValidationException::withMessages(['journal_date' => 'No fiscal year covers the selected journal date.']);
        }
        if ($fiscalYear->status !== 'OPEN') {
            throw ValidationException::withMessages(['journal_date' => "Fiscal year {$fiscalYear->code} is not open."]);
        }

        $period = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->whereDate('starts_on', '<=', $dateString)
            ->whereDate('ends_on', '>=', $dateString)
            ->first();

        if (! $period) {
            throw ValidationException::withMessages(['journal_date' => 'No accounting period covers the selected journal date.']);
        }
        if ($period->status !== 'OPEN' || $period->locked_at) {
            throw ValidationException::withMessages(['journal_date' => "Accounting period {$period->name} is locked/closed."]);
        }

        return [$fiscalYear, $period];
    }
}
