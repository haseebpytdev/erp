<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Controlled opening-balance authority for four approved balance domains. */
final class PartyBalanceLifecycleService
{
    public function __construct(private readonly AccountingPartyRoleResolver $roles, private readonly ChartOfAccountsWorkspaceService $chart, private readonly CashVoucherNativeJournalBridge $native) {}

    public function parties(string $type): array { return $this->roles->options($type === 'vendor' ? 'supplier' : 'customer'); }
    public function assertBranchForUser(int $branchId, mixed $user): void { $this->assertBranch($branchId); if ($this->native->authorizedBranchId($user, $branchId) !== $branchId) throw new RuntimeException('The selected branch is outside your authorized branch scope.'); }
    public function authorizedBranchForUser(?int $selected, mixed $user): int { return $this->native->authorizedBranchId($user, $selected); }

    public function mapping(string $key): array
    {
        $s = $this->chart->schema();
        if (!$s['control_type']) throw new RuntimeException('Opening Balance Clearing account is not configured.');
        $matches = DB::table($s['table'])->whereRaw('UPPER('.$s['control_type'].')=?', [strtoupper($key)])->get();
        if ($matches->count() !== 1) throw new RuntimeException('Opening Balance Clearing account identity is missing or duplicated.');
        $row = $matches->first();
        if (!$this->isPosting($row, $s) || ($s['control_flag'] && property_exists($row, $s['control_flag']) && !((bool) $row->{$s['control_flag']}))) throw new RuntimeException('Opening Balance Clearing account is not configured.');
        $this->assertClearingCompatibility($row, $s);
        return ['id' => (int) $row->{$s['id']}, 'code' => (string) $row->{$s['code']}, 'name' => (string) $row->{$s['name']}];
    }

    private function assertClearingCompatibility(object $row, array $s): void
    {
        if ($this->normalizeClearingText((string) $row->{$s['name']}) !== 'opening balance clearing' || $this->normalizeClearingType((string) $row->{$s['type']}) !== 'equity') throw new RuntimeException('Opening Balance Clearing account is semantically incompatible.');
        if ($s['subtype'] && $this->normalizeClearingText((string) ($row->{$s['subtype']} ?? '')) !== 'opening balance / migration clearing') throw new RuntimeException('Opening Balance Clearing subtype is incompatible.');
        if ($s['normal'] && strtolower(trim((string) ($row->{$s['normal']} ?? ''))) !== 'credit') throw new RuntimeException('Opening Balance Clearing normal balance must be Credit.');
        if ($s['status'] && ! in_array(strtolower(trim((string) ($row->{$s['status']} ?? ''))), ['active','enabled','open'], true)) throw new RuntimeException('Opening Balance Clearing account is not active.');
        if ($s['active'] && ! ((bool) ($row->{$s['active']} ?? false))) throw new RuntimeException('Opening Balance Clearing account is inactive.');
        if ($s['parent']) { $value = $row->{$s['parent']} ?? null; if ($value === null || $value === '') throw new RuntimeException('Opening Balance Clearing parent is missing.'); $parent = $this->runtimeParentUsesId($s) ? DB::table($s['table'])->where($s['id'], $value)->first() : DB::table($s['table'])->where($s['code'], (string) $value)->first(); if (!$parent || $this->normalizeClearingType((string) $parent->{$s['type']}) !== 'equity') throw new RuntimeException('Opening Balance Clearing parent is incompatible.'); }
    }

    private function normalizeClearingType(string $value): string { $v = strtolower(trim($value)); return str_contains($v, 'equity') || str_contains($v, 'capital') ? 'equity' : $v; }
    private function normalizeClearingText(string $value): string { return strtolower((string) preg_replace('/\s+/', ' ', trim($value))); }
    private function runtimeParentUsesId(array $s): bool { if (str_ends_with(strtolower((string) $s['parent']), '_id')) return true; $sample=DB::table($s['table'])->whereNotNull($s['parent'])->value($s['parent']); if($sample===null||$sample==='')return false; $byId=DB::table($s['table'])->where($s['id'],$sample)->exists();$byCode=DB::table($s['table'])->where($s['code'],(string)$sample)->exists();if($byId&&!$byCode)return true;if($byCode&&!$byId)return false;return str_contains(strtolower((string)$s['parent']),'id'); }

