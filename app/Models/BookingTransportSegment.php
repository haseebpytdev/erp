<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingTransportSegment extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_service_id',
        'rate_card_id',
        'service_mode',
        'transport_date',
        'pickup_time',
        'pickup_location',
        'dropoff_location',
        'route_label',
        'vehicle_type',
        'vehicle_number',
        'driver_name',
        'driver_contact',
        'provider_reference',
        'passengers_count',
        'supplier_party_id',
        'sale_currency_code',
        'sale_amount',
        'supplier_currency_code',
        'supplier_amount',
        'fx_rate_to_pkr',
        'supplier_amount_pkr',
        'commercial_locked',
        'status',
        'voucher_notes',
        'rate_snapshot',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'transport_date' => 'date',
            'sale_amount' => 'decimal:2',
            'supplier_amount' => 'decimal:2',
            'fx_rate_to_pkr' => 'decimal:6',
            'supplier_amount_pkr' => 'decimal:2',
            'commercial_locked' => 'boolean',
            'rate_snapshot' => 'array',
            'passengers_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
