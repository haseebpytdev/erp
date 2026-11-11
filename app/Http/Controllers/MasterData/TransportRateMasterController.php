<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\TransportRate;
use App\Models\TransportRateCard;
use App\Models\TransportRoute;
use App\Models\TransportVehicleType;
use App\Models\TravelVoucherPartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TransportRateMasterController extends Controller
{
    public function storeVehicleType(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless(Schema::hasTable('transport_vehicle_types'),422,'Transport master upgrade is not installed. Run Safe Database Upgrade first.');
        $data=$request->validate([
            'code'=>['nullable','string','max:30'],
            'name'=>['required','string','max:100'],
            'min_pax'=>['nullable','integer','min:1','max:100'],
            'max_pax'=>['nullable','integer','min:1','max:100'],
            'notes'=>['nullable','string','max:500'],
        ]);
        if(($data['min_pax']??null) && ($data['max_pax']??null) && (int)$data['min_pax']>(int)$data['max_pax']){
            throw ValidationException::withMessages(['max_pax'=>'Maximum passenger capacity must be equal to or greater than minimum capacity.']);
        }
        $code=strtoupper(trim((string)($data['code']??'')));
        if($code==='') $code=strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/','-',trim($data['name'])),'0',30));
        $exists=TransportVehicleType::query()->where('company_id',$company->id)->whereRaw('UPPER(code)=?',[strtoupper($code)])->exists();
        if($exists) throw ValidationException::withMessages(['code'=>'This Vehicle Type code already exists.']);
        TransportVehicleType::query()->create([
            'company_id'=>$company->id,'code'=>$code,'name'=>trim($data['name']),
            'min_pax'=>$data['min_pax']??null,'max_pax'=>$data['max_pax']??null,
            'notes'=>trim((string)($data['notes']??''))?:null,'is_active'=>true,
            'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
        ]);
        return back()->with('status','Vehicle Type added to Travel Masters.');
    }

    public function updateVehicleType(Request $request, TransportVehicleType $vehicleType): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$vehicleType->company_id===(int)$company->id,404);
        $data=$request->validate([
            'code'=>['required','string','max:30'],
            'name'=>['required','string','max:100'],
            'min_pax'=>['nullable','integer','min:1','max:100'],
            'max_pax'=>['nullable','integer','min:1','max:100'],
            'notes'=>['nullable','string','max:500'],
            'is_active'=>['nullable','boolean'],
        ]);
        if(($data['min_pax']??null) && ($data['max_pax']??null) && (int)$data['min_pax']>(int)$data['max_pax']){
            throw ValidationException::withMessages(['max_pax'=>'Maximum passenger capacity must be equal to or greater than minimum capacity.']);
        }
        $code=strtoupper(trim((string)$data['code']));
        $exists=TransportVehicleType::query()->where('company_id',$company->id)->where('id','!=',$vehicleType->id)->whereRaw('UPPER(code)=?',[strtoupper($code)])->exists();
        if($exists) throw ValidationException::withMessages(['code'=>'This Vehicle Type code already exists.']);
        $vehicleType->update([
            'code'=>$code,'name'=>trim($data['name']),
            'min_pax'=>$data['min_pax']??null,'max_pax'=>$data['max_pax']??null,
            'notes'=>trim((string)($data['notes']??''))?:null,
            'is_active'=>$request->boolean('is_active'),
            'updated_by'=>$request->user()->id,
        ]);
        return redirect()->route('master-data.travel.index',['tab'=>'vehicle-types'])->with('status','Vehicle Type updated. Historical booking snapshots remain unchanged.');
    }

    public function toggleVehicleType(Request $request, TransportVehicleType $vehicleType): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$vehicleType->company_id===(int)$company->id,404);
        $vehicleType->update(['is_active'=>!$vehicleType->is_active,'updated_by'=>$request->user()->id]);
        return redirect()->route('master-data.travel.index',['tab'=>'vehicle-types'])->with('status','Vehicle Type '.($vehicleType->fresh()->is_active?'activated':'deactivated').'. Historical bookings are not changed.');
    }

    public function storeRoute(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless(Schema::hasTable('transport_routes'),422,'Transport master upgrade is not installed. Run Safe Database Upgrade first.');
        $data=$request->validate([
            'code'=>['nullable','string','max:40'],'name'=>['required','string','max:180'],
            'pickup_location'=>['nullable','string','max:180'],'dropoff_location'=>['nullable','string','max:180'],
            'notes'=>['nullable','string','max:500'],
        ]);
        $code=strtoupper(trim((string)($data['code']??'')));
        if($code==='') $code=strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/','-',trim($data['name'])),'0',40));
        if(TransportRoute::query()->where('company_id',$company->id)->whereRaw('UPPER(code)=?',[strtoupper($code)])->exists()){
            throw ValidationException::withMessages(['code'=>'This Transport Route code already exists.']);
        }
        TransportRoute::query()->create([
            'company_id'=>$company->id,'code'=>$code,'name'=>trim($data['name']),
            'pickup_location'=>trim((string)($data['pickup_location']??''))?:null,
            'dropoff_location'=>trim((string)($data['dropoff_location']??''))?:null,
            'notes'=>trim((string)($data['notes']??''))?:null,'is_active'=>true,
            'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
        ]);
        return back()->with('status','Transport Route added to Travel Masters.');
    }

    public function updateRoute(Request $request, TransportRoute $transportRoute): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$transportRoute->company_id===(int)$company->id,404);
        $data=$request->validate([
            'code'=>['required','string','max:40'],
            'name'=>['required','string','max:180'],
            'pickup_location'=>['nullable','string','max:180'],
            'dropoff_location'=>['nullable','string','max:180'],
            'notes'=>['nullable','string','max:500'],
            'is_active'=>['nullable','boolean'],
        ]);
        $code=strtoupper(trim((string)$data['code']));
        $exists=TransportRoute::query()->where('company_id',$company->id)->where('id','!=',$transportRoute->id)->whereRaw('UPPER(code)=?',[strtoupper($code)])->exists();
        if($exists) throw ValidationException::withMessages(['code'=>'This Transport Route code already exists.']);
        $transportRoute->update([
            'code'=>$code,'name'=>trim($data['name']),
            'pickup_location'=>trim((string)($data['pickup_location']??''))?:null,
            'dropoff_location'=>trim((string)($data['dropoff_location']??''))?:null,
            'notes'=>trim((string)($data['notes']??''))?:null,
            'is_active'=>$request->boolean('is_active'),
            'updated_by'=>$request->user()->id,
        ]);
        return redirect()->route('master-data.travel.index',['tab'=>'transport-routes'])->with('status','Transport Route updated. Historical booking snapshots remain unchanged.');
    }

    public function toggleRoute(Request $request, TransportRoute $transportRoute): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$transportRoute->company_id===(int)$company->id,404);
        $transportRoute->update(['is_active'=>!$transportRoute->is_active,'updated_by'=>$request->user()->id]);
        return redirect()->route('master-data.travel.index',['tab'=>'transport-routes'])->with('status','Transport Route '.($transportRoute->fresh()->is_active?'activated':'deactivated').'. Historical bookings are not changed.');
    }

    public function storeRateCard(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $data=$this->rateCardData($request);
        $partner=$this->transportCompany($company->id,(int)$data['transport_company_id']);

        $card=TransportRateCard::query()->create([
            'company_id'=>$company->id,
            'transport_company_id'=>$partner->id,
            'name'=>trim($data['name']),
            'currency_code'=>strtoupper($data['currency_code']),
            'effective_from'=>$data['effective_from'],
            'effective_until'=>$data['effective_until']??null,
            'status'=>'DRAFT',
            'is_active'=>false,
            'notes'=>trim((string)($data['notes']??''))?:null,
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ]);

        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates','card'=>$card->id])
            ->with('status','New Draft Rate Card created. Enter or edit the route × vehicle rates, then Save Draft or Activate Rate Card.');
    }

    public function duplicateRateCard(Request $request, TransportRateCard $rateCard): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $this->assertCard($company->id,$rateCard);

        $newCard=DB::transaction(function()use($request,$company,$rateCard): TransportRateCard {
            $rateCard->load('rates');
            $suggestedFrom=$rateCard->effective_until
                ? $rateCard->effective_until->copy()->addDay()
                : ($rateCard->effective_from?->copy()->addYear() ?: now()->startOfDay());
            $suggestedUntil=$rateCard->effective_until ? $rateCard->effective_until->copy()->addYear() : null;

            $copyData=[
                'company_id'=>$company->id,
                'transport_company_id'=>$rateCard->transport_company_id,
                'name'=>'Copy of '.$rateCard->name,
                'currency_code'=>$rateCard->currency_code,
                'effective_from'=>$suggestedFrom->format('Y-m-d'),
                'effective_until'=>$suggestedUntil?->format('Y-m-d'),
                'status'=>'DRAFT',
                'is_active'=>false,
                'notes'=>$rateCard->notes,
                'created_by'=>$request->user()->id,
                'updated_by'=>$request->user()->id,
            ];
            if(Schema::hasColumn('transport_rate_cards','source_rate_card_id')) $copyData['source_rate_card_id']=$rateCard->id;
            $copy=TransportRateCard::query()->create($copyData);

            foreach($rateCard->rates as $rate){
                TransportRate::query()->create([
                    'transport_rate_card_id'=>$copy->id,
                    'transport_route_id'=>$rate->transport_route_id,
                    'transport_vehicle_type_id'=>$rate->transport_vehicle_type_id,
                    'cost_amount'=>$rate->cost_amount,
                    'notes'=>$rate->notes,
                ]);
            }
            return $copy;
        });

        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates','card'=>$newCard->id])
            ->with('status','Rate Card duplicated as a new Draft. All existing rates were copied and are editable; change only the new-season rates, then activate when ready.');
    }

    public function updateRateCard(Request $request, TransportRateCard $rateCard): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $this->assertCard($company->id,$rateCard);
        $this->assertDraft($rateCard);

        $data=$this->rateCardData($request);
        $partner=$this->transportCompany($company->id,(int)$data['transport_company_id']);
        $rates=$request->input('rates',[]);
        if(!is_array($rates)) $rates=[];

        DB::transaction(function()use($request,$rateCard,$partner,$data,$rates,$company): void {
            $rateCard->update([
                'transport_company_id'=>$partner->id,
                'name'=>trim($data['name']),
                'currency_code'=>strtoupper($data['currency_code']),
                'effective_from'=>$data['effective_from'],
                'effective_until'=>$data['effective_until']??null,
                'notes'=>trim((string)($data['notes']??''))?:null,
                'status'=>'DRAFT',
                'is_active'=>false,
                'updated_by'=>$request->user()->id,
            ]);

            $validRouteIds=TransportRoute::query()->where('company_id',$company->id)->pluck('id')->map(fn($x)=>(int)$x)->all();
            $validVehicleIds=TransportVehicleType::query()->where('company_id',$company->id)->pluck('id')->map(fn($x)=>(int)$x)->all();
            foreach($rates as $routeId=>$vehicleRates){
                $routeId=(int)$routeId;
                if(!in_array($routeId,$validRouteIds,true) || !is_array($vehicleRates)) continue;
                foreach($vehicleRates as $vehicleId=>$amount){
                    $vehicleId=(int)$vehicleId;
                    if(!in_array($vehicleId,$validVehicleIds,true)) continue;
                    $raw=trim((string)$amount);
                    if($raw===''){
                        TransportRate::query()->where('transport_rate_card_id',$rateCard->id)->where('transport_route_id',$routeId)->where('transport_vehicle_type_id',$vehicleId)->delete();
                        continue;
                    }
                    if(!is_numeric($raw) || (float)$raw<0){
                        throw ValidationException::withMessages(['rates'=>'Every entered vendor rate must be a number greater than or equal to zero.']);
                    }
                    TransportRate::query()->updateOrCreate(
                        ['transport_rate_card_id'=>$rateCard->id,'transport_route_id'=>$routeId,'transport_vehicle_type_id'=>$vehicleId],
                        ['cost_amount'=>round((float)$raw,2)]
                    );
                }
            }
        });

        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates','card'=>$rateCard->id])
            ->with('status','Draft Rate Card saved. It is not used by bookings until you activate it.');
    }

    public function bulkAdjust(Request $request, TransportRateCard $rateCard): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $this->assertCard($company->id,$rateCard);
        $this->assertDraft($rateCard);
        $data=$request->validate([
            'adjustment_type'=>['required','in:PERCENT,FIXED'],
            'adjustment_value'=>['required','numeric','between:-1000000,1000000'],
        ]);
        $value=(float)$data['adjustment_value'];
        if($data['adjustment_type']==='PERCENT' && $value<=-100){
            throw ValidationException::withMessages(['adjustment_value'=>'Percentage decrease must be greater than -100%.']);
        }

        DB::transaction(function()use($rateCard,$data,$value): void {
            foreach(TransportRate::query()->where('transport_rate_card_id',$rateCard->id)->get() as $rate){
                $old=(float)$rate->cost_amount;
                $new=$data['adjustment_type']==='PERCENT' ? $old*(1+($value/100)) : $old+$value;
                $rate->update(['cost_amount'=>max(0,round($new,2))]);
            }
        });

        $label=$data['adjustment_type']==='PERCENT' ? ($value.'%') : ($rateCard->currency_code.' '.number_format($value,2));
        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates','card'=>$rateCard->id])
            ->with('status','Bulk adjustment '.$label.' applied to the Draft Rate Card. Review individual rates and Save Draft before activation.');
    }

    public function activateRateCard(Request $request, TransportRateCard $rateCard): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $this->assertCard($company->id,$rateCard);
        $rateCard->refresh();

        if(!$rateCard->effective_from){
            throw ValidationException::withMessages(['effective_from'=>'Effective From is required before activation.']);
        }
        if(TransportRate::query()->where('transport_rate_card_id',$rateCard->id)->count()===0){
            throw ValidationException::withMessages(['rate_card'=>'Add at least one route / vehicle rate before activation.']);
        }
        $this->assertNoActiveOverlap($rateCard);

        $rateCard->update(['status'=>'ACTIVE','is_active'=>true,'updated_by'=>$request->user()->id]);
        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates'])
            ->with('status','Rate Card activated. Booking rate lookup will use it automatically when its effective dates match.');
    }

    public function deactivateRateCard(Request $request, TransportRateCard $rateCard): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $this->assertCard($company->id,$rateCard);
        $rateCard->update(['status'=>'INACTIVE','is_active'=>false,'updated_by'=>$request->user()->id]);
        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates'])
            ->with('status','Rate Card deactivated. Historical booking snapshots remain unchanged.');
    }

    public function destroyDraft(Request $request, TransportRateCard $rateCard): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $this->requireLifecycleUpgrade();
        $this->assertCard($company->id,$rateCard);
        $this->assertDraft($rateCard);
        DB::transaction(function()use($rateCard): void {
            TransportRate::query()->where('transport_rate_card_id',$rateCard->id)->delete();
            $rateCard->delete();
        });
        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates'])->with('status','Draft Rate Card cancelled.');
    }

    // Kept for compatibility with existing direct single-rate forms/API calls.
    public function storeRate(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless(Schema::hasTable('transport_rates'),422,'Transport rate-card upgrade is not installed. Run Safe Database Upgrade first.');
        $data=$request->validate([
            'transport_rate_card_id'=>['required','integer'],'transport_route_id'=>['required','integer'],
            'transport_vehicle_type_id'=>['required','integer'],'cost_amount'=>['required','numeric','min:0'],
            'notes'=>['nullable','string','max:500'],
        ]);
        $card=TransportRateCard::query()->where('company_id',$company->id)->whereKey($data['transport_rate_card_id'])->first();
        $route=TransportRoute::query()->where('company_id',$company->id)->whereKey($data['transport_route_id'])->first();
        $vehicle=TransportVehicleType::query()->where('company_id',$company->id)->whereKey($data['transport_vehicle_type_id'])->first();
        if(!$card||!$route||!$vehicle) throw ValidationException::withMessages(['rate'=>'Select a valid Rate Card, Route and Vehicle Type.']);
        if(Schema::hasColumn('transport_rate_cards','status') && !$card->isDraft()){
            throw ValidationException::withMessages(['rate'=>'Active/Inactive Rate Cards are protected. Duplicate the card to create a new editable Draft.']);
        }
        TransportRate::query()->updateOrCreate(
            ['transport_rate_card_id'=>$card->id,'transport_route_id'=>$route->id,'transport_vehicle_type_id'=>$vehicle->id],
            ['cost_amount'=>(float)$data['cost_amount'],'notes'=>trim((string)($data['notes']??''))?:null]
        );
        return redirect()->route('master-data.travel.index',['tab'=>'transport-rates','card'=>$card->id])->with('status','Transport vendor rate saved.');
    }

    private function rateCardData(Request $request): array
    {
        return $request->validate([
            'transport_company_id'=>['required','integer'],
            'name'=>['required','string','max:160'],
            'currency_code'=>['required','string','size:3'],
            'effective_from'=>['required','date'],
            'effective_until'=>['nullable','date','after_or_equal:effective_from'],
            'notes'=>['nullable','string','max:1000'],
        ]);
    }

    private function transportCompany(int $companyId,int $partnerId): TravelVoucherPartner
    {
        $partner=TravelVoucherPartner::query()->where('company_id',$companyId)->where('partner_type','TRANSPORT_COMPANY')->where('is_active',true)->whereKey($partnerId)->first();
        if(!$partner) throw ValidationException::withMessages(['transport_company_id'=>'Select an active Transport Company from Travel Masters.']);
        return $partner;
    }

    private function assertNoActiveOverlap(TransportRateCard $card): void
    {
        $query=TransportRateCard::query()
            ->where('company_id',$card->company_id)
            ->where('transport_company_id',$card->transport_company_id)
            ->where('id','!=',$card->id);

        if(Schema::hasColumn('transport_rate_cards','status')) $query->where('status','ACTIVE');
        else $query->where('is_active',true);

        $query->where(function($q)use($card): void {
            $q->whereNull('effective_until')->orWhereDate('effective_until','>=',$card->effective_from->format('Y-m-d'));
        });
        if($card->effective_until){
            $query->whereDate('effective_from','<=',$card->effective_until->format('Y-m-d'));
        }

        $overlap=$query->orderBy('effective_from')->first();
        if($overlap){
            throw ValidationException::withMessages([
                'effective_from'=>'This date range overlaps active Rate Card "'.$overlap->name.'". Deactivate/close the old card or choose non-overlapping effective dates.',
            ]);
        }
    }

    private function assertCard(int $companyId,TransportRateCard $card): void
    {
        abort_unless((int)$card->company_id===$companyId,404);
    }

    private function assertDraft(TransportRateCard $card): void
    {
        if(!$card->isDraft()){
            throw ValidationException::withMessages(['rate_card'=>'Only Draft Rate Cards can be edited. Use Duplicate to create a new editable Rate Card from this one.']);
        }
    }

    private function requireLifecycleUpgrade(): void
    {
        abort_unless(
            Schema::hasTable('transport_rate_cards') && Schema::hasTable('transport_rates') && Schema::hasColumn('transport_rate_cards','status'),
            422,
            'Rate Card lifecycle upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade first.'
        );
    }
}