    public function createOpening(array $data, mixed $user): int
    {
        return DB::transaction(function () use ($data, $user): int {
            $type = (string) $data['party_type']; $party = $this->roles->assertRole((int) $data['party_id'], $type === 'vendor' ? 'supplier' : 'customer');
            $balance = (string) ($data['balance_type'] ?? $data['opening_type']); $this->assertOpeningType($type, $balance); $this->assertBranch((int) $data['branch_id']); $this->mapping('OPENING_BALANCE_CLEARING'); $now = now(); $no = $this->nextOpeningNumber();
            $id = DB::table('party_opening_balances')->insertGetId(['opening_no'=>$no,'party_type'=>$type,'party_id'=>$party['party_id'],'party_name'=>$party['party_name'],'balance_type'=>$balance,'opening_date'=>$data['opening_date'],'amount'=>round((float)$data['amount'],2),'currency_code'=>strtoupper($data['currency_code']??'PKR'),'exchange_rate'=>(float)($data['exchange_rate']??1),'branch_id'=>(int)$data['branch_id'],'company_id'=>$data['company_id']??null,'legacy_reference'=>$data['legacy_reference']??null,'external_account_reference'=>$data['external_account_reference']??null,'narration'=>$data['narration']??null,'supporting_document'=>$data['supporting_document']??null,'status'=>'draft','created_by'=>$user?->id,'created_at'=>$now,'updated_at'=>$now]);
            DB::table('party_opening_balance_activities')->insert(['party_opening_balance_id'=>$id,'action'=>'create','to_status'=>'draft','user_id'=>$user?->id,'remarks'=>'Opening balance draft created.','created_at'=>$now,'updated_at'=>$now]); return $id;
        });
    }

    public function transitionOpening(int $id, string $action, mixed $user): void
    {
        DB::transaction(function () use ($id, $action, $user): void {
            $row = DB::table('party_opening_balances')->where('id', $id)->lockForUpdate()->first();
            if (!$row) throw new RuntimeException('Opening balance not found.');
            $from = ['submit'=>'draft','approve'=>'pending_approval','post'=>'approved'][$action] ?? null;
            $to = ['submit'=>'pending_approval','approve'=>'approved','post'=>'posted'][$action] ?? null;
            if (!$from || $row->status !== $from) throw new RuntimeException('Opening balance lifecycle transition is not allowed.');
            $this->assertBranchForUser((int) $row->branch_id, $user);
            if ($action === 'post') {
                $this->roles->assertRole((int) $row->party_id, (string) $row->party_type);
                $this->postOpening($row, $user);
            }
            $now = now(); $fields = ['status'=>$to, 'updated_at'=>$now];
            if ($action === 'submit') { $fields['submitted_by']=$user?->id; $fields['submitted_at']=$now; }
            if ($action === 'approve') { $fields['approved_by']=$user?->id; $fields['approved_at']=$now; }
            if ($action === 'post') { $fields['posted_by']=$user?->id; $fields['posted_at']=$now; $fields['posting_reference']='OBPOST-'.$row->id; }
            DB::table('party_opening_balances')->where('id',$id)->update($fields);
            DB::table('party_opening_balance_activities')->insert(['party_opening_balance_id'=>$id,'action'=>$action,'from_status'=>$from,'to_status'=>$to,'user_id'=>$user?->id,'remarks'=>'Workflow transition.','created_at'=>$now,'updated_at'=>$now]);
        });
    }

