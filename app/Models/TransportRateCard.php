<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class TransportRateCard extends Model
{
    protected $fillable=[
        'company_id','transport_company_id','name','currency_code','effective_from','effective_until',
        'status','source_rate_card_id','is_active','notes','created_by','updated_by'
    ];

    protected function casts(): array
    {
        return ['effective_from'=>'date','effective_until'=>'date','is_active'=>'boolean'];
    }

    public function transportCompany(): BelongsTo{return $this->belongsTo(TravelVoucherPartner::class,'transport_company_id');}
    public function rates(): HasMany{return $this->hasMany(TransportRate::class);}
    public function sourceRateCard(): BelongsTo{return $this->belongsTo(self::class,'source_rate_card_id');}

    public function storedStatus(): string
    {
        if (Schema::hasColumn($this->getTable(),'status')) {
            $status=strtoupper(trim((string)($this->status ?: '')));
            if ($status!=='') return $status;
        }
        return $this->is_active ? 'ACTIVE' : 'INACTIVE';
    }

    public function lifecycleStatus(?Carbon $onDate=null): string
    {
        $stored=$this->storedStatus();
        if ($stored==='DRAFT') return 'DRAFT';
        if ($stored==='INACTIVE') return 'INACTIVE';

        $onDate=$onDate ?: now()->startOfDay();
        if ($this->effective_from && $this->effective_from->startOfDay()->gt($onDate)) return 'UPCOMING';
        if ($this->effective_until && $this->effective_until->endOfDay()->lt($onDate)) return 'EXPIRED';
        return 'ACTIVE';
    }

    public function isDraft(): bool{return $this->storedStatus()==='DRAFT';}
}
