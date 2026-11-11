<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelVoucherItem extends Model
{
    protected $fillable = [
        'travel_voucher_id','item_type','title','reference','from_location','to_location',
        'starts_at','ends_at','details','meta_json','sort_order','is_active','created_by','updated_by',
    ];

    protected function casts(): array { return ['starts_at'=>'datetime','ends_at'=>'datetime','meta_json'=>'array','is_active'=>'boolean']; }
    public function voucher(): BelongsTo { return $this->belongsTo(TravelVoucher::class,'travel_voucher_id'); }
}
