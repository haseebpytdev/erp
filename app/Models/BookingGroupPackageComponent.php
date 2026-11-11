<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingGroupPackageComponent extends Model
{
    protected $table = 'booking_group_package_components';

    protected $fillable = [
        'booking_group_package_id', 'booking_id', 'component_type',
        'booking_service_id', 'booking_visa_service_id', 'group_package_flight_id', 'group_package_hotel_id',
        'group_package_transport_id', 'group_package_service_id', 'selected',
        'billing_treatment', 'created_by', 'updated_by',
    ];

    protected $casts = ['selected' => 'boolean'];
}
