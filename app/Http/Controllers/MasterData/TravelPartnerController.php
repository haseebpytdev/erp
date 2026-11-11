<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Party;
use App\Models\TravelVoucherPartner;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TravelPartnerController extends Controller
{
    public const TYPES = [
        'TRANSPORT_COMPANY',
        'SAUDI_VISA_COMPANY',
        'PAKISTANI_IATA_UMRAH_OPERATOR',
    ];

    public function store(Request $request): RedirectResponse
    {
        $this->requireRegistryUpgrade();
        $company=Company::query()->firstOrFail();
        $data=$this->validated($request,$company->id);
        $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $this->guardDuplicateName($company->id,$data['partner_type'],$data['name']);

        $code=trim((string)($data['code']??''));
        if ($code==='') {
            $code=$this->uniqueCode($company->id,$data['partner_type'],$data['name']);
        }

        $partner=TravelVoucherPartner::query()->create(array_merge($data,[
            'company_id'=>$company->id,
            'code'=>strtoupper($code),
            'country_code'=>isset($data['country_code']) && $data['country_code']!==null ? strtoupper($data['country_code']) : null,
            'iata_number'=>isset($data['iata_number']) ? strtoupper(trim((string)$data['iata_number'])) ?: null : null,
            'source_context'=>'MASTER_DATA',
            'is_active'=>$request->boolean('is_active',true),
            'created_by'=>$request->user()->id,
            'updated_by'=>$request->user()->id,
        ]));

        AuditService::log($request,'travel_partner.created',$partner,[],$partner->toArray());

        return back()->with('status',$this->label($partner->partner_type).' created. It is immediately reusable in booking/voucher workflows.');
    }

    public function update(Request $request, TravelVoucherPartner $partner): RedirectResponse
    {
        $this->requireRegistryUpgrade();
        $company=Company::query()->firstOrFail();
        abort_unless((int)$partner->company_id===(int)$company->id,404);

        $data=$this->validated($request,$company->id,$partner->id);
        $this->ensureVendor($company->id,$data['default_vendor_party_id']??null);
        $this->guardDuplicateName($company->id,$data['partner_type'],$data['name'],$partner->id);

        $old=$partner->toArray();
        $code=trim((string)($data['code']??''));
        if ($code==='') {
            $code=$partner->code ?: $this->uniqueCode($company->id,$data['partner_type'],$data['name'],$partner->id);
        }

        $partner->update(array_merge($data,[
            'code'=>strtoupper($code),
            'country_code'=>isset($data['country_code']) && $data['country_code']!==null ? strtoupper($data['country_code']) : null,
            'iata_number'=>isset($data['iata_number']) ? strtoupper(trim((string)$data['iata_number'])) ?: null : null,
            'is_active'=>$request->boolean('is_active'),
            'updated_by'=>$request->user()->id,
        ]));

        AuditService::log($request,'travel_partner.updated',$partner,$old,$partner->fresh()->toArray());

        return back()->with('status',$this->label($partner->partner_type).' updated. Historical voucher snapshots remain unchanged.');
    }

    private function validated(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $code=Rule::unique('travel_voucher_partners','code')->where('company_id',$companyId);
        if($ignoreId) $code=$code->ignore($ignoreId);

        $data=$request->validate([
            'partner_type'=>['required',Rule::in(self::TYPES)],
            'code'=>['nullable','string','max:40',$code],
            'name'=>['required','string','max:180'],
            'registration_no'=>['nullable','string','max:100'],
            'iata_number'=>['nullable','string','max:30'],
            'phone'=>['nullable','string','max:60'],
            'email'=>['nullable','email','max:160'],
            'website'=>['nullable','url','max:255'],
            'city'=>['nullable','string','max:100'],
            'country_code'=>['nullable','string','size:2'],
            'default_vendor_party_id'=>['nullable','integer','exists:parties,id'],
            'linked_pakistan_iata_operator_id'=>['nullable','integer','exists:travel_voucher_partners,id'],
            'voucher_footer_html'=>['nullable','string','max:10000'],
            'notes'=>['nullable','string','max:2000'],
            'is_active'=>['nullable','boolean'],
        ]);

        if ($data['partner_type']==='SAUDI_VISA_COMPANY') {
            if (!Schema::hasColumn('travel_voucher_partners','linked_pakistan_iata_operator_id')) {
                throw ValidationException::withMessages([
                    'linked_pakistan_iata_operator_id'=>'Visa operator linkage upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade.',
                ]);
            }
            $linkedId=(int)($data['linked_pakistan_iata_operator_id']??0);
            if($linkedId<1){
                throw ValidationException::withMessages([
                    'linked_pakistan_iata_operator_id'=>'Select the Pakistan Visa / IATA Operator linked to this Saudi Visa Company.',
                ]);
            }
            $linked=TravelVoucherPartner::query()
                ->where('company_id',$companyId)
                ->whereKey($linkedId)
                ->where('partner_type','PAKISTANI_IATA_UMRAH_OPERATOR')
                ->where('is_active',true)
                ->first();
            if(!$linked){
                throw ValidationException::withMessages([
                    'linked_pakistan_iata_operator_id'=>'The linked Pakistan Visa / IATA Operator must be an active operator in this company.',
                ]);
            }
            $data['linked_pakistan_iata_operator_id']=$linked->id;
        } else {
            if (Schema::hasColumn('travel_voucher_partners','linked_pakistan_iata_operator_id')) {
                $data['linked_pakistan_iata_operator_id']=null;
            } else {
                unset($data['linked_pakistan_iata_operator_id']);
            }
        }

        if (Schema::hasColumn('travel_voucher_partners','voucher_footer_html')) {
            $data['voucher_footer_html']=$this->sanitizeFooterHtml((string)($data['voucher_footer_html']??''));
        } else {
            unset($data['voucher_footer_html']);
        }

        return $data;
    }

    private function sanitizeFooterHtml(string $html): ?string
    {
        $html=trim($html);
        if($html==='') return null;

        $clean=strip_tags($html,'<br><p><div><span><strong><b><em><i><u>');
        $clean=preg_replace('/<(br|p|div|span|strong|b|em|i|u)\b[^>]*>/i','<$1>',$clean);

        return trim((string)$clean) ?: null;
    }

    private function guardDuplicateName(int $companyId,string $type,string $name,?int $ignoreId=null): void
    {
        $normalized=strtoupper(trim(preg_replace('/\s+/',' ',$name)));
        $query=TravelVoucherPartner::query()
            ->where('company_id',$companyId)
            ->where('partner_type',$type)
            ->whereRaw('UPPER(TRIM(name))=?',[$normalized]);

        if ($ignoreId) $query->whereKeyNot($ignoreId);

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name'=>'A similar '.$this->label($type).' already exists. Edit the existing master instead of creating a duplicate.',
            ]);
        }
    }

    private function uniqueCode(int $companyId,string $type,string $name,?int $ignoreId=null): string
    {
        $prefix=match($type) {
            'TRANSPORT_COMPANY'=>'TRN',
            'SAUDI_VISA_COMPANY'=>'SVC',
            'PAKISTANI_IATA_UMRAH_OPERATOR'=>'PKI',
            default=>'TPR',
        };
        $stem=Str::upper(Str::slug($name,''));
        $stem=substr($stem ?: 'MASTER',0,12);
        $candidate=$prefix.'-'.$stem;
        $n=1;

        while (TravelVoucherPartner::query()
            ->where('company_id',$companyId)
            ->where('code',$candidate)
            ->when($ignoreId,fn($q)=>$q->whereKeyNot($ignoreId))
            ->exists()) {
            $n++;
            $candidate=substr($prefix.'-'.$stem,0,34).'-'.$n;
        }

        return substr($candidate,0,40);
    }

    private function ensureVendor(int $companyId,?int $partyId): void
    {
        if(!$partyId) return;
        $ok=Party::query()->whereKey($partyId)->where('company_id',$companyId)->where('is_active',true)
            ->whereHas('roles',fn($q)=>$q->where('role','VENDOR')->where('is_active',true))->exists();
        if(!$ok) {
            throw ValidationException::withMessages([
                'default_vendor_party_id'=>'Linked supplier must be an active Vendor party in this company.',
            ]);
        }
    }

    private function requireRegistryUpgrade(): void
    {
        abort_unless(
            Schema::hasTable('travel_voucher_partners') && Schema::hasColumn('travel_voucher_partners','code'),
            422,
            'Travel Masters database upgrade is not installed. Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache.'
        );
    }

    private function label(string $type): string
    {
        return match($type) {
            'TRANSPORT_COMPANY'=>'Transport Company',
            'SAUDI_VISA_COMPANY'=>'Saudi Visa Company',
            'PAKISTANI_IATA_UMRAH_OPERATOR'=>'Pakistan Visa / IATA Company',
            default=>'Travel Partner',
        };
    }
}
