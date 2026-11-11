<?php
namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AirTicketDetail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingService;
use App\Models\BookingSource;
use App\Models\CommissionAllocation;
use App\Models\Hotel;
use App\Models\HotelBookingDetail;
use App\Models\Party;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\ServiceCostAllocation;
use App\Models\Staff;
use App\Models\TransportBookingDetail;
use App\Models\TransportVehicleType;
use App\Models\TransportRoute;
use App\Models\TransportRateCard;
use App\Models\TravelVoucherPartner;
use App\Models\VisaBookingDetail;
use App\Models\VisaServiceOperator;
use App\Services\AuditService;
use App\Services\HotelAccommodationLogic;
use App\Services\ExchangeRateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingServiceDetailController extends Controller
{
    public function edit(Request $request, Booking $booking, BookingService $service): View
    {
        $this->assertService($booking,$service);
        $service->load(['product','passengers']);
        $airTickets=AirTicketDetail::query()->where('booking_service_id',$service->id)->with(['passenger','airline','source','supplierVendor'])->orderBy('id')->get();
        $selected=null;
        if($request->boolean('new')) $selected=null;
        elseif($request->filled('ticket')) $selected=$airTickets->firstWhere('id',(int)$request->query('ticket'));
        else $selected=$airTickets->first();
        $agentCommission=$selected?CommissionAllocation::query()->where('air_ticket_detail_id',$selected->id)->where('commission_type','AGENT')->first():null;
        $staffCommission=$selected?CommissionAllocation::query()->where('air_ticket_detail_id',$selected->id)->where('commission_type','SALESPERSON')->first():null;
        $activeInvoice=SalesInvoice::query()->where('booking_id',$booking->id)->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->latest('id')->first();

        $bookingRateDate=$booking->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d');
        $baseCurrency=strtoupper((string)($booking->currency_code ?: 'PKR'));
        $sarFx=(new ExchangeRateService())->resolve((int)$booking->company_id,'SAR',$baseCurrency,$bookingRateDate);
        $transportDetail=Schema::hasTable('transport_booking_details') ? TransportBookingDetail::query()->where('booking_service_id',$service->id)->first() : null;
        $transportRateDate=$transportDetail?->pickup_at?->format('Y-m-d') ?: $bookingRateDate;
        $transportFx=(new ExchangeRateService())->resolve((int)$booking->company_id,'SAR',$baseCurrency,$transportRateDate);
        $transportMasterUpgradeInstalled=Schema::hasTable('transport_vehicle_types') && Schema::hasTable('transport_routes') && Schema::hasTable('transport_rate_cards') && Schema::hasTable('transport_rates') && Schema::hasColumn('transport_booking_details','transport_vehicle_type_id');
        $vehicleTypes=$transportMasterUpgradeInstalled ? TransportVehicleType::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('name')->get() : collect();
        $transportRoutes=$transportMasterUpgradeInstalled ? TransportRoute::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('name')->get() : collect();

        // ERP10.26: send all active vendor-card rows to the booking form. The browser
        // resolves the applicable card from Company + Route + Vehicle + service date,
        // while saveTransport() repeats the same lookup server-side before persisting.
        $transportRateOptions=collect();
        if($transportMasterUpgradeInstalled){
            $cards=TransportRateCard::query()->where('company_id',$booking->company_id)->where('is_active',true)
                ->with('rates')->orderByDesc('effective_from')->orderByDesc('id')->get();
            foreach($cards as $card){
                foreach($card->rates as $rate){
                    $transportRateOptions->push([
                        'rate_card_id'=>(int)$card->id,
                        'transport_company_id'=>(int)$card->transport_company_id,
                        'transport_route_id'=>(int)$rate->transport_route_id,
                        'transport_vehicle_type_id'=>(int)$rate->transport_vehicle_type_id,
                        'currency_code'=>strtoupper((string)($card->currency_code ?: 'SAR')),
                        'cost_amount'=>(float)$rate->cost_amount,
                        'rate_card_name'=>(string)$card->name,
                        'effective_from'=>$card->effective_from?->format('Y-m-d'),
                        'effective_until'=>$card->effective_until?->format('Y-m-d'),
                    ]);
                }
            }
        }

        // Dated FX history is supplied only for client-side preview. Server-side save
        // always resolves/validates the rate again through ExchangeRateService.
        $transportFxOptions=collect();
        if(Schema::hasTable('exchange_rates')){
            $transportFxOptions=DB::table('exchange_rates')
                ->where('company_id',$booking->company_id)
                ->where('to_currency_code',$baseCurrency)
                ->where('is_active',true)
                ->orderBy('from_currency_code')->orderBy('effective_date')->orderBy('id')
                ->get(['from_currency_code','to_currency_code','rate','effective_date'])
                ->map(fn($row)=>[
                    'from_currency_code'=>strtoupper((string)$row->from_currency_code),
                    'to_currency_code'=>strtoupper((string)$row->to_currency_code),
                    'rate'=>(float)$row->rate,
                    'effective_date'=>substr((string)$row->effective_date,0,10),
                ]);
        }

        return view('operations.bookings.services.details',[
            'booking'=>$booking,'service'=>$service,'category'=>strtoupper((string)$service->product?->category),
            'airlines'=>Airline::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('name')->get(),
            'sources'=>BookingSource::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('name')->get(),
            'hotels'=>Hotel::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('city')->orderBy('name')->get(),
            'transportCompanies'=>$this->travelPartners($booking->company_id,'TRANSPORT_COMPANY'),
            'saudiVisaCompanies'=>$this->travelPartners($booking->company_id,'SAUDI_VISA_COMPANY'),
            'pakistanIataCompanies'=>$this->travelPartners($booking->company_id,'PAKISTANI_IATA_UMRAH_OPERATOR'),
            'vendors'=>$this->partyRole($booking->company_id,'VENDOR'),
            'agents'=>$this->partyRole($booking->company_id,'AGENT'),
            'salespeople'=>Staff::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('name')->get(),
            'airTickets'=>$airTickets,'airTicket'=>$selected,'agentCommission'=>$agentCommission,'staffCommission'=>$staffCommission,'activeInvoice'=>$activeInvoice,
            'hotelDetail'=>HotelBookingDetail::query()->where('booking_service_id',$service->id)->first(),
            'visaDetails'=>VisaBookingDetail::query()->where('booking_service_id',$service->id)->get()->keyBy('booking_passenger_id'),
            'visaOperator'=>Schema::hasTable('visa_service_operators')
                ? VisaServiceOperator::query()->where('booking_service_id',$service->id)->with(['saudiVisaCompany.linkedPakistanIataOperator','pakistanIataOperator'])->first()
                : null,
            'visaOperatorUpgradeInstalled'=>Schema::hasTable('visa_service_operators') && Schema::hasColumn('travel_voucher_partners','linked_pakistan_iata_operator_id'),
            'visaPricingUpgradeInstalled'=>Schema::hasTable('visa_service_operators') && Schema::hasColumn('visa_service_operators','adult_sale_price'),
            'transportPricingUpgradeInstalled'=>Schema::hasTable('transport_booking_details') && Schema::hasColumn('transport_booking_details','sale_price'),
            'foreignCostUpgradeInstalled'=>Schema::hasTable('exchange_rates') && Schema::hasColumn('visa_service_operators','adult_cost_foreign') && Schema::hasColumn('transport_booking_details','cost_price_foreign'),
            'bookingRateDate'=>$bookingRateDate,'baseCurrency'=>$baseCurrency,'sarFx'=>$sarFx,
            'transportRateDate'=>$transportRateDate,'transportFx'=>$transportFx,'transportFxOptions'=>$transportFxOptions,
            'transportMasterUpgradeInstalled'=>$transportMasterUpgradeInstalled,'vehicleTypes'=>$vehicleTypes,'transportRoutes'=>$transportRoutes,'transportRateOptions'=>$transportRateOptions,
            'transportDetail'=>$transportDetail,
        ]);
    }

    public function update(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        $this->assertService($booking,$service); abort_if($service->status==='CANCELLED',422,'Cancelled services cannot be edited.');
        $service->load(['product','passengers']); $category=strtoupper((string)$service->product?->category);
        $ticketId=null;
        DB::transaction(function() use ($request,$booking,$service,$category,&$ticketId): void {
            $ticketId=match($category) {
                'AIR_TICKET' => $this->saveAirTicket($request,$booking,$service),
                'HOTEL' => $this->saveHotel($request,$booking,$service),
                'VISA' => $this->saveVisa($request,$booking,$service),
                'TRANSPORT' => $this->saveTransport($request,$booking,$service),
                default => throw ValidationException::withMessages(['service'=>'This service category does not have a specialized form.']),
            };
        });
        $params=[$booking,$service]; if($ticketId)$params['ticket']=$ticketId;
        $statusMessage=$category==='AIR_TICKET'
            ? 'Ticket commercial detail saved. Supplier cost/commissions remain non-GL forecasts until their accounting workflows post.'
            : ($category==='VISA' && strtolower((string)$request->input('visa_action'))==='operator'
                ? 'Visa operator saved. Pakistan Visa / IATA Operator was resolved automatically from the Saudi Visa Company master.'
                : 'Specialized service details saved. No accounting entry was created.');
        return redirect()->route('operations.bookings.services.details.edit',$params)->with('status',$statusMessage);
    }

    private function saveAirTicket(Request $request, Booking $booking, BookingService $service): int
    {
        $data=$request->validate([
            'air_ticket_detail_id'=>['nullable','integer'], 'booking_passenger_id'=>['required','integer'], 'pnr'=>['required','string','max:20'],
            'ticket_number'=>['nullable','string','max:40'],'ticket_status'=>['required','in:BOOKED,ISSUED,VOID,REFUNDED,REISSUED'],
            'airline_id'=>['required','integer','exists:airlines,id'],'booking_source_id'=>['required','integer','exists:booking_sources,id'],
            'origin'=>['required','string','max:10'],'destination'=>['required','string','max:10'],'sector'=>['nullable','string','max:80'],'flight_number'=>['nullable','string','max:30'],
            'departure_at'=>['required','date'],'arrival_at'=>['nullable','date','after_or_equal:departure_at'],'cabin_class'=>['nullable','string','max:30'],'fare_basis'=>['nullable','string','max:60'],'baggage'=>['nullable','string','max:80'],
            'base_fare'=>['required','numeric','min:0'],'airline_taxes'=>['required','numeric','min:0'],'customer_service_fee'=>['nullable','numeric','min:0'],'markup'=>['nullable','numeric','min:0'],'discount'=>['nullable','numeric','min:0'],
            'supplier_vendor_party_id'=>['nullable','integer'],'supplier_base_fare'=>['required','numeric','min:0'],'supplier_taxes'=>['required','numeric','min:0'],'supplier_charges'=>['nullable','numeric','min:0'],
            'supplier_commission'=>['nullable','numeric','min:0'],'supplier_discount'=>['nullable','numeric','min:0'],'supplier_incentive'=>['nullable','numeric','min:0'],'supplier_other_charges'=>['nullable','numeric','min:0'],
            'supplier_cost_status'=>['required','in:ESTIMATED,CONFIRMED'],'supplier_reference'=>['nullable','string','max:120'],
            'agent_party_id'=>['nullable','integer'],'agent_commission_basis'=>['nullable','in:FIXED,PER_TICKET,PERCENT_OF_SALE,PERCENT_OF_BASE_FARE,PERCENT_OF_GROSS_MARGIN,MANUAL'],'agent_commission_rate'=>['nullable','numeric','min:0'],
            'salesperson_staff_id'=>['nullable','integer'],'salesperson_commission_basis'=>['nullable','in:FIXED,PER_TICKET,PERCENT_OF_SALE,PERCENT_OF_BASE_FARE,PERCENT_OF_GROSS_MARGIN,MANUAL'],'salesperson_commission_rate'=>['nullable','numeric','min:0'],
            'notes'=>['nullable','string','max:2000'],
        ]);
        foreach(['customer_service_fee','markup','discount','supplier_charges','supplier_commission','supplier_discount','supplier_incentive','supplier_other_charges'] as $optionalAmount){
            $data[$optionalAmount]=(float)($data[$optionalAmount] ?? 0);
        }
        $linked=$service->passengers->pluck('id')->map(fn($v)=>(int)$v)->all();
        if(!in_array((int)$data['booking_passenger_id'],$linked,true)) throw ValidationException::withMessages(['booking_passenger_id'=>'The selected passenger must be linked to this Air Ticket service.']);
        if(in_array($data['ticket_status'],['ISSUED','REFUNDED','REISSUED'],true) && trim((string)($data['ticket_number']??''))==='') throw ValidationException::withMessages(['ticket_number'=>'Ticket Number is required once a ticket is issued, refunded or reissued.']);
        $airline=Airline::query()->where('company_id',$booking->company_id)->whereKey($data['airline_id'])->first();
        if(!$airline) throw ValidationException::withMessages(['airline_id'=>'Airline does not belong to this company.']);
        $source=BookingSource::query()->where('company_id',$booking->company_id)->whereKey($data['booking_source_id'])->first();
        if(!$source) throw ValidationException::withMessages(['booking_source_id'=>'Booking source does not belong to this company.']);

        $ticketId=(int)($data['air_ticket_detail_id']??0); $record=$ticketId?AirTicketDetail::query()->where('booking_service_id',$service->id)->whereKey($ticketId)->firstOrFail():new AirTicketDetail(['booking_service_id'=>$service->id,'created_by'=>$request->user()->id]);
        if(!empty($data['ticket_number'])){
            $duplicate=AirTicketDetail::query()->where('ticket_number',$data['ticket_number'])->whereKeyNot($record->id ?: 0)->exists();
            if($duplicate)throw ValidationException::withMessages(['ticket_number'=>'This Ticket Number already exists in the ERP.']);
        }
        if(!empty($data['supplier_vendor_party_id']) && !$this->partyHasRole((int)$data['supplier_vendor_party_id'],$booking->company_id,'VENDOR')) throw ValidationException::withMessages(['supplier_vendor_party_id'=>'Selected supplier is not an active Vendor party.']);
        if(!empty($data['agent_party_id']) && !$this->partyHasRole((int)$data['agent_party_id'],$booking->company_id,'AGENT')) throw ValidationException::withMessages(['agent_party_id'=>'Selected commission beneficiary is not an active Agent party.']);
        if(!empty($data['salesperson_staff_id']) && !Staff::query()->where('company_id',$booking->company_id)->where('is_active',true)->whereKey($data['salesperson_staff_id'])->exists()) throw ValidationException::withMessages(['salesperson_staff_id'=>'Selected salesperson is not an active staff member.']);

        $selling=round((float)$data['base_fare']+(float)$data['airline_taxes']+(float)$data['customer_service_fee']+(float)$data['markup']-(float)$data['discount'],2);
        if($selling<0)throw ValidationException::withMessages(['discount'=>'Discount cannot make the customer selling total negative.']);
        $supplier=round((float)$data['supplier_base_fare']+(float)$data['supplier_taxes']+(float)$data['supplier_charges']+(float)$data['supplier_other_charges']-(float)$data['supplier_commission']-(float)$data['supplier_discount']-(float)$data['supplier_incentive'],2);
        if($supplier<0)throw ValidationException::withMessages(['supplier_commission'=>'Supplier deductions cannot exceed gross supplier cost.']);
        if($supplier>0 && empty($data['supplier_vendor_party_id']))throw ValidationException::withMessages(['supplier_vendor_party_id'=>'Select the Vendor/Supplier when a purchase cost is entered.']);
        $data['sector']=trim((string)($data['sector']??'')) ?: strtoupper($data['origin']).'-'.strtoupper($data['destination']);
        $data['selling_total']=$selling; $data['net_supplier_cost']=$supplier; unset($data['air_ticket_detail_id'],$data['agent_party_id'],$data['agent_commission_basis'],$data['agent_commission_rate'],$data['salesperson_staff_id'],$data['salesperson_commission_basis'],$data['salesperson_commission_rate']);
        $old=$record->exists?$record->toArray():[]; $record->fill($data); $record->updated_by=$request->user()->id; $record->save();
        $this->syncTicketCost($request,$booking,$service,$record);
        $grossMargin=round($selling-$supplier,2);
        $this->syncCommission($request,$booking,$service,$record,'AGENT',(int)($request->input('agent_party_id')?:($booking->agent_party_id?:0)),null,(string)($request->input('agent_commission_basis')?:'FIXED'),(float)($request->input('agent_commission_rate')?:0),$selling,(float)$record->base_fare,$grossMargin);
        $this->syncCommission($request,$booking,$service,$record,'SALESPERSON',null,(int)($request->input('salesperson_staff_id')?:($booking->salesperson_staff_id?:0)),(string)($request->input('salesperson_commission_basis')?:'FIXED'),(float)($request->input('salesperson_commission_rate')?:0),$selling,(float)$record->base_fare,$grossMargin);
        AuditService::log($request,'booking.service.air_ticket.commercial_saved',$record,$old,$record->fresh()->toArray());
        return (int)$record->id;
    }

    private function syncTicketCost(Request $request, Booking $booking, BookingService $service, AirTicketDetail $ticket): void
    {
        $existing=ServiceCostAllocation::query()->where('air_ticket_detail_id',$ticket->id)->first();
        if((float)$ticket->net_supplier_cost<=0 || !$ticket->supplier_vendor_party_id){
            if($existing && $existing->status!=='CANCELLED')$existing->update(['status'=>'CANCELLED','cancelled_by'=>$request->user()->id,'cancelled_at'=>now(),'cancellation_reason'=>'Ticket supplier cost cleared from commercial detail.','updated_by'=>$request->user()->id]);
            return;
        }
        $invoice=SalesInvoice::query()->where('booking_id',$booking->id)->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->latest('id')->first();
        $line=$invoice?SalesInvoiceLine::query()->where('sales_invoice_id',$invoice->id)->where('source_booking_service_id',$service->id)->first():null;
        $payload=[
            'company_id'=>$booking->company_id,'branch_id'=>$booking->branch_id,'booking_id'=>$booking->id,'booking_service_id'=>$service->id,'air_ticket_detail_id'=>$ticket->id,
            'sales_invoice_id'=>$invoice?->id,'sales_invoice_line_id'=>$line?->id,'vendor_party_id'=>$ticket->supplier_vendor_party_id,'cost_type'=>'AIR_TICKET',
            'description'=>'Ticket '.($ticket->ticket_number ?: $ticket->pnr).' · '.$ticket->sector,'quantity'=>1,'unit_cost'=>$ticket->net_supplier_cost,'tax_amount'=>0,'total_cost'=>$ticket->net_supplier_cost,
            'currency_code'=>$booking->currency_code,'exchange_rate'=>1,'base_total_cost'=>$ticket->net_supplier_cost,'cost_mapping_key'=>'AIR_TICKET_COST','status'=>$ticket->supplier_cost_status,
            'supplier_reference'=>$ticket->supplier_reference,'notes'=>'Synced from ticket commercial detail; no Vendor Payable exists until a Vendor Bill is posted.','updated_by'=>$request->user()->id,
        ];
        if($ticket->supplier_cost_status==='CONFIRMED'){$payload['confirmed_by']=$request->user()->id;$payload['confirmed_at']=now();}
        $allocation=ServiceCostAllocation::query()->updateOrCreate(['air_ticket_detail_id'=>$ticket->id],array_merge($payload,['created_by'=>$existing?->created_by ?: $request->user()->id]));
        AuditService::log($request,'ticket.supplier_cost.synced',$allocation,$existing?->toArray()??[],$allocation->fresh()->toArray());
    }

    private function syncCommission(Request $request, Booking $booking, BookingService $service, AirTicketDetail $ticket, string $type, ?int $agentId, ?int $staffId, string $basis, float $rate, float $sale, float $baseFare, float $grossMargin): void
    {
        $base=match($basis){'PERCENT_OF_SALE'=>$sale,'PERCENT_OF_BASE_FARE'=>$baseFare,'PERCENT_OF_GROSS_MARGIN'=>max(0,$grossMargin),default=>1};
        $amount=in_array($basis,['PERCENT_OF_SALE','PERCENT_OF_BASE_FARE','PERCENT_OF_GROSS_MARGIN'],true)?round($base*$rate/100,2):round($rate,2);
        $existing=CommissionAllocation::query()->where('air_ticket_detail_id',$ticket->id)->where('commission_type',$type)->first();
        $beneficiary=$type==='AGENT'?$agentId:$staffId;
        if(!$beneficiary || $amount<=0){if($existing&&$existing->status!=='CANCELLED')$existing->update(['status'=>'CANCELLED','cancelled_by'=>$request->user()->id,'cancelled_at'=>now(),'cancellation_reason'=>'Commission cleared from ticket commercial detail.','updated_by'=>$request->user()->id]);return;}
        $invoice=SalesInvoice::query()->where('booking_id',$booking->id)->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->latest('id')->first();
        $line=$invoice?SalesInvoiceLine::query()->where('sales_invoice_id',$invoice->id)->where('source_booking_service_id',$service->id)->first():null;
        $payload=['company_id'=>$booking->company_id,'branch_id'=>$booking->branch_id,'booking_id'=>$booking->id,'booking_service_id'=>$service->id,'air_ticket_detail_id'=>$ticket->id,'sales_invoice_id'=>$invoice?->id,'sales_invoice_line_id'=>$line?->id,'commission_type'=>$type,'agent_party_id'=>$type==='AGENT'?$agentId:null,'salesperson_staff_id'=>$type==='SALESPERSON'?$staffId:null,'basis'=>$basis,'rate'=>$rate,'base_amount'=>$base,'commission_amount'=>$amount,'currency_code'=>$booking->currency_code,'status'=>'ESTIMATED','notes'=>'Forecast commission only. ERP-11 will approve/accrue commission to accounting.','updated_by'=>$request->user()->id];
        $row=CommissionAllocation::query()->updateOrCreate(['air_ticket_detail_id'=>$ticket->id,'commission_type'=>$type],array_merge($payload,['created_by'=>$existing?->created_by ?: $request->user()->id]));
        AuditService::log($request,'ticket.commission.synced',$row,$existing?->toArray()??[],$row->fresh()->toArray());
    }

    private function saveHotel(Request $request, Booking $booking, BookingService $service): ?int
    {
        $data=$request->validate([
            'hotel_id'=>['nullable','integer','exists:hotels,id'],
            'confirmation_number'=>['nullable','string','max:100'],
            'room_type'=>['nullable','string','max:100'],
            'rooms'=>['required','integer','min:1','max:999'],
            'check_in'=>['nullable','date'],
            'check_out'=>['nullable','date','after_or_equal:check_in'],
            'meal_plan'=>['nullable','string','max:80'],
            'occupancy'=>['nullable','string','max:80'],
            'special_requests'=>['nullable','string','max:2000'],
        ]);

        $hotel=null;
        if(!empty($data['hotel_id'])){
            $hotel=Hotel::query()->where('company_id',$booking->company_id)->whereKey($data['hotel_id'])->first();
            if(!$hotel) throw ValidationException::withMessages(['hotel_id'=>'Selected Hotel does not belong to this company.']);
        }

        $data['nights']=($data['check_in']??null)&&($data['check_out']??null)
            ?max(0,\Carbon\Carbon::parse($data['check_in'])->diffInDays(\Carbon\Carbon::parse($data['check_out'])))
            :null;

        // Keep every HOTEL service consistent with the dedicated Hotel Stay workspace:
        // all active booking passengers inherit the stay, and passenger headcount is
        // separate from bed capacity. Excess passengers are saved as No Bed.
        $activePassengerIds=BookingPassenger::query()
            ->where('booking_id',$booking->id)
            ->where('is_active',true)
            ->pluck('id')
            ->all();
        if($activePassengerIds){
            $service->passengers()->sync($activePassengerIds);
        }

        $roomCount=max(1,(int)($data['rooms']??1));
        $occupancy=HotelAccommodationLogic::resolve(
            count($activePassengerIds),
            $roomCount,
            $data['room_type']??null
        );
        if($occupancy['total_capacity']!==null){
            $data['occupancy']=$occupancy['snapshot'];
        }elseif(trim((string)($data['occupancy']??''))===''){
            $data['occupancy']=$occupancy['snapshot'];
        }

        $old=HotelBookingDetail::query()->where('booking_service_id',$service->id)->first()?->toArray() ?? [];
        $record=HotelBookingDetail::query()->updateOrCreate(
            ['booking_service_id'=>$service->id],
            array_merge($data,[
                'updated_by'=>$request->user()->id,
                'created_by'=>$old?($old['created_by']??$request->user()->id):$request->user()->id,
            ])
        );
        AuditService::log($request,'booking.service.hotel.saved',$record,$old,array_merge($record->fresh()->toArray(),[
            'active_booking_passengers'=>count($activePassengerIds),
            'total_room_capacity'=>$occupancy['total_capacity'],
            'passengers_with_bed'=>$occupancy['with_bed'],
            'passengers_no_bed'=>$occupancy['no_bed'],
        ]));
        return null;
    }

    private function saveVisa(Request $request, Booking $booking, BookingService $service): ?int
    {
        abort_unless(
            Schema::hasTable('visa_service_operators') && Schema::hasColumn('travel_voucher_partners','linked_pakistan_iata_operator_id'),
            422,
            'Visa operator linkage upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache.'
        );

        $action=strtolower(trim((string)$request->input('visa_action','passenger')));

        if($action==='operator'){
            abort_unless(
                Schema::hasColumn('visa_service_operators','adult_sale_price'),
                422,
                'Visa pricing upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache.'
            );
            abort_unless(
                Schema::hasTable('exchange_rates') && Schema::hasColumn('visa_service_operators','adult_cost_foreign'),
                422,
                'Foreign supplier-cost upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache.'
            );

            $data=$request->validate([
                'visa_action'=>['required','in:operator'],
                'saudi_visa_company_id'=>['required','integer'],
                'adult_sale_price'=>['nullable','numeric','min:0'],
                'adult_cost_price'=>['nullable','numeric','min:0'],
                'child_sale_price'=>['nullable','numeric','min:0'],
                'child_cost_price'=>['nullable','numeric','min:0'],
                'infant_sale_price'=>['nullable','numeric','min:0'],
                'infant_cost_price'=>['nullable','numeric','min:0'],
                'refresh_fx_rate'=>['nullable','boolean'],
            ]);
            foreach(['adult_sale_price','adult_cost_price','child_sale_price','child_cost_price','infant_sale_price','infant_cost_price'] as $priceField){
                $data[$priceField]=(float)($data[$priceField] ?? 0);
            }

            $saudi=$this->partnerForBooking($booking,$data['saudi_visa_company_id'],'SAUDI_VISA_COMPANY','saudi_visa_company_id');
            $pakistanId=(int)($saudi?->linked_pakistan_iata_operator_id ?? 0);
            if($pakistanId<1){
                throw ValidationException::withMessages([
                    'saudi_visa_company_id'=>'This Saudi Visa Company has no linked Pakistan Visa / IATA Operator. Update Master Data → Travel Masters → Saudi Visa Companies first.',
                ]);
            }
            $pakistan=$this->partnerForBooking($booking,$pakistanId,'PAKISTANI_IATA_UMRAH_OPERATOR','saudi_visa_company_id');
            $this->assertSingleBookingVisaPair($booking,$service,(int)$saudi->id,(int)$pakistan->id);

            $existing=VisaServiceOperator::query()->where('booking_service_id',$service->id)->first();
            $old=$existing?->toArray() ?? [];
            $baseCurrency=strtoupper((string)($booking->currency_code ?: 'PKR'));
            $rateDate=$booking->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d');
            $costForeign=[
                'ADULT'=>$data['adult_cost_price'],
                'CHILD'=>$data['child_cost_price'],
                'INFANT'=>$data['infant_cost_price'],
            ];
            $hasForeignCost=array_sum($costForeign)>0;
            $legacyBaseCosts=$existing
                && $existing->adult_cost_foreign===null && $existing->child_cost_foreign===null && $existing->infant_cost_foreign===null
                && ((float)$existing->adult_cost_price+(float)$existing->child_cost_price+(float)$existing->infant_cost_price)>0
                && !$hasForeignCost && !$request->boolean('refresh_fx_rate');
            $reuseSnapshot=$existing && (float)($existing->cost_fx_rate ?? 0)>0 && !$request->boolean('refresh_fx_rate');
            if($legacyBaseCosts){
                $fxRate=0.0;
                $fxRateDate=null;
                $adultCostBase=round((float)$existing->adult_cost_price,2);
                $childCostBase=round((float)$existing->child_cost_price,2);
                $infantCostBase=round((float)$existing->infant_cost_price,2);
                $costForeignStored=['ADULT'=>null,'CHILD'=>null,'INFANT'=>null];
            }else{
                if($reuseSnapshot){
                    $fxRate=(float)$existing->cost_fx_rate;
                    $fxRateDate=$existing->cost_fx_rate_date?->format('Y-m-d') ?: $rateDate;
                }elseif($hasForeignCost){
                    $quote=(new ExchangeRateService())->require((int)$booking->company_id,'SAR',$baseCurrency,$rateDate,'adult_cost_price');
                    $fxRate=(float)$quote['rate'];
                    $fxRateDate=$quote['effective_date'];
                }else{
                    $quote=(new ExchangeRateService())->resolve((int)$booking->company_id,'SAR',$baseCurrency,$rateDate);
                    $fxRate=(float)($quote['rate'] ?? 0);
                    $fxRateDate=$quote['effective_date'] ?? null;
                }
                $adultCostBase=round($costForeign['ADULT']*$fxRate,2);
                $childCostBase=round($costForeign['CHILD']*$fxRate,2);
                $infantCostBase=round($costForeign['INFANT']*$fxRate,2);
                $costForeignStored=$costForeign;
            }

            $paxCounts=['ADULT'=>0,'CHILD'=>0,'INFANT'=>0];
            foreach($service->passengers as $passenger){
                if(isset($passenger->is_active) && !$passenger->is_active) continue;
                $type=strtoupper((string)($passenger->pax_type ?: 'ADULT'));
                if(!array_key_exists($type,$paxCounts)) $type='ADULT';
                $paxCounts[$type]++;
            }
            $visaSaleTotal=round(
                ($paxCounts['ADULT']*$data['adult_sale_price'])+
                ($paxCounts['CHILD']*$data['child_sale_price'])+
                ($paxCounts['INFANT']*$data['infant_sale_price']),2
            );
            $visaCostForeignTotal=$legacyBaseCosts ? null : round(
                ($paxCounts['ADULT']*$costForeign['ADULT'])+
                ($paxCounts['CHILD']*$costForeign['CHILD'])+
                ($paxCounts['INFANT']*$costForeign['INFANT']),2
            );
            $visaCostTotal=$legacyBaseCosts ? round(
                ($paxCounts['ADULT']*$adultCostBase)+($paxCounts['CHILD']*$childCostBase)+($paxCounts['INFANT']*$infantCostBase),2
            ) : round((float)$visaCostForeignTotal*$fxRate,2);

            $record=VisaServiceOperator::query()->updateOrCreate(
                ['booking_service_id'=>$service->id],
                [
                    'saudi_visa_company_id'=>$saudi->id,
                    'pakistan_iata_operator_id'=>$pakistan->id,
                    'saudi_visa_company_snapshot'=>$saudi->name,
                    'saudi_visa_company_code_snapshot'=>$saudi->code,
                    'pakistan_iata_operator_snapshot'=>$pakistan->name,
                    'pakistan_iata_number_snapshot'=>$pakistan->iata_number,
                    'adult_sale_price'=>$data['adult_sale_price'],
                    'adult_cost_price'=>$adultCostBase,
                    'child_sale_price'=>$data['child_sale_price'],
                    'child_cost_price'=>$childCostBase,
                    'infant_sale_price'=>$data['infant_sale_price'],
                    'infant_cost_price'=>$infantCostBase,
                    'cost_currency_code'=>'SAR',
                    'adult_cost_foreign'=>$costForeignStored['ADULT'],
                    'child_cost_foreign'=>$costForeignStored['CHILD'],
                    'infant_cost_foreign'=>$costForeignStored['INFANT'],
                    'cost_fx_rate'=>$fxRate>0?$fxRate:null,
                    'cost_fx_rate_date'=>$fxRateDate,
                    'total_cost_foreign'=>$visaCostForeignTotal,
                    'total_cost_base'=>$visaCostTotal,
                    'created_by'=>$old?($old['created_by']??$request->user()->id):$request->user()->id,
                    'updated_by'=>$request->user()->id,
                ]
            );

            // Maintain the established passenger detail columns for backwards compatibility,
            // while the service-level operator row is authoritative.
            if(Schema::hasColumn('visa_booking_details','saudi_visa_company_id')){
                VisaBookingDetail::query()->where('booking_service_id',$service->id)->update([
                    'saudi_visa_company_id'=>$saudi->id,
                    'pakistan_iata_operator_id'=>$pakistan->id,
                    'saudi_visa_company_snapshot'=>$saudi->name,
                    'saudi_visa_company_code_snapshot'=>$saudi->code,
                    'pakistan_iata_operator_snapshot'=>$pakistan->name,
                    'pakistan_iata_number_snapshot'=>$pakistan->iata_number,
                    'updated_by'=>$request->user()->id,
                    'updated_at'=>now(),
                ]);
            }

            $this->syncBookingServiceSaleTotal($service,$visaSaleTotal);
            AuditService::log($request,'booking.service.visa.operator.saved',$record,$old,array_merge($record->fresh()->toArray(),[
                'pax_counts'=>$paxCounts,
                'visa_sale_total'=>$visaSaleTotal,
                'visa_cost_foreign_total'=>$visaCostForeignTotal,
                'visa_cost_total'=>$visaCostTotal,
                'fx_rate'=>$fxRate,'fx_rate_date'=>$fxRateDate,
            ]));
            return null;
        }

        $data=$request->validate([
            'visa_action'=>['nullable','in:passenger'],
            'booking_passenger_id'=>['required','integer'],
            'destination_country_code'=>['nullable','string','size:2'],
            'visa_type'=>['nullable','string','max:80'],
            'application_number'=>['nullable','string','max:100'],
            'visa_number'=>['nullable','string','max:100'],
            'status'=>['required','in:PENDING,SUBMITTED,APPROVED,ISSUED,REJECTED,CANCELLED'],
            'submitted_on'=>['nullable','date'],
            'issued_on'=>['nullable','date'],
            'valid_until'=>['nullable','date'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        $linked=$service->passengers->pluck('id')->map(fn($v)=>(int)$v)->all();
        if(!in_array((int)$data['booking_passenger_id'],$linked,true)){
            throw ValidationException::withMessages(['booking_passenger_id'=>'The selected passenger must be linked to this visa service.']);
        }

        $operator=VisaServiceOperator::query()
            ->where('booking_service_id',$service->id)
            ->with(['saudiVisaCompany','pakistanIataOperator'])
            ->first();
        if(!$operator || !$operator->saudiVisaCompany || !$operator->pakistanIataOperator){
            throw ValidationException::withMessages([
                'saudi_visa_company_id'=>'Save the Visa Operator first. Select the Saudi Visa Company; the linked Pakistan Visa / IATA Operator will be filled automatically.',
            ]);
        }

        $data['saudi_visa_company_id']=$operator->saudi_visa_company_id;
        $data['pakistan_iata_operator_id']=$operator->pakistan_iata_operator_id;
        unset($data['visa_action']);

        $old=VisaBookingDetail::query()
            ->where('booking_service_id',$service->id)
            ->where('booking_passenger_id',$data['booking_passenger_id'])
            ->first()?->toArray() ?? [];

        $record=VisaBookingDetail::query()->updateOrCreate(
            ['booking_service_id'=>$service->id,'booking_passenger_id'=>$data['booking_passenger_id']],
            array_merge($data,[
                'updated_by'=>$request->user()->id,
                'created_by'=>$old?($old['created_by']??$request->user()->id):$request->user()->id,
            ])
        );
        AuditService::log($request,'booking.service.visa.saved',$record,$old,$record->fresh()->toArray());
        return null;
    }

    private function assertSingleBookingVisaPair(Booking $booking, BookingService $service, int $saudiId, int $pakistanId): void
    {
        if(!Schema::hasTable('visa_service_operators') || !Schema::hasTable('booking_services')) return;

        $other=DB::table('visa_service_operators as vso')
            ->join('booking_services as bs','bs.id','=','vso.booking_service_id')
            ->where('bs.booking_id',$booking->id)
            ->where('bs.status','!=','CANCELLED')
            ->where('vso.booking_service_id','<>',$service->id)
            ->where(function($q)use($saudiId,$pakistanId){
                $q->where('vso.saudi_visa_company_id','<>',$saudiId)
                    ->orWhere('vso.pakistan_iata_operator_id','<>',$pakistanId);
            })
            ->first();

        if($other){
            $existing=VisaServiceOperator::query()->where('booking_service_id',$other->booking_service_id)->first();
            throw ValidationException::withMessages([
                'saudi_visa_company_id'=>'This booking already uses '.($existing?->saudi_visa_company_snapshot ?: 'another Saudi Visa Company').' → '.($existing?->pakistan_iata_operator_snapshot ?: 'another Pakistan IATA Operator').'. One travel voucher can use only one Visa operator pair.',
            ]);
        }
    }

    private function saveTransport(Request $request, Booking $booking, BookingService $service): ?int
    {
        $masterInstalled=Schema::hasTable('transport_vehicle_types') && Schema::hasTable('transport_routes') && Schema::hasTable('transport_rate_cards') && Schema::hasTable('transport_rates') && Schema::hasColumn('transport_booking_details','transport_vehicle_type_id');
        abort_unless($masterInstalled,422,'Transport Vehicle/Route master upgrade is not installed. Run Safe Database Upgrade, then Clear Application Cache.');
        abort_unless(
            Schema::hasTable('exchange_rates') && Schema::hasColumn('transport_booking_details','cost_price_foreign'),
            422,
            'Foreign supplier-cost upgrade is not installed. Run Safe Database Upgrade, then Clear Application Cache.'
        );

        $data=$request->validate([
            'transport_company_id'=>['required','integer'],
            'transport_vehicle_type_id'=>['required','integer'],
            'transport_route_id'=>['required','integer'],
            'provider_reference'=>['nullable','string','max:100'],
            'pickup_location'=>['nullable','string','max:220'],
            'dropoff_location'=>['nullable','string','max:220'],
            'pickup_at'=>['nullable','date'],
            'vehicle_number'=>['nullable','string','max:80'],
            'driver_name'=>['nullable','string','max:120'],
            'driver_mobile'=>['nullable','string','max:60'],
            'sale_price'=>['nullable','numeric','min:0'],
            'cost_price'=>['nullable','numeric','min:0'],
            'refresh_fx_rate'=>['nullable','boolean'],
            'notes'=>['nullable','string','max:2000'],
        ]);

        $company=$this->partnerForBooking($booking,$data['transport_company_id'],'TRANSPORT_COMPANY','transport_company_id');
        $vehicle=TransportVehicleType::query()->where('company_id',$booking->company_id)->where('is_active',true)->whereKey($data['transport_vehicle_type_id'])->first();
        $route=TransportRoute::query()->where('company_id',$booking->company_id)->where('is_active',true)->whereKey($data['transport_route_id'])->first();
        if(!$vehicle) throw ValidationException::withMessages(['transport_vehicle_type_id'=>'Select an active Vehicle Type from Travel Masters.']);
        if(!$route) throw ValidationException::withMessages(['transport_route_id'=>'Select an active Transport Route from Travel Masters.']);

        $linkedPassengerCount=$service->passengers->where('is_active',true)->count();
        if((int)($vehicle->max_pax ?? 0)>0 && $linkedPassengerCount>(int)$vehicle->max_pax){
            throw ValidationException::withMessages([
                'transport_vehicle_type_id'=>$vehicle->name.' capacity is '.$vehicle->max_pax.' pax but this Transport service has '.$linkedPassengerCount.' passenger(s). Select a larger vehicle or adjust passenger links.',
            ]);
        }

        if(trim((string)($data['pickup_location']??''))==='') $data['pickup_location']=$route->pickup_location;
        if(trim((string)($data['dropoff_location']??''))==='') $data['dropoff_location']=$route->dropoff_location;
        if(trim((string)($data['driver_mobile']??''))==='' && trim((string)$company->phone)!=='') $data['driver_mobile']=$company->phone;

        // Vendor rate and FX are dated by the actual transport service date when a
        // pickup date is available; booking date is the fallback for undated services.
        $rateDate=!empty($data['pickup_at'])
            ? \Illuminate\Support\Carbon::parse($data['pickup_at'])->format('Y-m-d')
            : ($booking->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d'));
        $baseCurrency=strtoupper((string)($booking->currency_code ?: 'PKR'));
        $matchedRate=null;
        $cards=TransportRateCard::query()->where('company_id',$booking->company_id)
            ->where('transport_company_id',$company->id)->where('is_active',true)
            ->whereDate('effective_from','<=',$rateDate)
            ->where(function($q)use($rateDate){$q->whereNull('effective_until')->orWhereDate('effective_until','>=',$rateDate);})
            ->with(['rates'=>fn($q)=>$q->where('transport_route_id',$route->id)->where('transport_vehicle_type_id',$vehicle->id)])
            ->orderByDesc('effective_from')->orderByDesc('id')->get();
        foreach($cards as $card){
            $row=$card->rates->first();
            if($row){$matchedRate=['card'=>$card,'row'=>$row];break;}
        }

        $oldRecord=TransportBookingDetail::query()->where('booking_service_id',$service->id)->first();
        $old=$oldRecord?->toArray() ?? [];
        $isConfirmed=in_array(strtoupper((string)$service->status),['CONFIRMED','COMPLETED'],true)
            || in_array(strtoupper((string)$booking->status),['CONFIRMED','COMPLETED'],true);
        if($isConfirmed && $oldRecord && $request->boolean('refresh_fx_rate')){
            throw ValidationException::withMessages(['refresh_fx_rate'=>'FX rate is locked after booking/service confirmation. Historical supplier-cost snapshots cannot be revalued here.']);
        }

        // Keep the saved vendor-card identity when staff edits only operational fields
        // (driver/vehicle number/notes). Re-resolve the dated card only when Company,
        // Route, Vehicle or transport service date changes. Confirmed snapshots are
        // always locked regardless of later master-rate changes.
        $oldRateDate=$oldRecord?->pickup_at?->format('Y-m-d') ?: ($booking->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d'));
        $masterSelectionChanged=$oldRecord && (
            (int)$oldRecord->transport_company_id!==(int)$company->id
            || (int)$oldRecord->transport_route_id!==(int)$route->id
            || (int)$oldRecord->transport_vehicle_type_id!==(int)$vehicle->id
            || $oldRateDate!==$rateDate
        );
        $preserveCommercialSnapshot=$isConfirmed && $oldRecord;
        $reuseVendorSnapshot=$oldRecord && !$masterSelectionChanged && $oldRecord->vendor_rate_card_id;
        $rateCardId=($preserveCommercialSnapshot || $reuseVendorSnapshot)
            ? $oldRecord->vendor_rate_card_id
            : ($matchedRate['card']->id ?? null);
        $vendorRateSnapshot=($preserveCommercialSnapshot || $reuseVendorSnapshot)
            ? $oldRecord->vendor_rate_snapshot
            : ($matchedRate['row']->cost_amount ?? null);
        $costCurrency=($preserveCommercialSnapshot || $reuseVendorSnapshot)
            ? strtoupper((string)($oldRecord->cost_currency_code ?: 'SAR'))
            : strtoupper((string)($matchedRate['card']->currency_code ?? $oldRecord?->cost_currency_code ?? 'SAR'));

        $submittedCost=$request->input('cost_price');
        $costForeign=$preserveCommercialSnapshot && $oldRecord?->cost_price_foreign!==null
            ? round((float)$oldRecord->cost_price_foreign,2)
            : ($submittedCost!==null && $submittedCost!==''
                ? round((float)$submittedCost,2)
                : ($matchedRate ? round((float)$matchedRate['row']->cost_amount,2) : 0.0));
        $salePrice=round((float)($data['sale_price'] ?? 0),2);

        $legacyBaseCost=$oldRecord && $oldRecord->cost_price_foreign===null && (float)($oldRecord->cost_price ?? 0)>0 && $costForeign<=0 && !$request->boolean('refresh_fx_rate');
        $reuseSnapshot=$oldRecord && (float)($oldRecord->cost_fx_rate ?? 0)>0 && !$request->boolean('refresh_fx_rate');
        if($legacyBaseCost){
            $fxRate=0.0;
            $fxRateDate=null;
            $costBase=round((float)$oldRecord->cost_price,2);
            $costForeignStored=null;
        }else{
            if($reuseSnapshot){
                $fxRate=(float)$oldRecord->cost_fx_rate;
                $fxRateDate=$oldRecord->cost_fx_rate_date?->format('Y-m-d') ?: $rateDate;
            }elseif($costForeign>0){
                $quote=(new ExchangeRateService())->require((int)$booking->company_id,$costCurrency,$baseCurrency,$rateDate,'cost_price');
                $fxRate=(float)$quote['rate'];
                $fxRateDate=$quote['effective_date'];
            }else{
                $quote=(new ExchangeRateService())->resolve((int)$booking->company_id,$costCurrency,$baseCurrency,$rateDate);
                $fxRate=(float)($quote['rate'] ?? 0);
                $fxRateDate=$quote['effective_date'] ?? null;
            }
            $costBase=round($costForeign*$fxRate,2);
            $costForeignStored=$costForeign;
        }

        $record=TransportBookingDetail::query()->updateOrCreate(
            ['booking_service_id'=>$service->id],
            [
                'transport_company_id'=>$company->id,
                'provider_reference'=>$data['provider_reference']??null,
                'pickup_location'=>$data['pickup_location']??null,
                'dropoff_location'=>$data['dropoff_location']??null,
                'pickup_at'=>$data['pickup_at']??null,
                'transport_vehicle_type_id'=>$vehicle->id,
                'transport_route_id'=>$route->id,
                'vehicle_type'=>$vehicle->name,
                'vehicle_type_snapshot'=>$vehicle->name,
                'route_snapshot'=>$route->name,
                'vehicle_number'=>$data['vehicle_number']??null,
                'driver_name'=>$data['driver_name']??null,
                'driver_mobile'=>$data['driver_mobile']??null,
                'sale_price'=>$salePrice,
                // cost_price remains the PKR/base equivalent for backward compatibility.
                'cost_price'=>$costBase,
                'cost_currency_code'=>$costCurrency,
                'cost_price_foreign'=>$costForeignStored,
                'cost_fx_rate'=>$fxRate>0?$fxRate:null,
                'cost_fx_rate_date'=>$fxRateDate,
                'cost_price_base'=>$costBase,
                'vendor_rate_card_id'=>$rateCardId,
                'vendor_rate_snapshot'=>$vendorRateSnapshot,
                'notes'=>$data['notes']??null,
                'updated_by'=>$request->user()->id,
                'created_by'=>$old?($old['created_by']??$request->user()->id):$request->user()->id,
            ]
        );
        $this->syncBookingServiceSaleTotal($service,$salePrice);
        AuditService::log($request,'booking.service.transport.saved',$record,$old,array_merge($record->fresh()->toArray(),[
            'transport_sale_total'=>$salePrice,
            'transport_cost_currency'=>$costCurrency,
            'transport_cost_foreign'=>$costForeign,
            'transport_cost_total'=>$costBase,
            'fx_rate'=>$fxRate,'fx_rate_date'=>$fxRateDate,'service_rate_date'=>$rateDate,
            'matched_rate_card'=>$preserveCommercialSnapshot ? 'LOCKED HISTORICAL SNAPSHOT' : ($matchedRate['card']->name ?? null),
        ]));
        return null;
    }

    private function syncBookingServiceSaleTotal(BookingService $service, float $saleTotal): void
    {
        if(!Schema::hasTable('booking_services') || !Schema::hasColumn('booking_services','line_total')) return;
        $payload=['line_total'=>round(max(0,$saleTotal),2)];
        if(Schema::hasColumn('booking_services','updated_at')) $payload['updated_at']=now();
        DB::table('booking_services')->where('id',$service->id)->update($payload);
        $service->line_total=$payload['line_total'];
    }

    private function partnerForBooking(Booking $booking, mixed $id, string $type, string $field): ?TravelVoucherPartner
    {
        if(!$id) return null;
        $partner=TravelVoucherPartner::query()
            ->where('company_id',$booking->company_id)
            ->where('partner_type',$type)
            ->where('is_active',true)
            ->whereKey((int)$id)
            ->first();
        if(!$partner){
            throw ValidationException::withMessages([$field=>'Selected Travel Master record is invalid or inactive.']);
        }
        return $partner;
    }

    private function travelPartners(int $companyId,string $type)
    {
        if(!Schema::hasTable('travel_voucher_partners')) return collect();
        return TravelVoucherPartner::query()
            ->with('linkedPakistanIataOperator')
            ->where('company_id',$companyId)
            ->where('partner_type',$type)
            ->where('is_active',true)
            ->orderBy('name')
            ->get();
    }

    private function partyRole(int $companyId,string $role){return Party::query()->where('company_id',$companyId)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role',$role)->where('is_active',true))->orderBy('display_name')->get();}
    private function partyHasRole(int $partyId,int $companyId,string $role):bool{return Party::query()->whereKey($partyId)->where('company_id',$companyId)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role',$role)->where('is_active',true))->exists();}
    private function assertService(Booking $booking, BookingService $service): void { abort_unless((int)$service->booking_id===(int)$booking->id,404); }
}

