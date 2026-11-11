<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\AirTicketDetail;
use App\Models\Airline;
use App\Models\Hotel;
use App\Models\HotelBookingDetail;
use App\Models\HotelBookingRoom;
use App\Models\BookingSource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\ProductService;
use App\Models\Staff;
use App\Services\Accounting\DocumentNumberService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $company=Company::query()->firstOrFail();
        $allowed=$this->allowedBranches($request,$company->id)->pluck('id');
        $query=Booking::query()->where('company_id',$company->id)->whereIn('branch_id',$allowed)
            ->with(['branch','customer','agent','salesperson'])->withCount(['passengers as passenger_count'=>fn($q)=>$q->where('is_active',true),'services as service_count'=>fn($q)=>$q->where('status','!=','CANCELLED')])
            ->orderByDesc('booking_date')->orderByDesc('id');
        $status=strtoupper((string)$request->query('status'));
        $q=trim((string)$request->query('q'));
        if(in_array($status,['DRAFT','PENDING_CONFIRMATION','CONFIRMED','COMPLETED','CANCELLED'],true))$query->where('status',$status);
        if($q!=='')$query->where(function($x)use($q){$x->where('booking_no','like',"%{$q}%")->orWhere('customer_reference','like',"%{$q}%")->orWhereHas('customer',fn($p)=>$p->where('display_name','like',"%{$q}%"));});
        $counts=Booking::query()->where('company_id',$company->id)->whereIn('branch_id',$allowed)->selectRaw('status, count(*) total')->groupBy('status')->pluck('total','status');
        return view('operations.bookings.index',['bookings'=>$query->get(),'counts'=>$counts,'filterStatus'=>$status,'search'=>$q]);
    }

    public function create(Request $request): View
    {
        $company=Company::query()->firstOrFail();
        return view('operations.bookings.form',$this->formData($request,$company,null));
    }

    public function store(Request $request, DocumentNumberService $numbers): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$this->validatedQuickStart($request,$company);
        $booking=DB::transaction(function()use($request,$company,$data,$numbers){
            $fy=FiscalYear::query()->where('company_id',$company->id)->whereDate('starts_on','<=',$data['booking_date'])->whereDate('ends_on','>=',$data['booking_date'])->first();
            if(!$fy)throw ValidationException::withMessages(['booking_date'=>'No fiscal year covers this booking date. Configure the fiscal year first.']);
            $no=$numbers->next('BOOKING',$company->id,(int)$data['branch_id'],$fy);
            $booking=Booking::query()->create(array_merge($data,['company_id'=>$company->id,'booking_no'=>$no,'status'=>'DRAFT','created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]));
            AuditService::log($request,'booking.created',$booking,[],$booking->toArray());
            return $booking;
        });
        return redirect()->route('operations.bookings.show',$booking)->with('status','Booking started. Add passengers, then complete the required service workspace.');
    }

    public function show(Request $request, Booking $booking): View
    {
        $this->authorizeScope($request,$booking);
        $booking->load(['branch','customer','agent','salesperson','passengers','services.product','services.passengers','salesInvoices.journal']);
        $company=Company::query()->findOrFail($booking->company_id);
        // The native show route is the overview surface. Dedicated product
        // routes own their editor payloads; keeping this flag server-side
        // prevents the overview from materializing those datasets/forms.
        $overviewOnly=true;
        $data=$this->formData($request,$company,$booking,false);
        $serviceIds=$booking->services->pluck('id');
        $data['airTicketRowsByService']=AirTicketDetail::query()
            ->whereIn('booking_service_id',$serviceIds)
            ->select(['booking_service_id','booking_passenger_id','selling_total','ticket_status'])
            ->orderBy('id')->get()->groupBy('booking_service_id');
        if ($overviewOnly) {
            // These collections are required by the shared Blade variables,
            // but their editor-only records are deliberately not loaded.
            $data['airlines']=collect();
            $data['sources']=collect();
            $data['hotels']=collect();
            $data['travelCities']=collect();
            $data['hotelLocationByHotelId']=collect();
            $data['hotelDetailsByService']=collect();
            $data['vendors']=collect();
            $data['booking']=$booking;
            return view('operations.bookings.show', $data + ['overviewOnly' => true]);
        }
        $data['airlines']=Airline::query()->where('company_id',$company->id)->where('is_active',true)->orderBy('name')->get();
        $data['sources']=BookingSource::query()->where('company_id',$company->id)->where('is_active',true)->orderBy('name')->get();
        $data['hotels']=Hotel::query()
            ->where('company_id',$company->id)
            ->where('is_active',true)
            ->with('defaultVendor')
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        // Backend Travel City/IATA master.
        // Database is authoritative after ERP-10.18.12 migration.
        if (Schema::hasTable('travel_city_codes')) {
            $travelCityRows=DB::table('travel_city_codes')
                ->where('is_active',1)
                ->orderBy('country_code')
                ->orderBy('city')
                ->get(['code','city','country_code','aliases'])
                ->map(fn($row)=>[
                    'code'=>strtoupper((string)$row->code),
                    'city'=>(string)$row->city,
                    'country_code'=>strtoupper((string)$row->country_code),
                    'aliases'=>json_decode((string)($row->aliases??'[]'),true) ?: [],
                ]);
        } else {
            $travelCityRows=collect(config('travel_cities.cities',[]))
                ->map(fn($meta,$code)=>[
                    'code'=>strtoupper((string)$code),
                    'city'=>(string)($meta['city']??''),
                    'country_code'=>strtoupper((string)($meta['country_code']??'')),
                    'aliases'=>[],
                ])
                ->values();
        }

        $data['travelCities']=$travelCityRows
            ->map(fn($row)=>[
                'code'=>$row['code'],
                'city'=>$row['city'],
                'country_code'=>$row['country_code'],
            ])
            ->values();

        $normalize=static fn(?string $value): string =>
            strtoupper(trim(preg_replace('/\s+/',' ',(string)$value)));

        $cityByCode=$travelCityRows->keyBy('code');
        $cityByName=[];

        foreach ($travelCityRows as $row) {
            $cityByName[$normalize($row['city'])]=$row;

            foreach ((array)($row['aliases']??[]) as $alias) {
                $cityByName[$normalize((string)$alias)]=$row;
            }
        }

        $hasHotelIata=Schema::hasColumn('hotels','city_iata_code');

        $data['hotelLocationByHotelId']=$data['hotels']->mapWithKeys(
            function($hotel) use($hasHotelIata,$cityByCode,$cityByName,$normalize): array {
                $storedCode=$hasHotelIata
                    ? strtoupper(trim((string)($hotel->getAttribute('city_iata_code')??'')))
                    : '';

                $resolved=$storedCode!=='' ? $cityByCode->get($storedCode) : null;

                if (!$resolved) {
                    $resolved=$cityByName[$normalize((string)$hotel->city)]??null;
                }

                return [
                    (int)$hotel->id => [
                        'city_code'=>$resolved['code']??($storedCode ?: null),
                        'country_code'=>$resolved['country_code']
                            ?? (strtoupper(trim((string)($hotel->country_code??''))) ?: null),
                        'city'=>$resolved['city']??(string)$hotel->city,
                    ],
                ];
            }
        );

        $data['hotelDetailsByService']=HotelBookingDetail::query()
            ->whereIn('booking_service_id',$serviceIds)
            ->with([
                'hotel',
                'supplierVendor',
                'roomsList',
                'commissions.agent',
                'commissions.salesperson',
            ])
            ->get()
            ->keyBy('booking_service_id');

        $data['vendors']=Party::query()->where('company_id',$company->id)->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->orderBy('display_name')->get();
        $data['booking']=$booking;
        return view('operations.bookings.show',$data);
    }

    public function diagnostic(Request $request, Booking $booking)
    {
        $this->authorizeScope($request,$booking);

        abort_unless((bool)$request->user()->is_super_admin,403);

        $result=[
            'booking_id'=>$booking->id,
            'booking_no'=>$booking->booking_no,
            'status'=>'STARTED',
            'checks'=>[],
        ];

        try{
            $result['checks']['booking_load']='START';
            $booking->load([
                'branch','customer','agent','salesperson',
                'passengers','services.product','services.passengers',
                'salesInvoices.journal'
            ]);
            $result['checks']['booking_load']='OK';

            $result['checks']['services']=[
                'count'=>$booking->services->count(),
                'ids'=>$booking->services->pluck('id')->values()->all(),
                'categories'=>$booking->services->map(fn($s)=>[
                    'id'=>$s->id,
                    'product'=>$s->product?->name,
                    'category'=>$s->product?->category,
                    'status'=>$s->status,
                ])->values()->all(),
            ];

            $serviceIds=$booking->services->pluck('id');

            $result['checks']['hotel_details']='START';
            $details=HotelBookingDetail::query()
                ->whereIn('booking_service_id',$serviceIds)
                ->with(['hotel','roomsList'])
                ->get();

            $result['checks']['hotel_details']=$details->map(fn($d)=>[
                'id'=>$d->id,
                'service_id'=>$d->booking_service_id,
                'hotel_id'=>$d->hotel_id,
                'hotel'=>$d->hotel?->name,
                'room_rows'=>$d->roomsList->count(),
                'check_in'=>$d->check_in?->format('Y-m-d'),
                'check_out'=>$d->check_out?->format('Y-m-d'),
            ])->values()->all();

            $result['checks']['hotel_master_count']=Hotel::query()
                ->where('company_id',$booking->company_id)
                ->count();

            // Exact normal page preparation + Blade render diagnostic.
            $result['checks']['full_show_render']='START';
            try{
                $view=$this->show($request,$booking);
                $html=$view->render();
                $rawBladeMarkers=[
                    '@foreach','@endforeach','@if','@endif','@php','@endphp',
                    '{{ $','{!! $',
                ];

                $foundMarkers=array_values(array_filter(
                    $rawBladeMarkers,
                    fn($marker)=>str_contains($html,$marker)
                ));

                $result['checks']['full_show_render']=[
                    'status'=>$foundMarkers ? 'FAILED_RAW_BLADE' : 'OK',
                    'html_bytes'=>strlen($html),
                    'raw_blade_markers'=>$foundMarkers,
                ];

                if($foundMarkers){
                    $result['status']='FAILED';
                    return response()->json(
                        $result,
                        200,
                        [],
                        JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
                    );
                }
            }catch(\Throwable $renderError){
                $result['checks']['full_show_render']=[
                    'status'=>'FAILED',
                    'class'=>get_class($renderError),
                    'message'=>$renderError->getMessage(),
                    'file'=>$renderError->getFile(),
                    'line'=>$renderError->getLine(),
                ];
                $result['status']='FAILED';
                return response()->json($result,200,[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            }

            $result['status']='OK';
        }catch(\Throwable $e){
            $result['status']='FAILED';
            $result['error']=[
                'class'=>get_class($e),
                'message'=>$e->getMessage(),
                'file'=>basename($e->getFile()),
                'line'=>$e->getLine(),
            ];
        }

        return response()->json($result,200,[],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking); abort_unless($booking->isEditable(),422);
        $company=Company::query()->findOrFail($booking->company_id); $data=$this->validatedHeader($request,$company);
        $old=$booking->toArray(); $booking->update(array_merge($data,['updated_by'=>$request->user()->id]));
        AuditService::log($request,'booking.updated',$booking,$old,$booking->fresh()->toArray());
        return back()->with('status','Booking header updated.');
    }

    public function submit(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking); abort_unless(in_array($booking->status,['DRAFT','PENDING_CONFIRMATION'],true),422);
        $this->validateCompleteness($booking);
        $old=$booking->status; $booking->update(['status'=>'PENDING_CONFIRMATION','submitted_by'=>$request->user()->id,'submitted_at'=>now(),'updated_by'=>$request->user()->id]);
        AuditService::log($request,'booking.submitted',$booking,['status'=>$old],['status'=>'PENDING_CONFIRMATION']);
        return back()->with('status','Booking submitted for confirmation.');
    }

    public function confirm(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking); abort_unless(in_array($booking->status,['DRAFT','PENDING_CONFIRMATION'],true),422);
        $this->validateCompleteness($booking);
        $old=$booking->status;
        DB::transaction(function()use($request,$booking):void{
            $booking->services()->where('status','!=','CANCELLED')->update(['status'=>'CONFIRMED','updated_by'=>$request->user()->id,'updated_at'=>now()]);
            $booking->update(['status'=>'CONFIRMED','confirmed_by'=>$request->user()->id,'confirmed_at'=>now(),'updated_by'=>$request->user()->id]);
        });
        AuditService::log($request,'booking.confirmed',$booking,['status'=>$old],['status'=>'CONFIRMED','service_status'=>'CONFIRMED']);
        return back()->with('status','Booking confirmed. All active service lines are confirmed; invoicing, supplier costing and travel-readiness remain separate workflows.');
    }

    public function reopen(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking); abort_unless($booking->status==='CONFIRMED',422);
        if ($booking->salesInvoices()->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->exists()) {
            throw ValidationException::withMessages(['booking'=>'This booking has an active Sales Invoice. Cancel the draft invoice first, or use the future Credit/Refund workflow for a posted invoice.']);
        }
        $data=$request->validate(['reopen_reason'=>['required','string','min:5','max:1000']]);
        $old=$booking->status;
        DB::transaction(function()use($request,$booking):void{
            $booking->services()->where('status','CONFIRMED')->update(['status'=>'DRAFT','updated_by'=>$request->user()->id,'updated_at'=>now()]);
            $booking->update(['status'=>'PENDING_CONFIRMATION','updated_by'=>$request->user()->id]);
        });
        AuditService::log($request,'booking.reopened_for_correction',$booking,['status'=>$old],['status'=>'PENDING_CONFIRMATION','reason'=>$data['reopen_reason']]);
        return back()->with('status','Booking reopened for correction. Review passenger links, quantities and pricing, then confirm it again.');
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeScope($request,$booking); abort_if(in_array($booking->status,['CANCELLED','COMPLETED'],true),422);
        if ($booking->salesInvoices()->whereIn('status',['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'])->exists()) {
            throw ValidationException::withMessages(['booking'=>'This booking has an active Sales Invoice. Cancel an unposted invoice first; posted invoices will later require Credit/Refund processing before booking cancellation.']);
        }
        $data=$request->validate(['cancellation_reason'=>['required','string','max:2000']]);
        $old=$booking->status; $booking->update(['status'=>'CANCELLED','cancelled_by'=>$request->user()->id,'cancelled_at'=>now(),'cancellation_reason'=>$data['cancellation_reason'],'updated_by'=>$request->user()->id]);
        AuditService::log($request,'booking.cancelled',$booking,['status'=>$old],['status'=>'CANCELLED','reason'=>$data['cancellation_reason']]);
        return back()->with('status','Booking cancelled. Historical data has been preserved.');
    }

    private function validatedQuickStart(Request $request, Company $company): array
    {
        $allowedBranches=$this->allowedBranches($request,$company->id);
        $allowedIds=$allowedBranches->pluck('id')->map(fn($v)=>(int)$v)->all();

        $primaryId=(int)($request->user()->primaryBranch()?->id ?? 0);
        $defaultBranchId=in_array($primaryId,$allowedIds,true)
            ? $primaryId
            : (int)($allowedBranches->first()?->id ?? 0);

        if (!$defaultBranchId) {
            throw ValidationException::withMessages([
                'branch_id'=>'No active branch is available for this user.'
            ]);
        }

        $data=$request->validate([
            'booking_type'=>['required','in:GENERAL,AIR_ONLY,HOTEL_ONLY,VISA_ONLY,TRANSPORT_ONLY,UMRAH,GROUP,CORPORATE'],
            'customer_party_id'=>['required','integer','exists:parties,id'],

            // Smart defaults are normally hidden. They remain optionally editable
            // from "Change defaults" without forcing staff through a full header.
            'booking_date'=>['nullable','date'],
            'branch_id'=>['nullable','integer',Rule::in($allowedIds)],
            'currency_code'=>['nullable','string','size:3','exists:currencies,code'],
        ]);

        $customer=Party::query()
            ->where('company_id',$company->id)
            ->whereKey($data['customer_party_id'])
            ->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','CUSTOMER')->where('is_active',true))
            ->exists();

        if (!$customer) {
            throw ValidationException::withMessages([
                'customer_party_id'=>'Select an active Customer party belonging to this company.'
            ]);
        }

        return [
            'booking_type'=>$data['booking_type'],
            'booking_date'=>$data['booking_date'] ?? now()->format('Y-m-d'),
            'branch_id'=>(int)($data['branch_id'] ?? $defaultBranchId),
            'customer_party_id'=>(int)$data['customer_party_id'],
            'agent_party_id'=>null,
            'salesperson_staff_id'=>null,
            'customer_reference'=>null,
            'currency_code'=>strtoupper((string)($data['currency_code'] ?? $company->base_currency)),
            'travel_start_date'=>null,
            'travel_end_date'=>null,
            'notes'=>null,
        ];
    }

    private function validatedHeader(Request $request, Company $company): array
    {
        $allowed=$this->allowedBranches($request,$company->id)->pluck('id')->all();
        $data=$request->validate([
            'booking_type'=>['required','in:GENERAL,AIR_ONLY,HOTEL_ONLY,VISA_ONLY,TRANSPORT_ONLY,UMRAH,GROUP,CORPORATE'],'booking_date'=>['required','date'],'branch_id'=>['required','integer',Rule::in($allowed)],
            'customer_party_id'=>['required','integer','exists:parties,id'],'agent_party_id'=>['nullable','integer','exists:parties,id'],'salesperson_staff_id'=>['nullable','integer','exists:staff,id'],
            'customer_reference'=>['nullable','string','max:120'],'currency_code'=>['required','string','size:3','exists:currencies,code'],
            'travel_start_date'=>['nullable','date'],'travel_end_date'=>['nullable','date','after_or_equal:travel_start_date'],'notes'=>['nullable','string','max:4000'],
        ]);
        $customer=Party::query()->where('company_id',$company->id)->whereKey($data['customer_party_id'])->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role','CUSTOMER')->where('is_active',true))->exists();
        if(!$customer)throw ValidationException::withMessages(['customer_party_id'=>'Select an active Customer party belonging to this company.']);
        if(!empty($data['agent_party_id'])){
            $agent=Party::query()->where('company_id',$company->id)->whereKey($data['agent_party_id'])->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role','AGENT')->where('is_active',true))->exists();
            if(!$agent)throw ValidationException::withMessages(['agent_party_id'=>'Select an active Agent/Service Partner party belonging to this company.']);
        }
        if(!empty($data['salesperson_staff_id'])){
            $staff=Staff::query()->where('company_id',$company->id)->whereKey($data['salesperson_staff_id'])->where('is_active',true)->exists();
            if(!$staff)throw ValidationException::withMessages(['salesperson_staff_id'=>'Select an active staff member from this company.']);
        }
        return $data;
    }

    private function formData(Request $request, Company $company, ?Booking $booking, bool $includeEditorData=true): array
    {
        $customers=Party::query()->where('company_id',$company->id)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role','CUSTOMER')->where('is_active',true))->orderBy('display_name')->get();
        $agents=Party::query()->where('company_id',$company->id)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role','AGENT')->where('is_active',true))->orderBy('display_name')->get();
        $staff=Staff::query()->where('company_id',$company->id)->where('is_active',true)->orderBy('name')->get();
        $products=$includeEditorData
            ? ProductService::query()->where('company_id',$company->id)->where('is_active',true)->orderBy('category')->orderBy('name')->get()
            : collect();
        $currencies=DB::table('currencies')->where('is_active',true)->orderBy('code')->get();
        return ['company'=>$company,'branches'=>$this->allowedBranches($request,$company->id),'customers'=>$customers,'agents'=>$agents,'staff'=>$staff,'products'=>$products,'currencies'=>$currencies,'booking'=>$booking];
    }

    private function validateCompleteness(Booking $booking): void
    {
        $activePassengerIds=$booking->activePassengers()->pluck('id');
        if($activePassengerIds->isEmpty())throw ValidationException::withMessages(['booking'=>'Add at least one active passenger before submitting or confirming.']);

        $services=$booking->activeServices()->with(['product','passengers'])->get();
        if($services->isEmpty())throw ValidationException::withMessages(['booking'=>'Add at least one active service before submitting or confirming.']);

        $errors=[];
        $hotelStayNo=0;

        foreach($services as $service){
            $label=$service->product?->name ?: $service->description;
            $category=strtoupper((string)$service->product?->category);

            if($category==='HOTEL'){
                $hotelStayNo++;
            }
            $activeLinked=$service->passengers->where('is_active',true);
            $mode=$service->passenger_link_mode_snapshot;
            $basis=$service->pricing_basis_snapshot;

            if(in_array($mode,['REQUIRED','MULTIPLE'],true) && $activeLinked->isEmpty()){
                $errors[]=$label.': link at least one active passenger.';
                continue;
            }

            if($basis==='PER_PERSON'){
                if($activeLinked->isEmpty()){
                    $errors[]=$label.': PER_PERSON pricing requires passenger links.';
                    continue;
                }
                $expected=(float)$activeLinked->count();
                if(abs((float)$service->quantity-$expected)>0.0001){
                    $errors[]=$label.': quantity must equal linked passenger count ('.$activeLinked->count().'). Edit the service to recalculate it.';
                }
            }

            if($category==='AIR_TICKET'){
                $ticketPassengerIds=AirTicketDetail::query()->where('booking_service_id',$service->id)->pluck('booking_passenger_id')->unique()->map(fn($v)=>(int)$v);
                foreach($activeLinked as $passenger){
                    if(!$ticketPassengerIds->contains((int)$passenger->id))$errors[]=$label.': create at least one ticket row for '.$passenger->fullName().'.';
                }
            }
            if($category==='HOTEL'){
                $hotelLabel='Hotel Stay '.str_pad((string)$hotelStayNo,2,'0',STR_PAD_LEFT);
                $detail=HotelBookingDetail::query()->where('booking_service_id',$service->id)->first();

                if(!$detail){
                    $errors[]=$hotelLabel.': complete this Hotel Stay or remove it before confirmation.';
                }else{
                    $rooms=HotelBookingRoom::query()->where('hotel_booking_detail_id',$detail->id)->get();

                    if($rooms->isEmpty()){
                        $errors[]=$hotelLabel.': complete the Room Plan or remove this Hotel Stay.';
                    }else{
                        $capacity=(int)$rooms->sum(function($r): int {
                            $stored=(int)($r->total_capacity ?: 0);
                            if($stored>0)return $stored;

                            $type=strtoupper((string)$r->room_type);
                            $perRoom=match($type){
                                'SINGLE'=>1,
                                'DOUBLE'=>2,
                                'TRIPLE'=>3,
                                'QUAD'=>4,
                                'SHARING'=>max(1,(int)($r->capacity_per_room ?: 1)),
                                default=>max(1,(int)($r->capacity_per_room ?: 1)),
                            };

                            return max(1,(int)($r->room_count ?: 1))*$perRoom;
                        });
                        $bookingPassengerCount=$activePassengerIds->count();
                        if($capacity<$bookingPassengerCount){
                            $errors[]=$hotelLabel.': room capacity is '.$capacity.' pax for '.$bookingPassengerCount.' active booking passenger(s).';
                        }
                    }
                }
            }
        }

        if($errors)throw ValidationException::withMessages(['booking'=>implode(' ', $errors)]);
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
}
