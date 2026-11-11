<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['company_id','code','name','description','is_system','is_active'];
    protected function casts(): array { return ['is_system'=>'boolean','is_active'=>'boolean']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function permissions(): BelongsToMany { return $this->belongsToMany(Permission::class, 'role_permission'); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class, 'role_user'); }
}
