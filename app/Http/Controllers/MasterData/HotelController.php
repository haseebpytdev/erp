<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\Party;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HotelController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$this->validated($request,$company->id);
        $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $this->guardDuplicate($company->id,$data['name'],$data['city']);
        if(!Schema::hasColumn('hotels','city_iata_code')) unset($data['city_iata_code']);

        $hotel=Hotel::query()->create(array_merge($data,[
            'company_id'=>$company->id,
            'code'=>strtoupper($data['code']),
            'city_iata_code'=>isset($data['city_iata_code'])?strtoupper((string)$data['city_iata_code']):null,
            'country_code'=>strtoupper($data['country_code']??'SA'),
            'is_active'=>$request->boolean('is_active',true),
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ]));
        AuditService::log($request,'hotel.created',$hotel,[],$hotel->toArray());
        return back()->with('status','Hotel created and available immediately in Hotel Booking.');
    }

    public function update(Request $request, Hotel $hotel): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$hotel->company_id===(int)$company->id,404);
        $data=$this->validated($request,$company->id,$hotel->id);
        $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $this->guardDuplicate($company->id,$data['name'],$data['city'],$hotel->id);
        if(!Schema::hasColumn('hotels','city_iata_code')) unset($data['city_iata_code']);

        $old=$hotel->toArray();
        $hotel->update(array_merge($data,[
            'code'=>strtoupper($data['code']),
            'city_iata_code'=>isset($data['city_iata_code'])?strtoupper((string)$data['city_iata_code']):null,
            'country_code'=>strtoupper($data['country_code']??'SA'),
            'is_active'=>$request->boolean('is_active'),
            'updated_by'=>$request->user()->id,
        ]));
        AuditService::log($request,'hotel.updated',$hotel,$old,$hotel->fresh()->toArray());
        return back()->with('status','Hotel updated. Existing documents retain their historical booking/voucher data.');
    }

    private function validated(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $code=Rule::unique('hotels')->where('company_id',$companyId); if($ignoreId)$code=$code->ignore($ignoreId);
        return $request->validate([
            'code'=>['required','string','max:30',$code],
            'name'=>['required','string','max:180'],
            'city'=>['required','string','max:100'],
            'city_iata_code'=>['nullable','string','size:3'],
            'country_code'=>['required','string','size:2'],
            'address'=>['nullable','string','max:500'],
            'phone'=>['nullable','string','max:60'],
            'star_rating'=>['nullable','numeric','min:0','max:5'],
            'default_vendor_party_id'=>['nullable','integer','exists:parties,id'],
            'notes'=>['nullable','string','max:2000'],
            'is_active'=>['nullable','boolean'],
        ]);
    }

    private function guardDuplicate(int $companyId,string $name,string $city,?int $ignoreId=null): void
    {
        $query=Hotel::query()->where('company_id',$companyId)
            ->whereRaw('UPPER(TRIM(name))=?',[strtoupper(trim($name))])
            ->whereRaw('UPPER(TRIM(city))=?',[strtoupper(trim($city))]);
        if($ignoreId)$query->whereKeyNot($ignoreId);
        if($query->exists()) throw ValidationException::withMessages(['name'=>'This hotel already exists in the same city. Select or edit the existing hotel master.']);
    }

    private function ensureVendor(int $companyId,?int $partyId): void
    {
        if(!$partyId)return;
        $ok=Party::query()->whereKey($partyId)->where('company_id',$companyId)->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->exists();
        if(!$ok)throw ValidationException::withMessages(['default_vendor_party_id'=>'Default supplier must be an active Vendor party in this company.']);
    }
}
