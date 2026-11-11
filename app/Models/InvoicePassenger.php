<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InvoicePassenger extends Model
{
    protected $fillable=['sales_invoice_id','source_booking_passenger_id','passenger_no','pax_type','title','first_name','middle_name','last_name','date_of_birth','nationality_country_code','passport_no','passport_expiry','is_lead'];
    protected function casts(): array { return ['date_of_birth'=>'date','passport_expiry'=>'date','is_lead'=>'boolean']; }
    public function invoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class,'sales_invoice_id'); }
    public function sourcePassenger(): BelongsTo { return $this->belongsTo(BookingPassenger::class,'source_booking_passenger_id'); }
    public function lines(): BelongsToMany { return $this->belongsToMany(SalesInvoiceLine::class,'sales_invoice_line_passengers')->withTimestamps(); }
    public function fullName(): string { return trim(implode(' ',array_filter([$this->title,$this->first_name,$this->middle_name,$this->last_name]))); }
}
