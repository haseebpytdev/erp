<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\AccountMapping;
use App\Models\ApprovalAction;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesInvoiceController extends Controller
{
    public function __construct(private readonly SalesInvoiceService $invoices) {}

    public function index(Request $request): View
    {
        $company=Company::query()->firstOrFail();
        $branchIds=$this->allowedBranches($request,$company->id)->pluck('id');
        $status=strtoupper((string)$request->query('status'));$search=trim((string)$request->query('q'));
        $query=SalesInvoice::query()->where('company_id',$company->id)->whereIn('branch_id',$branchIds)
            ->with(['booking','customer','branch'])->latest('invoice_date')->latest('id');
        if(in_array($status,['DRAFT','PENDING_APPROVAL','APPROVED','POSTED','CANCELLED'],true))$query->where('status',$status);
        if($search!=='')$query->where(function($q)use($search){$q->where('invoice_no','like',"%{$search}%")->orWhere('customer_reference','like',"%{$search}%")->orWhereHas('booking',fn($b)=>$b->where('booking_no','like',"%{$search}%"))->orWhereHas('customer',fn($p)=>$p->where('display_name','like',"%{$search}%"));});
        $base=SalesInvoice::query()->where('company_id',$company->id)->whereIn('branch_id',$branchIds);
        return view('sales.invoices.index',['invoices'=>$query->limit(200)->get(),'statusFilter'=>$status,'search'=>$search,'counts'=>[
            'draft'=>(clone $base)->where('status','DRAFT')->count(),'pending'=>(clone $base)->where('status','PENDING_APPROVAL')->count(),'approved'=>(clone $base)->where('status','APPROVED')->count(),'posted'=>(clone $base)->where('status','POSTED')->count(),
        ]]);
    }

    public function fromBooking(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeBooking($request,$booking);
        $invoice=$this->invoices->createFromBooking($request,$booking);
        return redirect()->route('sales.invoices.show',$invoice)->with('status','Draft Sales Invoice created from confirmed booking. Review it before submission.');
    }

    public function show(Request $request, SalesInvoice $invoice): View
    {
        $this->authorizeInvoice($request,$invoice);
        $invoice->load(['booking','branch','customer','agent','salesperson','passengers','lines.passengers','lines.product','journal','creator','approver','poster']);
        $actions=ApprovalAction::query()->where('document_type','SALES_INVOICE')->where('document_id',$invoice->id)->with('user')->orderBy('id')->get();
        $mappingKeys=$invoice->lines->pluck('revenue_mapping_key')->unique()->filter()->values();
        $revenueMappings=AccountMapping::query()->where('company_id',$invoice->company_id)->whereIn('mapping_key',$mappingKeys)->with('account')->get()->keyBy('mapping_key');
        $arMapping=AccountMapping::query()->where('company_id',$invoice->company_id)->where('mapping_key','CUSTOMER_RECEIVABLE')->with('account')->first();
        return view('sales.invoices.show',compact('invoice','actions','revenueMappings','arMapping'));
    }

    public function update(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($request,$invoice);
        $data=$request->validate([
            'invoice_date'=>['required','date'],'due_date'=>['nullable','date','after_or_equal:invoice_date'],'exchange_rate'=>['required','numeric','gt:0'],
            'customer_reference'=>['nullable','string','max:120'],'notes'=>['nullable','string','max:4000'],
            'lines'=>['required','array','min:1'],'lines.*.id'=>['required','integer','exists:sales_invoice_lines,id'],'lines.*.description'=>['required','string','max:500'],
            'lines.*.quantity'=>['required','numeric','gt:0'],'lines.*.unit_price'=>['required','numeric','min:0'],'lines.*.discount_amount'=>['nullable','numeric','min:0'],
        ]);
        $this->invoices->updateDraft($request,$invoice,$data);
        return back()->with('status','Sales Invoice draft updated. Booking values were not rewritten.');
    }

    public function submit(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($request,$invoice);$data=$request->validate(['remarks'=>['nullable','string','max:1000']]);
        $this->invoices->submit($request,$invoice,$data['remarks']??null);return back()->with('status','Sales Invoice submitted for approval.');
    }

    public function approve(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($request,$invoice);$data=$request->validate(['remarks'=>['nullable','string','max:1000']]);
        $this->invoices->approve($request,$invoice,$data['remarks']??null);return back()->with('status','Sales Invoice approved.');
    }

    public function post(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($request,$invoice);$data=$request->validate(['remarks'=>['nullable','string','max:1000']]);
        $this->invoices->post($request,$invoice,$data['remarks']??null);return back()->with('status','Sales Invoice posted to Customer Receivables and mapped revenue accounts.');
    }

    public function cancel(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->authorizeInvoice($request,$invoice);$data=$request->validate(['cancellation_reason'=>['required','string','min:5','max:1500']]);
        $this->invoices->cancelDraft($request,$invoice,$data['cancellation_reason']);return back()->with('status','Draft Sales Invoice cancelled. No accounting entry was created.');
    }

    public function print(Request $request, SalesInvoice $invoice): View
    {
        $this->authorizeInvoice($request,$invoice);
        $invoice->load(['company','branch','booking','customer','passengers','lines.passengers']);
        return view('sales.invoices.print',compact('invoice'));
    }

    private function allowedBranches(Request $request,int $companyId)
    {
        $user=$request->user();
        if($user->is_super_admin||$user->access_scope==='ALL_BRANCHES')return Branch::query()->where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        $query=$user->branches()->where('branches.company_id',$companyId)->where('branches.is_active',true);if($user->access_scope==='PRIMARY_BRANCH')$query->wherePivot('is_primary',true);return $query->orderBy('branches.name')->get();
    }

    private function authorizeBooking(Request $request,Booking $booking):void { abort_unless($this->allowedBranches($request,$booking->company_id)->pluck('id')->contains($booking->branch_id),403); }
    private function authorizeInvoice(Request $request,SalesInvoice $invoice):void { abort_unless($this->allowedBranches($request,$invoice->company_id)->pluck('id')->contains($invoice->branch_id),403); }
}
