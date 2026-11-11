<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HotelBookingRoom extends Model
{
    protected $fillable = [
        'hotel_booking_detail_id','room_no','room_label','room_type','room_count','capacity_per_room','total_capacity','occupancy','meal_plan','notes','created_by','updated_by',
    ];

    public function hotelBooking(): BelongsTo
    {
        return $this->belongsTo(HotelBookingDetail::class,'hotel_booking_detail_id');
    }

    public function passengers(): BelongsToMany
    {
        return $this->belongsToMany(
            BookingPassenger::class,
            'hotel_booking_room_passengers',
            'hotel_booking_room_id',
            'booking_passenger_id'
        )->withTimestamps();
    }
}
