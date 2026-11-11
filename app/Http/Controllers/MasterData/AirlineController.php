<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Airline;
use App\Models\Company;
use App\Models\Party;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AirlineController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$this->validated($request,$company->id);
        $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $this->guardDuplicates($company->id,$data);
        $data=$this->normalize($data);

        if(!Schema::hasColumn('airlines','accounting_prefix')) unset($data['accounting_prefix']);

        $airline=Airline::query()->create(array_merge($data,[
            'company_id'=>$company->id,
            'is_active'=>$request->boolean('is_active',true),
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ]));

        AuditService::log($request,'airline.created',$airline,[],$airline->toArray());
        return back()->with('status','Airline created. IATA/ICAO/accounting codes are now reusable across ticket and voucher workflows.');
    }

    public function update(Request $request, Airline $airline): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$airline->company_id===(int)$company->id,404);
        $data=$this->validated($request,$company->id,$airline->id);
        $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $this->guardDuplicates($company->id,$data,$airline->id);
        $data=$this->normalize($data);
        if(!Schema::hasColumn('airlines','accounting_prefix')) unset($data['accounting_prefix']);

        $old=$airline->toArray();
        $airline->update(array_merge($data,[
            'is_active'=>$request->boolean('is_active'),
            'updated_by'=>$request->user()->id,
        ]));

        AuditService::log($request,'airline.updated',$airline,$old,$airline->fresh()->toArray());
        return back()->with('status','Airline updated. Existing ticket/voucher snapshots remain historical; future entries use the updated master.');
    }

    private function validated(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $code=Rule::unique('airlines')->where('company_id',$companyId); if($ignoreId)$code=$code->ignore($ignoreId);
        return $request->validate([
            'code'=>['required','string','max:20',$code],
            'iata_code'=>['nullable','string','size:2'],
            'icao_code'=>['nullable','string','size:3'],
            'accounting_prefix'=>['nullable','regex:/^\d{3}$/'],
            'name'=>['required','string','max:160'],
            'country_code'=>['nullable','string','size:2'],
            'default_vendor_party_id'=>['nullable','integer','exists:parties,id'],
            'notes'=>['nullable','string','max:2000'],
            'is_active'=>['nullable','boolean'],
        ]);
    }

    private function normalize(array $data): array
    {
        foreach(['code','iata_code','icao_code','country_code'] as $key){
            if(array_key_exists($key,$data)) $data[$key]=trim((string)$data[$key])!==''?strtoupper(trim((string)$data[$key])):null;
        }
        // airlines.country_code is non-null in the ERP schema. XX means unknown/reference-only.
        $data['country_code']=$data['country_code']??'XX';
        if(isset($data['accounting_prefix'])) $data['accounting_prefix']=trim((string)$data['accounting_prefix']) ?: null;
        $data['name']=trim((string)$data['name']);
        return $data;
    }

    private function guardDuplicates(int $companyId,array $data,?int $ignoreId=null): void
    {
        $base=Airline::query()->where('company_id',$companyId)->when($ignoreId,fn($q)=>$q->whereKeyNot($ignoreId));
        if(trim((string)($data['iata_code']??''))!==''){
            $exists=(clone $base)->whereRaw('UPPER(iata_code)=?',[strtoupper(trim((string)$data['iata_code']))])->exists();
            if($exists) throw ValidationException::withMessages(['iata_code'=>'This airline IATA code already exists.']);
        }
        if(trim((string)($data['icao_code']??''))!==''){
            $exists=(clone $base)->whereRaw('UPPER(icao_code)=?',[strtoupper(trim((string)$data['icao_code']))])->exists();
            if($exists) throw ValidationException::withMessages(['icao_code'=>'This airline ICAO code already exists.']);
        }
        $sameName=(clone $base)->whereRaw('UPPER(TRIM(name))=?',[strtoupper(trim((string)$data['name']))])->exists();
        if($sameName) throw ValidationException::withMessages(['name'=>'A similar airline already exists. Edit the existing airline master instead.']);
    }

    private function ensureVendor(int $companyId,?int $partyId): void
    {
        if(!$partyId)return;
        $ok=Party::query()->whereKey($partyId)->where('company_id',$companyId)->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->exists();
        if(!$ok)throw ValidationException::withMessages(['default_vendor_party_id'=>'Default supplier must be an active Vendor party in this company.']);
    }
}
