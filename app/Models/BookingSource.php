<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSource extends Model
{
    protected $fillable = [
        'company_id','code','name','source_type','default_vendor_party_id','is_active','notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active'=>'boolean'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function defaultVendor(): BelongsTo { return $this->belongsTo(Party::class,'default_vendor_party_id'); }
}
