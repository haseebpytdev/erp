<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Party;
use App\Models\ProductService;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductServiceController extends Controller
{
    private const CATEGORY_DEFAULTS=[
        'AIR_TICKET'=>['AIR_TICKET_REVENUE','AIR_TICKET_COST'],'VISA'=>['VISA_REVENUE','VISA_COST'],'HOTEL'=>['HOTEL_REVENUE','HOTEL_COST'],
        'TRANSPORT'=>['TRANSPORT_REVENUE','TRANSPORT_COST'],'UMRAH_PACKAGE'=>['UMRAH_PACKAGE_REVENUE','PACKAGE_COST'],'SERVICE_FEE'=>['SERVICE_FEE_REVENUE',null],
    ];

    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail(); $data=$this->validated($request,$company->id); $data=$this->applyDefaults($data); $this->validateCommercialLinks($company->id,$data);
        $payload=array_merge($data,['company_id'=>$company->id,'code'=>strtoupper($data['code']),'default_currency_code'=>isset($data['default_currency_code'])?strtoupper($data['default_currency_code']):null,'has_supplier_cost'=>$request->boolean('has_supplier_cost',true),'is_active'=>$request->boolean('is_active',true),'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id]);
        $payload=$this->withSupportedCostCurrency($payload);
        $product=ProductService::query()->create($payload);
        AuditService::log($request,'product_service.created',$product,[],$product->toArray()); return back()->with('status','Product/service created with mapping-key based accounting defaults.');
    }
    public function update(Request $request, ProductService $productService): RedirectResponse
    {
        $company=Company::query()->firstOrFail(); abort_unless((int)$productService->company_id===(int)$company->id,404); $data=$this->validated($request,$company->id,$productService->id); $data=$this->applyDefaults($data); $this->validateCommercialLinks($company->id,$data);
        $old=$productService->toArray(); $payload=array_merge($data,['code'=>strtoupper($data['code']),'default_currency_code'=>isset($data['default_currency_code'])?strtoupper($data['default_currency_code']):null,'has_supplier_cost'=>$request->boolean('has_supplier_cost'),'is_active'=>$request->boolean('is_active'),'updated_by'=>$request->user()->id]); $productService->update($this->withSupportedCostCurrency($payload));
        AuditService::log($request,'product_service.updated',$productService,$old,$productService->fresh()->toArray()); return back()->with('status','Product/service updated.');
    }

    private function validated(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $code=Rule::unique('products_services')->where('company_id',$companyId); if($ignoreId)$code=$code->ignore($ignoreId);
        $currencyRule=['nullable','string','size:3'];
        if(Schema::hasTable('currencies') && Schema::hasColumn('currencies','code')) $currencyRule[]=Rule::exists('currencies','code');
        return $request->validate([
            'code'=>['required','string','max:40',$code],'name'=>['required','string','max:180'],'category'=>['required','in:AIR_TICKET,VISA,HOTEL,TRANSPORT,UMRAH_PACKAGE,INSURANCE,SERVICE_FEE,OTHER'],
            'pricing_basis'=>['required','in:PER_SERVICE,PER_PERSON,PER_TICKET,PER_ROOM,PER_NIGHT,PER_ROOM_NIGHT,PER_PERSON_NIGHT,FIXED'],
            'passenger_link_mode'=>['required','in:NONE,OPTIONAL,REQUIRED,MULTIPLE'],'default_unit'=>['nullable','string','max:40'],'default_currency_code'=>$currencyRule,
            'supplier_cost_currency_code'=>$currencyRule,'default_vendor_party_id'=>['nullable','integer','exists:parties,id'],'revenue_mapping_key'=>['nullable','string','max:80'],'cost_mapping_key'=>['nullable','string','max:80'],
            'has_supplier_cost'=>['nullable','boolean'],'notes'=>['nullable','string','max:2000'],'is_active'=>['nullable','boolean'],
        ]);
    }

    private function applyDefaults(array $data): array
    {
        $defaults=self::CATEGORY_DEFAULTS[$data['category']]??[null,null];
        if(empty($data['revenue_mapping_key']))$data['revenue_mapping_key']=$defaults[0];
        if(empty($data['cost_mapping_key']))$data['cost_mapping_key']=$defaults[1];
        // Customer sale currency remains the booking/company base currency. Saudi supplier costs
        // for Visa, Hotel and Transport default to SAR and are converted through dated FX rates.
        if(empty($data['supplier_cost_currency_code']) && in_array($data['category'],['VISA','HOTEL','TRANSPORT'],true)){
            $data['supplier_cost_currency_code']='SAR';
        }
        return $data;
    }

    private function withSupportedCostCurrency(array $payload): array
    {
        if(Schema::hasTable('products_services') && Schema::hasColumn('products_services','supplier_cost_currency_code')){
            if(array_key_exists('supplier_cost_currency_code',$payload)){
                $payload['supplier_cost_currency_code']=trim((string)$payload['supplier_cost_currency_code'])!==''
                    ? strtoupper((string)$payload['supplier_cost_currency_code'])
                    : null;
            }
        }else{
            unset($payload['supplier_cost_currency_code']);
        }
        return $payload;
    }

    private function validateCommercialLinks(int $companyId,array $data): void
    {
        if(!empty($data['default_vendor_party_id'])){
            $ok=Party::query()->whereKey($data['default_vendor_party_id'])->where('company_id',$companyId)->where('is_active',true)->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->exists();
            if(!$ok)throw ValidationException::withMessages(['default_vendor_party_id'=>'Default supplier must be an active Vendor party in this company.']);
        }
        foreach(['revenue_mapping_key','cost_mapping_key'] as $field){
            $key=$data[$field]??null; if(!$key)continue;
            if(!Schema::hasTable('account_mappings') || !Schema::hasColumn('account_mappings','mapping_key')){
                throw ValidationException::withMessages([$field=>'Account mapping setup is not available. Complete Foundation / accounting mappings before assigning this mapping.']);
            }
            $query=DB::table('account_mappings')->where('mapping_key',$key);
            if(Schema::hasColumn('account_mappings','company_id')) $query->where('company_id',$companyId);
            if(Schema::hasColumn('account_mappings','account_id')) $query->whereNotNull('account_id');
            $exists=$query->exists();
            if(!$exists)throw ValidationException::withMessages([$field=>'Selected account mapping is missing or unresolved in the current company.']);
        }
    }
}
