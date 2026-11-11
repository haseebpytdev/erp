<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Party extends Model
{
    protected $fillable = [
        'company_id','code','entity_type','display_name','legal_name','email','mobile','phone','tax_id','national_id',
        'address_line_1','address_line_2','city','country_code','notes','is_active','created_by','updated_by',
    ];
    protected function casts(): array { return ['is_active'=>'boolean']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function roles(): HasMany { return $this->hasMany(PartyRole::class); }
    public function customerProfile(): HasOne { return $this->hasOne(CustomerProfile::class); }
    public function vendorProfile(): HasOne { return $this->hasOne(VendorProfile::class); }
    public function agentProfile(): HasOne { return $this->hasOne(AgentProfile::class); }
    public function employeeProfile(): HasOne { return $this->hasOne(EmployeePartyProfile::class); }
    public function hasRole(string $role): bool { return $this->roles->contains(fn($r)=>$r->role===$role && $r->is_active); }
}
