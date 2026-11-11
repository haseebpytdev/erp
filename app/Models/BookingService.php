<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BookingService extends Model
{
    protected $fillable = [
        'booking_id','product_service_id','description','service_from','service_to','quantity','unit_price','line_total','currency_code',
        'pricing_basis_snapshot','passenger_link_mode_snapshot','status','notes','created_by','updated_by',
    ];
    protected function casts(): array { return ['service_from'=>'date','service_to'=>'date','quantity'=>'decimal:3','unit_price'=>'decimal:2','line_total'=>'decimal:2']; }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function product(): BelongsTo { return $this->belongsTo(ProductService::class,'product_service_id'); }
    public function passengers(): BelongsToMany { return $this->belongsToMany(BookingPassenger::class,'booking_service_passengers')->withTimestamps(); }
}
