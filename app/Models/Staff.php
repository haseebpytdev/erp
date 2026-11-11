<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Staff extends Model
{
    protected $table = 'staff';
    protected $fillable = ['company_id','branch_id','department_id','manager_staff_id','employee_code','name','designation','email','mobile','joining_date','employment_status','notes','is_active'];
    protected function casts(): array { return ['joining_date'=>'date','is_active'=>'boolean']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function manager(): BelongsTo { return $this->belongsTo(self::class, 'manager_staff_id'); }
    public function user(): HasOne { return $this->hasOne(User::class); }
}
