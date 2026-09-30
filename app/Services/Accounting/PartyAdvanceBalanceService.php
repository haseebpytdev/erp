<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Canonical, source-aware availability for customer and supplier advances. */
final class PartyAdvanceBalanceService
{
    public function __construct(private readonly CashVoucherService $cash, private readonly AccountingPartyRoleResolver $roles) {}

    public function advanceSourceSnapshot(string $type, int $id): array
    {
        $type = $this->normaliseType($type);
        $row = $type === 'cash_voucher'
            ? DB::table('cash_vouchers')->where('id', $id)->lockForUpdate()->first()
            : DB::table('party_opening_balances')->where('id', $id)->lockForUpdate()->first();
        if (!$row) throw new RuntimeException('Advance source was not found.');
        $partyType = $type === 'cash_voucher' ? (string) $row->party_type : ((string) $row->party_type === 'vendor' ? 'supplier' : 'customer');
        $openingType = $type === 'cash_voucher' ? (string) $row->voucher_type : (string) $row->opening_type;
        $eligible = $partyType === 'customer'
            ? in_array($openingType, ['customer_advance', 'customer_advance'], true)
            : in_array($openingType, ['supplier_advance', 'vendor_advance'], true);
        if (!$eligible || (string) $row->status !== 'posted') throw new RuntimeException('Source is not a Posted party advance.');
        $partyId = (int) $row->party_id;
        if ($partyId <= 0) throw new RuntimeException('Advance source has no canonical party.');
        $this->roles->assertRole($partyId, $partyType);
        $available = $this->availableAdvance($type, $id, $row);
        return ['advance_source_type'=>$type,'advance_source_id'=>$id,'source_number'=>(string) ($row->voucher_no ?? $row->document_no ?? ''),'party_type'=>$partyType,'party_id'=>$partyId,'party_name'=>(string) ($row->party_name ?? ''),'currency_code'=>(string) ($row->currency_code ?: 'PKR'),'original_amount'=>(float) ($row->amount ?? 0),'available_amount'=>$available,'source_date'=>(string) ($row->voucher_date ?? $row->opening_date ?? ''),'booking_id'=>$row->booking_id ?? null];
    }

    public function availableAdvance(string $type, int $id, ?object $row = null): float
    {
        $type = $this->normaliseType($type);
        $row ??= $type === 'cash_voucher' ? DB::table('cash_vouchers')->where('id',$id)->first() : DB::table('party_opening_balances')->where('id',$id)->first();
        if (!$row || (string) $row->status !== 'posted') return 0.0;
        $available = $type === 'cash_voucher' ? (float) ($row->unallocated_amount ?? 0) : (float) $row->amount;
        $adjusted = Schema::hasTable('advance_adjustments') ? (float) DB::table('advance_adjustments')->where('status','posted')->when($type === 'cash_voucher', fn($q) => $q->where(function($x) use($id){$x->where('advance_voucher_id',$id)->orWhere(fn($y)=>$y->where('advance_source_type','cash_voucher')->where('advance_source_id',$id));}))->when($type === 'party_opening_balance', fn($q) => $q->where('advance_source_type','party_opening_balance')->where('advance_source_id',$id))->sum('amount') : 0.0;
        $returned = Schema::hasTable('customer_advance_return_allocations') && Schema::hasTable('customer_advance_returns') ? (float) DB::table('customer_advance_return_allocations as a')->join('customer_advance_returns as r','r.id','=','a.customer_advance_return_id')->where('r.status','posted')->where('a.advance_source_type',$type)->where('a.advance_source_id',$id)->sum('a.amount') : 0.0;
        return max(0, round($available - $adjusted - $returned, 2));
    }

    public function advanceOptions(string $partyType, ?int $partyId = null): array
    {
        $out=[];
        if (Schema::hasTable('cash_vouchers')) foreach (DB::table('cash_vouchers')->where('status','posted')->where('party_type',$partyType)->where('unallocated_amount','>',0)->when($partyId,fn($q)=>$q->where('party_id',$partyId))->orderByDesc('id')->limit(500)->get() as $row) { try {$s=$this->advanceSourceSnapshot('cash_voucher',(int)$row->id); if($s['available_amount']>0)$out[]=$s;}catch(\Throwable){} }
        if (Schema::hasTable('party_opening_balances')) foreach (DB::table('party_opening_balances')->where('status','posted')->where('party_type',$partyType==='supplier'?'vendor':'customer')->whereIn('opening_type',$partyType==='supplier'?['vendor_advance']:['customer_advance'])->when($partyId,fn($q)=>$q->where('party_id',$partyId))->orderByDesc('id')->limit(500)->get() as $row) { try {$s=$this->advanceSourceSnapshot('party_opening_balance',(int)$row->id); if($s['available_amount']>0)$out[]=$s;}catch(\Throwable){} }
        return $out;
    }

    public function lockAdvanceSource(string $type, int $id): object
    {
        $table = $this->normaliseType($type) === 'cash_voucher' ? 'cash_vouchers' : 'party_opening_balances';
        $row = DB::table($table)->where('id',$id)->lockForUpdate()->first();
        if (!$row) throw new RuntimeException('Advance source was not found.');
        return $row;
    }

    private function normaliseType(string $type): string
    {
        return match ($type) { 'cash_voucher','customer_advance','supplier_advance' => 'cash_voucher', 'party_opening_balance','opening_balance' => 'party_opening_balance', default => throw new RuntimeException('Unsupported advance source type.') };
    }
}
