<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    protected $fillable = [
        'company_id','mapping_key','label','mapping_group','expected_account_type','account_id','is_required','description',
    ];

    protected function casts(): array { return ['is_required' => 'boolean']; }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
