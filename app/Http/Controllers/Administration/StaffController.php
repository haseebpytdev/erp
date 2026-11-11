<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Staff;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('administration.staff', [
            'staff'=>Staff::query()->with(['branch','department','manager','user'])->orderBy('name')->get(),
            'branches'=>Branch::query()->where('is_active',true)->orderBy('name')->get(),
            'departments'=>Department::query()->where('is_active',true)->orderBy('name')->get(),
            'managers'=>Staff::query()->where('is_active',true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company = Company::query()->firstOrFail();
        $data = $request->validate([
            'employee_code'=>['required','string','max:40',Rule::unique('staff')->where('company_id',$company->id)],
            'name'=>['required','string','max:150'],'designation'=>['nullable','string','max:120'],
            'branch_id'=>['nullable','exists:branches,id'],'department_id'=>['nullable','exists:departments,id'],
            'manager_staff_id'=>['nullable','exists:staff,id'],'email'=>['nullable','email','max:190'],'mobile'=>['nullable','string','max:60'],
            'joining_date'=>['nullable','date'],'employment_status'=>['required','in:ACTIVE,PROBATION,ON_LEAVE,RESIGNED,TERMINATED,INACTIVE'],
            'notes'=>['nullable','string','max:3000'],
        ]);
        $staff = Staff::query()->create(array_merge($data,['company_id'=>$company->id,'employee_code'=>strtoupper($data['employee_code']),'is_active'=>! in_array($data['employment_status'],['RESIGNED','TERMINATED','INACTIVE'],true)]));
        AuditService::log($request,'staff.created',$staff,[],$staff->toArray());
        return back()->with('status','Staff profile created. Create an ERP user separately only if login access is required.');
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $old = $staff->toArray();
        $data = $request->validate([
            'employee_code'=>['required','string','max:40',Rule::unique('staff')->ignore($staff->id)->where('company_id',$staff->company_id)],
            'name'=>['required','string','max:150'],'designation'=>['nullable','string','max:120'],
            'branch_id'=>['nullable','exists:branches,id'],'department_id'=>['nullable','exists:departments,id'],
            'manager_staff_id'=>['nullable','exists:staff,id'],'email'=>['nullable','email','max:190'],'mobile'=>['nullable','string','max:60'],
            'joining_date'=>['nullable','date'],'employment_status'=>['required','in:ACTIVE,PROBATION,ON_LEAVE,RESIGNED,TERMINATED,INACTIVE'],
            'notes'=>['nullable','string','max:3000'],
        ]);
        if ((int)($data['manager_staff_id'] ?? 0) === $staff->id) return back()->withErrors(['manager_staff_id'=>'A staff member cannot report to themselves.']);
        $data['employee_code']=strtoupper($data['employee_code']);
        $data['is_active']=! in_array($data['employment_status'],['RESIGNED','TERMINATED','INACTIVE'],true);
        $staff->update($data);
        if (! $staff->is_active && $staff->user) $staff->user->update(['is_active'=>false]);
        AuditService::log($request,'staff.updated',$staff,$old,$staff->fresh()->toArray());
        return back()->with('status','Staff profile updated.');
    }
}
