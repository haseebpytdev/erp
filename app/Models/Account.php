<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'company_id','parent_id','code','name','account_type','account_subtype','normal_balance',
        'allow_posting','is_control','control_type','is_active','notes',
    ];

    protected function casts(): array
    {
        return [
            'allow_posting' => 'boolean',
            'is_control' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_id'); }
    public function children(): HasMany { return $this->hasMany(self::class, 'parent_id'); }
    public function mappings(): HasMany { return $this->hasMany(AccountMapping::class); }
}
