<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransportRate extends Model
{
    protected $fillable=['transport_rate_card_id','transport_route_id','transport_vehicle_type_id','cost_amount','notes'];
    protected function casts(): array{return ['cost_amount'=>'decimal:2'];}
    public function rateCard(): BelongsTo{return $this->belongsTo(TransportRateCard::class,'transport_rate_card_id');}
    public function route(): BelongsTo{return $this->belongsTo(TransportRoute::class,'transport_route_id');}
    public function vehicleType(): BelongsTo{return $this->belongsTo(TransportVehicleType::class,'transport_vehicle_type_id');}
}
