<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id','line_no','account_id','description','debit','credit','base_debit','base_credit',
        'party_type','party_id','source_line_type','source_line_id',
    ];

    protected function casts(): array
    {
        return ['debit'=>'decimal:2','credit'=>'decimal:2','base_debit'=>'decimal:2','base_credit'=>'decimal:2'];
    }

    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
}
