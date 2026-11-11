<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\Company;
use App\Models\CustomerProfile;
use App\Models\EmployeePartyProfile;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\Staff;
use App\Models\VendorProfile;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PartyController extends Controller
{
    private const ROLES=['CUSTOMER','VENDOR','AGENT','EMPLOYEE','OTHER'];

    public function index(Request $request): View
    {
        $company=Company::query()->firstOrFail();
        $role=strtoupper((string)$request->query('role'));
        $status=strtoupper((string)$request->query('status'));
        $search=trim((string)$request->query('q'));
        $query=Party::query()->where('company_id',$company->id)
            ->with(['roles','customerProfile','vendorProfile','agentProfile','employeeProfile.staff'])
            ->orderBy('display_name');
        if(in_array($role,self::ROLES,true))$query->whereHas('roles',fn($q)=>$q->where('role',$role)->where('is_active',true));
        if(in_array($status,['ACTIVE','INACTIVE'],true))$query->where('is_active',$status==='ACTIVE');
        if($search!=='')$query->where(function($q)use($search){$q->where('code','like',"%{$search}%")->orWhere('display_name','like',"%{$search}%")->orWhere('legal_name','like',"%{$search}%")->orWhere('email','like',"%{$search}%")->orWhere('mobile','like',"%{$search}%");});
        $counts=[]; foreach(self::ROLES as $r)$counts[$r]=PartyRole::query()->where('role',$r)->where('is_active',true)->whereHas('party',fn($q)=>$q->where('company_id',$company->id)->where('is_active',true))->count();
        return view('master-data.parties',[
            'parties'=>$query->get(),'counts'=>$counts,'activeCount'=>Party::query()->where('company_id',$company->id)->where('is_active',true)->count(),
            'currencies'=>DB::table('currencies')->where('is_active',true)->orderBy('code')->get(),
            'staff'=>Staff::query()->where('company_id',$company->id)->where('is_active',true)->orderBy('name')->get(),
            'filterRole'=>$role,'filterStatus'=>$status,'search'=>$search,
        ]);
    }

    public function show(Request $request, Party $party): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$party->company_id===(int)$company->id,404);

        return redirect()->route('master-data.parties.index',[
            'q'=>$party->code,
        ])->with('status','Party '.$party->code.' loaded in the Party Register. Use Edit to update the master record.');
    }

    public function store(Request $request): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        $data=$this->validateParty($request,$company->id);
        $roles=$this->rolesFrom($request); $this->authorizeRoles($request,$roles);
        if(!$roles)return back()->withErrors(['roles'=>'Select at least one party role.'])->withInput();
        $this->ensureStaffNotLinkedElsewhere($request,$roles,null);
        DB::transaction(function()use($request,$company,$data,$roles,&$party){
            $party=Party::query()->create(array_merge($data,[
                'company_id'=>$company->id,'code'=>isset($data['code'])&&$data['code']!==null&&$data['code']!==''?strtoupper($data['code']):('TMP-'.strtoupper(Str::random(14))),'created_by'=>$request->user()->id,'updated_by'=>$request->user()->id,
                'country_code'=>strtoupper($data['country_code']??'PK'),'is_active'=>$request->boolean('is_active',true),
            ]));
            $party->update(['code'=>$data['code']??sprintf('PTY-%06d',$party->id)]);
            $this->syncRolesAndProfiles($request,$party,$roles);
            AuditService::log($request,'party.created',$party,[],$this->auditSnapshot($party->fresh()));
        });
        return back()->with('status','Party created. One master record may safely carry multiple commercial roles.');
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        $company=Company::query()->firstOrFail();
        abort_unless((int)$party->company_id===(int)$company->id,404);

        $data=$this->validateParty($request,(int)$company->id,(int)$party->id);
        $submittedRoles=$this->rolesFrom($request);
        $this->authorizeRoles($request,$submittedRoles);
        $roles=$this->preserveUnauthorizedExistingRoles($request,$party,$submittedRoles);

        if(!$roles){
            return redirect()->route('master-data.parties.index',['q'=>$party->code])
                ->withErrors(['roles'=>'A party must retain at least one role.']);
        }

        $this->ensureStaffNotLinkedElsewhere($request,$roles,(int)$party->id);
        $old=$this->auditSnapshot($party);

        try {
            DB::transaction(function()use($request,$party,$data,$roles,$old){
                // Only identity/contact fields belong on parties. Role-specific commercial
                // fields are deliberately written to their profile tables below.
                $partyFields=[
                    'code'=>strtoupper((string)$data['code']),
                    'entity_type'=>$data['entity_type'],
                    'display_name'=>$data['display_name'],
                    'legal_name'=>$data['legal_name']??null,
                    'email'=>$data['email']??null,
                    'mobile'=>$data['mobile']??null,
                    'phone'=>$data['phone']??null,
                    'tax_id'=>$data['tax_id']??null,
                    'national_id'=>$data['national_id']??null,
                    'address_line_1'=>$data['address_line_1']??null,
                    'address_line_2'=>$data['address_line_2']??null,
                    'city'=>$data['city']??null,
                    'country_code'=>strtoupper((string)($data['country_code']??'PK')),
                    'notes'=>$data['notes']??null,
                    'is_active'=>$request->boolean('is_active'),
                    'updated_by'=>$request->user()->id,
                ];
                $party->update($partyFields);
                $this->syncRolesAndProfiles($request,$party,$roles);
                AuditService::log($request,'party.updated',$party,$old,$this->auditSnapshot($party->fresh()));
            });
        } catch (Throwable $e) {
            report($e);
            return redirect()->route('master-data.parties.index',['q'=>$party->code])
                ->withErrors(['party'=>'Party update could not be completed. Runtime code has been aligned to the ERP-05 currency-code schema; if this message repeats, send the exact message shown in System Health.']);
        }

        return redirect()->route('master-data.parties.index',['q'=>$party->code])
            ->with('status','Party updated. Existing accounting history remains linked to this party identity.');
    }

    private function validateParty(Request $request,int $companyId,?int $ignoreId=null): array
    {
        $unique=Rule::unique('parties')->where('company_id',$companyId); if($ignoreId)$unique=$unique->ignore($ignoreId);
        return $request->validate([
            'code'=>[$ignoreId?'required':'nullable','string','max:40',$unique],
            'entity_type'=>['required','in:PERSON,ORGANIZATION'],'display_name'=>['required','string','max:180'],'legal_name'=>['nullable','string','max:220'],
            'email'=>['nullable','email','max:190'],'mobile'=>['nullable','string','max:60'],'phone'=>['nullable','string','max:60'],
            'tax_id'=>['nullable','string','max:80'],'national_id'=>['nullable','string','max:80'],
            'address_line_1'=>['nullable','string','max:220'],'address_line_2'=>['nullable','string','max:220'],'city'=>['nullable','string','max:100'],
            'country_code'=>['nullable','string','size:2'],'notes'=>['nullable','string','max:3000'],'is_active'=>['nullable','boolean'],
            'customer_category'=>['nullable','in:B2B,B2C,CORPORATE,WALK_IN'],'customer_currency_code'=>['nullable','string','size:3','exists:currencies,code'],'credit_limit'=>['nullable','numeric','min:0'],'credit_days'=>['nullable','integer','min:0','max:3650'],'credit_status'=>['nullable','in:ACTIVE,HOLD,BLOCKED'],'customer_reference'=>['nullable','string','max:100'],
            'vendor_category'=>['nullable','in:AIRLINE_GSA,HOTEL,VISA,TRANSPORT,INSURANCE,GENERAL'],'vendor_currency_code'=>['nullable','string','size:3','exists:currencies,code'],'payment_terms_days'=>['nullable','integer','min:0','max:3650'],'vendor_status'=>['nullable','in:ACTIVE,HOLD,BLOCKED'],'supplier_reference'=>['nullable','string','max:100'],
            'agent_category'=>['nullable','in:B2B_AGENT,REFERRER,SALES_PARTNER,SERVICE_PROVIDER'],'agent_currency_code'=>['nullable','string','size:3','exists:currencies,code'],'default_commission_basis'=>['nullable','in:PER_TICKET,PER_PASSENGER,PER_SERVICE,PER_INVOICE,FIXED,PERCENT_OF_SALE,PERCENT_OF_REVENUE,PERCENT_OF_MARGIN'],'default_commission_rate'=>['nullable','numeric','min:0'],'settlement_days'=>['nullable','integer','min:0','max:3650'],'agent_status'=>['nullable','in:ACTIVE,HOLD,BLOCKED'],
            'staff_id'=>['nullable','exists:staff,id'],
        ]);
    }

    private function rolesFrom(Request $request): array
    {
        $roles=array_values(array_unique(array_map('strtoupper',(array)$request->input('roles',[]))));
        return array_values(array_intersect($roles,self::ROLES));
    }

    private function authorizeRoles(Request $request,array $roles): void
    {
        $map=['CUSTOMER'=>'customers.manage','VENDOR'=>'vendors.manage','AGENT'=>'agents.manage','EMPLOYEE'=>'employees.manage'];
        foreach($roles as $role){if(isset($map[$role]))abort_unless($request->user()->hasPermission($map[$role]),403);}
    }

    private function syncRolesAndProfiles(Request $request,Party $party,array $roles): void
    {
        foreach(self::ROLES as $role){PartyRole::query()->updateOrCreate(['party_id'=>$party->id,'role'=>$role],['is_active'=>in_array($role,$roles,true)]);}
        $user=$request->user();
        if($user->hasPermission('customers.manage')) {
            if(in_array('CUSTOMER',$roles,true)) CustomerProfile::query()->updateOrCreate(['party_id'=>$party->id],[
                'default_currency_code'=>$request->input('customer_currency_code'),'customer_category'=>$request->input('customer_category','B2C'),'credit_limit'=>$request->input('credit_limit',0),'credit_days'=>$request->input('credit_days',0),'credit_status'=>$request->input('credit_status','ACTIVE'),'external_reference'=>$request->input('customer_reference'),
            ]);
        }
        if($user->hasPermission('vendors.manage')) {
            if(in_array('VENDOR',$roles,true)) VendorProfile::query()->updateOrCreate(['party_id'=>$party->id],[
                'default_currency_code'=>$request->input('vendor_currency_code'),'vendor_category'=>$request->input('vendor_category','GENERAL'),'payment_terms_days'=>$request->input('payment_terms_days',0),'vendor_status'=>$request->input('vendor_status','ACTIVE'),'supplier_reference'=>$request->input('supplier_reference'),
            ]);
        }
        if($user->hasPermission('agents.manage')) {
            if(in_array('AGENT',$roles,true)) AgentProfile::query()->updateOrCreate(['party_id'=>$party->id],[
                'default_currency_code'=>$request->input('agent_currency_code'),'agent_category'=>$request->input('agent_category','B2B_AGENT'),'default_commission_basis'=>$request->input('default_commission_basis','PER_SERVICE'),'default_commission_rate'=>$request->input('default_commission_rate',0),'settlement_days'=>$request->input('settlement_days',0),'agent_status'=>$request->input('agent_status','ACTIVE'),
            ]);
        }
        if($user->hasPermission('employees.manage')) {
            if(in_array('EMPLOYEE',$roles,true)) EmployeePartyProfile::query()->updateOrCreate(['party_id'=>$party->id],['staff_id'=>$request->input('staff_id'),'employee_account_status'=>'ACTIVE']);
        }
    }

    private function preserveUnauthorizedExistingRoles(Request $request, Party $party, array $submitted): array
    {
        $party->loadMissing('roles');
        $roles=$submitted;
        $permissionMap=['CUSTOMER'=>'customers.manage','VENDOR'=>'vendors.manage','AGENT'=>'agents.manage','EMPLOYEE'=>'employees.manage'];
        foreach($party->roles->where('is_active',true) as $existing){
            $permission=$permissionMap[$existing->role]??null;
            if($permission && ! $request->user()->hasPermission($permission)) $roles[]=$existing->role;
        }
        return array_values(array_unique($roles));
    }

    private function ensureStaffNotLinkedElsewhere(Request $request, array $roles, ?int $partyId): void
    {
        if(! in_array('EMPLOYEE',$roles,true) || ! $request->filled('staff_id') || ! $request->user()->hasPermission('employees.manage')) return;
        $exists=EmployeePartyProfile::query()->where('staff_id',$request->integer('staff_id'))->when($partyId,fn($q)=>$q->where('party_id','!=',$partyId))->exists();
        if($exists) throw ValidationException::withMessages(['staff_id'=>'This staff member is already linked to another employee accounting party.']);
    }

    private function auditSnapshot(Party $party): array
    {
        $party->loadMissing(['roles','customerProfile','vendorProfile','agentProfile','employeeProfile']);
        return [
            'party'=>$party->only(['id','code','entity_type','display_name','legal_name','email','mobile','phone','tax_id','national_id','city','country_code','is_active']),
            'roles'=>$party->roles->where('is_active',true)->pluck('role')->values()->all(),
            'customer'=>$party->customerProfile?->toArray(),'vendor'=>$party->vendorProfile?->toArray(),'agent'=>$party->agentProfile?->toArray(),'employee'=>$party->employeeProfile?->toArray(),
        ];
    }
}
