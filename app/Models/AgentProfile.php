<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AgentProfile extends Model
{
    protected $fillable=['party_id','default_currency_code','agent_category','default_commission_basis','default_commission_rate','settlement_days','agent_status'];
    protected function casts(): array { return ['default_commission_rate'=>'decimal:4']; }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
}
