<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['code','name','symbol','decimal_places','is_active'];

    protected function casts(): array
    {
        return ['decimal_places' => 'integer','is_active' => 'boolean'];
    }
}
