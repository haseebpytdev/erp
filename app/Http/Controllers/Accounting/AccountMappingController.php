<?php
namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountMapping;
use App\Models\Company;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountMappingController extends Controller
{
    public function index(Request $request): View
    {
        $company=Company::query()->firstOrFail();
        $mappings=AccountMapping::query()->where('company_id',$company->id)->with('account')->orderBy('mapping_group')->orderBy('label')->get();
        return view('accounting.account-mappings',[
            'groups'=>$mappings->groupBy('mapping_group'),
            'accountsByType'=>Account::query()->where('company_id',$company->id)->where('is_active',true)->where('allow_posting',true)->orderBy('code')->get()->groupBy('account_type'),
            'missingRequired'=>$mappings->where('is_required',true)->whereNull('account_id')->count(),
        ]);
    }

    public function update(Request $request, AccountMapping $mapping): RedirectResponse
    {
        $company=Company::query()->firstOrFail(); abort_unless($mapping->company_id===$company->id,404);
        $data=$request->validate(['account_id'=>['required','integer',Rule::exists('accounts','id')->where(fn($q)=>$q->where('company_id',$company->id)->where('account_type',$mapping->expected_account_type)->where('is_active',true)->where('allow_posting',true))]]);
        $old=$mapping->toArray(); $mapping->update(['account_id'=>$data['account_id']]); AuditService::log($request,'account_mapping.updated',$mapping,$old,$mapping->fresh()->toArray());
        return back()->with('status',$mapping->label.' mapping updated.');
    }
}