    public function reverseOpening(int $id, string $reason, mixed $user): void
    {
        DB::transaction(function () use ($id, $reason, $user): void {
            $r=DB::table('party_opening_balances')->where('id',$id)->lockForUpdate()->first(); if(!$r||$r->status!=='posted') throw new RuntimeException('Only posted opening balances can be reversed.'); if(trim($reason)==='') throw new RuntimeException('A reversal reason is required.'); $this->assertBranchForUser((int)$r->branch_id,$user); $this->assertOpeningReversalDependenciesLocked($r);
            if(DB::table('party_opening_balance_posting_lines')->where('party_opening_balance_id',$id)->where('entry_type','reversal')->lockForUpdate()->exists()) throw new RuntimeException('This opening balance has already been reversed.');
            $rows=DB::table('party_opening_balance_posting_lines')->where('party_opening_balance_id',$id)->where('entry_type','original')->lockForUpdate()->get(); $lines=[]; foreach($rows as $x){$a=(array)$x; $lines[]=['account_code'=>$a['account_code'],'account_name'=>$a['account_name'],'party_type'=>$a['party_type'],'party_id'=>$a['party_id'],'debit'=>$a['credit'],'credit'=>$a['debit'],'base_debit'=>$a['base_credit'],'base_credit'=>$a['base_debit'],'currency_code'=>$a['currency_code'],'exchange_rate'=>$a['exchange_rate'],'narration'=>$reason];} $this->insertLines($id,$lines,'reversal');
            $this->native->postControlledDocument($this->document($r),DB::table('party_opening_balance_posting_lines')->where('party_opening_balance_id',$id)->where('entry_type','reversal')->get(),'party_opening_balance_reversal',$id,'OBREV-'.$id,'Party opening balance reversal',$user,$r->posting_journal_id,'party_opening_balance','party_opening_balance_posting_line'); DB::table('party_opening_balances')->where('id',$id)->update(['status'=>'reversed','reversal_reference'=>'OBREV-'.$id,'reversed_by'=>$user?->id,'reversed_at'=>now(),'reversal_reason'=>$reason,'updated_at'=>now()]);
        });
    }

    private function assertOpeningReversalDependenciesLocked(object $r): void
    {
        $id=(int)$r->id; $type=(string)$r->balance_type; $target=in_array($type,['vendor_payable','vendor_advance'],true)?'party_opening_payable':'party_opening_balance'; $used=false;
        if(in_array($type,['customer_receivable','vendor_payable'],true)&&Schema::hasTable('cash_voucher_allocations')&&Schema::hasTable('cash_vouchers')) $used=DB::table('cash_voucher_allocations as a')->join('cash_vouchers as v','v.id','=','a.cash_voucher_id')->where('v.status','posted')->where('a.target_type',$target)->where('a.target_id',$id)->lockForUpdate()->exists();
        if(!$used&&in_array($type,['customer_receivable','vendor_payable'],true)&&Schema::hasTable('advance_adjustments')) $used=DB::table('advance_adjustments')->where('status','posted')->where('target_type',$target)->where('target_id',$id)->lockForUpdate()->exists();
        if(!$used&&in_array($type,['customer_advance','vendor_advance'],true)&&Schema::hasTable('advance_adjustments')) $used=DB::table('advance_adjustments')->where('status','posted')->where('advance_source_type','party_opening_balance')->where('advance_source_id',$id)->lockForUpdate()->exists();
        if(!$used&&$type==='customer_advance'&&Schema::hasTable('customer_advance_return_allocations')&&Schema::hasTable('customer_advance_returns')) $used=DB::table('customer_advance_return_allocations as a')->join('customer_advance_returns as r','r.id','=','a.customer_advance_return_id')->where('r.status','posted')->where('a.advance_source_type','party_opening_balance')->where('a.advance_source_id',$id)->lockForUpdate()->exists();
        if($used) throw new RuntimeException('This opening balance has Posted downstream usage and cannot be reversed. Reverse dependent documents first.');
    }

