<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AirTicketDetail;
use App\Models\Airline;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\HotelBookingDetail;
use App\Models\TransportBookingDetail;
use App\Models\TravelVoucher;
use App\Models\TravelVoucherRevision;
use App\Models\TravelVoucherItem;
use App\Models\TravelVoucherPartner;
use App\Models\VisaBookingDetail;
use App\Models\VisaServiceOperator;
use App\Services\HotelAccommodationLogic;
use App\Support\QrCodeSvg;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class TravelVoucherController extends Controller
{
    public function edit(Request $request, Booking $booking): View
    {
        $this->authorizeScope($request,$booking);
        $voucher=$this->ensureVoucher($request,$booking);
        $context=$this->context($booking,$voucher,$request);

        return view('operations.bookings.travel-voucher-edit',array_merge($context,[
            'saudiCompanies'=>$this->partners($booking,'SAUDI_VISA_COMPANY'),
            'pakistanOperators'=>$this->partners($booking,'PAKISTANI_IATA_UMRAH_OPERATOR'),
            'transportCompanies'=>$this->partners($booking,'TRANSPORT_COMPANY'),
            'airlines'=>Schema::hasTable('airlines') ? Airline::query()->where('company_id',$booking->company_id)->where('is_active',true)->orderBy('name')->get() : collect(),
        ]));
    }

    public function show(Request $request, Booking $booking)
    {
        try {
            $this->authorizeScope($request,$booking);
            $voucher=$this->ensureVoucher($request,$booking);
            $context=$this->context($booking,$voucher,$request);

            // Render inside the controller so Blade/View exceptions are caught here
            // instead of becoming a generic production 500 page.
            $context['publicMode']=false;
            $html=view('operations.bookings.travel-voucher',$context)->render();
            return response($html);
        } catch (Throwable $e) {
            return $this->runtimeFailureResponse($request,$booking,$e,'travel-voucher.show');
        }
    }

    public function publicCompactShow(string $publicCode)
    {
        abort_unless(
            Schema::hasTable('travel_vouchers')
                && Schema::hasColumn('travel_vouchers','public_token')
                && Schema::hasColumn('travel_vouchers','issued_at'),
            404
        );

        $publicCode=trim($publicCode);
        abort_unless(strlen($publicCode)===20 && preg_match('/^[A-Za-z0-9]{20}$/',$publicCode)===1,404);

        // The 20-character prefix is used only for the QR transport URL so the
        // built-in Version-4 QR encoder stays within its byte capacity. The full
        // random token remains the canonical public credential and is resolved
        // before rendering. Ambiguous prefixes fail closed.
        $matches=TravelVoucher::query()
            ->whereNotNull('issued_at')
            ->where('public_token','like',$publicCode.'%')
            ->limit(2)
            ->get();

        abort_unless($matches->count()===1,404);
        $voucher=$matches->first();
        $fullToken=trim((string)$voucher->public_token);
        abort_unless($fullToken!=='' && str_starts_with($fullToken,$publicCode),404);

        return $this->publicShow($fullToken);
    }

    public function publicShow(string $publicReference)
    {
        abort_unless(Schema::hasTable('travel_vouchers'),404);

        $supportsIssuedLinks=Schema::hasColumn('travel_vouchers','public_token')
            && Schema::hasColumn('travel_vouchers','issued_at');

        $voucher=null;
        $matchedBySecureToken=false;
        if($supportsIssuedLinks){
            $voucher=TravelVoucher::query()
                ->where('public_token',$publicReference)
                ->first();
            $matchedBySecureToken=(bool)$voucher;

            // Backward compatibility for vouchers printed before ERP10.27.0.
            // Sequential voucher numbers remain readable only while that legacy
            // document has never been formally issued/reissued by the new engine.
            if(!$voucher){
                $voucher=TravelVoucher::query()
                    ->where('voucher_no',$publicReference)
                    ->whereNull('issued_at')
                    ->first();
            }
        }else{
            $voucher=TravelVoucher::query()->where('voucher_no',$publicReference)->first();
        }

        abort_unless($voucher,404);

        // A guessed/random token must never expose a preview. Secure-token URLs
        // activate only when the Client Voucher is formally issued.
        if($supportsIssuedLinks && $matchedBySecureToken && !$voucher->issued_at){
            abort(404);
        }

        $booking=Booking::query()->with('company')->findOrFail($voucher->booking_id);

        if(Schema::hasColumn('travel_vouchers','voided_at') && $voucher->voided_at){
            return response()->view('operations.bookings.travel-voucher-public-status',[
                'booking'=>$booking,
                'voucher'=>$voucher,
                'status'=>'VOIDED',
                'message'=>'This client voucher is no longer valid. Please contact Easy Ticket for an updated voucher.',
            ],410);
        }

        if (Schema::hasTable('travel_voucher_revisions')) {
            $latestRevision=TravelVoucherRevision::query()->where('travel_voucher_id',$voucher->id)->orderByDesc('revision_no')->first();
            if($latestRevision && trim((string)$latestRevision->rendered_html)!=='') return response($latestRevision->rendered_html);
        }

        $request=request();
        try {
            $context=$this->context($booking,$voucher,$request,false);
            $context['publicMode']=true;

            return response(view('operations.bookings.travel-voucher',$context)->render());
        } catch (Throwable $e) {
            abort(404);
        }
    }

    public function issue(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking);
        $this->requireIssueUpgrade();

        if(!in_array(strtoupper((string)$booking->status),['CONFIRMED','COMPLETED'],true)){
            return back()->withErrors(['voucher'=>'Confirm the booking before generating a Client Voucher.']);
        }

        $voucher=$this->ensureVoucher($request,$booking);
        $context=$this->context($booking,$voucher,$request);
        if(!(bool)($context['isFinal']??false)){
            return back()->withErrors(['voucher'=>'Client Voucher is not ready yet. Complete the required passenger and confirmed service details first.']);
        }

        $data=$request->validate([
            'issue_reason'=>['required','string','min:3','max:1000'],
            'confirm_reissue'=>['accepted'],
            'expected_revision'=>['required','integer','min:0'],
        ]);

        $token=$this->newPublicToken();
        $revision=0;
        $reason=trim((string)$data['issue_reason']);

        DB::transaction(function() use($request,$booking,$voucher,$token,$reason,$data,&$revision): void {
            $locked=TravelVoucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ((int)$data['expected_revision'] !== (int)$locked->issue_revision) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'voucher'=>'This voucher was already issued or reissued. Reload the page before trying again.',
                ]);
            }
            $revision=max(0,(int)$locked->issue_revision)+1;
            $locked->update([
                'public_token'=>$token,
                'issued_at'=>now(),
                'issued_by'=>$request->user()->id,
                'issue_revision'=>$revision,
                'voided_at'=>null,
                'voided_by'=>null,
                'void_reason'=>null,
                'updated_by'=>$request->user()->id,
            ]);
            $locked=$locked->fresh();
            $snapshotContext=$this->context($booking->fresh(),$locked,$request);$snapshotContext['publicMode']=false;
            $rendered=view('operations.bookings.travel-voucher',$snapshotContext)->render();
            TravelVoucherRevision::create(['travel_voucher_id'=>$locked->id,'booking_id'=>$booking->id,'revision_no'=>$revision,'voucher_no'=>$locked->voucher_no,'reason'=>$reason,'issued_by'=>$request->user()->id,'issued_at'=>now(),'template_version'=>'ERP-11.3.378','rendered_html'=>$rendered]);
        });

        return redirect()->route('operations.bookings.travel-voucher.show',$booking)
            ->with('status',$revision>1?'Client Voucher reissued successfully.':'Client Voucher generated successfully.');
    }

    public function revision(Request $request, Booking $booking, int $revision)
    {
        $this->authorizeScope($request,$booking);abort_unless(Schema::hasTable('travel_voucher_revisions'),404);
        $voucher=TravelVoucher::query()->where('booking_id',$booking->id)->firstOrFail();
        $copy=TravelVoucherRevision::query()->where('travel_voucher_id',$voucher->id)->where('revision_no',$revision)->firstOrFail();
        return response($copy->rendered_html);
    }

    public function void(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking);
        $this->requireIssueUpgrade();

        $data=$request->validate([
            'void_reason'=>['required','string','max:1000'],
        ]);

        $voucher=TravelVoucher::query()->where('booking_id',$booking->id)->firstOrFail();
        if(!$voucher->issued_at){
            return back()->withErrors(['voucher'=>'Only an issued Client Voucher can be voided.']);
        }

        $voucher->update([
            'voided_at'=>now(),
            'voided_by'=>$request->user()->id,
            'void_reason'=>trim((string)$data['void_reason']),
            'updated_by'=>$request->user()->id,
        ]);

        return redirect()->route('operations.bookings.show',$booking)
            ->with('status','Client Voucher voided. The public QR/link now shows a void status until the voucher is reissued.');
    }

    public function diagnostic(Request $request, Booking $booking)
    {
        $this->authorizeScope($request,$booking);

        $stages=[];
        try {
            $stages[]=['stage'=>'booking','ok'=>true,'detail'=>$booking->booking_no];

            $tables=[
                'travel_vouchers',
                'travel_voucher_items',
                'travel_voucher_partners',
                'hotel_booking_details',
                'hotel_booking_rooms',
                'air_ticket_details',
                'transport_booking_details',
                'visa_booking_details',
                'visa_service_operators',
            ];
            foreach($tables as $table){
                $stages[]=[
                    'stage'=>'table:'.$table,
                    'ok'=>Schema::hasTable($table),
                    'detail'=>Schema::hasTable($table)?'present':'not present',
                ];
            }

            $voucher=$this->ensureVoucher($request,$booking);
            $stages[]=['stage'=>'ensureVoucher','ok'=>true,'detail'=>$voucher->voucher_no];

            $context=$this->context($booking,$voucher,$request);
            $stages[]=[
                'stage'=>'context',
                'ok'=>true,
                'detail'=>'hotels='.$context['hotelDetails']->count()
                    .', flights='.$context['flights']->count()
                    .', transport='.$context['transport']->count()
                    .', visas='.$context['visas']->count()
                    .', voucher_only='.$context['voucherOnly']->count(),
            ];

            view('operations.bookings.travel-voucher',$context)->render();
            $stages[]=['stage'=>'blade_render','ok'=>true,'detail'=>'Travel Voucher rendered successfully'];

            return response()->view('operations.bookings.travel-voucher-diagnostic',[
                'booking'=>$booking,
                'stages'=>$stages,
                'error'=>null,
            ]);
        } catch(Throwable $e) {
            $stages[]=[
                'stage'=>'FAILED',
                'ok'=>false,
                'detail'=>get_class($e).' · '.$e->getMessage(),
            ];

            return response()->view('operations.bookings.travel-voucher-diagnostic',[
                'booking'=>$booking,
                'stages'=>$stages,
                'error'=>$this->safeError($e),
            ],500);
        }
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking);
        $voucher=$this->ensureVoucher($request,$booking);

        $data=$request->validate([
            'manual_reference'=>['nullable','string','max:100'],
            'saudi_visa_company_id'=>['nullable','integer'],
            'special_instructions'=>['nullable','string','max:5000'],
            'footer_html'=>['nullable','string','max:10000'],
        ]);

        $type=$this->documentType($booking);
        $saudiId=null;
        $pakistanId=null;

        if($type==='UMRAH'){
            $servicePair=$this->bookingVisaOperatorPair($booking);
            if($servicePair){
                $saudiId=(int)$servicePair->saudi_visa_company_id;
                $pakistanId=(int)$servicePair->pakistan_iata_operator_id;
            }else{
                $saudiId=$this->validPartnerId($booking,$data['saudi_visa_company_id']??null,'SAUDI_VISA_COMPANY');
                if($saudiId){
                    $saudi=TravelVoucherPartner::query()
                        ->where('company_id',$booking->company_id)
                        ->whereKey($saudiId)
                        ->where('partner_type','SAUDI_VISA_COMPANY')
                        ->with('linkedPakistanIataOperator')
                        ->first();
                    $pakistanId=(int)($saudi?->linked_pakistan_iata_operator_id ?? 0) ?: null;
                    if(!$pakistanId || !$saudi?->linkedPakistanIataOperator){
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'saudi_visa_company_id'=>'This Saudi Visa Company has no linked Pakistan Visa / IATA Operator. Configure the relationship in Travel Masters first.',
                        ]);
                    }
                }
            }
        }

        DB::transaction(function() use($request,$voucher,$data,$type,$saudiId,$pakistanId): void {
            $voucher->update([
                'manual_reference'=>trim((string)($data['manual_reference']??'')) ?: null,
                'document_type'=>$type,
                'saudi_visa_company_id'=>$type==='UMRAH'?$saudiId:null,
                'pakistan_iata_operator_id'=>$type==='UMRAH'?$pakistanId:null,
                'special_instructions'=>trim((string)($data['special_instructions']??'')) ?: null,
                'footer_html'=>$this->sanitizeFooterHtml((string)($data['footer_html']??'')),
                'updated_by'=>$request->user()->id,
            ]);
        });

        $redirectParams=['booking'=>$booking];
        $selectedHotelService=(int)$request->query('hotel_service_id',0);
        if($selectedHotelService>0){
            $redirectParams['hotel_service_id']=$selectedHotelService;
        }

        return redirect()->route('operations.bookings.travel-voucher.edit',$redirectParams)
            ->with('status','Travel Voucher settings saved, including Company Contact / Footer Notes. Voucher-only data has no accounting effect.');
    }

    public function storeItem(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking);
        $voucher=$this->ensureVoucher($request,$booking);

        $type=strtoupper((string)$request->input('item_type'));
        abort_unless(in_array($type,['FLIGHT','TRANSPORT','VISA','OTHER'],true),422);

        $max=(int)TravelVoucherItem::query()
            ->where('travel_voucher_id',$voucher->id)
            ->max('sort_order');

        if($type==='FLIGHT'){
            $data=$request->validate([
                'flight_leg'=>['required',Rule::in(['DEPARTURE','ARRIVAL','OTHER'])],
                'airline_id'=>['nullable','integer'],
                'airline'=>['nullable','string','max:120'],
                'new_airline_name'=>['nullable','string','max:160'],
                'new_airline_iata_code'=>['nullable','string','size:2'],
                'new_airline_icao_code'=>['nullable','string','size:3'],
                'new_airline_accounting_prefix'=>['nullable','regex:/^\d{3}$/'],
                'new_airline_country_code'=>['nullable','string','size:2'],
                'flight_number'=>['required','string','max:60'],
                'pnr'=>['nullable','string','max:60'],
                'flight_class'=>['nullable','string','max:60'],
                'baggage'=>['nullable','string','max:80'],
                'from_location'=>['required','string','max:80'],
                'to_location'=>['required','string','max:80'],
                'departure_date'=>['required','date'],
                'departure_time'=>['required','date_format:H:i'],
                'arrival_date'=>['required','date'],
                'arrival_time'=>['required','date_format:H:i'],
                'details'=>['nullable','string','max:3000'],
            ]);

            $startsAt=$data['departure_date'].' '.$data['departure_time'].':00';
            $endsAt=$data['arrival_date'].' '.$data['arrival_time'].':00';

            $airlineMaster=null;
            if(trim((string)($data['new_airline_name']??''))!=='') {
                $airlineMaster=$this->createOrFindAirline($request,$booking,$data);
            } elseif(!empty($data['airline_id']) && Schema::hasTable('airlines')) {
                $airlineMaster=Airline::query()->where('company_id',$booking->company_id)->where('is_active',true)->whereKey((int)$data['airline_id'])->first();
                if(!$airlineMaster) {
                    abort(422,'Selected airline is not available for this company.');
                }
            }
            $airlineName=$airlineMaster?->name ?: trim((string)($data['airline']??''));

            TravelVoucherItem::query()->create([
                'travel_voucher_id'=>$voucher->id,
                'item_type'=>'FLIGHT',
                'title'=>match($data['flight_leg']){
                    'ARRIVAL'=>'Arrival Flight',
                    'OTHER'=>'Flight',
                    default=>'Departure Flight',
                },
                'reference'=>trim((string)($data['pnr']??'')) ?: null,
                'from_location'=>strtoupper(trim((string)$data['from_location'])),
                'to_location'=>strtoupper(trim((string)$data['to_location'])),
                'starts_at'=>$startsAt,
                'ends_at'=>$endsAt,
                'details'=>trim((string)($data['details']??'')) ?: null,
                'meta_json'=>[
                    'leg'=>$data['flight_leg'],
                    'airline_id'=>$airlineMaster?->id,
                    'airline'=>$airlineName,
                    'airline_iata'=>$airlineMaster?->iata_code,
                    'airline_icao'=>$airlineMaster?->icao_code,
                    'airline_accounting_prefix'=>$airlineMaster?->accounting_prefix,
                    'flight_number'=>strtoupper(trim((string)$data['flight_number'])),
                    'pnr'=>strtoupper(trim((string)($data['pnr']??''))),
                    'class'=>trim((string)($data['flight_class']??'')),
                    'baggage'=>trim((string)($data['baggage']??'')),
                ],
                'sort_order'=>$max+10,
                'is_active'=>true,
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]);

            return back()->with('status','Voucher-only flight timing added. No ticket sale or accounting entry was created.');
        }

        if($type==='TRANSPORT'){
            $data=$request->validate([
                'transport_company_id'=>['required','integer'],
                'vehicle_type'=>['required','string','max:100'],
                'service_type'=>['nullable','string','max:100'],
                'contact_number'=>['nullable','string','max:60'],
                'from_location'=>['nullable','string','max:180'],
                'to_location'=>['nullable','string','max:180'],
                'service_date'=>['nullable','date'],
                'pickup_time'=>['nullable','date_format:H:i'],
                'driver_name'=>['nullable','string','max:120'],
                'vehicle_number'=>['nullable','string','max:80'],
                'brn'=>['nullable','string','max:120'],
                'details'=>['nullable','string','max:3000'],
            ]);

            $partner=TravelVoucherPartner::query()
                ->where('company_id',$booking->company_id)
                ->where('partner_type','TRANSPORT_COMPANY')
                ->where('is_active',true)
                ->whereKey((int)$data['transport_company_id'])
                ->firstOrFail();

            $contact=trim((string)($data['contact_number']??'')) ?: trim((string)$partner->phone);
            $serviceDate=trim((string)($data['service_date']??''));
            $pickupTime=trim((string)($data['pickup_time']??''));
            $startsAt=$serviceDate!=='' ? $serviceDate.' '.($pickupTime!==''?$pickupTime:'00:00').':00' : null;

            TravelVoucherItem::query()->create([
                'travel_voucher_id'=>$voucher->id,
                'item_type'=>'TRANSPORT',
                'title'=>$partner->name,
                'reference'=>trim((string)($data['brn']??'')) ?: null,
                'from_location'=>trim((string)($data['from_location']??'')) ?: null,
                'to_location'=>trim((string)($data['to_location']??'')) ?: null,
                'starts_at'=>$startsAt,
                'ends_at'=>null,
                'details'=>trim((string)($data['details']??'')) ?: null,
                'meta_json'=>[
                    'transport_company_id'=>$partner->id,
                    'transport_company'=>$partner->name,
                    'vehicle_type'=>trim((string)$data['vehicle_type']),
                    'service_type'=>trim((string)($data['service_type']??'')),
                    'contact_number'=>$contact,
                    'pickup_time_provided'=>$pickupTime!=='',
                    'driver_name'=>trim((string)($data['driver_name']??'')),
                    'vehicle_number'=>trim((string)($data['vehicle_number']??'')),
                ],
                'sort_order'=>$max+10,
                'is_active'=>true,
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]);

            return back()->with('status','Voucher-only transport/service added. No sale, supplier cost or accounting entry was created.');
        }

        $data=$request->validate([
            'reference'=>['nullable','string','max:120'],
            'details'=>['required','string','max:5000'],
        ]);

        TravelVoucherItem::query()->create([
            'travel_voucher_id'=>$voucher->id,
            'item_type'=>$type,
            'title'=>$type==='VISA'?'Visa Information':'Other Information',
            'reference'=>trim((string)($data['reference']??'')) ?: null,
            'details'=>trim((string)$data['details']),
            'meta_json'=>[],
            'sort_order'=>$max+10,
            'is_active'=>true,
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ]);

        return back()->with('status',($type==='VISA'?'Visa':'Other').' voucher-only information added. It has no accounting effect.');
    }

    public function storeTransportCompany(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking);

        $data=$request->validate([
            'transport_company_name'=>['required','string','max:180'],
            'transport_company_phone'=>['nullable','string','max:60'],
            'transport_company_notes'=>['nullable','string','max:2000'],
        ]);

        $createValues=[
            'phone'=>trim((string)($data['transport_company_phone']??'')) ?: null,
            'notes'=>trim((string)($data['transport_company_notes']??'')) ?: null,
            'is_active'=>true,
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ];
        if(Schema::hasColumn('travel_voucher_partners','code')) $createValues['code']=$this->partnerCode($booking,'TRANSPORT_COMPANY');
        if(Schema::hasColumn('travel_voucher_partners','source_context')) $createValues['source_context']='VOUCHER_QUICK_CREATE';

        $partner=TravelVoucherPartner::query()->firstOrCreate(
            [
                'company_id'=>$booking->company_id,
                'partner_type'=>'TRANSPORT_COMPANY',
                'name'=>trim((string)$data['transport_company_name']),
            ],
            $createValues
        );

        if(!$partner->wasRecentlyCreated){
            $partner->update([
                'phone'=>trim((string)($data['transport_company_phone']??'')) ?: $partner->phone,
                'notes'=>trim((string)($data['transport_company_notes']??'')) ?: $partner->notes,
                'is_active'=>true,
                'updated_by'=>$request->user()->id,
            ]);
        }

        return back()->with('status','Transport company saved to the voucher master and is now available in the dropdown.');
    }

    public function updateTransportCompany(Request $request, Booking $booking, TravelVoucherPartner $partner): RedirectResponse
    {
        $this->authorizeScope($request,$booking);

        abort_unless(
            (int)$partner->company_id===(int)$booking->company_id
            && $partner->partner_type==='TRANSPORT_COMPANY',
            404
        );

        $data=$request->validate([
            'transport_company_name'=>['required','string','max:180'],
            'transport_company_phone'=>['nullable','string','max:60'],
            'transport_company_notes'=>['nullable','string','max:2000'],
        ]);

        $partner->update([
            'name'=>trim((string)$data['transport_company_name']),
            'phone'=>trim((string)($data['transport_company_phone']??'')) ?: null,
            'notes'=>trim((string)($data['transport_company_notes']??'')) ?: null,
            'updated_by'=>$request->user()->id,
        ]);

        return back()->with('status','Transport company updated. Existing voucher lines keep their saved snapshot; new lines use the updated details.');
    }

    public function removeItem(Request $request, Booking $booking, TravelVoucherItem $item): RedirectResponse
    {
        $this->authorizeScope($request,$booking);
        $voucher=$this->ensureVoucher($request,$booking);
        abort_unless((int)$item->travel_voucher_id===(int)$voucher->id,404);
        $item->update(['is_active'=>false,'updated_by'=>$request->user()->id]);
        return back()->with('status','Voucher-only item removed from the active document.');
    }

    private function context(Booking $booking, TravelVoucher $voucher, Request $request, bool $allowStatusUpdate=true): array
    {
        $booking->load(['company','branch','customer','agent','salesperson','passengers','services.product','services.passengers']);
        $voucher->load(['saudiVisaCompany','pakistanIataOperator','items']);

        $activePassengers=$booking->passengers->where('is_active',true)->values();
        $activeServices=$booking->services->where('status','!=','CANCELLED')->values();
        $documentType=$this->documentType($booking);

        if($voucher->document_type!==$documentType){
            $voucher->update(['document_type'=>$documentType,'updated_by'=>$request->user()?->id ?: $voucher->updated_by]);
        }

        $hotelServiceIds=$activeServices
            ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='HOTEL')
            ->pluck('id');

        $requestedHotelService=(int)$request->query('hotel_service_id',0);
        if($requestedHotelService>0 && $hotelServiceIds->contains($requestedHotelService)){
            $hotelServiceIds=collect([$requestedHotelService]);
        }

        $hotelDetails=$hotelServiceIds->isEmpty() || !Schema::hasTable('hotel_booking_details')
            ? collect()
            : HotelBookingDetail::query()
                ->whereIn('booking_service_id',$hotelServiceIds)
                ->with(['hotel','roomsList'])
                ->orderBy('check_in')
                ->orderBy('id')
                ->get();

        $airServiceIds=$activeServices
            ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='AIR_TICKET')
            ->pluck('id');

        $tickets=$airServiceIds->isEmpty()
            || !Schema::hasTable('air_ticket_details')
            || !class_exists(AirTicketDetail::class)
            ? collect()
            : AirTicketDetail::query()
                ->whereIn('booking_service_id',$airServiceIds)
                ->with(['passenger','airline'])
                ->orderBy('departure_at')
                ->orderBy('id')
                ->get();

        $flights=$tickets->groupBy(function($t){
            return implode('|',[
                $t->airline_id,
                $t->flight_number,
                $t->origin,
                $t->destination,
                optional($t->departure_at)->format('Y-m-d H:i'),
                optional($t->arrival_at)->format('Y-m-d H:i'),
                $t->pnr,
            ]);
        })->map(function($rows){
            $t=$rows->first();
            return [
                'source'=>'ERP SERVICE',
                'leg'=>null,
                'airline'=>$t->airline?->name,
                'flight_number'=>$t->flight_number,
                'origin'=>$t->origin,
                'destination'=>$t->destination,
                'departure_at'=>$t->departure_at,
                'arrival_at'=>$t->arrival_at,
                'pnr'=>$t->pnr,
                'class'=>$t->booking_class ?? $t->cabin_class ?? null,
                'baggage'=>$t->baggage,
                'passengers'=>$rows->count(),
            ];
        })->values();

        $transportServiceIds=$activeServices
            ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='TRANSPORT')
            ->pluck('id');

        $transport=$transportServiceIds->isEmpty()
            || !Schema::hasTable('transport_booking_details')
            || !class_exists(TransportBookingDetail::class)
            ? collect()
            : TransportBookingDetail::query()
                ->whereIn('booking_service_id',$transportServiceIds)
                ->with(['transportCompany','vehicleTypeMaster','routeMaster'])
                ->orderBy('pickup_at')
                ->get();

        $visaServiceIds=$activeServices
            ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='VISA')
            ->pluck('id');

        $visas=$visaServiceIds->isEmpty()
            || !Schema::hasTable('visa_booking_details')
            || !class_exists(VisaBookingDetail::class)
            ? collect()
            : VisaBookingDetail::query()
                ->whereIn('booking_service_id',$visaServiceIds)
                ->orderBy('booking_passenger_id')
                ->get();

        $visaOperators=$visaServiceIds->isEmpty()
            || !Schema::hasTable('visa_service_operators')
            || !class_exists(VisaServiceOperator::class)
            ? collect()
            : VisaServiceOperator::query()
                ->whereIn('booking_service_id',$visaServiceIds)
                ->with(['saudiVisaCompany.linkedPakistanIataOperator','pakistanIataOperator'])
                ->orderBy('booking_service_id')
                ->get();

        $umrahServices=$activeServices
            ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='UMRAH_PACKAGE')
            ->values();

        $groupPackage=Schema::hasTable('group_travel_packages')
            ? DB::table('group_travel_packages')->where('booking_id',$booking->id)->first()
            : null;

        $voucherOnly=$voucher->items->where('is_active',true)->values();

        $voucherOnlyFlights=$voucherOnly
            ->where('item_type','FLIGHT')
            ->map(function($item){
                $meta=(array)($item->meta_json ?? []);
                return [
                    'source'=>'VOUCHER ONLY',
                    'leg'=>strtoupper((string)($meta['leg']??'')) ?: null,
                    'airline'=>$meta['airline']??null,
                    'flight_number'=>$meta['flight_number']??null,
                    'origin'=>$item->from_location,
                    'destination'=>$item->to_location,
                    'departure_at'=>$item->starts_at,
                    'arrival_at'=>$item->ends_at,
                    'pnr'=>$meta['pnr']??$item->reference,
                    'class'=>$meta['class']??null,
                    'baggage'=>$meta['baggage']??null,
                    'passengers'=>null,
                    'item'=>$item,
                ];
            })->values();

        // Purchased Air Ticket records are authoritative for the printed flight boxes.
        // Voucher-only flights are a fallback only when no Air Ticket service exists, so
        // an old/manual voucher entry can never duplicate or override real ticket dates.
        if($flights->isNotEmpty()){
            $displayFlights=$flights
                ->sortBy(fn($f)=>$f['departure_at']?->timestamp ?? PHP_INT_MAX)
                ->values();
            $departureFlight=$displayFlights->first();
            $arrivalFlight=$displayFlights->count()>1 ? $displayFlights->last() : null;
        }else{
            $displayFlights=$voucherOnlyFlights
                ->sortBy(fn($f)=>$f['departure_at']?->timestamp ?? PHP_INT_MAX)
                ->values();

            $departureFlight=$displayFlights
                ->first(fn($f)=>strtoupper((string)($f['leg']??''))==='DEPARTURE')
                ?: $displayFlights->first();

            $arrivalFlight=$displayFlights
                ->first(fn($f)=>strtoupper((string)($f['leg']??''))==='ARRIVAL');

            if(!$arrivalFlight && $displayFlights->count()>1){
                $arrivalFlight=$displayFlights->last();
            }
        }

        $voucherOnlyTransport=$voucherOnly
            ->where('item_type','TRANSPORT')
            ->map(function($item){
                $meta=(array)($item->meta_json ?? []);
                return [
                    'source'=>'VOUCHER ONLY',
                    'transporter'=>$meta['transport_company']??$item->title,
                    'vehicle_type'=>$meta['vehicle_type']??'Transport',
                    'service_type'=>$meta['service_type']??null,
                    'description'=>$item->details,
                    'from'=>$item->from_location,
                    'to'=>$item->to_location,
                    'service_at'=>$item->starts_at,
                    'show_time'=>(bool)($meta['pickup_time_provided']??false),
                    'driver_name'=>$meta['driver_name']??null,
                    'vehicle_number'=>$meta['vehicle_number']??null,
                    'brn'=>$item->reference,
                    'contact_number'=>$meta['contact_number']??null,
                    'item'=>$item,
                ];
            })->values();

        $erpTransport=$transport->map(function($t){
            // Operational Transport details store stable company/vehicle/route snapshots.
            // Voucher rendering must use those snapshots rather than legacy alias columns.
            $transporter=trim((string)($t->transport_company_snapshot ?? ''))
                ?: (trim((string)($t->transportCompany?->name ?? '')) ?: 'Transport');
            $contact=trim((string)($t->driver_mobile ?? ''))
                ?: (trim((string)($t->transport_company_phone_snapshot ?? ''))
                    ?: (trim((string)($t->transportCompany?->phone ?? '')) ?: null));
            $vehicle=trim((string)($t->vehicle_type_snapshot ?? ''))
                ?: (trim((string)($t->vehicleTypeMaster?->name ?? ''))
                    ?: (trim((string)($t->vehicle_type ?? '')) ?: 'Transport'));
            $routeSnapshot=trim((string)($t->route_snapshot ?? ''))
                ?: trim((string)($t->routeMaster?->name ?? ''));
            $description=$routeSnapshot;
            if($description===''){
                $description=trim(implode(' ',array_filter([
                    ($t->pickup_location ?? null) && ($t->dropoff_location ?? null)
                        ? ($t->pickup_location.' → '.$t->dropoff_location)
                        : null,
                ])));
            }

            return [
                'source'=>'ERP SERVICE',
                'transporter'=>$transporter,
                'vehicle_type'=>$vehicle,
                'service_type'=>null,
                'description'=>$description ?: null,
                'from'=>$t->pickup_location ?? null,
                'to'=>$t->dropoff_location ?? null,
                'service_at'=>$t->pickup_at,
                'show_time'=>(bool)$t->pickup_at,
                'driver_name'=>$t->driver_name ?? null,
                'vehicle_number'=>$t->vehicle_number ?? null,
                'brn'=>$t->provider_reference ?? null,
                'contact_number'=>$contact,
                'item'=>null,
            ];
        })->values();

        $displayTransport=$erpTransport->concat($voucherOnlyTransport)->values();

        $ticketRefsByPassenger=$tickets
            ->groupBy('booking_passenger_id')
            ->map(fn($rows)=>$rows->pluck('pnr')->filter()->unique()->implode(', '));

        $visaRefsByPassenger=$visas
            ->groupBy('booking_passenger_id')
            ->map(fn($rows)=>$rows->pluck('visa_number')->filter()->unique()->implode(', '));

        $totalNights=(int)$hotelDetails->sum(fn($detail)=>(int)($detail->nights ?? 0));

        // Bed count is accommodation capacity, not passenger headcount. When multiple
        // hotel stays exist, use the lowest actual bed allocation so the single Bed
        // column never promises a bed that is unavailable in one of the stays.
        $hotelBedCounts=$hotelDetails->map(function($detail) use($activePassengers){
            $room=$detail->roomsList->first();
            $roomType=$room?->room_type ?: $detail->room_type;
            $roomCount=max(1,(int)($room?->room_count ?: $detail->rooms ?: 1));
            $storedCapacity=(int)($room?->total_capacity ?? 0);

            if($storedCapacity>0){
                return min($activePassengers->count(),$storedCapacity);
            }

            $sharingCapacity=strtoupper((string)$roomType)==='SHARING'
                ? ((int)($room?->capacity_per_room ?? 0) ?: null)
                : null;
            $resolved=HotelAccommodationLogic::resolve(
                $activePassengers->count(),
                $roomCount,
                $roomType,
                $sharingCapacity
            );
            return (int)$resolved['with_bed'];
        })->filter(fn($count)=>$count>=0)->values();

        $bedCount=$hotelBedCounts->isEmpty() ? 0 : (int)$hotelBedCounts->min();
        $bedPassengerIds=$activePassengers
            ->sortBy(function($passenger){
                $priority=match(strtoupper((string)$passenger->pax_type)){
                    'ADULT'=>0,
                    'CHILD'=>1,
                    'INFANT'=>2,
                    default=>3,
                };
                return sprintf('%02d-%08d',$priority,(int)$passenger->passenger_no);
            })
            ->take($bedCount)
            ->pluck('id')
            ->map(fn($id)=>(int)$id)
            ->values();

        // Visa operator authority: a saved Visa service pair is authoritative for the
        // voucher. Legacy voucher/passenger selections are fallback only. Saudi company
        // determines its linked Pakistan IATA operator from Travel Masters.
        $resolvedVisaServiceOperator=$visaOperators->first();
        $resolvedSaudiVisaCompany=$resolvedVisaServiceOperator?->saudiVisaCompany ?: $voucher->saudiVisaCompany;
        $resolvedPakistanIataOperator=$resolvedVisaServiceOperator?->pakistanIataOperator;

        if(!$resolvedSaudiVisaCompany){
            $legacySaudiId=(int)$visas->pluck('saudi_visa_company_id')->filter()->first();
            if($legacySaudiId>0){
                $resolvedSaudiVisaCompany=TravelVoucherPartner::query()
                    ->where('company_id',$booking->company_id)
                    ->where('partner_type','SAUDI_VISA_COMPANY')
                    ->whereKey($legacySaudiId)
                    ->first();
            }
        }

        if(!$resolvedPakistanIataOperator && $resolvedSaudiVisaCompany?->linked_pakistan_iata_operator_id){
            $resolvedPakistanIataOperator=$resolvedSaudiVisaCompany->linkedPakistanIataOperator;
        }
        if(!$resolvedPakistanIataOperator){
            $resolvedPakistanIataOperator=$voucher->pakistanIataOperator;
        }
        if(!$resolvedPakistanIataOperator){
            $visaIataId=(int)$visas->pluck('pakistan_iata_operator_id')->filter()->first();
            if($visaIataId>0){
                $resolvedPakistanIataOperator=TravelVoucherPartner::query()
                    ->where('company_id',$booking->company_id)
                    ->where('partner_type','PAKISTANI_IATA_UMRAH_OPERATOR')
                    ->whereKey($visaIataId)
                    ->first();
            }
        }

        $manualFooter=$this->sanitizeFooterHtml((string)$voucher->footer_html);
        $iataFooter=(Schema::hasColumn('travel_voucher_partners','voucher_footer_html') && $resolvedPakistanIataOperator)
            ? $this->sanitizeFooterHtml((string)$resolvedPakistanIataOperator->voucher_footer_html)
            : null;
        $companyFooter=Schema::hasColumn('companies','voucher_footer_html')
            ? $this->sanitizeFooterHtml((string)($booking->company?->voucher_footer_html ?? ''))
            : null;
        $resolvedFooterHtml=$manualFooter ?: $iataFooter ?: $companyFooter;

        $allHotelReady=$hotelServiceIds->isEmpty() || (
            $hotelDetails->count()===$hotelServiceIds->count()
            && $hotelDetails->every(
                fn($d)=>strtoupper((string)$d->hotel_status)==='CONFIRMED'
                    && trim((string)$d->confirmation_number)!==''
                    && $d->roomsList->isNotEmpty()
            )
        );

        $allAirReady=$airServiceIds->isEmpty()
            || $airServiceIds->every(fn($serviceId)=>$tickets->contains('booking_service_id',$serviceId));
        $allTransportReady=$transportServiceIds->isEmpty()
            || $transportServiceIds->every(fn($serviceId)=>$transport->contains('booking_service_id',$serviceId));
        $allVisaReady=$visaServiceIds->isEmpty()
            || $visaServiceIds->every(fn($serviceId)=>$visas->contains('booking_service_id',$serviceId));

        $bookingReady=in_array(strtoupper((string)$booking->status),['CONFIRMED','COMPLETED'],true);
        $isFinal=$activePassengers->isNotEmpty()
            && $bookingReady
            && $allHotelReady
            && $allAirReady
            && $allTransportReady
            && $allVisaReady;
        $derivedStatus=$isFinal?'FINAL':'DRAFT';

        if($allowStatusUpdate && $voucher->status!==$derivedStatus){
            $voucher->update([
                'status'=>$derivedStatus,
                'updated_by'=>$request->user()?->id,
            ]);
        }

        if($requestedHotelService>0){
            $documentTitle='ACCOMMODATION VOUCHER';
        }else{
            $serviceCategories=$activeServices
                ->map(fn($s)=>strtoupper((string)$s->product?->category))
                ->filter()->unique()->values();
            $singleCategory=$serviceCategories->count()===1 ? (string)$serviceCategories->first() : null;

            $documentTitle=match($documentType){
                'ACCOMMODATION'=>'ACCOMMODATION VOUCHER',
                'UMRAH'=>'UMRAH TRAVEL VOUCHER',
                default=>match($singleCategory){
                    'TRANSPORT'=>'TRANSPORT VOUCHER',
                    'VISA'=>'VISA VOUCHER',
                    'AIR_TICKET'=>'AIR TICKET VOUCHER',
                    default=>'CLIENT TRAVEL VOUCHER',
                },
            };
        }

        $supportsIssuedLinks=Schema::hasColumn('travel_vouchers','public_token')
            && Schema::hasColumn('travel_vouchers','issued_at');
        $issueControlsInstalled=$supportsIssuedLinks
            && Schema::hasColumn('travel_vouchers','issued_by')
            && Schema::hasColumn('travel_vouchers','issue_revision')
            && Schema::hasColumn('travel_vouchers','voided_at')
            && Schema::hasColumn('travel_vouchers','voided_by')
            && Schema::hasColumn('travel_vouchers','void_reason');
        $publicReference=$voucher->voucher_no;
        if($supportsIssuedLinks){
            $existingPublicToken=trim((string)$voucher->public_token);
            if($request->user()){
                // Authenticated staff may prepare the secure token before issue.
                $publicReference=$existingPublicToken!==''
                    ? $existingPublicToken
                    : $this->ensurePublicToken($voucher);
            }elseif($voucher->issued_at && $existingPublicToken!==''){
                // Public issued vouchers always self-reference the secure token.
                $publicReference=$existingPublicToken;
            }
            // Legacy, never-issued public voucher-number views keep their old QR
            // until staff formally issue/reissue them through ERP10.27.0.
        }

        // Keep the full secure URL for Copy Public Link, but use a compact QR
        // transport URL after issue. QrCodeSvg is a Version-4 encoder with a 78-byte
        // payload ceiling; /voucher/{48-char-token} is 82 bytes on erp.easyticket.pk
        // before any query string and therefore throws InvalidArgumentException.
        // A 20-character random-token prefix still carries >100 bits of effective
        // entropy on case-insensitive MySQL collations and is resolved fail-closed
        // to the full token by publicCompactShow().
        $publicVoucherUrl=url('/voucher/'.rawurlencode($publicReference));
        $qrVoucherUrl=url('/voucher/'.rawurlencode($voucher->voucher_no));

        $issuedToken=$supportsIssuedLinks ? trim((string)$voucher->public_token) : '';
        if($supportsIssuedLinks && $voucher->issued_at && $issuedToken!==''){
            $qrVoucherUrl=url('/v/'.rawurlencode(substr($issuedToken,0,20)));
        }

        if($requestedHotelService>0){
            // Keep the exact selected hotel/accommodation document when either link is used.
            $query=http_build_query(['hotel_service_id'=>$requestedHotelService]);
            $publicVoucherUrl.='?'.$query;
            $qrVoucherUrl.='?'.$query;
        }
        $qrDataUri=QrCodeSvg::dataUri($qrVoucherUrl);
        $isIssued=$supportsIssuedLinks && (bool)$voucher->issued_at && !$voucher->voided_at;
        $isVoided=$supportsIssuedLinks && (bool)$voucher->voided_at;
        $issueRevision=$supportsIssuedLinks ? max(0,(int)$voucher->issue_revision) : 0;

        return compact(
            'booking',
            'voucher',
            'activePassengers',
            'activeServices',
            'documentType',
            'documentTitle',
            'hotelDetails',
            'flights',
            'transport',
            'visas',
            'visaOperators',
            'umrahServices',
            'groupPackage',
            'voucherOnly',
            'isFinal',
            'requestedHotelService',
            'displayFlights',
            'departureFlight',
            'arrivalFlight',
            'displayTransport',
            'ticketRefsByPassenger',
            'visaRefsByPassenger',
            'totalNights',
            'bedCount',
            'bedPassengerIds',
            'resolvedVisaServiceOperator',
            'resolvedSaudiVisaCompany',
            'resolvedPakistanIataOperator',
            'resolvedFooterHtml',
            'publicVoucherUrl',
            'qrDataUri',
            'isIssued',
            'isVoided',
            'issueRevision',
            'issueControlsInstalled'
        );
    }

    private function ensureVoucher(Request $request, Booking $booking): TravelVoucher
    {
        abort_unless(
            Schema::hasTable('travel_vouchers') && Schema::hasTable('travel_voucher_items') && Schema::hasTable('travel_voucher_partners'),
            422,
            'Travel Voucher database upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade once, then Clear Application Cache.'
        );

        $type=$this->documentType($booking);
        $prefix=match($type){'ACCOMMODATION'=>'AV','UMRAH'=>'UV',default=>'TV'};
        $year=(int)($booking->booking_date?->format('Y') ?: now()->format('Y'));
        $voucherNo='ET-'.$prefix.'-'.$year.'-'.str_pad((string)$booking->id,6,'0',STR_PAD_LEFT);

        $voucher=TravelVoucher::query()->firstOrCreate(
            ['booking_id'=>$booking->id],
            [
                'voucher_no'=>$voucherNo,
                'document_type'=>$type,
                'status'=>'DRAFT',
                'created_by'=>$request->user()?->id,
                'updated_by'=>$request->user()?->id,
            ]
        );

        if(Schema::hasColumn('travel_vouchers','public_token')){
            $this->ensurePublicToken($voucher);
        }

        return $voucher->fresh() ?: $voucher;
    }

    private function requireIssueUpgrade(): void
    {
        $required=['public_token','issued_at','issued_by','issue_revision','voided_at','voided_by','void_reason'];
        foreach($required as $column){
            abort_unless(
                Schema::hasColumn('travel_vouchers',$column),
                422,
                'Client Voucher issue controls are not installed. Run System → Health & Updates → Safe Database Upgrade once, then Clear Application Cache.'
            );
        }
    }

    private function ensurePublicToken(TravelVoucher $voucher): string
    {
        if(!Schema::hasColumn('travel_vouchers','public_token')) return $voucher->voucher_no;

        $current=trim((string)$voucher->public_token);
        if($current!=='') return $current;

        $token=$this->newPublicToken();
        $voucher->forceFill(['public_token'=>$token])->save();
        return $token;
    }

    private function newPublicToken(): string
    {
        do {
            $token=Str::random(48);
        } while(TravelVoucher::query()->where('public_token',$token)->exists());

        return $token;
    }

    private function documentType(Booking $booking): string
    {
        $booking->loadMissing('services.product');
        $categories=$booking->services
            ->where('status','!=','CANCELLED')
            ->map(fn($s)=>strtoupper((string)$s->product?->category))
            ->filter()->unique()->values();

        $groupPackageIsUmrah=Schema::hasTable('group_travel_packages')
            && DB::table('group_travel_packages')
                ->where('booking_id',$booking->id)
                ->where('package_type','UMRAH')
                ->exists();

        if(strtoupper((string)$booking->booking_type)==='UMRAH' || $categories->contains('UMRAH_PACKAGE') || $groupPackageIsUmrah) return 'UMRAH';
        if(strtoupper((string)$booking->booking_type)==='HOTEL_ONLY' || ($categories->isNotEmpty() && $categories->every(fn($c)=>$c==='HOTEL'))) return 'ACCOMMODATION';
        return 'TRAVEL';
    }

    private function bookingVisaOperatorPair(Booking $booking): ?VisaServiceOperator
    {
        if(!Schema::hasTable('visa_service_operators') || !Schema::hasTable('booking_services')) return null;

        $serviceIds=DB::table('booking_services')->where('booking_id',$booking->id)->where('status','!=','CANCELLED')->pluck('id');
        if($serviceIds->isEmpty()) return null;

        return VisaServiceOperator::query()
            ->whereIn('booking_service_id',$serviceIds)
            ->with(['saudiVisaCompany.linkedPakistanIataOperator','pakistanIataOperator'])
            ->orderBy('booking_service_id')
            ->first();
    }

    private function partners(Booking $booking, string $type)
    {
        return TravelVoucherPartner::query()
            ->with('linkedPakistanIataOperator')
            ->where('company_id',$booking->company_id)
            ->where('partner_type',$type)
            ->where('is_active',true)
            ->orderBy('name')->get();
    }

    private function validPartnerId(Booking $booking, mixed $id, string $type): ?int
    {
        if(!$id) return null;
        $partner=TravelVoucherPartner::query()
            ->where('company_id',$booking->company_id)
            ->where('partner_type',$type)
            ->whereKey((int)$id)->first();
        return $partner?->id;
    }

    private function createOrFindPartner(Request $request, Booking $booking, string $type, string $name, ?string $registration): TravelVoucherPartner
    {
        $existing=TravelVoucherPartner::query()->where('company_id',$booking->company_id)->where('partner_type',$type)
            ->whereRaw('UPPER(TRIM(name))=?',[strtoupper(trim($name))])->first();
        if($existing) return $existing;

        $values=[
            'company_id'=>$booking->company_id,
            'partner_type'=>$type,
            'name'=>$name,
            'registration_no'=>$registration,
            'is_active'=>true,
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ];
        if(Schema::hasColumn('travel_voucher_partners','code')) $values['code']=$this->partnerCode($booking,$type);
        if(Schema::hasColumn('travel_voucher_partners','source_context')) $values['source_context']='VOUCHER_QUICK_CREATE';
        return TravelVoucherPartner::query()->create($values);
    }

    private function partnerCode(Booking $booking,string $type): string
    {
        $prefix=match($type){'TRANSPORT_COMPANY'=>'TRN','SAUDI_VISA_COMPANY'=>'SVC','PAKISTANI_IATA_UMRAH_OPERATOR'=>'PKI',default=>'TPR'};
        $n=(int)TravelVoucherPartner::query()->where('company_id',$booking->company_id)->where('partner_type',$type)->max('id')+1;
        do { $code=$prefix.'-'.str_pad((string)$n,6,'0',STR_PAD_LEFT); $n++; }
        while(TravelVoucherPartner::query()->where('company_id',$booking->company_id)->where('code',$code)->exists());
        return $code;
    }

    private function createOrFindAirline(Request $request, Booking $booking, array $data): Airline
    {
        abort_unless(Schema::hasTable('airlines'),422,'Airline master is not installed.');
        $name=trim((string)$data['new_airline_name']);
        $existing=Airline::query()->where('company_id',$booking->company_id)->whereRaw('UPPER(TRIM(name))=?',[strtoupper($name)])->first();
        if($existing) return $existing;

        $iata=strtoupper(trim((string)($data['new_airline_iata_code']??''))) ?: null;
        $icao=strtoupper(trim((string)($data['new_airline_icao_code']??''))) ?: null;
        if($iata && Airline::query()->where('company_id',$booking->company_id)->whereRaw('UPPER(iata_code)=?',[$iata])->exists()) {
            abort(422,'This airline IATA code already exists. Select the existing airline master.');
        }
        $base=$iata ?: 'AIR';
        $code=$base; $n=1;
        while(Airline::query()->where('company_id',$booking->company_id)->where('code',$code)->exists()) { $n++; $code=substr($base,0,15).'-'.$n; }

        $values=[
            'company_id'=>$booking->company_id,'code'=>$code,'iata_code'=>$iata,'icao_code'=>$icao,'name'=>$name,
            'country_code'=>strtoupper(trim((string)($data['new_airline_country_code']??'PK'))) ?: 'PK',
            'is_active'=>true,'notes'=>'Created during Travel Voucher flight entry.','created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
        ];
        if(Schema::hasColumn('airlines','accounting_prefix')) $values['accounting_prefix']=trim((string)($data['new_airline_accounting_prefix']??'')) ?: null;
        return Airline::query()->create($values);
    }

    private function sanitizeFooterHtml(string $html): ?string
    {
        $html=trim($html);
        if($html==='') return null;

        // Small safe HTML subset for company contact/footer notes.
        // Attributes, scripts, styles, links and executable markup are not allowed.
        $clean=strip_tags($html,'<br><p><div><span><strong><b><em><i><u>');
        $clean=preg_replace(
            '/<(br|p|div|span|strong|b|em|i|u)\b[^>]*>/i',
            '<$1>',
            $clean
        );

        return trim((string)$clean) ?: null;
    }

    private function runtimeFailureResponse(Request $request, Booking $booking, Throwable $e, string $stage)
    {
        // In production do not expose stack traces or file-system paths.
        // Super Admin gets the exact exception class/message + basename/line so a no-SSH install can be diagnosed.
        $user=$request->user();
        $canDiagnose=(bool)($user && $user->is_super_admin);

        return response()->view('operations.bookings.travel-voucher-diagnostic',[
            'booking'=>$booking,
            'stages'=>[
                ['stage'=>$stage,'ok'=>false,'detail'=>$canDiagnose ? get_class($e).' · '.$e->getMessage() : 'Travel Voucher could not be rendered.'],
            ],
            'error'=>$canDiagnose ? $this->safeError($e) : null,
        ],500);
    }

    private function safeError(Throwable $e): array
    {
        $messages=[];
        $current=$e;
        $depth=0;
        while($current && $depth<4){
            $messages[]=[
                'class'=>get_class($current),
                'message'=>$current->getMessage(),
                'file'=>basename($current->getFile()),
                'line'=>$current->getLine(),
            ];
            $current=$current->getPrevious();
            $depth++;
        }
        return $messages;
    }

    private function authorizeScope(Request $request, Booking $booking): void
    {
        $user=$request->user();
        abort_unless($user,403);

        if($user->is_super_admin || $user->access_scope==='ALL_BRANCHES'){
            $allowed=Branch::query()->where('company_id',$booking->company_id)->where('is_active',true)->pluck('id');
        }else{
            $query=$user->branches()->where('branches.company_id',$booking->company_id)->where('branches.is_active',true);
            if($user->access_scope==='PRIMARY_BRANCH') $query->wherePivot('is_primary',true);
            $allowed=$query->pluck('branches.id');
        }

        abort_unless($allowed->contains($booking->branch_id),403);
    }
}
