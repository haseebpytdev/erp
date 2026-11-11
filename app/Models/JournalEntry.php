<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    protected $fillable = [
        'company_id','branch_id','fiscal_year_id','accounting_period_id','journal_no','journal_date','journal_type','origin',
        'source_type','source_id','reference','description','currency_code','exchange_rate','total_debit','total_credit',
        'total_debit_base','total_credit_base','status','created_by','approved_by','approved_at','posted_by','posted_at',
        'reversed_by','reversed_at','reversal_of_id','reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'journal_date'=>'date','exchange_rate'=>'decimal:8','total_debit'=>'decimal:2','total_credit'=>'decimal:2',
            'total_debit_base'=>'decimal:2','total_credit_base'=>'decimal:2','approved_at'=>'datetime','posted_at'=>'datetime','reversed_at'=>'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function fiscalYear(): BelongsTo { return $this->belongsTo(FiscalYear::class); }
    public function accountingPeriod(): BelongsTo { return $this->belongsTo(AccountingPeriod::class); }
    public function lines(): HasMany { return $this->hasMany(JournalLine::class)->orderBy('line_no'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function reverser(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(self::class, 'reversal_of_id'); }
    public function reversalEntry(): HasOne { return $this->hasOne(self::class, 'reversal_of_id'); }

    public function isEditable(): bool { return $this->status === 'DRAFT'; }
    public function isPosted(): bool { return $this->status === 'POSTED'; }
}
