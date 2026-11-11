<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserApprovalLimit extends Model
{
    protected $fillable = ['user_id','approval_policy_id','amount_limit','can_approve','can_override'];
    protected function casts(): array { return ['amount_limit'=>'decimal:2','can_approve'=>'boolean','can_override'=>'boolean']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function policy(): BelongsTo { return $this->belongsTo(ApprovalPolicy::class, 'approval_policy_id'); }
}
