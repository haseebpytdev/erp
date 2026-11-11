<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisaServiceOperator extends Model
{
    protected $fillable=[
        'booking_service_id','saudi_visa_company_id','pakistan_iata_operator_id',
        'saudi_visa_company_snapshot','saudi_visa_company_code_snapshot',
        'pakistan_iata_operator_snapshot','pakistan_iata_number_snapshot',
        'adult_sale_price','adult_cost_price','child_sale_price','child_cost_price','infant_sale_price','infant_cost_price',
        'cost_currency_code','adult_cost_foreign','child_cost_foreign','infant_cost_foreign','cost_fx_rate','cost_fx_rate_date','total_cost_foreign','total_cost_base',
        'created_by','updated_by',
    ];


    protected function casts(): array
    {
        return [
            'adult_sale_price'=>'decimal:2','adult_cost_price'=>'decimal:2',
            'child_sale_price'=>'decimal:2','child_cost_price'=>'decimal:2',
            'infant_sale_price'=>'decimal:2','infant_cost_price'=>'decimal:2',
            'adult_cost_foreign'=>'decimal:2','child_cost_foreign'=>'decimal:2','infant_cost_foreign'=>'decimal:2',
            'cost_fx_rate'=>'decimal:8','cost_fx_rate_date'=>'date','total_cost_foreign'=>'decimal:2','total_cost_base'=>'decimal:2',
        ];
    }

    public function saudiVisaCompany(): BelongsTo
    {
        return $this->belongsTo(TravelVoucherPartner::class,'saudi_visa_company_id');
    }

    public function pakistanIataOperator(): BelongsTo
    {
        return $this->belongsTo(TravelVoucherPartner::class,'pakistan_iata_operator_id');
    }
}
