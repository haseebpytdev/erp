<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $fillable = ['company_id','code','name','starts_on','ends_on','status'];

    protected function casts(): array
    {
        return ['starts_on' => 'date','ends_on' => 'date'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('period_no');
    }
}
