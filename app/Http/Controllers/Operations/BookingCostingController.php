<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Models\SalesInvoiceLine;
use App\Models\ServiceCostAllocation;
use App\Services\AuditService;
use App\Services\Costing\BookingCostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingCostingController extends Controller
{
    public function index(Request $request, BookingCostingService $costing): View
    {
        $company = Company::query()->firstOrFail();
        $allowed = $this->allowedBranches($request,$company->id)->pluck('id');
        $query = Booking::query()->where('company_id',$company->id)->whereIn('branch_id',$allowed)
            ->whereIn('status',['CONFIRMED','COMPLETED'])
            ->with(['customer','branch','salesInvoices'])
            ->orderByDesc('booking_date')->orderByDesc('id');
        $q = trim((string)$request->query('q'));
        if ($q !== '') $query->where(function($x) use ($q) { $x->where('booking_no','like',"%{$q}%")->orWhereHas('customer',fn($p)=>$p->where('display_name','like',"%{$q}%")); });
        $bookings = $query->get();
        $summaries = $bookings->mapWithKeys(fn($b)=>[$b->id=>$costing->summary($b)]);
        return view('operations.costing.index',compact('bookings','summaries','q','company'));
    }

    public function show(Request $request, Booking $booking, BookingCostingService $costing): View
    {
        $this->authorizeScope($request,$booking);
        $booking->load(['branch','customer','agent','services.product','salesInvoices.lines']);
        $company = Company::query()->findOrFail($booking->company_id);
        $vendors = Party::query()->where('company_id',$company->id)->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->orderBy('display_name')->get();
        $currencies = DB::table('currencies')->where('is_active',true)->orderBy('code')->get();
        $allocations = ServiceCostAllocation::query()
            ->where('booking_id',$booking->id)
            ->with(['vendor','bookingService.product','salesInvoice'])
            ->orderByDesc('id')
            ->get();
        $summary = $costing->summary($booking);
        return view('operations.costing.show',compact('booking','company','vendors','currencies','allocations','summary'));
    }

    public function store(Request $request, Booking $booking, BookingCostingService $costing): RedirectResponse
    {
        $this->authorizeScope($request,$booking);
        abort_unless(in_array($booking->status,['CONFIRMED','COMPLETED'],true),422);
        $data = $this->validated($request,$booking,$costing);
        $invoiceLine = null;
        foreach (['POSTED','APPROVED','PENDING_APPROVAL','DRAFT'] as $invoiceStatus) {
            $invoiceLine = SalesInvoiceLine::query()->where('source_booking_service_id',$data['booking_service_id'])
                ->whereHas('invoice',fn($q)=>$q->where('status',$invoiceStatus))->orderByDesc('id')->first();
            if ($invoiceLine) break;
        }
        $total = round(((float)$data['quantity']*(float)$data['unit_cost'])+(float)$data['tax_amount'],2);
        $rate = $this->rate($data['currency_code'],$booking->company_id,(float)$data['exchange_rate']);
        $allocation = ServiceCostAllocation::query()->create(array_merge($data,[
            'company_id'=>$booking->company_id,'branch_id'=>$booking->branch_id,'booking_id'=>$booking->id,
            'sales_invoice_id'=>$invoiceLine?->sales_invoice_id,'sales_invoice_line_id'=>$invoiceLine?->id,
            'total_cost'=>$total,'exchange_rate'=>$rate,'base_total_cost'=>round($total*$rate,2),'status'=>'ESTIMATED',
            'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
        ]));
        AuditService::log($request,'supplier_cost.created',$allocation,[],$allocation->toArray());
        return back()->with('status','Estimated supplier cost added. No AP/GL entry has been created.');
    }

    public function update(Request $request, Booking $booking, ServiceCostAllocation $allocation, BookingCostingService $costing): RedirectResponse
    {
        $this->authorizeAllocation($request,$booking,$allocation);
        if (! $allocation->isEditable()) throw ValidationException::withMessages(['costing'=>'Only estimated cost allocations may be edited.']);
        $data = $this->validated($request,$booking,$costing,$allocation->id);
        $total = round(((float)$data['quantity']*(float)$data['unit_cost'])+(float)$data['tax_amount'],2);
        $rate = $this->rate($data['currency_code'],$booking->company_id,(float)$data['exchange_rate']);
        $old=$allocation->toArray();
        $allocation->update(array_merge($data,['total_cost'=>$total,'exchange_rate'=>$rate,'base_total_cost'=>round($total*$rate,2),'updated_by'=>$request->user()->id]));
        AuditService::log($request,'supplier_cost.updated',$allocation,$old,$allocation->fresh()->toArray());
        return back()->with('status','Estimated supplier cost updated.');
    }

    public function confirm(Request $request, Booking $booking, ServiceCostAllocation $allocation): RedirectResponse
    {
        $this->authorizeAllocation($request,$booking,$allocation);
        if ($allocation->status !== 'ESTIMATED') throw ValidationException::withMessages(['costing'=>'Only an estimated supplier cost may be confirmed.']);
        $mapped = DB::table('account_mappings')->where('company_id',$booking->company_id)->where('mapping_key',$allocation->cost_mapping_key)->whereNotNull('account_id')->exists();
        if (! $mapped) throw ValidationException::withMessages(['costing'=>'The cost mapping '.$allocation->cost_mapping_key.' is not resolved in Account Mappings.']);
        $old=$allocation->toArray();
        $allocation->update(['status'=>'CONFIRMED','confirmed_by'=>$request->user()->id,'confirmed_at'=>now(),'updated_by'=>$request->user()->id]);
        AuditService::log($request,'supplier_cost.confirmed',$allocation,$old,$allocation->fresh()->toArray());
        return back()->with('status','Supplier cost confirmed. It is ready for a future Vendor Bill, but no AP/GL posting exists yet.');
    }

    public function cancel(Request $request, Booking $booking, ServiceCostAllocation $allocation): RedirectResponse
    {
        $this->authorizeAllocation($request,$booking,$allocation);
        $data=$request->validate(['cancellation_reason'=>['required','string','max:1000']]);
        if ($allocation->status === 'CANCELLED') return back()->with('status','Cost allocation is already cancelled.');
        $old=$allocation->toArray();
        $allocation->update(['status'=>'CANCELLED','cancelled_by'=>$request->user()->id,'cancelled_at'=>now(),'cancellation_reason'=>$data['cancellation_reason'],'updated_by'=>$request->user()->id]);
        AuditService::log($request,'supplier_cost.cancelled',$allocation,$old,$allocation->fresh()->toArray());
        return back()->with('status','Supplier cost allocation cancelled. Historical record preserved.');
    }

    private function validated(Request $request, Booking $booking, BookingCostingService $costing, ?int $ignore=null): array
    {
        $data = $request->validate([
            'booking_service_id'=>['required','integer',Rule::exists('booking_services','id')->where('booking_id',$booking->id)],
            'vendor_party_id'=>['required','integer','exists:parties,id'],
            'cost_type'=>['required','in:AIR_TICKET,HOTEL,VISA,TRANSPORT,PACKAGE,OTHER'],
            'description'=>['required','string','max:500'],'quantity'=>['required','numeric','gt:0','max:999999'],
            'unit_cost'=>['required','numeric','min:0','max:999999999999'],'tax_amount'=>['nullable','numeric','min:0','max:999999999999'],
            'currency_code'=>['required','string','size:3','exists:currencies,code'],'exchange_rate'=>['nullable','numeric','gt:0','max:999999999'],
            'supplier_reference'=>['nullable','string','max:120'],'notes'=>['nullable','string','max:2000'],
        ]);
        $vendorOk=Party::query()->whereKey($data['vendor_party_id'])->where('company_id',$booking->company_id)->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->exists();
        if(!$vendorOk)throw ValidationException::withMessages(['vendor_party_id'=>'Supplier must be an active Vendor party in the current company.']);
        $data['tax_amount']=$data['tax_amount']??0;
        $data['exchange_rate']=$data['exchange_rate']??1;
        $data['currency_code']=strtoupper($data['currency_code']);
        $data['cost_mapping_key']=$costing->costTypeMapping($data['cost_type']);
        return $data;
    }

    private function rate(string $currency,int $companyId,float $rate): float
    {
        $company=Company::query()->findOrFail($companyId);
        if(strtoupper($currency)===strtoupper($company->base_currency))return 1.0;
        if($rate<=0)throw ValidationException::withMessages(['exchange_rate'=>'Exchange rate is required for non-base-currency supplier costs.']);
        return $rate;
    }

    private function allowedBranches(Request $request,int $companyId)
    {
        $user=$request->user();
        if($user->is_super_admin||$user->access_scope==='ALL_BRANCHES')return Branch::query()->where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();
        $query=$user->branches()->where('branches.company_id',$companyId)->where('branches.is_active',true);
        if($user->access_scope==='PRIMARY_BRANCH')$query->wherePivot('is_primary',true);
        return $query->orderBy('branches.name')->get();
    }

    private function authorizeScope(Request $request,Booking $booking): void
    {
        abort_unless($this->allowedBranches($request,$booking->company_id)->pluck('id')->contains($booking->branch_id),403);
    }

    private function authorizeAllocation(Request $request,Booking $booking,ServiceCostAllocation $allocation): void
    {
        $this->authorizeScope($request,$booking);
        abort_unless((int)$allocation->booking_id===(int)$booking->id,404);
    }
}