    private function postOpening(object $r,mixed $user): void { $clear=$this->mapping('OPENING_BALANCE_CLEARING');$map=['customer_receivable'=>[['CUSTOMER_AR',$r->amount,0,$r->party_type,$r->party_id],['CLEAR',0,$r->amount,null,null]],'customer_advance'=>[['CLEAR',$r->amount,0,null,null],['CUSTOMER_ADVANCE',0,$r->amount,$r->party_type,$r->party_id]],'vendor_payable'=>[['CLEAR',$r->amount,0,null,null],['VENDOR_AP',0,$r->amount,'vendor',$r->party_id]],'vendor_advance'=>[['VENDOR_ADVANCE',$r->amount,0,'vendor',$r->party_id],['CLEAR',0,$r->amount,null,null]]][$r->balance_type]??null;if(!$map)throw new RuntimeException('Unsupported opening balance type.');$lines=[];foreach($map as $x){$a=$x[0]==='CLEAR'?$clear:$this->control($x[0]);$lines[]=['account_code'=>$a['code'],'account_name'=>$a['name'],'party_type'=>$x[3],'party_id'=>$x[4],'debit'=>$x[1],'credit'=>$x[2],'currency_code'=>$r->currency_code,'exchange_rate'=>$r->exchange_rate,'narration'=>$r->narration];}$this->insertLines($r->id,$lines,'original');$jid=$this->native->postControlledDocument($this->document($r),DB::table('party_opening_balance_posting_lines')->where('party_opening_balance_id',$r->id)->where('entry_type','original')->get(),'party_opening_balance',$r->id,$r->opening_no,'Party opening balance',$user,null,'party_opening_balance','party_opening_balance_posting_line');DB::table('party_opening_balances')->where('id',$r->id)->update(['posting_journal_id'=>$jid]); }
    private function control(string $key): array { $s=$this->chart->schema();if(!$s['control_type'])throw new RuntimeException('Required accounting control account is not configured.');$r=DB::table($s['table'])->whereRaw('UPPER('.$s['control_type'].')=?',[$key])->first();if(!$r||!$this->isPosting($r,$s)||($s['control_flag']&&property_exists($r,$s['control_flag'])&&!((bool)$r->{$s['control_flag']}))||($s['type']&&property_exists($r,$s['type'])&&strtolower((string)$r->{$s['type']})==='header') )throw new RuntimeException('Required accounting control account is not configured.');return ['code'=>(string)$r->{$s['code']},'name'=>(string)$r->{$s['name']}]; }
    private function insertLines(int $id,array $lines,string $entry): void { foreach($lines as $i=>$l)DB::table('party_opening_balance_posting_lines')->insert(['party_opening_balance_id'=>$id,'line_no'=>$i+1,'account_code'=>$l['account_code'],'account_name'=>$l['account_name'],'party_type'=>$l['party_type']??null,'party_id'=>$l['party_id']??null,'debit'=>$l['debit'],'credit'=>$l['credit'],'base_debit'=>$l['base_debit']??$l['debit']*$l['exchange_rate'],'base_credit'=>$l['base_credit']??$l['credit']*$l['exchange_rate'],'currency_code'=>$l['currency_code']??'PKR','exchange_rate'=>$l['exchange_rate']??1,'entry_type'=>$entry,'narration'=>$l['narration']??null,'created_at'=>now(),'updated_at'=>now()]); }
    private function document(object $r): object { return (object)['voucher_no'=>$r->opening_no,'voucher_date'=>$r->opening_date,'voucher_type'=>'party_opening_balance','branch_id'=>$r->branch_id,'company_id'=>$r->company_id,'party_name'=>$r->party_name,'currency_code'=>$r->currency_code,'exchange_rate'=>$r->exchange_rate,'narration'=>$r->narration,'created_by'=>$r->created_by,'approved_by'=>$r->approved_by,'posted_by'=>$r->posted_by,'created_at'=>$r->created_at]; }
    private function nextOpeningNumber(): string { $year=now()->format('Y');$stem='OB-'.$year.'-';$last=DB::table('party_opening_balances')->where('opening_no','like',$stem.'%')->lockForUpdate()->orderByDesc('opening_no')->value('opening_no');$n=$last&&preg_match('/(\d+)$/',(string)$last,$m)?(int)$m[1]+1:1000;return $stem.$n; }
    private function assertOpeningType(string $party,string $type): void { $allowed=$party==='customer'?['customer_receivable','customer_advance']:['vendor_payable','vendor_advance'];if(!in_array($type,$allowed,true))throw new RuntimeException('Opening balance type is not allowed for this party.'); }
    private function assertBranch(int $id): void { if($id<=0)throw new RuntimeException('A valid branch is required.');foreach(['branches','branch_master','branch_masters','offices','office_master','office_masters'] as $t)if(Schema::hasTable($t)&&Schema::hasColumn($t,'id')&&DB::table($t)->where('id',$id)->exists())return;throw new RuntimeException('The selected branch is not available.'); }
    private function isPosting(object $r,array $s): bool { $active=$s['active']??null;if($active&&isset($r->{$active})&&!((bool)$r->{$active}))return false;$status=$s['status']??null;if($status&&isset($r->{$status})&&!in_array(strtolower((string)$r->{$status}),['active','enabled','open'],true))return false;$posting=$s['posting']??null;if($posting&&isset($r->{$posting})&&!((bool)$r->{$posting}))return false;$control=$s['control_flag']??null;if($control&&isset($r->{$control})&&!((bool)$r->{$control}))return false;$type=$s['type']??null;if($type&&isset($r->{$type})&&strtolower((string)$r->{$type})==='header')return false;return true; }
}
