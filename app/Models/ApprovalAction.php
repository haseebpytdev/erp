<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalAction extends Model
{
    public $timestamps = false;
    protected $fillable = ['company_id','document_type','document_id','action','from_status','to_status','amount','user_id','remarks','metadata','created_at'];
    protected function casts(): array { return ['amount'=>'decimal:2','metadata'=>'array','created_at'=>'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
