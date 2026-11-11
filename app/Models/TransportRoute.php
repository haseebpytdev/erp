<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    protected $fillable=['company_id','code','name','pickup_location','dropoff_location','is_active','notes','created_by','updated_by'];
    protected function casts(): array{return ['is_active'=>'boolean'];}
}
