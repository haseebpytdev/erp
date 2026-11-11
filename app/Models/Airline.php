<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Airline extends Model
{
    protected $fillable = [
        'company_id','code','iata_code','icao_code','accounting_prefix','name','country_code',
        'default_vendor_party_id','is_active','notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active'=>'boolean'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function defaultVendor(): BelongsTo { return $this->belongsTo(Party::class,'default_vendor_party_id'); }
    public function airTicketDetails(): HasMany { return $this->hasMany(AirTicketDetail::class,'airline_id'); }
}
