<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberSequence extends Model
{
    protected $fillable = [
        'company_id','branch_id','document_type','name','prefix','next_number','padding','reset_policy','is_active',
    ];

    protected function casts(): array
    {
        return ['next_number' => 'integer','padding' => 'integer','is_active' => 'boolean'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
}
