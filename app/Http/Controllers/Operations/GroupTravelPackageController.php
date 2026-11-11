<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\GroupTravelPackage;
use App\Models\GroupTravelPackagePassengerPrice;
use App\Models\Party;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GroupTravelPackageController extends Controller
{
    public function edit(Request $request, Booking $booking): View
    {
        $this->assertEligible($booking);
        $booking->loadMissing(['customer','branch','passengers','services.product']);

        $installed=$this->installed();
        $package=$installed
            ? GroupTravelPackage::query()->with(['vendor','passengerPrices'])->where('booking_id',$booking->id)->first()
            : null;

        $activePassengers=$booking->passengers->where('is_active',true)->sortBy('passenger_no')->values();
        $overrides=$package ? $package->passengerPrices->keyBy('booking_passenger_id') : collect();
        $vendors=$this->vendors((int)$booking->company_id);
        $summary=$this->summary($activePassengers,$package,$overrides,$booking->currency_code ?: 'PKR');
        $componentCounts=$this->componentCounts($booking);

        return view('operations.bookings.group-travel-package',compact(
            'booking','installed','package','activePassengers','overrides','vendors','summary','componentCounts'
        ));
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        $this->assertEligible($booking);
        abort_unless($this->installed(),409,'Run System → Health & Updates → Safe Database Upgrade first.');
        $booking->loadMissing('passengers');

        $currency=strtoupper((string)($booking->currency_code ?: 'PKR'));
        $data=$request->validate([
            'package_type'=>['required',Rule::in(['UMRAH','TOUR','GROUP_HOLIDAY','CUSTOM_GROUP'])],
            'package_name'=>['required','string','max:180'],
            'vendor_party_id'=>['required','integer','exists:parties,id'],
            'vendor_reference'=>['nullable','string','max:100'],
            'adult_vendor_cost'=>['required','numeric','min:0'],
            'adult_customer_price'=>['required','numeric','min:0'],
            'child_vendor_cost'=>['required','numeric','min:0'],
            'child_customer_price'=>['required','numeric','min:0'],
            'infant_vendor_cost'=>['required','numeric','min:0'],
            'infant_customer_price'=>['required','numeric','min:0'],
            'notes'=>['nullable','string','max:5000'],
            'overrides'=>['nullable','array'],
            'overrides.*.vendor_cost'=>['nullable','numeric','min:0'],
            'overrides.*.customer_price'=>['nullable','numeric','min:0'],
        ]);

        $this->assertVendor((int)$data['vendor_party_id'],(int)$booking->company_id);

        $activePassengers=$booking->passengers->where('is_active',true)->sortBy('passenger_no')->values();
        if($activePassengers->isEmpty()){
            throw ValidationException::withMessages(['package_name'=>'Add at least one active passenger before saving package commercial rates.']);
        }

        $userId=$request->user()?->id;
        $oldPackage=GroupTravelPackage::query()->where('booking_id',$booking->id)->first();
        $old=$oldPackage?->toArray() ?? [];
        $package=null;

        DB::transaction(function() use($data,$booking,$currency,$activePassengers,$userId,$oldPackage,&$package): void {
            $package=GroupTravelPackage::query()->updateOrCreate(
                ['booking_id'=>$booking->id],
                [
                    'package_type'=>$data['package_type'],
                    'package_name'=>trim((string)$data['package_name']),
                    'vendor_party_id'=>(int)$data['vendor_party_id'],
                    'vendor_reference'=>trim((string)($data['vendor_reference']??'')) ?: null,
                    'currency_code'=>$currency,
                    // Group-package visa is intentionally fixed to issued/included to reduce duplicate workflow.
                    'visa_included'=>true,
                    'visa_status'=>'ISSUED',
                    'adult_vendor_cost'=>(float)$data['adult_vendor_cost'],
                    'adult_customer_price'=>(float)$data['adult_customer_price'],
                    'child_vendor_cost'=>(float)$data['child_vendor_cost'],
                    'child_customer_price'=>(float)$data['child_customer_price'],
                    'infant_vendor_cost'=>(float)$data['infant_vendor_cost'],
                    'infant_customer_price'=>(float)$data['infant_customer_price'],
                    'notes'=>trim((string)($data['notes']??'')) ?: null,
                    'updated_by'=>$userId,
                    'created_by'=>$oldPackage?->created_by ?: $userId,
                ]
            );

            $submitted=(array)($data['overrides']??[]);
            $activeIds=[];
            foreach($activePassengers as $passenger){
                $pid=(int)$passenger->id;
                $activeIds[]=$pid;
                $row=(array)($submitted[$pid]??[]);
                $buy=array_key_exists('vendor_cost',$row) && $row['vendor_cost']!==null && $row['vendor_cost']!=='' ? (float)$row['vendor_cost'] : null;
                $sell=array_key_exists('customer_price',$row) && $row['customer_price']!==null && $row['customer_price']!=='' ? (float)$row['customer_price'] : null;

                if($buy===null && $sell===null){
                    GroupTravelPackagePassengerPrice::query()
                        ->where('group_travel_package_id',$package->id)
                        ->where('booking_passenger_id',$pid)
                        ->delete();
                    continue;
                }

                GroupTravelPackagePassengerPrice::query()->updateOrCreate(
                    ['group_travel_package_id'=>$package->id,'booking_passenger_id'=>$pid],
                    [
                        'pax_type_snapshot'=>strtoupper((string)$passenger->pax_type),
                        'vendor_cost_override'=>$buy,
                        'customer_price_override'=>$sell,
                        'updated_by'=>$userId,
                        'created_by'=>$userId,
                    ]
                );
            }

            GroupTravelPackagePassengerPrice::query()
                ->where('group_travel_package_id',$package->id)
                ->whereNotIn('booking_passenger_id',$activeIds)
                ->delete();

            $overrides=GroupTravelPackagePassengerPrice::query()
                ->where('group_travel_package_id',$package->id)
                ->get()->keyBy('booking_passenger_id');
            $summary=$this->summary($activePassengers,$package,$overrides,$currency);
            $package->update([
                'total_vendor_cost'=>$summary['vendor_total'],
                'total_customer_price'=>$summary['sale_total'],
                'gross_margin'=>$summary['margin'],
                'updated_by'=>$userId,
            ]);
        });

        $fresh=$package->fresh()->toArray();
        if(class_exists(AuditService::class)){
            AuditService::log($request,'booking.group_package.saved',$package,$old,$fresh);
        }

        return redirect()->to(route('operations.bookings.show',$booking).'#package-services')->with('status','Group Travel Package saved. Continue with Flights, Hotels and Transport. Visa is ISSUED / INCLUDED automatically.');
    }

    private function installed(): bool
    {
        return Schema::hasTable('group_travel_packages') && Schema::hasTable('group_travel_package_passenger_prices');
    }

    private function assertEligible(Booking $booking): void
    {
        abort_unless(in_array(strtoupper((string)$booking->booking_type),['GROUP','UMRAH'],true),404);
    }

    private function vendors(int $companyId): Collection
    {
        if(!Schema::hasTable('parties')) return collect();
        return Party::query()
            ->where('company_id',$companyId)
            ->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))
            ->orderBy('display_name')
            ->get();
    }

    private function assertVendor(int $partyId,int $companyId): void
    {
        $ok=Party::query()
            ->whereKey($partyId)
            ->where('company_id',$companyId)
            ->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))
            ->exists();
        if(!$ok){
            throw ValidationException::withMessages(['vendor_party_id'=>'Selected supplier must be an active Vendor party in this company.']);
        }
    }

    private function componentCounts(Booking $booking): array
    {
        $counts=['AIR_TICKET'=>0,'HOTEL'=>0,'TRANSPORT'=>0,'VISA'=>0];
        foreach($booking->services->where('status','!=','CANCELLED') as $service){
            $category=strtoupper((string)$service->product?->category);
            if(array_key_exists($category,$counts)) $counts[$category]++;
        }
        // Visa workflow is implicit for Group Travel Package: no separate Visa service is required.
        $counts['VISA_INCLUDED']=1;
        return $counts;
    }

    private function summary(Collection $passengers, ?GroupTravelPackage $package, Collection $overrides, string $currency): array
    {
        $rows=[];
        $vendorTotal=0.0;
        $saleTotal=0.0;
        $counts=['ADULT'=>0,'CHILD'=>0,'INFANT'=>0,'OTHER'=>0];

        foreach($passengers as $passenger){
            $type=strtoupper((string)$passenger->pax_type);
            if(!isset($counts[$type])) $type='OTHER';
            $counts[$type]++;
            $override=$overrides->get($passenger->id);
            $buy=$override?->vendor_cost_override;
            $sell=$override?->customer_price_override;

            if($buy===null){
                $buy=match($type){
                    'CHILD'=>(float)($package?->child_vendor_cost ?? 0),
                    'INFANT'=>(float)($package?->infant_vendor_cost ?? 0),
                    default=>(float)($package?->adult_vendor_cost ?? 0),
                };
            }
            if($sell===null){
                $sell=match($type){
                    'CHILD'=>(float)($package?->child_customer_price ?? 0),
                    'INFANT'=>(float)($package?->infant_customer_price ?? 0),
                    default=>(float)($package?->adult_customer_price ?? 0),
                };
            }
            $vendorTotal+=(float)$buy;
            $saleTotal+=(float)$sell;
            $rows[(int)$passenger->id]=['vendor_cost'=>(float)$buy,'customer_price'=>(float)$sell,'margin'=>(float)$sell-(float)$buy];
        }

        return [
            'currency'=>$currency,
            'counts'=>$counts,
            'rows'=>$rows,
            'vendor_total'=>round($vendorTotal,2),
            'sale_total'=>round($saleTotal,2),
            'margin'=>round($saleTotal-$vendorTotal,2),
            'margin_percent'=>$saleTotal>0 ? round((($saleTotal-$vendorTotal)/$saleTotal)*100,2) : 0,
        ];
    }
}
