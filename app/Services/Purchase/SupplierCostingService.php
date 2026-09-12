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
        private readonly BookingSupplierObligationResolver $obligations,
    ) {
    }

    public function nextNumber(): string
    {
        $year=now()->format('Y');$prefix='SC-'.$year.'-';$last=DB::table('supplier_costings')->where('costing_no','like',$prefix.'%')->orderByDesc('id')->value('costing_no');$seq=$last&&preg_match('/(\d+)$/',(string)$last,$m)?((int)$m[1]+1):1000;return $prefix.(string)$seq;
    }

    public function recalculate(int $id): void
    {
        $t=DB::table('supplier_costing_lines')->where('supplier_costing_id',$id)->selectRaw('COALESCE(SUM(base_cost),0) base_cost, COALESCE(SUM(tax_amount),0) tax_amount, COALESCE(SUM(other_charges),0) other_charges, COALESCE(SUM(total_cost),0) total_cost')->first();DB::table('supplier_costings')->where('id',$id)->update(['base_cost'=>(float)$t->base_cost,'tax_amount'=>(float)$t->tax_amount,'other_charges'=>(float)$t->other_charges,'total_cost'=>(float)$t->total_cost,'updated_at'=>now()]);
    }

    public function transition(int $id,string $action,$user): void
    {
        DB::transaction(function()use($id,$action,$user):void{
            $row=DB::table('supplier_costings')->where('id',$id)->lockForUpdate()->first();if(!$row)throw new RuntimeException('Supplier costing document not found.');$map=['submit'=>['draft','pending_approval'],'approve'=>['pending_approval','approved'],'post'=>['approved','posted']];if(!isset($map[$action]))throw new RuntimeException('Unsupported workflow action.');[$from,$to]=$map[$action];if($row->status!==$from)throw new RuntimeException('Workflow action is not valid for the current status.');if($action!=='submit'&&!$this->canApprove($user))throw new RuntimeException('You are not authorized to approve/post supplier costing.');$this->validateBookingSourceIntegrity($row);if((float)$row->total_cost<=0)throw new RuntimeException('Supplier costing total must be greater than zero.');
            $update=['status'=>$to,'updated_at'=>now()];if($action==='submit'){$update['submitted_by']=$user?->id;$update['submitted_at']=now();}if($action==='approve'){$update['approved_by']=$user?->id;$update['approved_at']=now();}if($action==='post'){$reference='SCPOST-'.now()->format('Ymd').'-'.(string)$id;$this->createPosting($row,$reference);$this->nativeJournal->postSupplierCosting($id,$user,$reference);$update['posted_by']=$user?->id;$update['posted_at']=now();$update['posting_reference']=$reference;}
            DB::table('supplier_costings')->where('id',$id)->update($update);$this->activity($id,$action,$from,$to,$user,$action==='post'?'Balanced supplier payable posting and native journal created.':null);
        });
    }

    public function canApprove($user): bool
    {
        if(!$user)return false;foreach(['is_super_admin','is_admin'] as $flag)if(!empty($user->{$flag}))return true;$role=strtolower((string)($user->role??$user->role_name??''));if(in_array($role,['super admin','super_admin','admin','administrator'],true))return true;foreach(['approve supplier costing','manage supplier costing','post supplier costing'] as $ability){try{if(method_exists($user,'can')&&$user->can($ability))return true;}catch(\Throwable){}}return false;
    }

    /** @return list<object> */
    public function accountingPreview(int $id): array
    {
        $row = DB::table('supplier_costings')->where('id', $id)->first();
        if (! $row) return [];
        $source = DB::table('supplier_costing_lines')->where('supplier_costing_id', $id)->get(['service_type', 'total_cost']);
        $group = [];
        foreach ($source as $line) {
            $amount = round((float) $line->total_cost, 2);
            if ($amount <= 0) continue;
            $key = $this->costCodeForService((string) $line->service_type);
            $group[$key] = ($group[$key] ?? 0) + $amount;
        }
        $preview = [];
        foreach ($group as $code => $amount) {
            $account = $this->chartAccount(null, $code);
            $preview[] = (object) ['account_code' => $account['code'], 'account_name' => $account['name'], 'party_type' => null, 'party_id' => null, 'debit' => round($amount, 2), 'credit' => 0.0];
        }
        $payable = $this->chartAccount('VENDOR_AP', '2110');
        $preview[] = (object) ['account_code' => $payable['code'], 'account_name' => $payable['name'], 'party_type' => 'supplier', 'party_id' => $row->supplier_id, 'debit' => 0.0, 'credit' => round((float) $row->total_cost, 2)];
        return $preview;
    }

    private function validateBookingSourceIntegrity(object $row): void
    {
        if (! Schema::hasTable('supplier_costing_source_links')) return;
        $links = DB::table('supplier_costing_source_links')->where('supplier_costing_id', $row->id)->lockForUpdate()->get();
        if ($links->isEmpty()) return; // Historical/manual documents are preserved without guessed backfill.
        if ((int) $row->booking_id <= 0 || (int) $row->supplier_id <= 0) throw new RuntimeException('Booking-driven Supplier Costing requires one booking and one supplier.');

        $current = collect($this->obligations->resolve((int) $row->booking_id, (int) $row->id))->keyBy('source_key');
        $lines = DB::table('supplier_costing_lines')->where('supplier_costing_id', $row->id)->get()->keyBy('id');
        if ($links->count() !== $lines->count()) throw new RuntimeException('Every Supplier Costing line must retain one authoritative booking source link.');

        $expectedKeys = $current
            ->filter(fn (array $source): bool => (int) $source['supplier_id'] === (int) $row->supplier_id && (string) $source['status'] === 'available')
            ->keys()->sort()->values()->all();
        $linkedKeys = $links->pluck('source_key')->map(fn ($key): string => (string) $key)->sort()->values()->all();
        if ($expectedKeys !== $linkedKeys) throw new RuntimeException('Booking vendor obligations changed. Refresh the Draft before continuing.');

        foreach ($links as $link) {
            $source = $current->get((string) $link->source_key);
            $line = $lines->get((int) $link->supplier_costing_line_id);
            if (! $source || ! $line) throw new RuntimeException('A booking source linked to this Supplier Costing no longer exists. Refresh the Draft before continuing.');
            if ((int) $source['supplier_id'] !== (int) $row->supplier_id || (int) $link->supplier_id !== (int) $row->supplier_id) throw new RuntimeException('Supplier Costing source vendor no longer matches the header supplier.');
            if ((string) $source['product_type'] !== (string) $link->product_type || (string) $line->service_type !== (string) $link->product_type) throw new RuntimeException('Supplier Costing product source traceability does not reconcile.');
            if (abs((float) $source['source_cost'] - (float) $link->source_cost_snapshot) > 0.005 || abs((float) $line->base_cost - (float) $link->source_cost_snapshot) > 0.005) throw new RuntimeException('Authoritative booking vendor cost changed. Refresh the Draft before continuing.');
            $expectedTotal = round((float) $line->base_cost + (float) $line->tax_amount + (float) $line->other_charges, 2);
            if (abs($expectedTotal - (float) $line->total_cost) > 0.005) throw new RuntimeException('Supplier Costing line total does not reconcile to source cost, tax, and other charges.');
        }
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
    {
        try {
            return app(\App\Services\Operations\UnifiedGroupPackageDataSource::class)->vendors()
                ->map(static fn (array $row): array => ['id' => (int) ($row['id'] ?? 0), 'name' => trim((string) ($row['name'] ?? ''))])
                ->filter(static fn (array $row): bool => $row['id'] > 0 && $row['name'] !== '')->unique('id')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function bookingOptions(): array
    {foreach(['bookings','travel_bookings','booking_group_package_unified'] as $table){if(!Schema::hasTable($table))continue;$cols=Schema::getColumnListing($table);$id=$this->first($cols,['id','booking_id']);$ref=$this->first($cols,['booking_no','booking_number','booking_ref','reference','booking_reference']);if(!$id)continue;$select=[$id.' as id'];if($ref)$select[]=$ref.' as reference';$rows=DB::table($table)->select($select)->orderByDesc($id)->limit(300)->get();return $rows->map(fn($r)=>['id'=>$r->id,'reference'=>$r->reference??('Booking #'.$r->id)])->all();}return [];}

    private function first(array $cols,array $candidates): ?string{foreach($candidates as $c)if(in_array($c,$cols,true))return $c;return null;}
}
