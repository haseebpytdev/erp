<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\BookingService;
use App\Models\CommissionAllocation;
use App\Models\Hotel;
use App\Models\HotelBookingDetail;
use App\Models\HotelBookingRoom;
use App\Models\Party;
use App\Models\Staff;
use App\Services\AuditService;
use App\Services\HotelAccommodationLogic;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class HotelWorkspaceController extends Controller
{
    public function update(Request $request, Booking $booking, BookingService $service): RedirectResponse
    {
        $this->assertHotelService($booking,$service);
        abort_unless($booking->isEditable(),422,'Booking must be editable before Hotel details can be changed.');
        abort_if($service->status==='CANCELLED',422,'Cancelled Hotel services cannot be edited.');

        $data=$request->validate([
            'hotel_id'=>['nullable','integer','exists:hotels,id'],
            'new_hotel_name'=>['nullable','string','max:180'],
            'new_hotel_city_code'=>['nullable','string','size:3'],

            'hotel_status'=>['required','in:REQUESTED,CONFIRMED,CANCELLED'],
            'confirmation_number'=>['nullable','string','max:100'],
            'check_in'=>['required','date'],
            'check_out'=>['required','date','after:check_in'],

            // Exactly one Room Plan per Hotel Stay.
            'room_type'=>['required','in:SINGLE,DOUBLE,TRIPLE,QUAD,SHARING'],
            'room_count'=>['required','integer','min:1','max:100'],
            'sharing_capacity'=>['nullable','integer','min:1','max:50'],
            'meal_plan'=>['nullable','string','max:80'],
            'room_notes'=>['nullable','string','max:1000'],

            'customer_price'=>['required','numeric','min:0'],
            'customer_discount_type'=>['required','in:NONE,FIXED,PERCENT'],
            'customer_discount_value'=>['required','numeric','min:0'],

            'supplier_vendor_party_id'=>['nullable','integer'],
            'supplier_price'=>['required','numeric','min:0'],
            'refresh_fx_rate'=>['nullable','boolean'],

            'agent_party_id'=>['nullable','integer'],
            'agent_commission'=>['required','numeric','min:0'],

            'salesperson_staff_id'=>['nullable','integer'],
            'salesperson_commission'=>['required','numeric','min:0'],

            'special_requests'=>['nullable','string','max:2000'],
        ]);

        $newHotelName=trim((string)($data['new_hotel_name']??''));
        $newHotelCityCode=strtoupper(trim((string)($data['new_hotel_city_code']??'')));
        $travelCities=$this->travelCityMap();

        if (!$data['hotel_id'] && $newHotelName==='') {
            throw ValidationException::withMessages([
                'hotel_id'=>'Select an existing Hotel or choose + New Hotel.'
            ]);
        }

        if ($newHotelName!=='') {
            $cityMeta=$travelCities[$newHotelCityCode]??null;

            if(!$cityMeta){
                throw ValidationException::withMessages([
                    'new_hotel_city_code'=>'Select a valid Travel City / IATA code.'
                ]);
            }

            $newHotelCity=trim((string)($cityMeta['city']??''));
            $newHotelCountry=strtoupper(trim((string)($cityMeta['country_code']??'')));

            $hotel=Hotel::query()
                ->where('company_id',$booking->company_id)
                ->whereRaw('UPPER(name)=?', [strtoupper($newHotelName)])
                ->whereRaw('UPPER(city)=?', [strtoupper($newHotelCity)])
                ->first();

            if(!$hotel){
                $hotelPayload=[
                    'company_id'=>$booking->company_id,
                    'code'=>$this->uniqueHotelCode($booking->company_id,$newHotelName),
                    'name'=>$newHotelName,
                    'city'=>$newHotelCity,
                    'country_code'=>$newHotelCountry,
                    'default_vendor_party_id'=>$data['supplier_vendor_party_id']?:null,
                    'is_active'=>true,
                    'notes'=>'Created directly from Hotel Booking Workspace. Travel city: '.$newHotelCityCode,
                    'created_by'=>$request->user()->id,
                    'updated_by'=>$request->user()->id,
                ];

                if (Schema::hasColumn('hotels','city_iata_code')) {
                    $hotelPayload['city_iata_code']=$newHotelCityCode;
                }

                $hotel=Hotel::query()->create($hotelPayload);

                AuditService::log(
                    $request,
                    'hotel.master.created_from_booking',
                    $hotel,
                    [],
                    array_merge($hotel->toArray(),['travel_city_code'=>$newHotelCityCode])
                );
            }
        }else{
            $hotel=Hotel::query()
                ->where('company_id',$booking->company_id)
                ->whereKey($data['hotel_id'])
                ->where('is_active',true)
                ->first();

            if(!$hotel){
                throw ValidationException::withMessages([
                    'hotel_id'=>'Select an active Hotel belonging to this company.'
                ]);
            }

            // Touch the Hotel through Eloquent so the Hotel model's saving hook
            // synchronizes city_iata_code + country_code from Travel City Master.
            $hotel->updated_by=$request->user()->id;
            $hotel->save();
            $hotel->refresh();
        }

        if (!empty($data['supplier_vendor_party_id']) && !$this->partyHasRole((int)$data['supplier_vendor_party_id'],$booking->company_id,'VENDOR')) {
            throw ValidationException::withMessages(['supplier_vendor_party_id'=>'Selected supplier is not an active Vendor party.']);
        }

        if (!empty($data['agent_party_id']) && !$this->partyHasRole((int)$data['agent_party_id'],$booking->company_id,'AGENT')) {
            throw ValidationException::withMessages(['agent_party_id'=>'Selected beneficiary is not an active Agent party.']);
        }

        if (!empty($data['salesperson_staff_id']) && !Staff::query()
            ->where('company_id',$booking->company_id)
            ->whereKey($data['salesperson_staff_id'])
            ->where('is_active',true)
            ->exists()) {
            throw ValidationException::withMessages(['salesperson_staff_id'=>'Selected salesperson is not active.']);
        }

        $nights=Carbon::parse($data['check_in'])->diffInDays(Carbon::parse($data['check_out']));
        if ($nights<1) {
            throw ValidationException::withMessages(['check_out'=>'Hotel stay must contain at least one night.']);
        }

        $activePassengerCount=BookingPassenger::query()
            ->where('booking_id',$booking->id)
            ->where('is_active',true)
            ->count();

        if ($activePassengerCount<1) {
            throw ValidationException::withMessages(['rooms'=>'Add at least one active Booking Passenger before saving a Hotel stay.']);
        }

        $roomType=strtoupper((string)$data['room_type']);
        $roomCount=max(1,(int)$data['room_count']);

        if ($roomType==='SHARING' && empty($data['sharing_capacity'])) {
            throw ValidationException::withMessages([
                'sharing_capacity'=>'Enter Sharing capacity per room.'
            ]);
        }

        $totalRooms=$roomCount;
        $occupancy=HotelAccommodationLogic::resolve(
            $activePassengerCount,
            $roomCount,
            $roomType,
            isset($data['sharing_capacity']) ? (int)$data['sharing_capacity'] : null
        );
        $capacityPerRoom=(int)$occupancy['capacity_per_room'];
        $totalCapacity=(int)$occupancy['total_capacity'];
        $withBedCount=(int)$occupancy['with_bed'];
        $noBedCount=(int)$occupancy['no_bed'];
        $occupancySnapshot=(string)$occupancy['snapshot'];

        $roomPlan=[
            'room_type'=>$roomType,
            'room_count'=>$roomCount,
            'capacity_per_room'=>$capacityPerRoom,
            'total_capacity'=>$totalCapacity,
            'meal_plan'=>trim((string)($data['meal_plan']??''))?:null,
            'notes'=>trim((string)($data['room_notes']??''))?:null,
        ];

        $customerPrice=round((float)$data['customer_price'],2);
        $discountValue=(float)$data['customer_discount_value'];

        if ($data['customer_discount_type']==='PERCENT' && $discountValue>100) {
            throw ValidationException::withMessages([
                'customer_discount_value'=>'Percentage discount cannot exceed 100%.'
            ]);
        }

        $discount=match($data['customer_discount_type']) {
            'FIXED' => round($discountValue,2),
            'PERCENT' => round($customerPrice*$discountValue/100,2),
            default => 0,
        };

        $customerNet=round($customerPrice-$discount,2);
        if ($customerNet<0) {
            throw ValidationException::withMessages([
                'customer_discount_value'=>'Discount cannot make Customer Price negative.'
            ]);
        }

        abort_unless(
            Schema::hasTable('exchange_rates') && Schema::hasColumn('hotel_booking_details','supplier_cost_foreign'),
            422,
            'Foreign supplier-cost upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache.'
        );
        $supplierForeign=round((float)$data['supplier_price'],2); // Hotel vendor cost is entered in SAR.
        $existingFxDetail=HotelBookingDetail::query()->where('booking_service_id',$service->id)->first();
        $rateDate=$booking->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d');
        $baseCurrency=strtoupper((string)($booking->currency_code ?: 'PKR'));
        // Existing pre-FX Hotel stays may contain a valid PKR supplier cost but no SAR snapshot.
        // If staff saves another field before entering the new SAR cost, preserve that historical PKR cost.
        $preserveLegacyBase=$existingFxDetail
            && $existingFxDetail->supplier_cost_foreign===null
            && (float)($existingFxDetail->net_supplier_cost ?? 0)>0
            && $supplierForeign<=0
            && !$request->boolean('refresh_fx_rate');
        $reuseFx=$existingFxDetail && (float)($existingFxDetail->supplier_fx_rate ?? 0)>0 && !$request->boolean('refresh_fx_rate');
        if($preserveLegacyBase){
            $supplierFxRate=0.0;
            $supplierFxRateDate=null;
            $supplierPrice=round((float)$existingFxDetail->net_supplier_cost,2);
            $supplierForeignStored=null;
        }else{
            if($reuseFx){
                $supplierFxRate=(float)$existingFxDetail->supplier_fx_rate;
                $supplierFxRateDate=$existingFxDetail->supplier_fx_rate_date?->format('Y-m-d') ?: $rateDate;
            }elseif($supplierForeign>0){
                $quote=(new ExchangeRateService())->require((int)$booking->company_id,'SAR',$baseCurrency,$rateDate,'supplier_price');
                $supplierFxRate=(float)$quote['rate'];
                $supplierFxRateDate=$quote['effective_date'];
            }else{
                $quote=(new ExchangeRateService())->resolve((int)$booking->company_id,'SAR',$baseCurrency,$rateDate);
                $supplierFxRate=(float)($quote['rate'] ?? 0);
                $supplierFxRateDate=$quote['effective_date'] ?? null;
            }
            $supplierPrice=round($supplierForeign*$supplierFxRate,2); // base/PKR equivalent used by existing margin logic.
            $supplierForeignStored=$supplierForeign;
        }
        $agentCommission=round((float)$data['agent_commission'],2);
        $salespersonCommission=round((float)$data['salesperson_commission'],2);

        $grossMargin=round($customerNet-$supplierPrice,2);
        $netContribution=round($grossMargin-$agentCommission-$salespersonCommission,2);

        DB::transaction(function() use(
            $request,$booking,$service,$hotel,$data,$roomPlan,$nights,
            $totalRooms,$totalCapacity,$activePassengerCount,$withBedCount,$noBedCount,$occupancySnapshot,
            $customerPrice,$discount,$customerNet,$supplierPrice,$supplierForeign,$supplierForeignStored,$supplierFxRate,$supplierFxRateDate,
            $agentCommission,$salespersonCommission,$grossMargin,$netContribution
        ): void {
            $oldDetail=HotelBookingDetail::query()
                ->where('booking_service_id',$service->id)
                ->first();

            $old=$oldDetail?->toArray() ?? [];

            $detail=HotelBookingDetail::query()->updateOrCreate(
                ['booking_service_id'=>$service->id],
                [
                    'hotel_id'=>$hotel->id,
                    'confirmation_number'=>trim((string)($data['confirmation_number']??''))?:null,
                    'hotel_status'=>$data['hotel_status'],
                    'room_type'=>$roomPlan['room_type'],
                    'rooms'=>$totalRooms,
                    'check_in'=>$data['check_in'],
                    'check_out'=>$data['check_out'],
                    'nights'=>$nights,
                    'meal_plan'=>$roomPlan['meal_plan'],
                    'occupancy'=>$occupancySnapshot,
                    'pricing_basis'=>'PER_BOOKING',

                    // Backward-compatible commercial columns:
                    'customer_base_rate'=>$customerPrice,
                    'customer_base_total'=>$customerPrice,
                    'customer_service_markup'=>0,
                    'customer_discount_type'=>$data['customer_discount_type']==='NONE'?'FIXED':$data['customer_discount_type'],
                    'customer_discount_value'=>$data['customer_discount_type']==='NONE'?0:(float)$data['customer_discount_value'],
                    'customer_discount'=>$discount,
                    'customer_sell_total'=>$customerNet,

                    'supplier_vendor_party_id'=>$data['supplier_vendor_party_id']?:null,
                    'supplier_base_rate'=>$supplierPrice,
                    'supplier_base_total'=>$supplierPrice,
                    'supplier_charges'=>0,
                    'supplier_discount_type'=>'FIXED',
                    'supplier_discount_value'=>0,
                    'supplier_discount'=>0,
                    'supplier_incentive'=>0,
                    'net_supplier_cost'=>$supplierPrice,
                    'supplier_cost_status'=>'ESTIMATED',
                    'supplier_reference'=>null,
                    'supplier_currency_code'=>'SAR',
                    'supplier_cost_foreign'=>$supplierForeignStored,
                    'supplier_fx_rate'=>$supplierFxRate>0?$supplierFxRate:null,
                    'supplier_fx_rate_date'=>$supplierFxRateDate,
                    'supplier_cost_base'=>$supplierPrice,

                    'special_requests'=>$data['special_requests']??null,
                    'created_by'=>$old['created_by']??$request->user()->id,
                    'updated_by'=>$request->user()->id,
                ]
            );

            $detail->roomsList()->delete();

            // One Hotel Stay always owns exactly one Room Plan row.
            HotelBookingRoom::query()->create([
                'hotel_booking_detail_id'=>$detail->id,
                'room_no'=>1,
                'room_label'=>'Room Plan',
                'room_type'=>$roomPlan['room_type'],
                'room_count'=>$roomPlan['room_count'],
                'capacity_per_room'=>$roomPlan['capacity_per_room'],
                'total_capacity'=>$roomPlan['total_capacity'],
                'occupancy'=>$occupancySnapshot,
                'meal_plan'=>$roomPlan['meal_plan'],
                'notes'=>$roomPlan['notes'],
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ]);

            // Hotel stays inherit all active booking passengers.
            $activeIds=BookingPassenger::query()
                ->where('booking_id',$booking->id)
                ->where('is_active',true)
                ->pluck('id')
                ->all();

            $service->passengers()->sync($activeIds);

            $service->update([
                'description'=>trim(implode(' · ',array_filter([
                    $hotel->name,
                    $hotel->city,
                    $totalRooms.' room'.($totalRooms===1?'':'s'),
                    $nights.' night'.($nights===1?'':'s'),
                ]))),
                'service_from'=>$data['check_in'],
                'service_to'=>$data['check_out'],
                'quantity'=>1,
                'unit_price'=>$customerNet,
                'line_total'=>$customerNet,
                'pricing_basis_snapshot'=>'PER_BOOKING',
                'status'=>'DRAFT',
                'updated_by'=>$request->user()->id,
            ]);

            $this->syncSimpleCommission(
                $request,$booking,$service,$detail,'AGENT',
                (int)($data['agent_party_id']?:($booking->agent_party_id?:0)),
                null,
                $agentCommission
            );

            $this->syncSimpleCommission(
                $request,$booking,$service,$detail,'SALESPERSON',
                null,
                (int)($data['salesperson_staff_id']?:($booking->salesperson_staff_id?:0)),
                $salespersonCommission
            );

            AuditService::log(
                $request,
                'booking.hotel_workspace.compact_saved',
                $detail,
                $old,
                array_merge($detail->fresh()->toArray(),[
                    'active_booking_passengers'=>$activePassengerCount,
                    'total_room_capacity'=>$totalCapacity,
                    'passengers_with_bed'=>$withBedCount,
                    'passengers_no_bed'=>$noBedCount,
                    'gross_margin'=>$grossMargin,
                    'net_contribution'=>$netContribution,
                    'supplier_cost_foreign'=>$supplierForeignStored,
                    'supplier_fx_rate'=>$supplierFxRate,
                    'supplier_fx_rate_date'=>$supplierFxRateDate,
                    'supplier_cost_base'=>$supplierPrice,
                ])
            );
        });

        return back()
            ->with('status','Hotel stay saved successfully.')
            ->with('hotel_saved_service_id',$service->id);
    }

    private function syncSimpleCommission(
        Request $request,
        Booking $booking,
        BookingService $service,
        HotelBookingDetail $detail,
        string $type,
        ?int $agentId,
        ?int $staffId,
        float $amount
    ): void {
        $existing=CommissionAllocation::query()
            ->where('hotel_booking_detail_id',$detail->id)
            ->where('commission_type',$type)
            ->first();

        $beneficiary=$type==='AGENT'?$agentId:$staffId;

        if (!$beneficiary || $amount<=0) {
            if ($existing) {
                $existing->update([
                    'status'=>'CANCELLED',
                    'commission_amount'=>0,
                    'rate'=>0,
                    'updated_by'=>$request->user()->id,
                    'cancelled_by'=>$request->user()->id,
                    'cancelled_at'=>now(),
                    'cancellation_reason'=>'Removed from compact Hotel Stay.',
                ]);
            }
            return;
        }

        CommissionAllocation::query()->updateOrCreate(
            [
                'hotel_booking_detail_id'=>$detail->id,
                'commission_type'=>$type,
            ],
            [
                'company_id'=>$booking->company_id,
                'branch_id'=>$booking->branch_id,
                'booking_id'=>$booking->id,
                'booking_service_id'=>$service->id,
                'air_ticket_detail_id'=>null,
                'sales_invoice_id'=>null,
                'sales_invoice_line_id'=>null,
                'agent_party_id'=>$type==='AGENT'?$agentId:null,
                'salesperson_staff_id'=>$type==='SALESPERSON'?$staffId:null,
                'basis'=>'FIXED',
                'rate'=>$amount,
                'base_amount'=>0,
                'commission_amount'=>$amount,
                'currency_code'=>$booking->currency_code,
                'status'=>'ESTIMATED',
                'notes'=>'Compact Hotel Stay forecast commission.',
                'created_by'=>$existing?->created_by ?: $request->user()->id,
                'updated_by'=>$request->user()->id,
                'approved_by'=>null,
                'approved_at'=>null,
                'cancelled_by'=>null,
                'cancelled_at'=>null,
                'cancellation_reason'=>null,
            ]
        );
    }

    private function travelCityMap(): array
    {
        if (Schema::hasTable('travel_city_codes')) {
            return DB::table('travel_city_codes')
                ->where('is_active',1)
                ->get(['code','city','country_code'])
                ->mapWithKeys(fn($row)=>[
                    strtoupper((string)$row->code)=>[
                        'city'=>(string)$row->city,
                        'country_code'=>strtoupper((string)$row->country_code),
                    ],
                ])
                ->all();
        }

        return config('travel_cities.cities',[]);
    }

    private function uniqueHotelCode(int $companyId,string $name): string
    {
        $base=Str::upper(Str::slug($name,''));
        $base=substr($base ?: 'HOTEL',0,18);

        do {
            $code='HTL-'.$base.'-'.Str::upper(Str::random(4));
        } while (
            Hotel::query()
                ->where('company_id',$companyId)
                ->where('code',$code)
                ->exists()
        );

        return substr($code,0,30);
    }

    private function partyHasRole(int $partyId,int $companyId,string $role): bool
    {
        return Party::query()
            ->where('company_id',$companyId)
            ->whereKey($partyId)
            ->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role',$role)->where('is_active',true))
            ->exists();
    }

    private function assertHotelService(Booking $booking,BookingService $service): void
    {
        abort_unless((int)$service->booking_id===(int)$booking->id,404);
        $service->loadMissing('product');
        abort_unless(strtoupper((string)$service->product?->category)==='HOTEL',404);
    }
}
