<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BookingPassenger extends Model
{
    protected $fillable = [
        'booking_id','passenger_profile_id','passenger_no','calculated_pax_type','pax_type','title','first_name','middle_name','last_name','gender','date_of_birth','nationality_country_code',
        'passport_no','passport_expiry','national_id','email','mobile','is_lead','is_active','notes','created_by','updated_by',
    ];
    protected function casts(): array { return ['date_of_birth'=>'date','passport_expiry'=>'date','is_lead'=>'boolean','is_active'=>'boolean']; }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function profile(): BelongsTo { return $this->belongsTo(PassengerProfile::class,'passenger_profile_id'); }
    public function services(): BelongsToMany { return $this->belongsToMany(BookingService::class,'booking_service_passengers')->withTimestamps(); }
    public function fullName(): string { return trim(implode(' ',array_filter([$this->title,$this->first_name,$this->middle_name,$this->last_name]))); }
}
