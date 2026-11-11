<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class TransportBookingDetail extends Model
{
    protected $fillable=[
        'booking_service_id','transport_company_id',
        'transport_company_snapshot','transport_company_code_snapshot','transport_company_phone_snapshot',
        'provider_reference','pickup_location','dropoff_location','pickup_at',
        'transport_vehicle_type_id','transport_route_id','vehicle_type','vehicle_type_snapshot','route_snapshot','vehicle_number','driver_name','driver_mobile',
        'sale_price','cost_price','cost_currency_code','cost_price_foreign','cost_fx_rate','cost_fx_rate_date','cost_price_base','vendor_rate_card_id','vendor_rate_snapshot',
        'notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return ['pickup_at'=>'datetime','sale_price'=>'decimal:2','cost_price'=>'decimal:2','cost_price_foreign'=>'decimal:2','cost_fx_rate'=>'decimal:8','cost_fx_rate_date'=>'date','cost_price_base'=>'decimal:2','vendor_rate_snapshot'=>'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (TransportBookingDetail $detail): void {
            if (!Schema::hasTable('transport_booking_details') || !Schema::hasColumn('transport_booking_details','transport_company_id')) return;
            if (!$detail->transport_company_id || (!$detail->isDirty('transport_company_id') && trim((string)$detail->transport_company_snapshot)!=='')) return;

            $partner=TravelVoucherPartner::query()->find($detail->transport_company_id);
            if (!$partner) return;
            $detail->transport_company_snapshot=$partner->name;
            $detail->transport_company_code_snapshot=$partner->code;
            $detail->transport_company_phone_snapshot=$partner->phone;
        });
    }

    public function transportCompany(): BelongsTo
    {
        return $this->belongsTo(TravelVoucherPartner::class,'transport_company_id');
    }


    public function vehicleTypeMaster(): BelongsTo
    {
        return $this->belongsTo(TransportVehicleType::class,'transport_vehicle_type_id');
    }

    public function routeMaster(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class,'transport_route_id');
    }
}
