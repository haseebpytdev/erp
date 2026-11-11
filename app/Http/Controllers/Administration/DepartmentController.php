<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Department;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(): View { return view('administration.departments',['departments'=>Department::query()->orderBy('name')->get()]); }
    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$request->validate(['code'=>['required','string','max:30',Rule::unique('departments')->where('company_id',$company->id)],'name'=>['required','string','max:120']]);
        $model=Department::query()->create(['company_id'=>$company->id,'code'=>strtoupper($data['code']),'name'=>$data['name'],'is_active'=>true]);
        AuditService::log($request,'department.created',$model,[],$model->toArray());
        return back()->with('status','Department created.');
    }
    public function update(Request $request, Department $department): RedirectResponse
    {
        $old=$department->toArray();
        $data=$request->validate(['code'=>['required','string','max:30',Rule::unique('departments')->ignore($department->id)->where('company_id',$department->company_id)],'name'=>['required','string','max:120'],'is_active'=>['nullable','boolean']]);
        $department->update(['code'=>strtoupper($data['code']),'name'=>$data['name'],'is_active'=>$request->boolean('is_active')]);
        AuditService::log($request,'department.updated',$department,$old,$department->fresh()->toArray());
        return back()->with('status','Department updated.');
    }
}
