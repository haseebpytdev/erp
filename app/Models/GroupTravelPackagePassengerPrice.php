<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupTravelPackagePassengerPrice extends Model
{
    protected $fillable=[
        'group_travel_package_id','booking_passenger_id','pax_type_snapshot',
        'vendor_cost_override','customer_price_override','created_by','updated_by',
    ];

    protected $casts=[
        'vendor_cost_override'=>'decimal:2',
        'customer_price_override'=>'decimal:2',
    ];

    public function package(): BelongsTo { return $this->belongsTo(GroupTravelPackage::class,'group_travel_package_id'); }
    public function passenger(): BelongsTo { return $this->belongsTo(BookingPassenger::class,'booking_passenger_id'); }
}
