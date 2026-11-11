<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelVoucherPartner extends Model
{
    protected $fillable = [
        'company_id','partner_type','code','name','registration_no','iata_number','phone','email','website',
        'city','country_code','default_vendor_party_id','linked_pakistan_iata_operator_id','source_context','voucher_footer_html','notes','is_active','created_by','updated_by',
    ];

    protected function casts(): array { return ['is_active'=>'boolean']; }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function defaultVendor(): BelongsTo { return $this->belongsTo(Party::class,'default_vendor_party_id'); }
    public function linkedPakistanIataOperator(): BelongsTo { return $this->belongsTo(self::class,'linked_pakistan_iata_operator_id'); }
}
