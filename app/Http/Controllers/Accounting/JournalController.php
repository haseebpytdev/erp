<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApprovalAction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function __construct(private readonly JournalService $journals) {}

    public function index(Request $request): View
    {
        $company = Company::query()->firstOrFail();
        $branchIds = $this->allowedBranches($request, $company->id)->pluck('id');
        $status = strtoupper((string)$request->query('status'));
        $search = trim((string)$request->query('q'));
        $query = JournalEntry::query()->where('company_id',$company->id)->whereIn('branch_id',$branchIds)
            ->with(['branch','creator','approver','poster'])->latest('journal_date')->latest('id');
        if (in_array($status,['DRAFT','PENDING_APPROVAL','APPROVED','POSTED','REVERSED'],true)) $query->where('status',$status);
        if ($search !== '') $query->where(fn($q)=>$q->where('journal_no','like',"%{$search}%")->orWhere('reference','like',"%{$search}%")->orWhere('description','like',"%{$search}%"));

        $base = JournalEntry::query()->where('company_id',$company->id)->whereIn('branch_id',$branchIds);
        return view('accounting.journals.index',[
            'journals'=>$query->limit(150)->get(),
            'counts'=>[
                'draft'=>(clone $base)->where('status','DRAFT')->count(),
                'pending'=>(clone $base)->where('status','PENDING_APPROVAL')->count(),
                'approved'=>(clone $base)->where('status','APPROVED')->count(),
                'posted'=>(clone $base)->where('status','POSTED')->count(),
            ],
            'statusFilter'=>$status,'search'=>$search,
        ]);
    }

    public function create(Request $request): View
    {
        return $this->formView($request, null);
    }

    public function store(Request $request): RedirectResponse
    {
        [$company,$header,$lines] = $this->validatedPayload($request);
        $journal = $this->journals->createDraft($request,$header,$lines);
        return redirect()->route('accounting.journals.show',$journal)->with('status','Journal draft created.');
    }

    public function show(Request $request, JournalEntry $journal): View
    {
        $this->authorizeScope($request,$journal);
        $journal->load(['lines.account','branch','fiscalYear','accountingPeriod','creator','approver','poster','reverser','reversalOf','reversalEntry']);
        $actions = ApprovalAction::query()->where('document_type','JOURNAL_ENTRY')->where('document_id',$journal->id)->with('user')->orderBy('id')->get();
        return view('accounting.journals.show',compact('journal','actions'));
    }

    public function edit(Request $request, JournalEntry $journal): View
    {
        $this->authorizeScope($request,$journal);
        abort_unless($journal->isEditable(),409,'Posted/submitted journals are immutable.');
        $journal->load('lines');
        return $this->formView($request,$journal);
    }

    public function update(Request $request, JournalEntry $journal): RedirectResponse
    {
        $this->authorizeScope($request,$journal);
        [, $header,$lines] = $this->validatedPayload($request,$journal);
        $this->journals->updateDraft($request,$journal,$header,$lines);
        return redirect()->route('accounting.journals.show',$journal)->with('status','Journal draft updated.');
    }

    public function submit(Request $request, JournalEntry $journal): RedirectResponse
    {
        $this->authorizeScope($request,$journal);
        $data=$request->validate(['remarks'=>['nullable','string','max:1000']]);
        $this->journals->submit($request,$journal,$data['remarks']??null);
        return back()->with('status','Journal submitted for approval.');
    }

    public function approve(Request $request, JournalEntry $journal): RedirectResponse
    {
        $this->authorizeScope($request,$journal);
        $data=$request->validate(['remarks'=>['nullable','string','max:1000']]);
        $this->journals->approve($request,$journal,$data['remarks']??null);
        return back()->with('status','Journal approved.');
    }

    public function post(Request $request, JournalEntry $journal): RedirectResponse
    {
        $this->authorizeScope($request,$journal);
        $data=$request->validate(['remarks'=>['nullable','string','max:1000']]);
        $this->journals->post($request,$journal,$data['remarks']??null);
        return back()->with('status','Journal posted. It is now immutable.');
    }

    public function reverse(Request $request, JournalEntry $journal): RedirectResponse
    {
        $this->authorizeScope($request,$journal);
        $data=$request->validate(['reversal_date'=>['required','date'],'reason'=>['required','string','min:5','max:1500']]);
        $reversal=$this->journals->reverse($request,$journal,$data['reversal_date'],$data['reason']);
        return redirect()->route('accounting.journals.show',$reversal)->with('status',"Reversal {$reversal->journal_no} posted successfully.");
    }

    private function formView(Request $request, ?JournalEntry $journal): View
    {
        $company=Company::query()->firstOrFail();
        $branches=$this->allowedBranches($request,$company->id);
        $accounts=Account::query()->where('company_id',$company->id)->where('is_active',true)->where('allow_posting',true)->where('is_control',false)->orderBy('code')->get();
        $currencies=Currency::query()->where('is_active',true)->orderBy('code')->get();
        return view('accounting.journals.form',compact('company','branches','accounts','currencies','journal'));
    }

    /** @return array{0:Company,1:array,2:array} */
    private function validatedPayload(Request $request, ?JournalEntry $journal=null): array
    {
        $company=Company::query()->firstOrFail();
        $allowed=$this->allowedBranches($request,$company->id)->pluck('id')->all();
        $data=$request->validate([
            'journal_date'=>['required','date'],'branch_id'=>['required','integer',Rule::in($allowed)],
            'journal_type'=>['required','in:GENERAL,ADJUSTMENT,OPENING'],'currency_code'=>['required','string','size:3','exists:currencies,code'],
            'exchange_rate'=>['required','numeric','gt:0','max:999999999'],'reference'=>['nullable','string','max:120'],'description'=>['required','string','max:2000'],
            'lines'=>['required','array','min:2','max:100'],'lines.*.account_id'=>['required','integer','exists:accounts,id'],
            'lines.*.description'=>['nullable','string','max:500'],'lines.*.debit'=>['nullable','numeric','min:0','max:9999999999999999.99'],
            'lines.*.credit'=>['nullable','numeric','min:0','max:9999999999999999.99'],
        ]);
        $rate=strtoupper($data['currency_code'])===strtoupper($company->base_currency)?1:(float)$data['exchange_rate'];
        $header=[
            'company_id'=>$company->id,'branch_id'=>(int)$data['branch_id'],'journal_date'=>$data['journal_date'],'journal_type'=>$data['journal_type'],
            'reference'=>trim((string)($data['reference']??''))?:null,'description'=>trim($data['description']),'currency_code'=>strtoupper($data['currency_code']),
            'exchange_rate'=>$rate,
        ];
        return [$company,$header,$data['lines']];
    }

    private function allowedBranches(Request $request, int $companyId)
    {
        $user=$request->user();
        if ($user->is_super_admin || $user->access_scope==='ALL_BRANCHES') return Branch::query()->where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        $query=$user->branches()->where('branches.company_id',$companyId)->where('branches.is_active',true);
        if ($user->access_scope==='PRIMARY_BRANCH') $query->wherePivot('is_primary',true);
        return $query->orderBy('branches.name')->get();
    }

    private function authorizeScope(Request $request, JournalEntry $journal): void
    {
        $allowed=$this->allowedBranches($request,$journal->company_id)->pluck('id')->contains($journal->branch_id);
        abort_unless($allowed,403);
    }
}
