<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CustomerProfile extends Model
{
    protected $fillable=['party_id','default_currency_code','customer_category','credit_limit','credit_days','credit_status','external_reference'];
    protected function casts(): array { return ['credit_limit'=>'decimal:2']; }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
}
