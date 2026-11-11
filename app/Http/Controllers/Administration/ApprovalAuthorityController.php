<?php
namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Models\ApprovalPolicy;
use App\Models\User;
use App\Models\UserApprovalLimit;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApprovalAuthorityController extends Controller
{
    public function index(): View
    {
        return view('administration.approvals',[
            'policies'=>ApprovalPolicy::query()->where('is_active',true)->orderBy('module')->orderBy('name')->get(),
            'users'=>User::query()->where('is_active',true)->orderBy('name')->get(),
            'limits'=>UserApprovalLimit::query()->with(['user','policy'])->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data=$request->validate(['user_id'=>['required','exists:users,id'],'approval_policy_id'=>['required','exists:approval_policies,id'],'amount_limit'=>['nullable','numeric','min:0'],'can_approve'=>['nullable','boolean'],'can_override'=>['nullable','boolean']]);
        $model=UserApprovalLimit::query()->updateOrCreate(['user_id'=>$data['user_id'],'approval_policy_id'=>$data['approval_policy_id']],['amount_limit'=>$data['amount_limit']??null,'can_approve'=>$request->boolean('can_approve'),'can_override'=>$request->boolean('can_override')]);
        AuditService::log($request,'approval_authority.saved',$model,[],['user_id'=>$model->user_id,'policy_id'=>$model->approval_policy_id,'amount_limit'=>$model->amount_limit,'can_approve'=>$model->can_approve,'can_override'=>$model->can_override]);
        return back()->with('status','Approval authority saved.');
    }

    public function destroy(Request $request, UserApprovalLimit $limit): RedirectResponse
    {
        $old=$limit->toArray(); $limit->delete(); AuditService::log($request,'approval_authority.removed',null,$old,[]);
        return back()->with('status','Approval authority removed.');
    }
}
