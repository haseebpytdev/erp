<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EmployeePartyProfile extends Model
{
    protected $fillable=['party_id','staff_id','employee_account_status'];
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function staff(): BelongsTo { return $this->belongsTo(Staff::class); }
}
