<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    protected $fillable = [
        'company_id','branch_id','booking_id','invoice_no','invoice_date','due_date','customer_party_id','agent_party_id','salesperson_staff_id',
        'customer_reference','currency_code','exchange_rate','subtotal','discount_total','grand_total','status','payment_terms_snapshot','notes',
        'fiscal_year_id','accounting_period_id','journal_entry_id','created_by','updated_by','submitted_by','submitted_at','approved_by','approved_at',
        'posted_by','posted_at','cancelled_by','cancelled_at','cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date'=>'date','due_date'=>'date','exchange_rate'=>'decimal:8','subtotal'=>'decimal:2','discount_total'=>'decimal:2','grand_total'=>'decimal:2',
            'submitted_at'=>'datetime','approved_at'=>'datetime','posted_at'=>'datetime','cancelled_at'=>'datetime',
        ];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Party::class,'customer_party_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(Party::class,'agent_party_id'); }
    public function salesperson(): BelongsTo { return $this->belongsTo(Staff::class,'salesperson_staff_id'); }
    public function passengers(): HasMany { return $this->hasMany(InvoicePassenger::class)->orderByDesc('is_lead')->orderBy('passenger_no'); }
    public function lines(): HasMany { return $this->hasMany(SalesInvoiceLine::class)->orderBy('line_no'); }
    public function journal(): BelongsTo { return $this->belongsTo(JournalEntry::class,'journal_entry_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class,'approved_by'); }
    public function poster(): BelongsTo { return $this->belongsTo(User::class,'posted_by'); }

    public function isEditable(): bool { return $this->status === 'DRAFT'; }
    public function isPosted(): bool { return $this->status === 'POSTED'; }
}
