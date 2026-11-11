<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TravelVoucherRevision extends Model
{
    protected $table = 'travel_voucher_revisions';

    protected $fillable = [
        'travel_voucher_id', 'booking_id', 'revision_no', 'voucher_no',
        'reason', 'issued_by', 'issued_at', 'template_version', 'rendered_html',
    ];

    protected $casts = [
        'revision_no' => 'integer',
        'issued_at' => 'datetime',
    ];

    public function voucher(): BelongsTo { return $this->belongsTo(TravelVoucher::class, 'travel_voucher_id'); }
    public function booking(): BelongsTo { return $this->belongsTo(Booking::class, 'booking_id'); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
}
