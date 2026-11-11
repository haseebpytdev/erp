<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Airline;
use App\Models\BookingSource;
use App\Models\Company;
use App\Models\Hotel;
use App\Models\Party;
use App\Models\ProductService;
use App\Models\TravelVoucherPartner;
use App\Models\TransportRate;
use App\Models\TransportRateCard;
use App\Models\TransportRoute;
use App\Models\TransportVehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class TravelMasterController extends Controller
{
    private const TABS = [
        'overview','hotels','transport','vehicle-types','transport-routes','transport-rates','saudi-visa','pakistan-iata','airlines','airline-codes','booking-sources','products',
    ];

    public function index(Request $request): Response
    {
        $company=Company::query()->firstOrFail();
        $baseCurrencyCode=$this->companyBaseCurrencyCode((int)$company->id);
        $tab=strtolower(trim((string)$request->query('tab','overview')));
        if(!in_array($tab,self::TABS,true)) $tab='overview';
        $q=trim((string)$request->query('q',''));
        $status=strtolower(trim((string)$request->query('status','all')));
        if(!in_array($status,['all','active','inactive'],true)) $status='all';
        $rateStatus=strtolower(trim((string)$request->query('rate_status','all')));
        if(!in_array($rateStatus,['all','draft','active','upcoming','expired','inactive'],true)) $rateStatus='all';

        $loadError=null;
        try{
            $vendors=Party::query()
                ->where('company_id',$company->id)
                ->where('is_active',true)
                ->whereHas('roles',fn($x)=>$x->where('role','VENDOR')->where('is_active',true))
                ->orderBy('display_name')->get();
        }catch(Throwable $e){
            report($e);
            $vendors=collect();
            $loadError='vendors';
        }

        try{$summary=$this->summary((int)$company->id);}catch(Throwable $e){
            report($e);
            $summary=['hotels'=>0,'transport'=>0,'saudiVisa'=>0,'pakistanIata'=>0,'airlines'=>0];
            $loadError=$loadError?:'summary';
        }
        try{$usage=$this->usageMaps();}catch(Throwable $e){
            report($e);
            $usage=['hotels'=>[],'airlines'=>[],'partners'=>[]];
            $loadError=$loadError?:'usage';
        }

        $data=[
            'company'=>$company,'baseCurrencyCode'=>$baseCurrencyCode,'loadError'=>$loadError,'tab'=>$tab,'q'=>$q,'status'=>$status,'rateStatus'=>$rateStatus,'vendors'=>$vendors,'summary'=>$summary,
            'hotels'=>collect(),'partners'=>collect(),'airlines'=>collect(),'bookingSources'=>collect(),'products'=>collect(),
            'vehicleTypes'=>collect(),'transportRoutes'=>collect(),'transportRateCards'=>collect(),'transportRates'=>collect(),'transportCompanies'=>collect(),
            'selectedRateCard'=>null,'selectedRateValues'=>[],
            'pakistanIataOptions'=>collect(),
            'currencies'=>collect(),'revenueMappings'=>collect(),'costMappings'=>collect(),'productCostCurrencies'=>[],'usage'=>$usage,
            'registryUpgradeInstalled'=>Schema::hasTable('travel_voucher_partners') && Schema::hasColumn('travel_voucher_partners','code'),
            'airlineAccountingPrefixInstalled'=>Schema::hasTable('airlines') && Schema::hasColumn('airlines','accounting_prefix'),
            'hotelCityIataInstalled'=>Schema::hasTable('hotels') && Schema::hasColumn('hotels','city_iata_code'),
            'voucherFooterUpgradeInstalled'=>Schema::hasTable('companies') && Schema::hasColumn('companies','voucher_footer_html') && Schema::hasTable('travel_voucher_partners') && Schema::hasColumn('travel_voucher_partners','voucher_footer_html'),
            'visaOperatorLinkUpgradeInstalled'=>Schema::hasTable('travel_voucher_partners') && Schema::hasColumn('travel_voucher_partners','linked_pakistan_iata_operator_id') && Schema::hasTable('visa_service_operators'),
            'transportRateMasterUpgradeInstalled'=>Schema::hasTable('transport_vehicle_types') && Schema::hasTable('transport_routes') && Schema::hasTable('transport_rate_cards') && Schema::hasTable('transport_rates'),
            'rateCardLifecycleUpgradeInstalled'=>Schema::hasTable('transport_rate_cards') && Schema::hasColumn('transport_rate_cards','status'),
            'rateCardSourceLinkInstalled'=>Schema::hasTable('transport_rate_cards') && Schema::hasColumn('transport_rate_cards','source_rate_card_id'),
            'productCostCurrencyUpgradeInstalled'=>Schema::hasTable('products_services') && Schema::hasColumn('products_services','supplier_cost_currency_code'),
        ];

        if($tab==='hotels'){
            $query=Hotel::query()->where('company_id',$company->id)->with('defaultVendor');
            $this->applyStatus($query,$status);
            if($q!=='') $query->where(fn($x)=>$x->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('city','like','%'.$q.'%')->orWhere('country_code','like','%'.$q.'%'));
            $data['hotels']=$query->orderBy('city')->orderBy('name')->get();
        }

        if(in_array($tab,['saudi-visa','pakistan-iata'],true) && Schema::hasTable('travel_voucher_partners')){
            $data['pakistanIataOptions']=TravelVoucherPartner::query()
                ->where('company_id',$company->id)
                ->where('partner_type','PAKISTANI_IATA_UMRAH_OPERATOR')
                ->where('is_active',true)
                ->orderBy('name')
                ->get();
        }

        $partnerType=match($tab){
            'transport'=>'TRANSPORT_COMPANY',
            'saudi-visa'=>'SAUDI_VISA_COMPANY',
            'pakistan-iata'=>'PAKISTANI_IATA_UMRAH_OPERATOR',
            default=>null,
        };
        if($partnerType){
            $query=TravelVoucherPartner::query()->where('company_id',$company->id)->where('partner_type',$partnerType)->with(['defaultVendor','linkedPakistanIataOperator']);
            $this->applyStatus($query,$status);
            if($q!=='') $query->where(function($x)use($q){
                $x->where('name','like','%'.$q.'%')->orWhere('registration_no','like','%'.$q.'%')->orWhere('phone','like','%'.$q.'%');
                if(Schema::hasColumn('travel_voucher_partners','code')) $x->orWhere('code','like','%'.$q.'%');
                if(Schema::hasColumn('travel_voucher_partners','iata_number')) $x->orWhere('iata_number','like','%'.$q.'%');
            });
            $data['partners']=$query->orderBy('name')->get();
        }


        if(in_array($tab,['vehicle-types','transport-routes','transport-rates'],true) && Schema::hasTable('transport_vehicle_types')){
            $vehicleQuery=TransportVehicleType::query()->where('company_id',$company->id);
            if($tab==='vehicle-types'){
                $this->applyStatus($vehicleQuery,$status);
                if($q!=='') $vehicleQuery->where(fn($x)=>$x->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%'));
            }
            $data['vehicleTypes']=$vehicleQuery->orderBy('name')->get();
        }
        if(in_array($tab,['transport-routes','transport-rates'],true) && Schema::hasTable('transport_routes')){
            $routeQuery=TransportRoute::query()->where('company_id',$company->id);
            if($tab==='transport-routes'){
                $this->applyStatus($routeQuery,$status);
                if($q!=='') $routeQuery->where(function($x)use($q){
                    $x->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('pickup_location','like','%'.$q.'%')->orWhere('dropoff_location','like','%'.$q.'%');
                });
            }
            $data['transportRoutes']=$routeQuery->orderBy('name')->get();
        }
        if($tab==='transport-rates' && $data['transportRateMasterUpgradeInstalled']){
            $data['currencies']=$this->currencyOptions();
            $data['transportCompanies']=TravelVoucherPartner::query()->where('company_id',$company->id)->where('partner_type','TRANSPORT_COMPANY')->where('is_active',true)->orderBy('name')->get();
            $cards=TransportRateCard::query()->where('company_id',$company->id)->with('transportCompany')->withCount('rates')->orderByDesc('effective_from')->orderBy('name')->get();
            if(Schema::hasColumn('transport_rate_cards','status') && $rateStatus!=='all'){
                $cards=$cards->filter(fn(TransportRateCard $card)=>strtolower($card->lifecycleStatus())===$rateStatus)->values();
            }elseif(!Schema::hasColumn('transport_rate_cards','status') && in_array($rateStatus,['active','inactive'],true)){
                $cards=$cards->filter(fn(TransportRateCard $card)=>$rateStatus==='active' ? $card->is_active : !$card->is_active)->values();
            }
            $data['transportRateCards']=$cards;

            $selectedId=(int)$request->query('card',0);
            if($selectedId>0){
                $selectedQuery=TransportRateCard::query()->where('company_id',$company->id)->with('transportCompany')->withCount('rates');
                if($data['rateCardLifecycleUpgradeInstalled'] && Schema::hasColumn('transport_rate_cards','source_rate_card_id')){
                    $selectedQuery->with('sourceRateCard');
                }
                $selected=$selectedQuery->find($selectedId);
                if($selected){
                    $data['selectedRateCard']=$selected;
                    if(Schema::hasTable('transport_rates')){
                        $data['selectedRateValues']=TransportRate::query()->where('transport_rate_card_id',$selected->id)->get()->mapWithKeys(
                            fn(TransportRate $rate)=>[$rate->transport_route_id.'_'.$rate->transport_vehicle_type_id=>(string)$rate->cost_amount]
                        )->all();
                    }
                }
            }
        }

        if(in_array($tab,['airlines','airline-codes'],true)){
            $query=Airline::query()->where('company_id',$company->id)->with('defaultVendor');
            $this->applyStatus($query,$status);
            if($q!=='') $query->where(function($x)use($q){
                $x->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('iata_code','like','%'.$q.'%')->orWhere('icao_code','like','%'.$q.'%');
                if(Schema::hasColumn('airlines','accounting_prefix')) $x->orWhere('accounting_prefix','like','%'.$q.'%');
            });
            $data['airlines']=$query->orderBy('name')->paginate(100)->withQueryString();
        }

        if($tab==='booking-sources'){
            $query=BookingSource::query()->where('company_id',$company->id)->with('defaultVendor');
            $this->applyStatus($query,$status);
            if($q!=='') $query->where(fn($x)=>$x->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('source_type','like','%'.$q.'%'));
            $data['bookingSources']=$query->orderBy('name')->get();
        }

        if($tab==='products'){
            $query=ProductService::query()->where('company_id',$company->id)->with('defaultVendor');
            $this->applyStatus($query,$status);
            if($q!=='') $query->where(fn($x)=>$x->where('name','like','%'.$q.'%')->orWhere('code','like','%'.$q.'%')->orWhere('category','like','%'.$q.'%'));
            $data['products']=$query->orderBy('category')->orderBy('name')->get();

            // Optional catalogue metadata must never make the Products tab unavailable. Older ERP
            // databases can legitimately have a currencies/account_mappings table with a slightly
            // different column set, and a partially applied upgrade must still render a usable page.
            $data['currencies']=$this->currencyOptions();
            $data['revenueMappings']=$this->accountMappingOptions((int)$company->id,'REVENUE');
            $data['costMappings']=$this->accountMappingOptions((int)$company->id,'DIRECT_COST');

            if($data['productCostCurrencyUpgradeInstalled']){
                try{
                    $storedCostCurrencies=DB::table('products_services')
                        ->where('company_id',$company->id)
                        ->pluck('supplier_cost_currency_code','id')
                        ->mapWithKeys(fn($value,$id)=>[(int)$id=>strtoupper(trim((string)$value))])
                        ->all();
                    $data['productCostCurrencies']=$data['products']->mapWithKeys(function(ProductService $product) use($storedCostCurrencies,$baseCurrencyCode): array {
                        $stored=$storedCostCurrencies[(int)$product->id]??'';
                        $fallback=in_array(strtoupper((string)$product->category),['VISA','HOTEL','TRANSPORT'],true)
                            ? 'SAR'
                            : $baseCurrencyCode;
                        return [(int)$product->id=>$stored!==''?$stored:$fallback];
                    })->all();
                }catch(Throwable $e){
                    report($e);
                    $data['productCostCurrencies']=[];
                    $data['productCostCurrencyUpgradeInstalled']=false;
                }
            }
        }

        try{
            // ERP10.25.7 uses one cache-proof, self-contained Travel Masters view based on
            // the working recovery baseline. Rate-card lifecycle and product currency UI are
            // rendered inline so an optional partial cannot take down the whole master screen.
            return response(view('master-data.travel-masters-v10257',$data)->render());
        }catch(Throwable $enhancedError){
            report($enhancedError);

            try{
                $stableData=$data;
                $stableData['loadError']=$stableData['loadError'] ?: 'v10257-view';
                return response(view('master-data.travel-masters-stable-v10256',$stableData)->render());
            }catch(Throwable $stableError){
                report($stableError);
                $diagnostic=auth()->check() && (bool)(auth()->user()->is_super_admin ?? false)
                    ? class_basename($enhancedError).': '.str($enhancedError->getMessage())->limit(240)
                    : null;
                return response(view('master-data.travel-masters-fallback',[
                    'tab'=>$tab,
                    'company'=>$company,
                    'baseCurrencyCode'=>$baseCurrencyCode,
                    'message'=>'Travel Masters could not render the enhanced or stable view.',
                    'diagnostic'=>$diagnostic,
                ])->render());
            }
        }
    }


    public function updateCompanyVoucherFooter(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless(
            Schema::hasTable('companies') && Schema::hasColumn('companies','voucher_footer_html'),
            422,
            'Voucher footer database upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade once, then Clear Application Cache.'
        );

        $data=$request->validate([
            'voucher_footer_html'=>['nullable','string','max:10000'],
        ]);

        DB::table('companies')->where('id',$company->id)->update([
            'voucher_footer_html'=>$this->sanitizeFooterHtml((string)($data['voucher_footer_html']??'')),
            'updated_at'=>now(),
        ]);

        return redirect()->route('master-data.travel.index',['tab'=>'pakistan-iata'])
            ->with('status','Company Profile default voucher footer saved. It is used only when no voucher-specific or selected IATA footer is available.');
    }

    public function export(Request $request,string $section): StreamedResponse
    {
        $company=Company::query()->firstOrFail();
        $section=strtolower($section);
        abort_unless(in_array($section,['hotels','transport','saudi-visa','pakistan-iata','airlines','airline-codes'],true),404);

        $usage=$this->usageMaps();
        $rows=[];
        $headers=[];

        if($section==='hotels'){
            $headers=['Code','Hotel','City IATA','City','Country','Phone','Stars','Default Vendor','Usage','Status','Notes'];
            foreach(Hotel::query()->where('company_id',$company->id)->with('defaultVendor')->orderBy('city')->orderBy('name')->get() as $x){
                $rows[]=[
                    $x->code,$x->name,$x->city_iata_code,$x->city,$x->country_code,$x->phone,$x->star_rating,
                    $x->defaultVendor?->display_name,$usage['hotels'][(int)$x->id]??0,$x->is_active?'ACTIVE':'INACTIVE',$x->notes,
                ];
            }
        }elseif(in_array($section,['airlines','airline-codes'],true)){
            $headers=['Code','Airline','IATA','ICAO','Accounting Prefix','Country','Default Vendor','Ticket Usage','Status','Notes'];
            foreach(Airline::query()->where('company_id',$company->id)->with('defaultVendor')->orderBy('name')->get() as $x){
                $rows[]=[
                    $x->code,$x->name,$x->iata_code,$x->icao_code,$x->accounting_prefix,$x->country_code,
                    $x->defaultVendor?->display_name,$usage['airlines'][(int)$x->id]??0,$x->is_active?'ACTIVE':'INACTIVE',$x->notes,
                ];
            }
        }else{
            $type=match($section){
                'transport'=>'TRANSPORT_COMPANY','saudi-visa'=>'SAUDI_VISA_COMPANY','pakistan-iata'=>'PAKISTANI_IATA_UMRAH_OPERATOR',
            };
            $headers=['Code','Name','Registration / License','IATA No.','Linked Pakistan IATA','Phone','Email','City','Country','Default Vendor','Usage','Status','Source','Voucher Footer HTML','Notes'];
            foreach(TravelVoucherPartner::query()->where('company_id',$company->id)->where('partner_type',$type)->with(['defaultVendor','linkedPakistanIataOperator'])->orderBy('name')->get() as $x){
                $rows[]=[
                    $x->code,$x->name,$x->registration_no,$x->iata_number,$x->linkedPakistanIataOperator?->name,$x->phone,$x->email,$x->city,$x->country_code,
                    $x->defaultVendor?->display_name,$usage['partners'][(int)$x->id]??0,$x->is_active?'ACTIVE':'INACTIVE',$x->source_context,Schema::hasColumn('travel_voucher_partners','voucher_footer_html')?$x->voucher_footer_html:null,$x->notes,
                ];
            }
        }

        $filename='travel-masters-'.$section.'-'.now()->format('Ymd-His').'.csv';
        return response()->streamDownload(function()use($headers,$rows): void {
            $out=fopen('php://output','w');
            fputcsv($out,$headers);
            foreach($rows as $row) fputcsv($out,array_map([$this,'csvSafe'],$row));
            fclose($out);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    private function companyBaseCurrencyCode(int $companyId): string
    {
        // Company currency columns vary across older ERP installations. Never access an
        // optional Eloquent attribute directly: strict missing-attribute mode can turn that
        // into a 500 on every Travel Masters tab. PKR remains the ERP functional fallback.
        if(!Schema::hasTable('companies')) return 'PKR';
        foreach(['base_currency_code','currency_code','base_currency'] as $column){
            if(!Schema::hasColumn('companies',$column)) continue;
            try{
                $value=strtoupper(trim((string)DB::table('companies')->where('id',$companyId)->value($column)));
                if(preg_match('/^[A-Z]{3}$/',$value)) return $value;
            }catch(Throwable $e){
                report($e);
            }
        }
        return 'PKR';
    }

    private function currencyOptions(): Collection
    {
        if(!Schema::hasTable('currencies') || !Schema::hasColumn('currencies','code')) return collect();
        try{
            $query=DB::table('currencies');
            if(Schema::hasColumn('currencies','is_active')) $query->where('is_active',true);
            return $query->orderBy('code')->get();
        }catch(Throwable $e){
            report($e);
            return collect();
        }
    }

    private function accountMappingOptions(int $companyId,string $group): Collection
    {
        if(!Schema::hasTable('account_mappings') || !Schema::hasColumn('account_mappings','mapping_key')) return collect();
        try{
            $query=DB::table('account_mappings');
            if(Schema::hasColumn('account_mappings','company_id')) $query->where('company_id',$companyId);
            if(Schema::hasColumn('account_mappings','mapping_group')) $query->where('mapping_group',$group);
            elseif(Schema::hasColumn('account_mappings','group')) $query->where('group',$group);
            $orderColumn=Schema::hasColumn('account_mappings','label')?'label':'mapping_key';
            return $query->orderBy($orderColumn)->get()->map(function($row){
                if(!property_exists($row,'label') || trim((string)($row->label??''))==='') $row->label=(string)($row->mapping_key??'');
                return $row;
            });
        }catch(Throwable $e){
            report($e);
            return collect();
        }
    }

    private function summary(int $companyId): array
    {
        return [
            'hotels'=>Schema::hasTable('hotels')?Hotel::query()->where('company_id',$companyId)->count():0,
            'transport'=>Schema::hasTable('travel_voucher_partners')?TravelVoucherPartner::query()->where('company_id',$companyId)->where('partner_type','TRANSPORT_COMPANY')->count():0,
            'saudiVisa'=>Schema::hasTable('travel_voucher_partners')?TravelVoucherPartner::query()->where('company_id',$companyId)->where('partner_type','SAUDI_VISA_COMPANY')->count():0,
            'pakistanIata'=>Schema::hasTable('travel_voucher_partners')?TravelVoucherPartner::query()->where('company_id',$companyId)->where('partner_type','PAKISTANI_IATA_UMRAH_OPERATOR')->count():0,
            'airlines'=>Schema::hasTable('airlines')?Airline::query()->where('company_id',$companyId)->count():0,
        ];
    }

    private function usageMaps(): array
    {
        $hotelUsage=Schema::hasTable('hotel_booking_details')
            ? DB::table('hotel_booking_details')->whereNotNull('hotel_id')->select('hotel_id',DB::raw('COUNT(*) as uses'))->groupBy('hotel_id')->pluck('uses','hotel_id')->map(fn($v)=>(int)$v)->all()
            : [];
        $airlineUsage=Schema::hasTable('air_ticket_details')
            ? DB::table('air_ticket_details')->whereNotNull('airline_id')->select('airline_id',DB::raw('COUNT(*) as uses'))->groupBy('airline_id')->pluck('uses','airline_id')->map(fn($v)=>(int)$v)->all()
            : [];

        $partnerUsage=[];
        if(Schema::hasTable('travel_vouchers')){
            foreach(['saudi_visa_company_id','pakistan_iata_operator_id'] as $column){
                if(!Schema::hasColumn('travel_vouchers',$column)) continue;
                $counts=DB::table('travel_vouchers')->whereNotNull($column)->select($column,DB::raw('COUNT(*) as uses'))->groupBy($column)->get();
                foreach($counts as $row){
                    $id=(int)$row->{$column};
                    $partnerUsage[$id]=($partnerUsage[$id]??0)+(int)$row->uses;
                }
            }
        }
        if(Schema::hasTable('travel_voucher_items') && Schema::hasColumn('travel_voucher_items','meta_json')){
            DB::table('travel_voucher_items')->where('item_type','TRANSPORT')->where('is_active',1)->whereNotNull('meta_json')->get(['meta_json'])->each(function($row)use(&$partnerUsage): void {
                $meta=json_decode((string)$row->meta_json,true);
                $id=(int)($meta['transport_company_id']??0);
                if($id>0) $partnerUsage[$id]=($partnerUsage[$id]??0)+1;
            });
        }

        // ERP-10.22: operational service details now link to the same Travel Masters.
        if(Schema::hasTable('transport_booking_details') && Schema::hasColumn('transport_booking_details','transport_company_id')){
            $counts=DB::table('transport_booking_details')->whereNotNull('transport_company_id')
                ->select('transport_company_id',DB::raw('COUNT(*) as uses'))->groupBy('transport_company_id')->get();
            foreach($counts as $row){
                $id=(int)$row->transport_company_id;
                $partnerUsage[$id]=($partnerUsage[$id]??0)+(int)$row->uses;
            }
        }
        if(Schema::hasTable('visa_service_operators')){
            foreach(['saudi_visa_company_id','pakistan_iata_operator_id'] as $column){
                $counts=DB::table('visa_service_operators')->whereNotNull($column)
                    ->select($column,DB::raw('COUNT(*) as uses'))->groupBy($column)->get();
                foreach($counts as $row){
                    $id=(int)$row->{$column};
                    $partnerUsage[$id]=($partnerUsage[$id]??0)+(int)$row->uses;
                }
            }
        }elseif(Schema::hasTable('visa_booking_details')){
            foreach(['saudi_visa_company_id','pakistan_iata_operator_id'] as $column){
                if(!Schema::hasColumn('visa_booking_details',$column)) continue;
                $counts=DB::table('visa_booking_details')->whereNotNull($column)
                    ->select($column,DB::raw('COUNT(*) as uses'))->groupBy($column)->get();
                foreach($counts as $row){
                    $id=(int)$row->{$column};
                    $partnerUsage[$id]=($partnerUsage[$id]??0)+(int)$row->uses;
                }
            }
        }

        return ['hotels'=>$hotelUsage,'airlines'=>$airlineUsage,'partners'=>$partnerUsage];
    }

    private function applyStatus($query,string $status): void
    {
        if($status==='active') $query->where('is_active',true);
        elseif($status==='inactive') $query->where('is_active',false);
    }

    private function csvSafe(mixed $value): string
    {
        $value=(string)($value??'');
        return preg_match('/^[=+\-@]/',$value) ? "'".$value : $value;
    }

    private function sanitizeFooterHtml(string $html): ?string
    {
        $html=trim($html);
        if($html==='') return null;

        $clean=strip_tags($html,'<br><p><div><span><strong><b><em><i><u>');
        $clean=preg_replace('/<(br|p|div|span|strong|b|em|i|u)\b[^>]*>/i','<$1>',$clean);

        return trim((string)$clean) ?: null;
    }

}
