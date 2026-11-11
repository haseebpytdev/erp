<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'staff_id','name','email','password','is_super_admin','is_active','access_scope','force_password_change',
        'last_login_at','failed_login_count','last_failed_login_at','last_login_ip',
    ];

    protected $hidden = ['password','remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'=>'datetime','password'=>'hashed','is_super_admin'=>'boolean','is_active'=>'boolean',
            'force_password_change'=>'boolean','last_login_at'=>'datetime','last_failed_login_at'=>'datetime',
        ];
    }

    public function staff(): BelongsTo { return $this->belongsTo(Staff::class); }
    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class, 'role_user')->withTimestamps(); }
    public function branches(): BelongsToMany { return $this->belongsToMany(Branch::class, 'branch_user')->withPivot('is_primary')->withTimestamps(); }
    public function approvalLimits(): HasMany { return $this->hasMany(UserApprovalLimit::class); }

    public function hasPermission(string $key): bool
    {
        if ($this->is_super_admin) return true;
        if (! $this->is_active || ! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) return false;
        return $this->roles()->where('is_active', true)->whereHas('permissions', fn ($q) => $q->where('key', $key))->exists();
    }

    public function hasAnyPermission(array $keys): bool
    {
        if ($this->is_super_admin) return true;
        foreach ($keys as $key) if ($this->hasPermission($key)) return true;
        return false;
    }

    public function roleLabel(): string
    {
        if ($this->is_super_admin) return 'Super Admin';
        if (! Schema::hasTable('roles')) return 'User';
        return $this->roles()->where('is_active', true)->orderBy('name')->value('name') ?? 'User';
    }

    public function primaryBranch(): ?Branch
    {
        if (! Schema::hasTable('branch_user')) return null;
        return $this->branches()->wherePivot('is_primary', true)->first() ?? $this->branches()->first();
    }

    public function canApprove(string $policyKey, ?float $amount = null): bool
    {
        if ($this->is_super_admin) return true;
        if (! Schema::hasTable('user_approval_limits')) return false;
        $limit = $this->approvalLimits()->whereHas('policy', fn ($q) => $q->where('key', $policyKey)->where('is_active', true))->first();
        if (! $limit || ! $limit->can_approve) return false;
        if ($limit->can_override || $amount === null || $limit->amount_limit === null) return true;
        return $amount <= (float) $limit->amount_limit;
    }
}
