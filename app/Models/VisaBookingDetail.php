<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class VisaBookingDetail extends Model
{
    protected $fillable=[
        'booking_service_id','booking_passenger_id',
        'saudi_visa_company_id','pakistan_iata_operator_id',
        'saudi_visa_company_snapshot','saudi_visa_company_code_snapshot',
        'pakistan_iata_operator_snapshot','pakistan_iata_number_snapshot',
        'destination_country_code','visa_type','application_number','visa_number','status',
        'submitted_on','issued_on','valid_until','notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return ['submitted_on'=>'date','issued_on'=>'date','valid_until'=>'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (VisaBookingDetail $detail): void {
            if (!Schema::hasTable('visa_booking_details') || !Schema::hasColumn('visa_booking_details','saudi_visa_company_id')) return;

            if ($detail->saudi_visa_company_id && ($detail->isDirty('saudi_visa_company_id') || trim((string)$detail->saudi_visa_company_snapshot)==='')) {
                $partner=TravelVoucherPartner::query()->find($detail->saudi_visa_company_id);
                if ($partner) {
                    $detail->saudi_visa_company_snapshot=$partner->name;
                    $detail->saudi_visa_company_code_snapshot=$partner->code;
                }
            }
            if ($detail->pakistan_iata_operator_id && ($detail->isDirty('pakistan_iata_operator_id') || trim((string)$detail->pakistan_iata_operator_snapshot)==='')) {
                $partner=TravelVoucherPartner::query()->find($detail->pakistan_iata_operator_id);
                if ($partner) {
                    $detail->pakistan_iata_operator_snapshot=$partner->name;
                    $detail->pakistan_iata_number_snapshot=$partner->iata_number;
                }
            }
        });
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
