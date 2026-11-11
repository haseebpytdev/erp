<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('administration.roles', [
            'roles'=>Role::query()->with('permissions')->withCount('users')->orderBy('name')->get(),
            'permissionGroups'=>Permission::query()->orderBy('module')->orderBy('name')->get()->groupBy('module'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$request->validate(['code'=>['required','string','max:60',Rule::unique('roles')->where('company_id',$company->id)],'name'=>['required','string','max:120'],'description'=>['nullable','string','max:1000'],'permission_ids'=>['nullable','array'],'permission_ids.*'=>['exists:permissions,id']]);
        DB::transaction(function () use ($company,$data,$request): void {
            $role=Role::query()->create(['company_id'=>$company->id,'code'=>strtoupper($data['code']),'name'=>$data['name'],'description'=>$data['description']??null,'is_system'=>false,'is_active'=>true]);
            $role->permissions()->sync($data['permission_ids']??[]);
            AuditService::log($request,'role.created',$role,[],['code'=>$role->code,'name'=>$role->name,'permissions'=>$role->permissions()->pluck('key')->all()]);
        });
        return back()->with('status','Custom role created.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $old=['code'=>$role->code,'name'=>$role->name,'description'=>$role->description,'is_active'=>$role->is_active,'permissions'=>$role->permissions()->pluck('key')->all()];
        $data=$request->validate(['name'=>['required','string','max:120'],'description'=>['nullable','string','max:1000'],'is_active'=>['nullable','boolean'],'permission_ids'=>['nullable','array'],'permission_ids.*'=>['exists:permissions,id']]);
        if ($role->code==='SUPER_ADMIN') return back()->withErrors(['role'=>'Super Admin permissions are unrestricted and cannot be reduced.']);
        $role->update(['name'=>$data['name'],'description'=>$data['description']??null,'is_active'=>$request->boolean('is_active')]);
        $role->permissions()->sync($data['permission_ids']??[]);
        AuditService::log($request,'role.updated',$role,$old,['code'=>$role->code,'name'=>$role->name,'description'=>$role->description,'is_active'=>$role->is_active,'permissions'=>$role->permissions()->pluck('key')->all()]);
        return back()->with('status','Role permissions updated.');
    }
}
