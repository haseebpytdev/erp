<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('administration.users', [
            'users'=>User::query()->with(['staff','roles','branches'])->orderBy('name')->get(),
            'staff'=>Staff::query()->where('is_active',true)->whereDoesntHave('user')->orderBy('name')->get(),
            'roles'=>Role::query()->where('is_active',true)->where('code','!=','SUPER_ADMIN')->orderBy('name')->get(),
            'branches'=>Branch::query()->where('is_active',true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'staff_id'=>['nullable','exists:staff,id','unique:users,staff_id'],'name'=>['required','string','max:150'],
            'email'=>['required','email','max:190','unique:users,email'],'password'=>['required','string','min:10','confirmed'],
            'access_scope'=>['required','in:PRIMARY_BRANCH,SELECTED_BRANCHES,ALL_BRANCHES'],
            'role_ids'=>['required','array','min:1'],'role_ids.*'=>[Rule::exists('roles','id')->where(fn($q)=>$q->where('code','!=','SUPER_ADMIN'))],
            'branch_ids'=>['nullable','array'],'branch_ids.*'=>['exists:branches,id'],'primary_branch_id'=>['nullable','exists:branches,id'],
        ]);
        if ($data['access_scope'] !== 'ALL_BRANCHES' && empty($data['branch_ids'])) return back()->withErrors(['branch_ids'=>'Select at least one branch for this access scope.'])->withInput();
        DB::transaction(function () use ($data,$request): void {
            $user=User::query()->create(['staff_id'=>$data['staff_id']??null,'name'=>$data['name'],'email'=>strtolower($data['email']),'password'=>$data['password'],'is_super_admin'=>false,'is_active'=>true,'access_scope'=>$data['access_scope'],'force_password_change'=>true]);
            $user->roles()->sync($data['role_ids']);
            $this->syncBranches($user,$data['branch_ids']??[],$data['primary_branch_id']??null,$data['access_scope']);
            AuditService::log($request,'user.created',$user,[],$this->snapshot($user));
        });
        return back()->with('status','ERP user created. The user will be required to change the initial password.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $old=$this->snapshot($user);
        $data=$request->validate([
            'staff_id'=>['nullable','exists:staff,id',Rule::unique('users','staff_id')->ignore($user->id)],'name'=>['required','string','max:150'],
            'email'=>['required','email','max:190',Rule::unique('users','email')->ignore($user->id)],
            'access_scope'=>['required','in:PRIMARY_BRANCH,SELECTED_BRANCHES,ALL_BRANCHES'],'is_active'=>['nullable','boolean'],
            'role_ids'=>['nullable','array'],'role_ids.*'=>[Rule::exists('roles','id')->where(fn($q)=>$q->where('code','!=','SUPER_ADMIN'))],'branch_ids'=>['nullable','array'],'branch_ids.*'=>['exists:branches,id'],'primary_branch_id'=>['nullable','exists:branches,id'],
        ]);
        $active=$request->boolean('is_active');
        if ($request->user()->is($user) && ! $active) return back()->withErrors(['is_active'=>'You cannot disable your own logged-in account.']);
        if ($user->is_super_admin && ! $active && User::query()->where('is_super_admin',true)->where('is_active',true)->count() <= 1) return back()->withErrors(['is_active'=>'The last active Super Admin cannot be disabled.']);
        if ($data['access_scope'] !== 'ALL_BRANCHES' && empty($data['branch_ids'])) return back()->withErrors(['branch_ids'=>'Select at least one branch for this access scope.']);
        DB::transaction(function () use ($data,$request,$user,$active,$old): void {
            $user->update(['staff_id'=>$data['staff_id']??null,'name'=>$data['name'],'email'=>strtolower($data['email']),'access_scope'=>$data['access_scope'],'is_active'=>$active]);
            if (! $user->is_super_admin) $user->roles()->sync($data['role_ids']??[]);
            $this->syncBranches($user,$data['branch_ids']??[],$data['primary_branch_id']??null,$data['access_scope']);
            AuditService::log($request,'user.updated',$user,$old,$this->snapshot($user));
        });
        return back()->with('status','User access updated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $data=$request->validate(['password'=>['required','string','min:10','confirmed']]);
        $user->update(['password'=>$data['password'],'force_password_change'=>true,'failed_login_count'=>0]);
        AuditService::log($request,'user.password_reset',$user,[],['force_password_change'=>true]);
        return back()->with('status','Password reset. User will be required to change it after login.');
    }

    private function syncBranches(User $user,array $branchIds,?int $primary,string $scope): void
    {
        if ($scope==='ALL_BRANCHES') { $user->branches()->detach(); return; }
        $sync=[]; foreach ($branchIds as $bid) $sync[(int)$bid]=['is_primary'=>(int)$bid===(int)$primary];
        if ($sync && ! collect($sync)->contains(fn($v)=>$v['is_primary'])) { $first=array_key_first($sync); $sync[$first]['is_primary']=true; }
        $user->branches()->sync($sync);
    }

    private function snapshot(User $user): array
    {
        $user->loadMissing(['roles:id,code,name','branches:id,code,name','staff:id,employee_code,name']);
        return ['id'=>$user->id,'staff_id'=>$user->staff_id,'name'=>$user->name,'email'=>$user->email,'is_super_admin'=>$user->is_super_admin,'is_active'=>$user->is_active,'access_scope'=>$user->access_scope,'roles'=>$user->roles->pluck('code')->all(),'branches'=>$user->branches->pluck('code')->all()];
    }
}
