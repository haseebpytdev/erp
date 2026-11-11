<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SalesInvoiceLine extends Model
{
    protected $fillable=['sales_invoice_id','line_no','source_booking_service_id','product_service_id','category_snapshot','pricing_basis_snapshot','description','service_from','service_to','quantity','unit_price','line_subtotal','discount_amount','line_total','currency_code','revenue_mapping_key','detail_snapshot','notes'];
    protected function casts(): array { return ['service_from'=>'date','service_to'=>'date','quantity'=>'decimal:3','unit_price'=>'decimal:2','line_subtotal'=>'decimal:2','discount_amount'=>'decimal:2','line_total'=>'decimal:2','detail_snapshot'=>'array']; }
    public function invoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class,'sales_invoice_id'); }
    public function sourceService(): BelongsTo { return $this->belongsTo(BookingService::class,'source_booking_service_id'); }
    public function product(): BelongsTo { return $this->belongsTo(ProductService::class,'product_service_id'); }
    public function passengers(): BelongsToMany { return $this->belongsToMany(InvoicePassenger::class,'sales_invoice_line_passengers')->withTimestamps(); }
}
