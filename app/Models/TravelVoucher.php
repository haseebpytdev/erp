<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TravelVoucher extends Model
{
    protected $fillable = [
        'booking_id','voucher_no','manual_reference','document_type',
        'saudi_visa_company_id','pakistan_iata_operator_id','special_instructions','footer_html',
        'public_token','issued_at','issued_by','issue_revision','voided_at','voided_by','void_reason',
        'status','created_by','updated_by',
    ];

    protected $casts = [
        'issued_at'=>'datetime',
        'voided_at'=>'datetime',
        'issue_revision'=>'integer',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function saudiVisaCompany(): BelongsTo { return $this->belongsTo(TravelVoucherPartner::class,'saudi_visa_company_id'); }
    public function pakistanIataOperator(): BelongsTo { return $this->belongsTo(TravelVoucherPartner::class,'pakistan_iata_operator_id'); }
    public function items(): HasMany { return $this->hasMany(TravelVoucherItem::class)->where('is_active',true)->orderBy('sort_order')->orderBy('id'); }
    public function revisions(): HasMany { return $this->hasMany(TravelVoucherRevision::class,'travel_voucher_id')->orderBy('revision_no'); }
}
