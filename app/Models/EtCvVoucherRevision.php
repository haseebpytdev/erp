<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EtCvVoucherRevision extends Model
{
    protected $table = 'et_cv_voucher_revisions';
    protected $guarded = [];
    protected $casts = [
        'snapshot' => 'array',
        'issued_at' => 'datetime',
    ];
}
