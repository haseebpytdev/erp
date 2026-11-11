<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductService extends Model
{
    protected $table = 'products_services';

    protected $fillable = [
        'company_id','code','name','category','pricing_basis','passenger_link_mode','default_unit','default_currency_code','supplier_cost_currency_code',
        'default_vendor_party_id','revenue_mapping_key','cost_mapping_key','has_supplier_cost','is_active','notes','created_by','updated_by',
    ];

    protected function casts(): array
    {
        return ['has_supplier_cost'=>'boolean','is_active'=>'boolean'];
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function defaultVendor(): BelongsTo { return $this->belongsTo(Party::class,'default_vendor_party_id'); }
}
