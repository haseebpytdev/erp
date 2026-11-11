<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\BookingSource;
use App\Models\Company;
use App\Models\Party;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingSourceController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail(); $data=$this->validated($request,$company->id); $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $source=BookingSource::query()->create(array_merge($data,['company_id'=>$company->id,'code'=>strtoupper($data['code']),'is_active'=>$request->boolean('is_active',true),'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]));
        AuditService::log($request,'booking_source.created',$source,[],$source->toArray());
        return back()->with('status','Booking source created. Source/GDS identity is independent from supplier identity.');
    }
    public function update(Request $request, BookingSource $bookingSource): RedirectResponse
    {
        $company=Company::query()->firstOrFail(); abort_unless((int)$bookingSource->company_id===(int)$company->id,404); $data=$this->validated($request,$company->id,$bookingSource->id); $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $old=$bookingSource->toArray(); $bookingSource->update(array_merge($data,['code'=>strtoupper($data['code']),'is_active'=>$request->boolean('is_active'),'updated_by'=>$request->user()->id]));
        AuditService::log($request,'booking_source.updated',$bookingSource,$old,$bookingSource->fresh()->toArray()); return back()->with('status','Booking source updated.');
    }
    private function validated(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $code=Rule::unique('booking_sources')->where('company_id',$companyId); if($ignoreId)$code=$code->ignore($ignoreId);
        return $request->validate(['code'=>['required','string','max:30',$code],'name'=>['required','string','max:160'],'source_type'=>['required','in:GDS,NDC,AIRLINE_API,AGGREGATOR,MANUAL,OTHER'],'default_vendor_party_id'=>['nullable','integer','exists:parties,id'],'notes'=>['nullable','string','max:2000'],'is_active'=>['nullable','boolean']]);
    }
    private function ensureVendor(int $companyId,?int $partyId): void
    {
        if(!$partyId)return; $ok=Party::query()->whereKey($partyId)->where('company_id',$companyId)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->exists();
        if(!$ok)throw ValidationException::withMessages(['default_vendor_party_id'=>'Default supplier must be an active Vendor party in this company.']);
    }
}
