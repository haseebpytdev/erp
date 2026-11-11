<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ExchangeRateService
{
    /**
     * Resolve the latest active rate on or before the requested date.
     * Returned rate means: 1 FROM currency = RATE TO currency.
     */
    public function resolve(int $companyId, string $fromCurrency, string $toCurrency, CarbonInterface|string|null $date): ?array
    {
        $from=strtoupper(trim($fromCurrency));
        $to=strtoupper(trim($toCurrency));
        $dateString=$date instanceof CarbonInterface ? $date->format('Y-m-d') : trim((string)$date);
        if($dateString==='') $dateString=now()->format('Y-m-d');

        if($from===$to){
            return [
                'from_currency_code'=>$from,
                'to_currency_code'=>$to,
                'rate'=>1.0,
                'effective_date'=>$dateString,
                'exact'=>true,
            ];
        }

        if(!Schema::hasTable('exchange_rates')) return null;

        $row=DB::table('exchange_rates')
            ->where('company_id',$companyId)
            ->where('from_currency_code',$from)
            ->where('to_currency_code',$to)
            ->where('is_active',true)
            ->whereDate('effective_date','<=',$dateString)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if(!$row) return null;

        $effective=substr((string)$row->effective_date,0,10);
        return [
            'id'=>(int)$row->id,
            'from_currency_code'=>$from,
            'to_currency_code'=>$to,
            'rate'=>(float)$row->rate,
            'effective_date'=>$effective,
            'exact'=>$effective===$dateString,
        ];
    }

    public function require(int $companyId, string $fromCurrency, string $toCurrency, CarbonInterface|string|null $date, string $field='exchange_rate'): array
    {
        $quote=$this->resolve($companyId,$fromCurrency,$toCurrency,$date);
        if($quote) return $quote;

        $dateString=$date instanceof CarbonInterface ? $date->format('Y-m-d') : trim((string)$date);
        if($dateString==='') $dateString=now()->format('Y-m-d');

        throw ValidationException::withMessages([
            $field=>sprintf(
                '%s → %s exchange rate is not configured for %s or any earlier date. Add it in Foundation → Currency Rates first.',
                strtoupper($fromCurrency),strtoupper($toCurrency),$dateString
            ),
        ]);
    }
}
