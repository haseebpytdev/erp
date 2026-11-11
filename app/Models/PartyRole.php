<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PartyRole extends Model
{
    protected $fillable=['party_id','role','is_active','starts_on','ends_on'];
    protected function casts(): array { return ['is_active'=>'boolean','starts_on'=>'date','ends_on'=>'date']; }
    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
}
