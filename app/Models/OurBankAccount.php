<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OurBankAccount extends Model
{
    protected $table = 'our_bank_accounts';
    protected $guarded = ['id'];
    protected $casts = [
        'company_id' => 'integer', 'branch_id' => 'integer', 'linked_ledger_id' => 'integer',
        'is_active' => 'boolean', 'use_for_ai_receipt_matching' => 'boolean',
    ];
}
