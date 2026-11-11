<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AirTicketDetail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BookingSource;
use App\Models\CommissionAllocation;
use App\Models\Party;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\ServiceCostAllocation;
use App\Models\Staff;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AirTicketWorkspaceController extends Controller
{
    public function batchStore(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        $this->assertAirService($booking, $service);
        abort_unless($booking->isEditable(), 422, 'Reopen the booking before adding passenger tickets.');
        $service->load(['product','passengers']);

        if ($booking->salesInvoices()->where('status','POSTED')->exists()) {
            throw ValidationException::withMessages([
                'tickets'=>'A posted Sales Invoice already exists. New customer-sale tickets cannot be added silently. Use the future reissue/credit workflow.'
            ]);
        }

        $data = $request->validate([
            'passenger_ids' => ['required','array','min:1'],
            'passenger_ids.*' => ['integer'],
            'pnr' => ['required','string','max:20'],
            'ticket_status' => ['required','in:BOOKED,ISSUED'],
            'ticket_numbers_text' => ['nullable','string','max:5000'],
            'airline_id' => ['required','integer','exists:airlines,id'],
            'booking_source_id' => ['required','integer','exists:booking_sources,id'],
            'origin' => ['required','string','max:10'],
            'destination' => ['required','string','max:10'],
            'sector' => ['nullable','string','max:80'],
            'flight_number' => ['nullable','string','max:30'],
            'departure_at' => ['required','date'],
            'arrival_at' => ['nullable','date','after_or_equal:departure_at'],
            'cabin_class' => ['nullable','string','max:30'],
            'fare_basis' => ['nullable','string','max:60'],
            'baggage' => ['nullable','string','max:80'],

            'fare' => ['required','array'],
            'fare.*.base_fare' => ['required','numeric','min:0'],
            'fare.*.airline_taxes' => ['required','numeric','min:0'],
            'fare.*.service_markup' => ['required','numeric','min:0'],
            'fare.*.discount_type' => ['required','in:FIXED,PERCENT'],
            'fare.*.discount_value' => ['required','numeric','min:0'],

            'purchase' => ['required','array'],
            'purchase.*.supplier_charges' => ['required','numeric','min:0'],
            'purchase.*.discount_type' => ['required','in:FIXED,PERCENT'],
            'purchase.*.discount_value' => ['required','numeric','min:0'],
            'purchase.*.supplier_incentive' => ['required','numeric','min:0'],

            'supplier_vendor_party_id' => ['nullable','integer'],
            'supplier_cost_status' => ['required','in:ESTIMATED,CONFIRMED'],
            'supplier_reference' => ['nullable','string','max:120'],

            'agent_party_id' => ['nullable','integer'],
            'agent_commission_basis' => ['nullable','in:FIXED,PER_TICKET,PERCENT_OF_SALE,PERCENT_OF_BASE_FARE,PERCENT_OF_GROSS_MARGIN,MANUAL'],
            'agent_commission_rate' => ['nullable','numeric','min:0'],

            'salesperson_staff_id' => ['nullable','integer'],
            'salesperson_commission_basis' => ['nullable','in:FIXED,PER_TICKET,PERCENT_OF_SALE,PERCENT_OF_BASE_FARE,PERCENT_OF_GROSS_MARGIN,MANUAL'],
            'salesperson_commission_rate' => ['nullable','numeric','min:0'],

            'notes' => ['nullable','string','max:2000'],
        ]);

        $selected = array_values(array_unique(array_map('intval', $data['passenger_ids'])));
        $linkedPassengers = $service->passengers->where('is_active',true)->keyBy('id');

        foreach ($selected as $pid) {
            if (!$linkedPassengers->has($pid)) {
                throw ValidationException::withMessages([
                    'passenger_ids'=>'Every selected passenger must first be linked to this Air Ticket service.'
                ]);
            }
        }

        if (!Airline::query()->where('company_id',$booking->company_id)->whereKey($data['airline_id'])->where('is_active',true)->exists()) {
            throw ValidationException::withMessages(['airline_id'=>'Select an active airline belonging to this company.']);
        }
        if (!BookingSource::query()->where('company_id',$booking->company_id)->whereKey($data['booking_source_id'])->where('is_active',true)->exists()) {
            throw ValidationException::withMessages(['booking_source_id'=>'Select an active booking source belonging to this company.']);
        }
        if (!empty($data['supplier_vendor_party_id']) && !$this->partyHasRole((int)$data['supplier_vendor_party_id'],$booking->company_id,'VENDOR')) {
            throw ValidationException::withMessages(['supplier_vendor_party_id'=>'Selected supplier is not an active Vendor party.']);
        }
        if (!empty($data['agent_party_id']) && !$this->partyHasRole((int)$data['agent_party_id'],$booking->company_id,'AGENT')) {
            throw ValidationException::withMessages(['agent_party_id'=>'Selected beneficiary is not an active Agent party.']);
        }
        if (!empty($data['salesperson_staff_id']) && !Staff::query()->where('company_id',$booking->company_id)->whereKey($data['salesperson_staff_id'])->where('is_active',true)->exists()) {
            throw ValidationException::withMessages(['salesperson_staff_id'=>'Selected salesperson is not active.']);
        }

        $neededTypes = [];
        foreach ($selected as $pid) {
            $neededTypes[] = strtoupper((string)$linkedPassengers->get($pid)->pax_type);
        }
        foreach (array_unique($neededTypes) as $type) {
            if (!isset($data['fare'][$type], $data['purchase'][$type])) {
                throw ValidationException::withMessages(['fare'=>'Enter fare/pricing values for '.$type.'.']);
            }
            $this->validateDiscountRate($data['fare'][$type]['discount_type'], (float)$data['fare'][$type]['discount_value'], 'fare.'.$type.'.discount_value');
            $this->validateDiscountRate($data['purchase'][$type]['discount_type'], (float)$data['purchase'][$type]['discount_value'], 'purchase.'.$type.'.discount_value');
        }

        $ticketNumbers = $this->parseTicketNumbers((string)($data['ticket_numbers_text'] ?? ''));
        if ($data['ticket_status'] === 'ISSUED' && count($ticketNumbers) !== count($selected)) {
            throw ValidationException::withMessages([
                'ticket_numbers_text'=>'For ISSUED status, enter exactly one unique Ticket Number per selected passenger.'
            ]);
        }
        if (count($ticketNumbers) > count($selected)) {
            throw ValidationException::withMessages(['ticket_numbers_text'=>'More Ticket Numbers were entered than selected passengers.']);
        }
        foreach ($ticketNumbers as $number) {
            $this->assertTicketNumberUnique($number, null);
        }

        DB::transaction(function () use ($request,$booking,$service,$data,$selected,$linkedPassengers,$ticketNumbers): void {
            foreach ($selected as $i => $passengerId) {
                $passenger = $linkedPassengers->get($passengerId);
                $type = strtoupper((string)$passenger->pax_type);
                $fare = $data['fare'][$type];
                $purchase = $data['purchase'][$type];

                $base = round((float)$fare['base_fare'],2);
                $taxes = round((float)$fare['airline_taxes'],2);
                $fareTotal = round($base + $taxes,2);

                $customerDiscount = $this->discountAmount($base, (string)$fare['discount_type'], (float)$fare['discount_value']);
                $serviceMarkup = round((float)$fare['service_markup'],2);
                $selling = round($fareTotal + $serviceMarkup - $customerDiscount,2);

                $supplierDiscount = $this->discountAmount($base, (string)$purchase['discount_type'], (float)$purchase['discount_value']);
                $supplierCharges = round((float)$purchase['supplier_charges'],2);
                $supplierIncentive = round((float)$purchase['supplier_incentive'],2);
                $supplier = round($fareTotal + $supplierCharges - $supplierDiscount - $supplierIncentive,2);

                if ($selling < 0) {
                    throw ValidationException::withMessages(['fare.'.$type.'.discount_value'=>$type.' customer discount cannot make Sell / Ticket negative.']);
                }
                if ($supplier < 0) {
                    throw ValidationException::withMessages(['purchase.'.$type.'.discount_value'=>$type.' supplier deductions cannot make Net Cost / Ticket negative.']);
                }

                $ticketNumber = $ticketNumbers[$i] ?? null;
                $ticket = AirTicketDetail::query()->create([
                    'booking_service_id'=>$service->id,
                    'booking_passenger_id'=>$passengerId,
                    'pnr'=>strtoupper(trim($data['pnr'])),
                    'ticket_number'=>$ticketNumber,
                    'ticket_status'=>$ticketNumber?'ISSUED':$data['ticket_status'],
                    'airline_id'=>$data['airline_id'],
                    'booking_source_id'=>$data['booking_source_id'],
                    'origin'=>strtoupper(trim($data['origin'])),
                    'destination'=>strtoupper(trim($data['destination'])),
                    'sector'=>trim((string)($data['sector'] ?: strtoupper(trim($data['origin'])).'-'.strtoupper(trim($data['destination'])))),
                    'flight_number'=>trim((string)($data['flight_number']??''))?:null,
                    'departure_at'=>$data['departure_at'],
                    'arrival_at'=>$data['arrival_at']??null,
                    'cabin_class'=>$data['cabin_class']??null,
                    'fare_basis'=>$data['fare_basis']??null,
                    'baggage'=>$data['baggage']??null,

                    // Shared fare core: entered once and intentionally mirrored to supplier fields.
                    'base_fare'=>$base,
                    'airline_taxes'=>$taxes,
                    'customer_service_fee'=>0,
                    'markup'=>$serviceMarkup,
                    'discount'=>$customerDiscount,
                    'customer_discount_type'=>$fare['discount_type'],
                    'customer_discount_value'=>$fare['discount_value'],
                    'selling_total'=>$selling,

                    'supplier_vendor_party_id'=>$data['supplier_vendor_party_id']?:null,
                    'supplier_base_fare'=>$base,
                    'supplier_taxes'=>$taxes,
                    'supplier_charges'=>$supplierCharges,
                    'supplier_other_charges'=>0,
                    'supplier_commission'=>0,
                    'supplier_discount'=>$supplierDiscount,
                    'supplier_discount_type'=>$purchase['discount_type'],
                    'supplier_discount_value'=>$purchase['discount_value'],
                    'supplier_incentive'=>$supplierIncentive,
                    'net_supplier_cost'=>$supplier,
                    'supplier_cost_status'=>$data['supplier_cost_status'],
                    'supplier_reference'=>$data['supplier_reference']??null,

                    'notes'=>trim($type.' fare · '.(string)($data['notes']??'')),
                    'created_by'=>$request->user()->id,
                    'updated_by'=>$request->user()->id,
                ]);

                $this->syncTicketCost($request,$booking,$service,$ticket);
                $gross = round($selling-$supplier,2);
                $this->syncCommission(
                    $request,$booking,$service,$ticket,'AGENT',
                    (int)($data['agent_party_id']?:($booking->agent_party_id?:0)),null,
                    (string)($data['agent_commission_basis']?:'FIXED'),
                    (float)($data['agent_commission_rate']?:0),
                    $selling,$base,$gross
                );
                $this->syncCommission(
                    $request,$booking,$service,$ticket,'SALESPERSON',
                    null,(int)($data['salesperson_staff_id']?:($booking->salesperson_staff_id?:0)),
                    (string)($data['salesperson_commission_basis']?:'FIXED'),
                    (float)($data['salesperson_commission_rate']?:0),
                    $selling,$base,$gross
                );

                AuditService::log($request,'booking.air_ticket.quick_created',$ticket,[],array_merge($ticket->toArray(),[
                    'pax_type'=>$type,
                    'fare_total'=>$fareTotal,
                    'customer_discount_amount'=>$customerDiscount,
                    'supplier_discount_amount'=>$supplierDiscount,
                ]));
            }

            $this->syncServiceTotals($request,$service);
        });

        return back()->with('status','Passenger tickets saved. Fare Core was shared between customer and supplier pricing; discounts were calculated on Base Fare.');
    }

    public function bulkNumbers(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        $this->assertAirService($booking,$service);
        $data=$request->validate([
            'ticket_number'=>['nullable','array'],
            'ticket_number.*'=>['nullable','string','max:40'],
            'ticket_status'=>['nullable','array'],
            'ticket_status.*'=>['nullable','in:BOOKED,ISSUED,VOID,REFUNDED,REISSUED'],
        ]);

        $rows=AirTicketDetail::query()->where('booking_service_id',$service->id)->get()->keyBy('id');

        DB::transaction(function() use($request,$data,$rows,$service):void{
            $seenNumbers=[];
            foreach((array)($data['ticket_number']??[]) as $id=>$number){
                $ticket=$rows->get((int)$id);
                if(!$ticket) continue;

                $number=trim((string)$number) ?: null;
                if($number !== null){
                    $key=strtoupper($number);
                    if(isset($seenNumbers[$key])){
                        throw ValidationException::withMessages(['ticket_number.'.$id=>'Ticket Number must be unique within this ticket group.']);
                    }
                    $seenNumbers[$key]=true;
                }
                if($number) $this->assertTicketNumberUnique($number,(int)$ticket->id);

                $status=(string)(($data['ticket_status'][$id]??$ticket->ticket_status) ?: 'BOOKED');
                if(in_array($status,['ISSUED','REFUNDED','REISSUED'],true) && !$number){
                    throw ValidationException::withMessages(['ticket_number.'.$id=>'Ticket Number is required for '.$status.' status.']);
                }

                $old=$ticket->toArray();
                $ticket->update(['ticket_number'=>$number,'ticket_status'=>$status,'updated_by'=>$request->user()->id]);
                AuditService::log($request,'booking.air_ticket.number_status_updated',$ticket,$old,$ticket->fresh()->toArray());
            }

            // Operational issuance is derived only after the submitted numbers
            // have passed validation. Existing terminal states are preserved.
            $terminal=['CANCELLED','VOID','VOIDED','REFUNDED'];
            $current=AirTicketDetail::query()->where('booking_service_id',$service->id)->get();
            $active=$current->filter(static fn($ticket): bool => !in_array(strtoupper((string)$ticket->ticket_status),$terminal,true));
            $numbers=$active->map(static fn($ticket): string => trim((string)$ticket->ticket_number))->filter();
            $complete=$active->isNotEmpty() && $numbers->count()===$active->count() && $numbers->unique()->count()===$numbers->count();
            if($complete){
                foreach($active as $ticket){
                    if(strtoupper((string)$ticket->ticket_status)==='ISSUED') continue;
                    $old=$ticket->toArray();
                    $ticket->update(['ticket_status'=>'ISSUED','updated_by'=>$request->user()->id]);
                    AuditService::log($request,'booking.air_ticket.auto_issued',$ticket,$old,$ticket->fresh()->toArray());
                }
            }
        });

        return back()->with('status','Passenger Ticket Numbers / statuses updated.');
    }

    public function updateTicket(Request $request, Booking $booking, BookingService $service, AirTicketDetail $ticket): RedirectResponse
    {
        $this->assertAirService($booking,$service);
        abort_unless((int)$ticket->booking_service_id===(int)$service->id,404);
        abort_unless($booking->isEditable(),422,'Reopen the booking before changing commercial or itinerary ticket data. Use the operational ticket-number/status action for an approved booking.');

        $data=$request->validate([
            'pnr'=>['required','string','max:20'],
            'ticket_number'=>['nullable','string','max:40'],
            'ticket_status'=>['required','in:BOOKED,ISSUED,VOID,REFUNDED,REISSUED'],
            'airline_id'=>['required','integer','exists:airlines,id'],
            'booking_source_id'=>['required','integer','exists:booking_sources,id'],
            'origin'=>['required','string','max:10'],
            'destination'=>['required','string','max:10'],
            'sector'=>['nullable','string','max:80'],
            'flight_number'=>['nullable','string','max:30'],
            'departure_at'=>['required','date'],
            'arrival_at'=>['nullable','date','after_or_equal:departure_at'],
            'cabin_class'=>['nullable','string','max:30'],
            'fare_basis'=>['nullable','string','max:60'],
            'baggage'=>['nullable','string','max:80'],

            'base_fare'=>['required','numeric','min:0'],
            'airline_taxes'=>['required','numeric','min:0'],
            'service_markup'=>['required','numeric','min:0'],
            'customer_discount_type'=>['required','in:FIXED,PERCENT'],
            'customer_discount_value'=>['required','numeric','min:0'],

            'supplier_vendor_party_id'=>['nullable','integer'],
            'supplier_charges'=>['required','numeric','min:0'],
            'supplier_discount_type'=>['required','in:FIXED,PERCENT'],
            'supplier_discount_value'=>['required','numeric','min:0'],
            'supplier_incentive'=>['required','numeric','min:0'],
            'supplier_cost_status'=>['required','in:ESTIMATED,CONFIRMED'],
            'supplier_reference'=>['nullable','string','max:120'],

            'agent_party_id'=>['nullable','integer'],
            'agent_commission_basis'=>['nullable','in:FIXED,PER_TICKET,PERCENT_OF_SALE,PERCENT_OF_BASE_FARE,PERCENT_OF_GROSS_MARGIN,MANUAL'],
            'agent_commission_rate'=>['nullable','numeric','min:0'],

            'salesperson_staff_id'=>['nullable','integer'],
            'salesperson_commission_basis'=>['nullable','in:FIXED,PER_TICKET,PERCENT_OF_SALE,PERCENT_OF_BASE_FARE,PERCENT_OF_GROSS_MARGIN,MANUAL'],
            'salesperson_commission_rate'=>['nullable','numeric','min:0'],

            'notes'=>['nullable','string','max:2000'],
        ]);

        if(in_array($data['ticket_status'],['ISSUED','REFUNDED','REISSUED'],true) && !trim((string)($data['ticket_number']??''))) {
            throw ValidationException::withMessages(['ticket_number'=>'Ticket Number is required for this status.']);
        }
        if(!empty($data['ticket_number'])) {
            $this->assertTicketNumberUnique(trim($data['ticket_number']),(int)$ticket->id);
        }
        if(!empty($data['supplier_vendor_party_id']) && !$this->partyHasRole((int)$data['supplier_vendor_party_id'],$booking->company_id,'VENDOR')) {
            throw ValidationException::withMessages(['supplier_vendor_party_id'=>'Selected supplier is not an active Vendor party.']);
        }
        if(!empty($data['agent_party_id']) && !$this->partyHasRole((int)$data['agent_party_id'],$booking->company_id,'AGENT')) {
            throw ValidationException::withMessages(['agent_party_id'=>'Selected beneficiary is not an active Agent party.']);
        }

        $this->validateDiscountRate($data['customer_discount_type'],(float)$data['customer_discount_value'],'customer_discount_value');
        $this->validateDiscountRate($data['supplier_discount_type'],(float)$data['supplier_discount_value'],'supplier_discount_value');

        $base=round((float)$data['base_fare'],2);
        $taxes=round((float)$data['airline_taxes'],2);
        $fareTotal=round($base+$taxes,2);

        $customerDiscount=$this->discountAmount($base,$data['customer_discount_type'],(float)$data['customer_discount_value']);
        $selling=round($fareTotal+(float)$data['service_markup']-$customerDiscount,2);

        $supplierDiscount=$this->discountAmount($base,$data['supplier_discount_type'],(float)$data['supplier_discount_value']);
        $supplier=round($fareTotal+(float)$data['supplier_charges']-$supplierDiscount-(float)$data['supplier_incentive'],2);

        if($selling<0 || $supplier<0) {
            throw ValidationException::withMessages(['discount'=>'Commercial totals cannot be negative.']);
        }

        $posted=SalesInvoice::query()->where('booking_id',$booking->id)->where('status','POSTED')->exists();
        if($posted){
            $locked = [
                'base_fare'=>$base,
                'airline_taxes'=>$taxes,
                'customer_service_fee'=>0,
                'markup'=>(float)$data['service_markup'],
                'discount'=>$customerDiscount,
            ];
            foreach($locked as $field=>$value){
                if(abs((float)$ticket->{$field}-(float)$value)>0.009){
                    throw ValidationException::withMessages([
                        $field=>'Customer selling values are locked because a Sales Invoice is already POSTED. Ticket number/status and supplier/commission data may still be maintained.'
                    ]);
                }
            }
        }

        DB::transaction(function()use(
            $request,$booking,$service,$ticket,$data,$base,$taxes,$selling,$supplier,$customerDiscount,$supplierDiscount
        ):void{
            $old=$ticket->toArray();

            $ticket->update([
                'pnr'=>strtoupper(trim($data['pnr'])),
                'ticket_number'=>trim((string)($data['ticket_number']??''))?:null,
                'ticket_status'=>$data['ticket_status'],
                'airline_id'=>$data['airline_id'],
                'booking_source_id'=>$data['booking_source_id'],
                'origin'=>strtoupper(trim($data['origin'])),
                'destination'=>strtoupper(trim($data['destination'])),
                'sector'=>trim((string)($data['sector'] ?: strtoupper(trim($data['origin'])).'-'.strtoupper(trim($data['destination'])))),
                'flight_number'=>trim((string)($data['flight_number']??''))?:null,
                'departure_at'=>$data['departure_at'],
                'arrival_at'=>$data['arrival_at']??null,
                'cabin_class'=>$data['cabin_class']??null,
                'fare_basis'=>$data['fare_basis']??null,
                'baggage'=>$data['baggage']??null,

                'base_fare'=>$base,
                'airline_taxes'=>$taxes,
                'customer_service_fee'=>0,
                'markup'=>$data['service_markup'],
                'discount'=>$customerDiscount,
                'customer_discount_type'=>$data['customer_discount_type'],
                'customer_discount_value'=>$data['customer_discount_value'],
                'selling_total'=>$selling,

                'supplier_vendor_party_id'=>$data['supplier_vendor_party_id']?:null,
                'supplier_base_fare'=>$base,
                'supplier_taxes'=>$taxes,
                'supplier_charges'=>$data['supplier_charges'],
                'supplier_other_charges'=>0,
                'supplier_commission'=>0,
                'supplier_discount'=>$supplierDiscount,
                'supplier_discount_type'=>$data['supplier_discount_type'],
                'supplier_discount_value'=>$data['supplier_discount_value'],
                'supplier_incentive'=>$data['supplier_incentive'],
                'net_supplier_cost'=>$supplier,
                'supplier_cost_status'=>$data['supplier_cost_status'],
                'supplier_reference'=>$data['supplier_reference']??null,

                'notes'=>$data['notes']??null,
                'updated_by'=>$request->user()->id,
            ]);

            $this->syncTicketCost($request,$booking,$service,$ticket);
            $gross=round($selling-$supplier,2);

            $this->syncCommission(
                $request,$booking,$service,$ticket,'AGENT',
                (int)($data['agent_party_id']?:($booking->agent_party_id?:0)),null,
                (string)($data['agent_commission_basis']?:'FIXED'),
                (float)($data['agent_commission_rate']?:0),
                $selling,$base,$gross
            );
            $this->syncCommission(
                $request,$booking,$service,$ticket,'SALESPERSON',
                null,(int)($data['salesperson_staff_id']?:($booking->salesperson_staff_id?:0)),
                (string)($data['salesperson_commission_basis']?:'FIXED'),
                (float)($data['salesperson_commission_rate']?:0),
                $selling,$base,$gross
            );

            $this->syncServiceTotals($request,$service);
            AuditService::log($request,'booking.air_ticket.quick_updated',$ticket,$old,$ticket->fresh()->toArray());
        });

        return back()->with('status','Passenger ticket updated.');
    }

    private function discountAmount(float $baseFare, string $type, float $value): float
    {
        return round($type === 'PERCENT' ? ($baseFare * $value / 100) : $value, 2);
    }

    private function validateDiscountRate(string $type, float $value, string $field): void
    {
        if ($type === 'PERCENT' && $value > 100) {
            throw ValidationException::withMessages([$field=>'Percentage discount cannot exceed 100% of Base Fare.']);
        }
    }

    private function parseTicketNumbers(string $text): array
    {
        $parts=preg_split('/[\r\n,;\t ]+/',trim($text)) ?: [];
        return array_values(array_filter(array_map(fn($v)=>trim($v),$parts),fn($v)=>$v!==''));
    }

    private function assertTicketNumberUnique(string $number, ?int $exceptId): void
    {
        $q=AirTicketDetail::query()->where('ticket_number',$number);
        if($exceptId) $q->whereKeyNot($exceptId);
        if($q->exists()) {
            throw ValidationException::withMessages(['ticket_number'=>'Ticket Number '.$number.' already exists in the ERP.']);
        }
    }

    private function syncServiceTotals(Request $request, BookingService $service): void
    {
        $rows=AirTicketDetail::query()->where('booking_service_id',$service->id)->get();
        $count=$rows->count();
        $total=round((float)$rows->sum('selling_total'),2);
        $avg=$count?round($total/$count,2):0;

        $service->update([
            'quantity'=>max(1,$count),
            'unit_price'=>$avg,
            'line_total'=>$total,
            'updated_by'=>$request->user()->id
        ]);
    }

    private function syncTicketCost(Request $request, Booking $booking, BookingService $service, AirTicketDetail $ticket): void
    {
        $existing=ServiceCostAllocation::query()->where('air_ticket_detail_id',$ticket->id)->first();

        if((float)$ticket->net_supplier_cost<=0 || !$ticket->supplier_vendor_party_id){
            if($existing && $existing->status!=='CANCELLED'){
                $existing->update([
                    'status'=>'CANCELLED',
                    'cancelled_by'=>$request->user()->id,
                    'cancelled_at'=>now(),
                    'cancellation_reason'=>'Ticket supplier cost cleared from quick workspace.',
                    'updated_by'=>$request->user()->id
                ]);
            }
            return;
        }

        $invoice=SalesInvoice::query()
            ->where('booking_id',$booking->id)
            ->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])
            ->latest('id')->first();

        $line=$invoice
            ? SalesInvoiceLine::query()
                ->where('sales_invoice_id',$invoice->id)
                ->where('source_booking_service_id',$service->id)
                ->first()
            : null;

        $payload=[
            'company_id'=>$booking->company_id,
            'branch_id'=>$booking->branch_id,
            'booking_id'=>$booking->id,
            'booking_service_id'=>$service->id,
            'air_ticket_detail_id'=>$ticket->id,
            'sales_invoice_id'=>$invoice?->id,
            'sales_invoice_line_id'=>$line?->id,
            'vendor_party_id'=>$ticket->supplier_vendor_party_id,
            'cost_type'=>'AIR_TICKET',
            'description'=>'Ticket '.($ticket->ticket_number?:$ticket->pnr).' · '.$ticket->sector,
            'quantity'=>1,
            'unit_cost'=>$ticket->net_supplier_cost,
            'tax_amount'=>0,
            'total_cost'=>$ticket->net_supplier_cost,
            'currency_code'=>$booking->currency_code,
            'exchange_rate'=>1,
            'base_total_cost'=>$ticket->net_supplier_cost,
            'cost_mapping_key'=>'AIR_TICKET_COST',
            'status'=>$ticket->supplier_cost_status,
            'supplier_reference'=>$ticket->supplier_reference,
            'notes'=>'Synced from inline ticket workspace; no Vendor Payable until Vendor Bill posting.',
            'updated_by'=>$request->user()->id,
        ];

        if($ticket->supplier_cost_status==='CONFIRMED'){
            $payload['confirmed_by']=$request->user()->id;
            $payload['confirmed_at']=now();
        }

        $row=ServiceCostAllocation::query()->updateOrCreate(
            ['air_ticket_detail_id'=>$ticket->id],
            array_merge($payload,['created_by'=>$existing?->created_by?:$request->user()->id])
        );

        AuditService::log($request,'ticket.supplier_cost.synced',$row,$existing?->toArray()??[],$row->fresh()->toArray());
    }

    private function syncCommission(
        Request $request,
        Booking $booking,
        BookingService $service,
        AirTicketDetail $ticket,
        string $type,
        ?int $agentId,
        ?int $staffId,
        string $basis,
        float $rate,
        float $sale,
        float $baseFare,
        float $grossMargin
    ): void {
        $base=match($basis){
            'PERCENT_OF_SALE'=>$sale,
            'PERCENT_OF_BASE_FARE'=>$baseFare,
            'PERCENT_OF_GROSS_MARGIN'=>max(0,$grossMargin),
            default=>1
        };

        $amount=in_array($basis,['PERCENT_OF_SALE','PERCENT_OF_BASE_FARE','PERCENT_OF_GROSS_MARGIN'],true)
            ? round($base*$rate/100,2)
            : round($rate,2);

        $existing=CommissionAllocation::query()
            ->where('air_ticket_detail_id',$ticket->id)
            ->where('commission_type',$type)
            ->first();

        $beneficiary=$type==='AGENT'?$agentId:$staffId;

        if(!$beneficiary || $amount<=0){
            if($existing && $existing->status!=='CANCELLED'){
                $existing->update([
                    'status'=>'CANCELLED',
                    'cancelled_by'=>$request->user()->id,
                    'cancelled_at'=>now(),
                    'cancellation_reason'=>'Commission cleared from inline ticket workspace.',
                    'updated_by'=>$request->user()->id
                ]);
            }
            return;
        }

        $invoice=SalesInvoice::query()
            ->where('booking_id',$booking->id)
            ->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])
            ->latest('id')->first();

        $line=$invoice
            ? SalesInvoiceLine::query()
                ->where('sales_invoice_id',$invoice->id)
                ->where('source_booking_service_id',$service->id)
                ->first()
            : null;

        $payload=[
            'company_id'=>$booking->company_id,
            'branch_id'=>$booking->branch_id,
            'booking_id'=>$booking->id,
            'booking_service_id'=>$service->id,
            'air_ticket_detail_id'=>$ticket->id,
            'sales_invoice_id'=>$invoice?->id,
            'sales_invoice_line_id'=>$line?->id,
            'commission_type'=>$type,
            'agent_party_id'=>$type==='AGENT'?$agentId:null,
            'salesperson_staff_id'=>$type==='SALESPERSON'?$staffId:null,
            'basis'=>$basis,
            'rate'=>$rate,
            'base_amount'=>$base,
            'commission_amount'=>$amount,
            'currency_code'=>$booking->currency_code,
            'status'=>'ESTIMATED',
            'notes'=>'Forecast commission only. Future commission accounting will approve/accrue it.',
            'updated_by'=>$request->user()->id,
        ];

        $row=CommissionAllocation::query()->updateOrCreate(
            ['air_ticket_detail_id'=>$ticket->id,'commission_type'=>$type],
            array_merge($payload,['created_by'=>$existing?->created_by?:$request->user()->id])
        );

        AuditService::log($request,'ticket.commission.synced',$row,$existing?->toArray()??[],$row->fresh()->toArray());
    }

    private function partyHasRole(int $partyId,int $companyId,string $role): bool
    {
        return Party::query()
            ->whereKey($partyId)
            ->where('company_id',$companyId)
            ->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role',$role)->where('is_active',true))
            ->exists();
    }

    private function assertAirService(Booking $booking, BookingService $service): void
    {
        abort_unless((int)$service->booking_id===(int)$booking->id,404);
        $service->loadMissing('product');
        abort_unless(
            strtoupper((string)$service->product?->category)==='AIR_TICKET',
            422,
            'This quick workspace is only for Air Ticket services.'
        );
    }
}
