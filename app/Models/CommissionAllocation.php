<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionAllocation extends Model
{
    protected $fillable = [
        'company_id','branch_id','booking_id','booking_service_id','air_ticket_detail_id','hotel_booking_detail_id','sales_invoice_id','sales_invoice_line_id',
        'commission_type','agent_party_id','salesperson_staff_id','basis','rate','base_amount','commission_amount','currency_code',
        'status','notes','created_by','updated_by','approved_by','approved_at','cancelled_by','cancelled_at','cancellation_reason',
    ];
    protected function casts(): array { return ['rate'=>'decimal:4','base_amount'=>'decimal:2','commission_amount'=>'decimal:2','approved_at'=>'datetime','cancelled_at'=>'datetime']; }
    public function airTicket(): BelongsTo { return $this->belongsTo(AirTicketDetail::class,'air_ticket_detail_id'); }
    public function hotelBooking(): BelongsTo { return $this->belongsTo(HotelBookingDetail::class,'hotel_booking_detail_id'); }
    public function agent(): BelongsTo { return $this->belongsTo(Party::class,'agent_party_id'); }
    public function salesperson(): BelongsTo { return $this->belongsTo(Staff::class,'salesperson_staff_id'); }
}
