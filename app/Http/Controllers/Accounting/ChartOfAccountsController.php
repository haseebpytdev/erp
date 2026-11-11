<?php
namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Company;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ChartOfAccountsController extends Controller
{
    public function index(Request $request): View
    {
        $company = Company::query()->firstOrFail();
        $type = strtoupper((string) $request->query('type'));
        $search = trim((string) $request->query('q'));
        $query = Account::query()->where('company_id',$company->id)->with('parent')->orderBy('code');
        if (in_array($type,['ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'],true)) $query->where('account_type',$type);
        if ($search !== '') $query->where(fn($q) => $q->where('code','like',"%{$search}%")->orWhere('name','like',"%{$search}%"));
        return view('accounting.chart-of-accounts',[
            'accounts'=>$query->get(),
            'parents'=>Account::query()->where('company_id',$company->id)->where('is_active',true)->orderBy('code')->get(),
            'counts'=>Account::query()->where('company_id',$company->id)->selectRaw('account_type, count(*) as total')->groupBy('account_type')->pluck('total','account_type'),
            'mappedCount'=>Account::query()->where('company_id',$company->id)->whereHas('mappings')->count(),
            'filterType'=>$type,'search'=>$search,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$this->validateAccount($request,$company->id); $data['company_id']=$company->id;
        $data['normal_balance']=$this->normalBalance($data['account_type']);
        $data['allow_posting']=$request->boolean('allow_posting'); $data['is_control']=$request->boolean('is_control'); $data['is_active']=true;
        $data['control_type']=$data['is_control']?($data['control_type']??null):null;
        $account=Account::query()->create($data); AuditService::log($request,'account.created',$account,[],$account->toArray());
        return back()->with('status','Account created.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $company=Company::query()->firstOrFail(); abort_unless($account->company_id===$company->id,404);
        $old=$account->toArray(); $data=$this->validateAccount($request,$company->id,$account->id);
        if ((int)($data['parent_id']??0)===$account->id) return back()->withErrors(['parent_id'=>'An account cannot be its own parent.']);
        if ($account->children()->exists() && $request->boolean('allow_posting')) return back()->withErrors(['allow_posting'=>'An account with child accounts must remain non-posting.']);
        $data['normal_balance']=$this->normalBalance($data['account_type']); $data['allow_posting']=$request->boolean('allow_posting');
        $data['is_control']=$request->boolean('is_control'); $data['is_active']=$request->boolean('is_active'); $data['control_type']=$data['is_control']?($data['control_type']??null):null;
        if ($account->mappings()->exists() && (!$data['allow_posting'] || !$data['is_active'])) return back()->withErrors(['account'=>'Mapped accounts must remain active posting accounts. Change the mapping first.']);
        $account->update($data); AuditService::log($request,'account.updated',$account,$old,$account->fresh()->toArray());
        return back()->with('status','Account updated.');
    }

    private function validateAccount(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $unique=Rule::unique('accounts')->where('company_id',$companyId); if($ignoreId)$unique=$unique->ignore($ignoreId);
        return $request->validate([
            'code'=>['required','string','max:30',$unique],'name'=>['required','string','max:150'],
            'account_type'=>['required','in:ASSET,LIABILITY,EQUITY,INCOME,EXPENSE'],'account_subtype'=>['nullable','string','max:50'],
            'parent_id'=>['nullable','integer',Rule::exists('accounts','id')->where('company_id',$companyId)],'control_type'=>['nullable','string','max:60'],
            'notes'=>['nullable','string','max:1500'],'allow_posting'=>['nullable','boolean'],'is_control'=>['nullable','boolean'],'is_active'=>['nullable','boolean'],
        ]);
    }
    private function normalBalance(string $type): string { return in_array($type,['ASSET','EXPENSE'],true)?'DEBIT':'CREDIT'; }
}
