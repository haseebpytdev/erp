<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCostAllocation extends Model
{
    protected $fillable = [
        'company_id','branch_id','booking_id','booking_service_id','sales_invoice_id','sales_invoice_line_id','vendor_party_id',
        'cost_type','description','quantity','unit_cost','tax_amount','total_cost','currency_code','exchange_rate','base_total_cost',
        'cost_mapping_key','status','supplier_reference','notes','created_by','updated_by','confirmed_by','confirmed_at',
        'cancelled_by','cancelled_at','cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity'=>'decimal:3','unit_cost'=>'decimal:2','tax_amount'=>'decimal:2','total_cost'=>'decimal:2',
            'exchange_rate'=>'decimal:8','base_total_cost'=>'decimal:2','confirmed_at'=>'datetime','cancelled_at'=>'datetime',
        ];
    }

    public function booking(): BelongsTo { return $this->belongsTo(Booking::class); }
    public function bookingService(): BelongsTo { return $this->belongsTo(BookingService::class); }
    public function salesInvoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class); }
    public function salesInvoiceLine(): BelongsTo { return $this->belongsTo(SalesInvoiceLine::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Party::class,'vendor_party_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class,'created_by'); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class,'confirmed_by'); }

    public function isEditable(): bool { return $this->status === 'ESTIMATED'; }
}
