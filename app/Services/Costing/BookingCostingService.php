<?php

namespace App\Services\Costing;

use App\Models\Booking;
use App\Models\Company;
use App\Models\ServiceCostAllocation;

class BookingCostingService
{
    public function summary(Booking $booking): array
    {
        $booking->loadMissing(['salesInvoices']);
        $company = Company::query()->findOrFail($booking->company_id);
        $posted = $booking->salesInvoices->where('status','POSTED');
        $active = $booking->salesInvoices->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED']);

        $salesBase = 0.0; $salesLabel = 'Booking estimate'; $salesCurrency = $company->base_currency;
        if ($posted->isNotEmpty()) {
            $salesBase = (float) $posted->sum(fn($i)=>(float)$i->grand_total * (float)($i->exchange_rate ?: 1));
            $salesLabel = $posted->count().' posted Sales Invoice'.($posted->count()===1?'':'s');
        } elseif ($active->isNotEmpty()) {
            $salesBase = (float) $active->sum(fn($i)=>(float)$i->grand_total * (float)($i->exchange_rate ?: 1));
            $salesLabel = $active->count().' active Sales Invoice'.($active->count()===1?'':'s').' · not all posted';
        } elseif (strtoupper($booking->currency_code) === strtoupper($company->base_currency)) {
            $salesBase = (float) $booking->activeServices()->sum('line_total');
        }

        $allocations = ServiceCostAllocation::query()->where('booking_id',$booking->id)->where('status','!=','CANCELLED')->get();
        $forecastCost = (float) $allocations->sum('base_total_cost');
        $confirmedCost = (float) $allocations->where('status','CONFIRMED')->sum('base_total_cost');
        $estimatedOnly = (float) $allocations->where('status','ESTIMATED')->sum('base_total_cost');
        $grossMargin = round($salesBase - $forecastCost, 2);
        $marginPct = $salesBase > 0 ? round(($grossMargin / $salesBase) * 100, 2) : null;

        return [
            'base_currency'=>$company->base_currency,'sales_base'=>round($salesBase,2),'sales_label'=>$salesLabel,
            'forecast_cost'=>round($forecastCost,2),'confirmed_cost'=>round($confirmedCost,2),'estimated_cost'=>round($estimatedOnly,2),
            'gross_margin'=>$grossMargin,'gross_margin_pct'=>$marginPct,'allocation_count'=>$allocations->count(),
        ];
    }

    public function costTypeMapping(string $type): string
    {
        return match (strtoupper($type)) {
            'AIR_TICKET' => 'AIR_TICKET_COST',
            'VISA' => 'VISA_COST',
            'HOTEL' => 'HOTEL_COST',
            'TRANSPORT' => 'TRANSPORT_COST',
            'PACKAGE', 'UMRAH_PACKAGE' => 'PACKAGE_COST',
            default => 'OTHER_DIRECT_COST',
        };
    }
}
