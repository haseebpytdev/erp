<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $fillable = [
        'company_id','branch_id','booking_no','booking_type','booking_date','travel_start_date','travel_end_date',
        'customer_party_id','agent_party_id','salesperson_staff_id','customer_reference','currency_code','status','notes',
        'created_by','updated_by','submitted_by','submitted_at','confirmed_by','confirmed_at','cancelled_by','cancelled_at','cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'booking_date'=>'date','travel_start_date'=>'date','travel_end_date'=>'date','submitted_at'=>'datetime','confirmed_at'=>'datetime','cancelled_at'=>'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Party::class,'customer_party_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(Party::class,'agent_party_id'); }
    public function salesperson(): BelongsTo { return $this->belongsTo(Staff::class,'salesperson_staff_id'); }
    public function passengers(): HasMany { return $this->hasMany(BookingPassenger::class)->orderByDesc('is_lead')->orderBy('passenger_no'); }
    public function services(): HasMany { return $this->hasMany(BookingService::class)->orderBy('id'); }
    public function salesInvoices(): HasMany { return $this->hasMany(SalesInvoice::class)->orderByDesc('id'); }

    public function isEditable(): bool { return in_array($this->status,['DRAFT','PENDING_CONFIRMATION'],true); }
    public function activePassengers(): HasMany { return $this->hasMany(BookingPassenger::class)->where('is_active',true); }
    public function activeServices(): HasMany { return $this->hasMany(BookingService::class)->where('status','!=','CANCELLED'); }
}
