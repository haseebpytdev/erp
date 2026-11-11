<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupTravelPackage extends Model
{
    protected $fillable=[
        'booking_id','package_type','package_name','vendor_party_id','vendor_reference',
        'currency_code','visa_included','visa_status',
        'adult_vendor_cost','adult_customer_price','child_vendor_cost','child_customer_price',
        'infant_vendor_cost','infant_customer_price','total_vendor_cost','total_customer_price','gross_margin',
        'notes','created_by','updated_by',
    ];

    protected $casts=[
        'visa_included'=>'boolean',
        'adult_vendor_cost'=>'decimal:2','adult_customer_price'=>'decimal:2',
        'child_vendor_cost'=>'decimal:2','child_customer_price'=>'decimal:2',
        'infant_vendor_cost'=>'decimal:2','infant_customer_price'=>'decimal:2',
        'total_vendor_cost'=>'decimal:2','total_customer_price'=>'decimal:2','gross_margin'=>'decimal:2',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Party::class,'vendor_party_id'); }
    public function passengerPrices(): HasMany { return $this->hasMany(GroupTravelPackagePassengerPrice::class); }
}
