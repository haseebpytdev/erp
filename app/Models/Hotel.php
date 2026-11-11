<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Hotel extends Model
{
    protected $fillable = [
        'company_id','code','name','city','city_iata_code','country_code','address','phone','star_rating',
        'default_vendor_party_id','is_active','notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active'=>'boolean','star_rating'=>'decimal:1'];
    }

    protected static function booted(): void
    {
        static::saving(function (Hotel $hotel): void {
            if (
                !Schema::hasTable('travel_city_codes')
                || !Schema::hasColumn('hotels','city_iata_code')
                || trim((string)$hotel->city)===''
            ) {
                return;
            }

            $normalize=static fn(?string $value): string =>
                strtoupper(trim(preg_replace('/\s+/',' ',(string)$value)));

            $needle=$normalize((string)$hotel->city);
            $rows=DB::table('travel_city_codes')
                ->where('is_active',1)
                ->get(['code','city','country_code','aliases']);

            $match=$rows->first(function($row) use($needle,$normalize): bool {
                if ($normalize((string)$row->city)===$needle) return true;
                foreach ((array)json_decode((string)($row->aliases??'[]'),true) as $alias) {
                    if ($normalize((string)$alias)===$needle) return true;
                }
                return false;
            });

            if ($match) {
                $hotel->city_iata_code=strtoupper((string)$match->code);
                $hotel->country_code=strtoupper((string)$match->country_code);
            }
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function defaultVendor(): BelongsTo { return $this->belongsTo(Party::class,'default_vendor_party_id'); }
    public function bookingDetails(): HasMany { return $this->hasMany(HotelBookingDetail::class,'hotel_id'); }
}
