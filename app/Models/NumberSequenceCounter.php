<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberSequenceCounter extends Model
{
    protected $fillable = ['number_sequence_id','fiscal_year_id','accounting_period_id','last_number'];
    protected function casts(): array { return ['last_number'=>'integer']; }
    public function sequence(): BelongsTo { return $this->belongsTo(NumberSequence::class, 'number_sequence_id'); }
}
