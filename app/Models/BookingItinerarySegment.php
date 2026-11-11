<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingItinerarySegment extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_service_id',
        'segment_type',
        'from_code',
        'to_code',
        'airline_name',
        'airline_code',
        'flight_number',
        'departure_at',
        'arrival_at',
        'pnr',
        'cabin',
        'baggage',
        'status',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'departure_at' => 'datetime',
            'arrival_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }
}
