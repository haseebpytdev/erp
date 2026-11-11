<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class HotelBookingDetail extends Model
{
    protected $fillable = [
        'booking_service_id','hotel_id','hotel_name_snapshot','hotel_city_snapshot','hotel_city_iata_snapshot','hotel_country_snapshot','hotel_address_snapshot','hotel_phone_snapshot','confirmation_number','room_type','rooms','check_in','check_out','nights','meal_plan','occupancy','special_requests',
        'hotel_status','pricing_basis',
        'customer_base_rate','customer_base_total','customer_service_markup','customer_discount_type','customer_discount_value','customer_discount','customer_sell_total',
        'supplier_vendor_party_id','supplier_base_rate','supplier_base_total','supplier_charges','supplier_discount_type','supplier_discount_value','supplier_discount',
        'supplier_incentive','net_supplier_cost','supplier_cost_status','supplier_reference',
        'supplier_currency_code','supplier_cost_foreign','supplier_fx_rate','supplier_fx_rate_date','supplier_cost_base',
        'created_by','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'check_in'=>'date','check_out'=>'date',
            'customer_base_rate'=>'decimal:2','customer_base_total'=>'decimal:2','customer_service_markup'=>'decimal:2',
            'customer_discount_value'=>'decimal:4','customer_discount'=>'decimal:2','customer_sell_total'=>'decimal:2',
            'supplier_base_rate'=>'decimal:2','supplier_base_total'=>'decimal:2','supplier_charges'=>'decimal:2',
            'supplier_discount_value'=>'decimal:4','supplier_discount'=>'decimal:2','supplier_incentive'=>'decimal:2','net_supplier_cost'=>'decimal:2',
            'supplier_cost_foreign'=>'decimal:2','supplier_fx_rate'=>'decimal:8','supplier_fx_rate_date'=>'date','supplier_cost_base'=>'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (HotelBookingDetail $detail): void {
            if (!Schema::hasTable('hotel_booking_details') || !Schema::hasColumn('hotel_booking_details','hotel_name_snapshot')) return;
            if (!$detail->hotel_id || (!$detail->isDirty('hotel_id') && trim((string)$detail->hotel_name_snapshot)!=='')) return;

            $hotel=Hotel::query()->find($detail->hotel_id);
            if (!$hotel) return;
            $detail->hotel_name_snapshot=$hotel->name;
            $detail->hotel_city_snapshot=$hotel->city;
            $detail->hotel_city_iata_snapshot=Schema::hasColumn('hotels','city_iata_code') ? $hotel->city_iata_code : null;
            $detail->hotel_country_snapshot=$hotel->country_code;
            $detail->hotel_address_snapshot=$hotel->address;
            $detail->hotel_phone_snapshot=$hotel->phone;
        });
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class,'booking_service_id');
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function supplierVendor(): BelongsTo
    {
        return $this->belongsTo(Party::class,'supplier_vendor_party_id');
    }

    public function roomsList(): HasMany
    {
        return $this->hasMany(HotelBookingRoom::class)->orderBy('room_no');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionAllocation::class);
    }
}
