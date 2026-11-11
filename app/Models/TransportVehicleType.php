<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransportVehicleType extends Model
{
    protected $fillable=['company_id','code','name','min_pax','max_pax','is_active','notes','created_by','updated_by'];
    protected function casts(): array{return ['min_pax'=>'integer','max_pax'=>'integer','is_active'=>'boolean'];}
    public function label(): string
    {
        $range=$this->min_pax && $this->max_pax ? $this->min_pax.'–'.$this->max_pax.' Pax' : ($this->max_pax ? 'Up to '.$this->max_pax.' Pax' : '');
        return $this->name.($range!==''?' · '.$range:'');
    }
}
