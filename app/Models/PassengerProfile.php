<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PassengerProfile extends Model
{
    protected $fillable = [
        'company_id','title','first_name','middle_name','last_name','gender','date_of_birth','nationality_country_code',
        'passport_no','passport_no_normalized','passport_expiry','national_id','national_id_normalized',
        'email','mobile','pax_type_hint','is_active','notes','last_used_at','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'=>'date',
            'passport_expiry'=>'date',
            'is_active'=>'boolean',
            'last_used_at'=>'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bookingPassengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class,'passenger_profile_id');
    }

    public function fullName(): string
    {
        return trim(implode(' ',array_filter([$this->title,$this->first_name,$this->middle_name,$this->last_name])));
    }
}
