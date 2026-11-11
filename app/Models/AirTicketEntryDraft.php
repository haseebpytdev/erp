<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AirTicketEntryDraft extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_service_id',
        'payload',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
