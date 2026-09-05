<?php

namespace App\Services\Purchase;

use App\Services\Accounting\CashVoucherNativeJournalBridge;
use App\Services\Accounting\ChartOfAccountsWorkspaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class SupplierCostingService
{
    public function __construct(
        private readonly ChartOfAccountsWorkspaceService $chart,
        private readonly CashVoucherNativeJournalBridge $nativeJournal,
    ) {
    }

    public function nextNumber(): string
    {
        $year=now()->format('Y');$prefix='SC-'.$year.'-';$last=DB::table('supplier_costings')->where('costing_no','like',$prefix.'%')->orderByDesc('id')->value('costing_no');$seq=$last&&preg_match('/(\d+)$/',(string)$last,$m)?((int)$m[1]+1):1;return $prefix.str_pad((string)$seq,6,'0',STR_PAD_LEFT);
    }

    public function recalculate(int $id): void
    {
        $t=DB::table('supplier_costing_lines')->where('supplier_costing_id',$id)->selectRaw('COALESCE(SUM(base_cost),0) base_cost, COALESCE(SUM(tax_amount),0) tax_amount, COALESCE(SUM(other_charges),0) other_charges, COALESCE(SUM(total_cost),0) total_cost')->first();DB::table('supplier_costings')->where('id',$id)->update(['base_cost'=>(float)$t->base_cost,'tax_amount'=>(float)$t->tax_amount,'other_charges'=>(float)$t->other_charges,'total_cost'=>(float)$t->total_cost,'updated_at'=>now()]);
    }

    public function transition(int $id,string $action,$user): void
    {
        DB::transaction(function()use($id,$action,$user):void{
            $row=DB::table('supplier_costings')->where('id',$id)->lockForUpdate()->first();if(!$row)throw new RuntimeException('Supplier costing document not found.');$map=['submit'=>['draft','pending_approval'],'approve'=>['pending_approval','approved'],'post'=>['approved','posted']];if(!isset($map[$action]))throw new RuntimeException('Unsupported workflow action.');[$from,$to]=$map[$action];if($row->status!==$from)throw new RuntimeException('Workflow action is not valid for the current status.');if($action!=='submit'&&!$this->canApprove($user))throw new RuntimeException('You are not authorized to approve/post supplier costing.');if((float)$row->total_cost<=0)throw new RuntimeException('Supplier costing total must be greater than zero.');
            $update=['status'=>$to,'updated_at'=>now()];if($action==='submit'){$update['submitted_by']=$user?->id;$update['submitted_at']=now();}if($action==='approve'){$update['approved_by']=$user?->id;$update['approved_at']=now();}if($action==='post'){$reference='SCPOST-'.now()->format('Ymd').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);$this->createPosting($row,$reference);$this->nativeJournal->postSupplierCosting($id,$user,$reference);$update['posted_by']=$user?->id;$update['posted_at']=now();$update['posting_reference']=$reference;}
            DB::table('supplier_costings')->where('id',$id)->update($update);$this->activity($id,$action,$from,$to,$user,$action==='post'?'Balanced supplier payable posting and native journal created.':null);
        });
    }

    public function canApprove($user): bool
    {
        if(!$user)return false;foreach(['is_super_admin','is_admin'] as $flag)if(!empty($user->{$flag}))return true;$role=strtolower((string)($user->role??$user->role_name??''));if(in_array($role,['super admin','super_admin','admin','administrator'],true))return true;foreach(['approve supplier costing','manage supplier costing','post supplier costing'] as $ability){try{if(method_exists($user,'can')&&$user->can($ability))return true;}catch(\Throwable){}}return false;
    }

    private function createPosting(object $row,string $reference): void
    {
        if(DB::table('supplier_costing_posting_lines')->where('supplier_costing_id',$row->id)->exists())throw new RuntimeException('This supplier costing document already has accounting posting lines.');
        $currency=(string)($row->currency_code?:'PKR');$rate=(float)($row->exchange_rate?:1);$now=now();$narration=trim((string)($row->remarks??''));
        $source=DB::table('supplier_costing_lines')->where('supplier_costing_id',$row->id)->get(['service_type','total_cost']);$group=[];foreach($source as $line){$amount=round((float)$line->total_cost,2);if($amount<=0)continue;$key=$this->costCodeForService((string)$line->service_type);$group[$key]=($group[$key]??0)+$amount;}
        if($group===[]){$group[$this->costCodeForService((string)$row->service_type)]=round((float)$row->total_cost,2);}
        $lines=[];$debit=0.0;foreach($group as $code=>$amount){$acct=$this->chartAccount(null,$code);$amount=round($amount,2);$debit+=$amount;$lines[]=['supplier_costing_id'=>$row->id,'posting_reference'=>$reference,'account_code'=>$acct['code'],'account_name'=>$acct['name'],'party_type'=>null,'party_id'=>null,'debit'=>$amount,'credit'=>0,'currency_code'=>$currency,'exchange_rate'=>$rate,'narration'=>$narration!==''?$narration:'Supplier cost '.$row->costing_no,'created_at'=>$now,'updated_at'=>$now];}
        $payable=$this->chartAccount('VENDOR_AP','2110');$credit=round((float)$row->total_cost,2);if(abs(round($debit,2)-$credit)>0.005)throw new RuntimeException('Supplier costing source lines do not equal the document total; posting stopped.');$lines[]=['supplier_costing_id'=>$row->id,'posting_reference'=>$reference,'account_code'=>$payable['code'],'account_name'=>$payable['name'],'party_type'=>'supplier','party_id'=>$row->supplier_id,'debit'=>0,'credit'=>$credit,'currency_code'=>$currency,'exchange_rate'=>$rate,'narration'=>$narration!==''?$narration:'Supplier payable '.$row->costing_no.' - '.($row->supplier_name?:'Supplier'),'created_at'=>$now,'updated_at'=>$now];DB::table('supplier_costing_posting_lines')->insert($lines);
    }

    private function costCodeForService(string $service): string
    {
        $s=strtolower(trim($service));if(str_contains($s,'air')||str_contains($s,'ticket')||str_contains($s,'flight'))return '5110';if(str_contains($s,'visa'))return '5120';if(str_contains($s,'hotel'))return '5130';if(str_contains($s,'transport'))return '5140';if(str_contains($s,'umrah')||str_contains($s,'package'))return '5150';if(str_contains($s,'commission'))return '5310';return '5190';
    }

    private function chartAccount(?string $control,string $fallbackCode): array
    {
        $s=$this->chart->schema();$row=null;if($control&&$s['control_type'])$row=DB::table($s['table'])->where($s['control_type'],$control)->first();if(!$row)$row=DB::table($s['table'])->where($s['code'],$fallbackCode)->first();if(!$row)throw new RuntimeException('Required Chart account '.$fallbackCode.($control?' / '.$control:'').' was not found.');return ['code'=>(string)$row->{$s['code']},'name'=>(string)$row->{$s['name']}];
    }

    public function activity(int $id,string $action,?string $from,?string $to,$user,?string $notes=null): void
    {DB::table('supplier_costing_activities')->insert(['supplier_costing_id'=>$id,'action'=>$action,'from_status'=>$from,'to_status'=>$to,'user_id'=>$user?->id,'user_name'=>$user?->name??$user?->email,'notes'=>$notes,'created_at'=>now(),'updated_at'=>now()]);}

    public function supplierOptions(): array
    {foreach(['parties','party_master','party_masters','suppliers','vendors'] as $table){if(!Schema::hasTable($table))continue;$cols=Schema::getColumnListing($table);$id=$this->first($cols,['id','party_id','supplier_id','vendor_id']);$name=$this->first($cols,['name','party_name','supplier_name','vendor_name','display_name','company_name']);if(!$id||!$name)continue;$q=DB::table($table)->select([$id.' as id',$name.' as name']);$type=$this->first($cols,['type','party_type','category']);if($type)$q->where(function($qq)use($type){$qq->where($type,'like','%supplier%')->orWhere($type,'like','%vendor%')->orWhereNull($type);});return $q->orderBy($name)->limit(500)->get()->map(fn($r)=>(array)$r)->all();}return [];}

    public function bookingOptions(): array
    {foreach(['bookings','travel_bookings','booking_group_package_unified'] as $table){if(!Schema::hasTable($table))continue;$cols=Schema::getColumnListing($table);$id=$this->first($cols,['id','booking_id']);$ref=$this->first($cols,['booking_no','booking_number','booking_ref','reference','booking_reference']);if(!$id)continue;$select=[$id.' as id'];if($ref)$select[]=$ref.' as reference';$rows=DB::table($table)->select($select)->orderByDesc($id)->limit(300)->get();return $rows->map(fn($r)=>['id'=>$r->id,'reference'=>$r->reference??('Booking #'.$r->id)])->all();}return [];}

    private function first(array $cols,array $candidates): ?string{foreach($candidates as $c)if(in_array($c,$cols,true))return $c;return null;}
}
