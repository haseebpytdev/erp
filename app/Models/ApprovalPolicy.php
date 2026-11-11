<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalPolicy extends Model
{
    protected $fillable = ['company_id','key','name','module','is_amount_based','requires_checker','prevent_self_approval','is_active'];
    protected function casts(): array { return ['is_amount_based'=>'boolean','requires_checker'=>'boolean','prevent_self_approval'=>'boolean','is_active'=>'boolean']; }
    public function limits(): HasMany { return $this->hasMany(UserApprovalLimit::class); }
}
