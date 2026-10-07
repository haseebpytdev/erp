<?php

namespace App\Services\Sales;

use App\Models\ApprovalAction;
use App\Models\ApprovalPolicy;
use App\Models\AirTicketDetail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\BookingSource;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\HotelBookingDetail;
use App\Models\SalesInvoice;
use App\Models\TransportBookingDetail;
use App\Models\VisaBookingDetail;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PeriodGuard;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesInvoiceService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly PeriodGuard $periodGuard,
        private readonly InvoiceAccountingService $accounting,
        private readonly BaseBookingInvoiceScopeResolver $baseScope,
        private readonly BaseSalesInvoiceConsistencyResolver $baseConsistency,
    ) {}

    public function createFromBooking(Request $request, Booking $booking): SalesInvoice
    {
        if ($booking->status !== 'CONFIRMED') throw ValidationException::withMessages(['booking'=>'Only a confirmed booking can create a Sales Invoice.']);
        $booking->loadMissing(['company','customer.customerProfile','passengers','services.product','services.passengers']);
        $activeServices = collect($this->baseScope->resolve($booking)['expected_services'] ?? []);
        if ($activeServices->isEmpty()) throw ValidationException::withMessages(['booking'=>'The booking has no active services to invoice.']);
        if ($booking->salesInvoices()->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->exists()) {
            throw ValidationException::withMessages(['booking'=>'This booking already has an active Sales Invoice. Open that invoice instead of creating a duplicate.']);
        }
        foreach ($activeServices as $service) {
            if (! $service->product?->revenue_mapping_key) throw ValidationException::withMessages(['booking'=>'Service '.$service->description.' has no revenue account mapping key. Configure the Product/Service first.']);
            $linked=$service->passengers->where('is_active',true);
            if(in_array($service->passenger_link_mode_snapshot,['REQUIRED','MULTIPLE'],true) && $linked->isEmpty()) {
                throw ValidationException::withMessages(['booking'=>'Service '.$service->description.' requires passenger links before invoicing. Reopen/correct the booking first.']);
            }
            if($service->pricing_basis_snapshot==='PER_PERSON' && ($linked->isEmpty() || abs((float)$service->quantity-(float)$linked->count())>0.0001)) {
                throw ValidationException::withMessages(['booking'=>'Service '.$service->description.' has an invalid PER_PERSON quantity. Correct the booking before invoicing.']);
            }
        }

        $company = $booking->company;
        $invoiceDate = now()->toDateString();
        [$fy,$period] = $this->periodGuard->resolveOpen($company->id,$invoiceDate);
        $creditDays = (int)($booking->customer?->customerProfile?->credit_days ?? 0);
        $dueDate = Carbon::parse($invoiceDate)->addDays(max(0,$creditDays))->toDateString();

        return DB::transaction(function () use ($request,$booking,$activeServices,$company,$invoiceDate,$dueDate,$creditDays,$fy,$period): SalesInvoice {
            $lockedBooking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($lockedBooking->status !== 'CONFIRMED') throw ValidationException::withMessages(['booking'=>'Booking status changed before invoicing. Refresh and try again.']);
            if (SalesInvoice::query()->where('booking_id',$booking->id)->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->exists()) {
                throw ValidationException::withMessages(['booking'=>'An active Sales Invoice was already created for this booking.']);
            }

            $invoice = SalesInvoice::query()->create([
                'company_id'=>$booking->company_id,'branch_id'=>$booking->branch_id,'booking_id'=>$booking->id,
                'invoice_no'=>$this->numbers->next('SALES_INVOICE',$booking->company_id,$booking->branch_id,$fy),
                'invoice_date'=>$invoiceDate,'due_date'=>$dueDate,
                'customer_party_id'=>$booking->customer_party_id,'agent_party_id'=>$booking->agent_party_id,'salesperson_staff_id'=>$booking->salesperson_staff_id,
                'customer_reference'=>$booking->customer_reference,'currency_code'=>$booking->currency_code,'exchange_rate'=>1,
                'subtotal'=>0,'discount_total'=>0,'grand_total'=>0,'status'=>'DRAFT',
                'payment_terms_snapshot'=>$creditDays>0 ? $creditDays.' day credit' : 'Due on receipt',
                'notes'=>'Created from confirmed booking '.$booking->booking_no.'. Booking confirmation and invoice posting remain separate events.',
                'fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,
                'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
            ]);

            $passengerMap=[];
            foreach ($booking->passengers->where('is_active',true) as $p) {
                $snap=$invoice->passengers()->create([
                    'source_booking_passenger_id'=>$p->id,'passenger_no'=>$p->passenger_no,'pax_type'=>$p->pax_type,'title'=>$p->title,
                    'first_name'=>$p->first_name,'middle_name'=>$p->middle_name,'last_name'=>$p->last_name,'date_of_birth'=>$p->date_of_birth,
                    'nationality_country_code'=>$p->nationality_country_code,'passport_no'=>$p->passport_no,'passport_expiry'=>$p->passport_expiry,'is_lead'=>$p->is_lead,
                ]);
                $passengerMap[$p->id]=$snap->id;
            }

            $lineNo=1;$subtotal=0.0;
            foreach ($activeServices as $s) {
                $line=$invoice->lines()->create([
                    'line_no'=>$lineNo++,'source_booking_service_id'=>$s->id,'product_service_id'=>$s->product_service_id,
                    'category_snapshot'=>$s->product?->category,'pricing_basis_snapshot'=>$s->pricing_basis_snapshot,'description'=>$s->description,
                    'service_from'=>$s->service_from,'service_to'=>$s->service_to,'quantity'=>$s->quantity,'unit_price'=>$s->unit_price,
                    'line_subtotal'=>$s->line_total,'discount_amount'=>0,'line_total'=>$s->line_total,'currency_code'=>$s->currency_code,
                    'revenue_mapping_key'=>$s->product->revenue_mapping_key,'detail_snapshot'=>$this->serviceDetailSnapshot($s),'notes'=>$s->notes,
                ]);
                $ids=[];foreach($s->passengers->where('is_active',true) as $p){if(isset($passengerMap[$p->id]))$ids[]=$passengerMap[$p->id];}
                if($ids)$line->passengers()->sync($ids);
                $subtotal+=(float)$s->line_total;
            }
            $invoice->update(['subtotal'=>round($subtotal,2),'grand_total'=>round($subtotal,2)]);
            $this->action($invoice,$request,'CREATE',null,'DRAFT','Created from booking '.$booking->booking_no);
            AuditService::log($request,'sales_invoice.created',$invoice,[],$this->snapshot($invoice));
            return $invoice->fresh(['booking','customer','passengers','lines.passengers']);
        },3);
    }

    public function createFromBookingServices(Request $request, Booking $booking, array $bookingServiceIds): SalesInvoice
    {
        if ($bookingServiceIds === []) throw ValidationException::withMessages(['booking_services'=>'At least one booking service is required.']);
        $normalized=[];
        foreach ($bookingServiceIds as $raw) {
            if (is_int($raw)) $id=$raw;
            elseif (is_string($raw) && preg_match('/^[0-9]+$/D',trim($raw))) $id=(int)trim($raw);
            else throw ValidationException::withMessages(['booking_services'=>'Booking service IDs must be positive integers.']);
            if ($id<=0) throw ValidationException::withMessages(['booking_services'=>'Booking service IDs must be positive integers.']);
            $normalized[]=$id;
        }
        if (count($normalized)!==count(array_unique($normalized))) throw ValidationException::withMessages(['booking_services'=>'Duplicate booking service IDs are not allowed.']);
        if ($booking->status !== 'CONFIRMED') throw ValidationException::withMessages(['booking'=>'Only a confirmed booking can create a Sales Invoice.']);

        return DB::transaction(function () use ($request,$booking,$normalized): SalesInvoice {
            $lockedBooking=Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($lockedBooking->status !== 'CONFIRMED') throw ValidationException::withMessages(['booking'=>'Booking status changed before invoicing. Refresh and try again.']);
            $lockedBooking->loadMissing(['company','customer.customerProfile','passengers','services.product','services.passengers']);
            $activeServices = collect($this->baseScope->resolve($lockedBooking)['expected_services'] ?? []);
            if ($activeServices->isEmpty()) throw ValidationException::withMessages(['booking'=>'The booking has no active base services to invoice.']);
            $selectedServices=$lockedBooking->services->whereIn('id',$normalized)->values();
            if ($selectedServices->count()!==count($normalized)) throw ValidationException::withMessages(['booking_services'=>'Every selected booking service must belong to this booking.']);
            if ($selectedServices->contains(fn($service): bool=>strtoupper((string)$service->status)==='CANCELLED')) throw ValidationException::withMessages(['booking_services'=>'Cancelled booking services cannot be invoiced.']);
            $alreadyInvoiced=SalesInvoice::query()->where('booking_id',$lockedBooking->id)->with('lines')->get()->flatMap(fn($invoice)=>$invoice->lines)->pluck('source_booking_service_id')->filter()->map(fn($id)=>(int)$id)->intersect($normalized);
            if ($alreadyInvoiced->isNotEmpty()) throw ValidationException::withMessages(['booking_services'=>'One or more selected booking services are already represented on a Sales Invoice.']);
            foreach ($selectedServices as $service) {
                if (! $service->product?->revenue_mapping_key) throw ValidationException::withMessages(['booking'=>'Service '.$service->description.' has no revenue account mapping key. Configure the Product/Service first.']);
                $linked=$service->passengers->where('is_active',true);
                if(in_array($service->passenger_link_mode_snapshot,['REQUIRED','MULTIPLE'],true) && $linked->isEmpty()) throw ValidationException::withMessages(['booking'=>'Service '.$service->description.' requires passenger links before invoicing. Reopen/correct the booking first.']);
                if($service->pricing_basis_snapshot==='PER_PERSON' && ($linked->isEmpty() || abs((float)$service->quantity-(float)$linked->count())>0.0001)) throw ValidationException::withMessages(['booking'=>'Service '.$service->description.' has an invalid PER_PERSON quantity. Correct the booking before invoicing.']);
            }
            $company=$lockedBooking->company;$invoiceDate=now()->toDateString();[$fy,$period]=$this->periodGuard->resolveOpen($company->id,$invoiceDate);$creditDays=(int)($lockedBooking->customer?->customerProfile?->credit_days??0);$dueDate=Carbon::parse($invoiceDate)->addDays(max(0,$creditDays))->toDateString();
            $invoice=SalesInvoice::query()->create(['company_id'=>$lockedBooking->company_id,'branch_id'=>$lockedBooking->branch_id,'booking_id'=>$lockedBooking->id,'invoice_no'=>$this->numbers->next('SALES_INVOICE',$lockedBooking->company_id,$lockedBooking->branch_id,$fy),'invoice_date'=>$invoiceDate,'due_date'=>$dueDate,'customer_party_id'=>$lockedBooking->customer_party_id,'agent_party_id'=>$lockedBooking->agent_party_id,'salesperson_staff_id'=>$lockedBooking->salesperson_staff_id,'customer_reference'=>$lockedBooking->customer_reference,'currency_code'=>$lockedBooking->currency_code,'exchange_rate'=>1,'subtotal'=>0,'discount_total'=>0,'grand_total'=>0,'status'=>'DRAFT','payment_terms_snapshot'=>$creditDays>0?$creditDays.' day credit':'Due on receipt','notes'=>'Created from selected confirmed booking services '.$lockedBooking->booking_no.'. Booking confirmation and invoice posting remain separate events.','fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]);
            $passengerMap=[];foreach($lockedBooking->passengers->where('is_active',true) as $p){$snap=$invoice->passengers()->create(['source_booking_passenger_id'=>$p->id,'passenger_no'=>$p->passenger_no,'pax_type'=>$p->pax_type,'title'=>$p->title,'first_name'=>$p->first_name,'middle_name'=>$p->middle_name,'last_name'=>$p->last_name,'date_of_birth'=>$p->date_of_birth,'nationality_country_code'=>$p->nationality_country_code,'passport_no'=>$p->passport_no,'passport_expiry'=>$p->passport_expiry,'is_lead'=>$p->is_lead]);$passengerMap[$p->id]=$snap->id;}
            $lineNo=1;$subtotal=0.0;foreach($selectedServices as $s){$line=$invoice->lines()->create(['line_no'=>$lineNo++,'source_booking_service_id'=>$s->id,'product_service_id'=>$s->product_service_id,'category_snapshot'=>$s->product?->category,'pricing_basis_snapshot'=>$s->pricing_basis_snapshot,'description'=>$s->description,'service_from'=>$s->service_from,'service_to'=>$s->service_to,'quantity'=>$s->quantity,'unit_price'=>$s->unit_price,'line_subtotal'=>$s->line_total,'discount_amount'=>0,'line_total'=>$s->line_total,'currency_code'=>$s->currency_code,'revenue_mapping_key'=>$s->product->revenue_mapping_key,'detail_snapshot'=>$this->serviceDetailSnapshot($s),'notes'=>$s->notes]);$ids=[];foreach($s->passengers->where('is_active',true) as $p){if(isset($passengerMap[$p->id]))$ids[]=$passengerMap[$p->id];}if($ids)$line->passengers()->sync($ids);$subtotal+=(float)$s->line_total;}
            if($subtotal<=0) throw ValidationException::withMessages(['booking_services'=>'The selected services must produce a positive invoice total.']);
            $invoice->update(['subtotal'=>round($subtotal,2),'grand_total'=>round($subtotal,2)]);$this->action($invoice,$request,'CREATE',null,'DRAFT','Created from selected booking services '.$lockedBooking->booking_no);AuditService::log($request,'sales_invoice.created',$invoice,[],$this->snapshot($invoice));return $invoice->fresh(['booking','customer','passengers','lines.passengers']);
        },3);
    }

    public function updateDraft(Request $request, SalesInvoice $invoice, array $data): SalesInvoice
    {
        if (! $invoice->isEditable()) throw ValidationException::withMessages(['invoice'=>'Only a draft Sales Invoice can be edited.']);
        $invoice->loadMissing(['company','lines']);
        [$fy,$period] = $this->periodGuard->resolveOpen($invoice->company_id,$data['invoice_date']);
        if ($invoice->fiscal_year_id && (int)$invoice->fiscal_year_id !== (int)$fy->id) throw ValidationException::withMessages(['invoice_date'=>'Invoice date cannot be moved to a different fiscal year after numbering. Create a new invoice in that fiscal year instead.']);
        $rate = strtoupper($invoice->currency_code)===strtoupper($invoice->company->base_currency) ? 1.0 : (float)$data['exchange_rate'];
        if ($rate<=0) throw ValidationException::withMessages(['exchange_rate'=>'A positive exchange rate is required.']);

        $linePayload=collect($data['lines'])->keyBy(fn($x)=>(int)$x['id']);
        $subtotal=0.0;$discountTotal=0.0;
        foreach($invoice->lines as $line){
            $row=$linePayload->get($line->id); if(!$row) throw ValidationException::withMessages(['lines'=>'Every invoice line must remain present.']);
            $qty=(float)$row['quantity'];$unit=(float)$row['unit_price'];$discount=(float)($row['discount_amount']??0);
            $gross=round($qty*$unit,2);if($discount>$gross)throw ValidationException::withMessages(['lines'=>'A line discount cannot exceed the line subtotal.']);
            $subtotal+=$gross;$discountTotal+=$discount;
        }
        $grand=round($subtotal-$discountTotal,2);if($grand<=0)throw ValidationException::withMessages(['invoice'=>'Invoice grand total must be greater than zero.']);
        $old=$this->snapshot($invoice);

        DB::transaction(function()use($request,$invoice,$data,$rate,$linePayload,$subtotal,$discountTotal,$grand):void{
            foreach($invoice->lines as $line){
                $row=$linePayload->get($line->id);$qty=(float)$row['quantity'];$unit=(float)$row['unit_price'];$discount=(float)($row['discount_amount']??0);$gross=round($qty*$unit,2);
                $line->update(['description'=>trim($row['description']),'quantity'=>$qty,'unit_price'=>$unit,'line_subtotal'=>$gross,'discount_amount'=>$discount,'line_total'=>round($gross-$discount,2)]);
            }
            $invoice->update([
                'invoice_date'=>$data['invoice_date'],'due_date'=>$data['due_date']??null,'exchange_rate'=>$rate,'customer_reference'=>trim((string)($data['customer_reference']??''))?:null,
                'notes'=>trim((string)($data['notes']??''))?:null,'subtotal'=>round($subtotal,2),'discount_total'=>round($discountTotal,2),'grand_total'=>$grand,
                'fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,'updated_by'=>$request->user()->id,
            ]);
        });
        $this->action($invoice->fresh(),$request,'EDIT','DRAFT','DRAFT','Draft commercial values updated.');
        AuditService::log($request,'sales_invoice.updated',$invoice,$old,$this->snapshot($invoice->fresh()));
        return $invoice->fresh(['lines.passengers']);
    }

    public function submit(Request $request, SalesInvoice $invoice, ?string $remarks=null): void
    {
        if($invoice->status!=='DRAFT')throw ValidationException::withMessages(['invoice'=>'Only a draft Sales Invoice can be submitted.']);
        $this->guardBaseConsistency($invoice);
        $this->validateInvoice($invoice);
        $invoice->update(['status'=>'PENDING_APPROVAL','submitted_by'=>$request->user()->id,'submitted_at'=>now(),'updated_by'=>$request->user()->id]);
        $this->action($invoice,$request,'SUBMIT','DRAFT','PENDING_APPROVAL',$remarks);
        AuditService::log($request,'sales_invoice.submitted',$invoice,['status'=>'DRAFT'],['status'=>'PENDING_APPROVAL']);
    }

    public function approve(Request $request, SalesInvoice $invoice, ?string $remarks=null): void
    {
        if($invoice->status!=='PENDING_APPROVAL')throw ValidationException::withMessages(['invoice'=>'Sales Invoice is not pending approval.']);
        $policy=ApprovalPolicy::query()->where('company_id',$invoice->company_id)->where('key','SALES_INVOICE_APPROVAL')->where('is_active',true)->first();
        if($policy?->prevent_self_approval && $invoice->created_by===$request->user()->id && !$request->user()->is_super_admin)throw ValidationException::withMessages(['approval'=>'Maker/checker policy prevents approval of your own Sales Invoice.']);
        if(!$request->user()->canApprove('SALES_INVOICE_APPROVAL',(float)$invoice->grand_total))throw ValidationException::withMessages(['approval'=>'Your Sales Invoice approval authority is insufficient for this amount.']);
        $this->guardBaseConsistency($invoice);
        $this->validateInvoice($invoice);
        $invoice->update(['status'=>'APPROVED','approved_by'=>$request->user()->id,'approved_at'=>now(),'updated_by'=>$request->user()->id]);
        $this->action($invoice,$request,'APPROVE','PENDING_APPROVAL','APPROVED',$remarks);
        AuditService::log($request,'sales_invoice.approved',$invoice,['status'=>'PENDING_APPROVAL'],['status'=>'APPROVED']);
    }

    public function post(Request $request, SalesInvoice $invoice, ?string $remarks=null): void
    {
        if($invoice->status!=='APPROVED')throw ValidationException::withMessages(['invoice'=>'Only an approved Sales Invoice can be posted.']);
        $this->guardBaseConsistency($invoice);
        $this->validateInvoice($invoice);
        $journal=$this->accounting->post($invoice,$request->user()->id);
        $invoice->refresh();
        $this->action($invoice,$request,'POST','APPROVED','POSTED',$remarks,['journal_no'=>$journal->journal_no,'journal_id'=>$journal->id]);
        AuditService::log($request,'sales_invoice.posted',$invoice,['status'=>'APPROVED'],['status'=>'POSTED','journal_entry_id'=>$journal->id]);
    }

    public function cancelDraft(Request $request, SalesInvoice $invoice, string $reason): void
    {
        if($invoice->status!=='DRAFT')throw ValidationException::withMessages(['invoice'=>'Only a draft Sales Invoice can be cancelled. Posted invoices will later be corrected by Credit Note, never deletion.']);
        $invoice->update(['status'=>'CANCELLED','cancelled_by'=>$request->user()->id,'cancelled_at'=>now(),'cancellation_reason'=>$reason,'updated_by'=>$request->user()->id]);
        $this->action($invoice,$request,'CANCEL','DRAFT','CANCELLED',$reason);
        AuditService::log($request,'sales_invoice.cancelled',$invoice,['status'=>'DRAFT'],['status'=>'CANCELLED','reason'=>$reason]);
    }

    private function guardBaseConsistency(SalesInvoice $invoice): void
    {
        if (! $invoice->booking_id || $this->baseConsistency->resolve($invoice)['scope'] !== 'base') return;
        $state = $this->baseConsistency->resolve($invoice);
        if (($state['status'] ?? 'MISMATCH') === 'IN_SYNC') return;
        $detail = [];
        if ($state['missing_service_ids'] ?? []) $detail[] = 'Missing '.count($state['missing_service_ids']).' booking service(s).';
        if ($state['stale_service_ids'] ?? []) $detail[] = 'Stale '.count($state['stale_service_ids']).' invoice service(s).';
        throw ValidationException::withMessages(['invoice' => 'This base Sales Invoice is out of sync with its booking. Cancel the Draft invoice and recreate it from the approved booking before continuing. '.implode(' ', $detail)]);
    }

    private function serviceDetailSnapshot($service): ?array
    {
        $category=strtoupper((string)$service->product?->category);
        if($category==='AIR_TICKET'){
            $rows=AirTicketDetail::query()->where('booking_service_id',$service->id)->orderBy('id')->get();
            if($rows->isEmpty())return null;
            return ['type'=>'AIR_TICKET','items'=>$rows->map(function($d){
                $airline=$d->airline_id?Airline::query()->find($d->airline_id):null;
                $source=$d->booking_source_id?BookingSource::query()->find($d->booking_source_id):null;
                return [
                    'booking_passenger_id'=>$d->booking_passenger_id,'pnr'=>$d->pnr,'ticket_number'=>$d->ticket_number,'ticket_status'=>$d->ticket_status,
                    'airline'=>$airline?->name,'airline_iata'=>$airline?->iata_code,'booking_source'=>$source?->name,
                    'origin'=>$d->origin,'destination'=>$d->destination,'sector'=>$d->sector,'flight_number'=>$d->flight_number,
                    'departure_at'=>$d->departure_at?->toIso8601String(),'arrival_at'=>$d->arrival_at?->toIso8601String(),'cabin_class'=>$d->cabin_class,'fare_basis'=>$d->fare_basis,'baggage'=>$d->baggage,
                    'base_fare'=>(float)$d->base_fare,'airline_taxes'=>(float)$d->airline_taxes,'customer_service_fee'=>(float)$d->customer_service_fee,
                    'markup'=>(float)$d->markup,'discount'=>(float)$d->discount,'selling_total'=>(float)$d->selling_total,
                ];
            })->values()->all()];
        }
        if($category==='HOTEL'){
            $d=HotelBookingDetail::query()->where('booking_service_id',$service->id)->first();
            if(!$d)return null;$hotel=$d->hotel_id?Hotel::query()->find($d->hotel_id):null;
            return ['type'=>'HOTEL','hotel'=>$hotel?->name,'city'=>$hotel?->city,'confirmation_number'=>$d->confirmation_number,'room_type'=>$d->room_type,'rooms'=>$d->rooms,'check_in'=>$d->check_in?->toDateString(),'check_out'=>$d->check_out?->toDateString(),'nights'=>$d->nights,'meal_plan'=>$d->meal_plan,'occupancy'=>$d->occupancy];
        }
        if($category==='VISA'){
            $rows=VisaBookingDetail::query()->where('booking_service_id',$service->id)->get();
            if($rows->isEmpty())return null;
            return ['type'=>'VISA','items'=>$rows->map(fn($d)=>['booking_passenger_id'=>$d->booking_passenger_id,'country'=>$d->destination_country_code,'visa_type'=>$d->visa_type,'application_number'=>$d->application_number,'visa_number'=>$d->visa_number,'status'=>$d->status,'issued_on'=>$d->issued_on?->toDateString(),'valid_until'=>$d->valid_until?->toDateString()])->values()->all()];
        }
        if($category==='TRANSPORT'){
            $d=TransportBookingDetail::query()->where('booking_service_id',$service->id)->first();
            if(!$d)return null;
            return ['type'=>'TRANSPORT','provider_reference'=>$d->provider_reference,'pickup_location'=>$d->pickup_location,'dropoff_location'=>$d->dropoff_location,'pickup_at'=>$d->pickup_at?->toIso8601String(),'vehicle_type'=>$d->vehicle_type,'vehicle_number'=>$d->vehicle_number,'driver_name'=>$d->driver_name,'driver_mobile'=>$d->driver_mobile];
        }
        return null;
    }

    private function validateInvoice(SalesInvoice $invoice): void
    {
        $invoice->loadMissing(['lines','company']);
        $this->periodGuard->resolveOpen($invoice->company_id,$invoice->invoice_date);
        if($invoice->lines->isEmpty())throw ValidationException::withMessages(['invoice'=>'Sales Invoice has no lines.']);
        if((float)$invoice->grand_total<=0)throw ValidationException::withMessages(['invoice'=>'Sales Invoice total must be greater than zero.']);
        $sum=round((float)$invoice->lines->sum('line_total'),2);if(abs($sum-(float)$invoice->grand_total)>0.01)throw ValidationException::withMessages(['invoice'=>'Sales Invoice line totals do not reconcile to the grand total.']);
        foreach($invoice->lines as $line){if(!$line->revenue_mapping_key)throw ValidationException::withMessages(['invoice'=>'A revenue mapping is missing for invoice line '.$line->line_no.'.']);}
        if(strtoupper($invoice->currency_code)!==strtoupper($invoice->company->base_currency)&&(float)$invoice->exchange_rate<=0)throw ValidationException::withMessages(['exchange_rate'=>'A valid exchange rate is required before approval/posting.']);
    }

    private function action(SalesInvoice $invoice,Request $request,string $action,?string $from,?string $to,?string $remarks=null,array $metadata=[]):void
    {
        ApprovalAction::query()->create(['company_id'=>$invoice->company_id,'document_type'=>'SALES_INVOICE','document_id'=>$invoice->id,'action'=>$action,'from_status'=>$from,'to_status'=>$to,'amount'=>$invoice->grand_total,'user_id'=>$request->user()->id,'remarks'=>$remarks,'metadata'=>$metadata?:null,'created_at'=>now()]);
    }

    private function snapshot(SalesInvoice $invoice): array
    {
        return ['invoice_no'=>$invoice->invoice_no,'booking_id'=>$invoice->booking_id,'invoice_date'=>(string)$invoice->invoice_date,'customer_party_id'=>$invoice->customer_party_id,'currency_code'=>$invoice->currency_code,'grand_total'=>(float)$invoice->grand_total,'status'=>$invoice->status];
    }
}
