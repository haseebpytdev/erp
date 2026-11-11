<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VendorProfile extends Model
{
    protected $fillable=['party_id','default_currency_code','vendor_category','payment_terms_days','vendor_status','supplier_reference'];
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
}
