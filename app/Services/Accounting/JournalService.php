<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\ApprovalAction;
use App\Models\ApprovalPolicy;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalService
{
    public function __construct(
        private readonly PeriodGuard $periodGuard,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function createDraft(Request $request, array $header, array $lines): JournalEntry
    {
        $company = Company::query()->findOrFail($header['company_id']);
        [$fy,$period] = $this->periodGuard->resolveOpen($company->id, $header['journal_date']);
        $calculated = $this->validateAndCalculate($company->id, $header, $lines, true);

        return DB::transaction(function () use ($request,$header,$lines,$company,$fy,$period,$calculated): JournalEntry {
            $journal = JournalEntry::query()->create(array_merge($header,[
                'fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,
                'journal_no'=>$this->numbers->next('JOURNAL_VOUCHER',$company->id,$header['branch_id'],$fy,$period->id),
                'origin'=>'MANUAL','status'=>'DRAFT','created_by'=>$request->user()->id,
                'total_debit'=>$calculated['debit'],'total_credit'=>$calculated['credit'],
                'total_debit_base'=>$calculated['base_debit'],'total_credit_base'=>$calculated['base_credit'],
            ]));
            $this->replaceLines($journal,$lines,(float)$header['exchange_rate']);
            $this->action($journal,$request->user(),'CREATE',null,'DRAFT',$calculated['base_debit'],'Draft created.');
            AuditService::log($request,'journal.created',$journal,[],$this->snapshot($journal));
            return $journal->fresh(['lines.account']);
        });
    }

    public function updateDraft(Request $request, JournalEntry $journal, array $header, array $lines): JournalEntry
    {
        if (! $journal->isEditable()) throw ValidationException::withMessages(['journal'=>'Only draft journals may be edited.']);
        [$fy,$period] = $this->periodGuard->resolveOpen($journal->company_id,$header['journal_date']);
        $calculated = $this->validateAndCalculate($journal->company_id,$header,$lines,true);
        $old = $this->snapshot($journal);

        return DB::transaction(function () use ($request,$journal,$header,$lines,$fy,$period,$calculated,$old): JournalEntry {
            $journal->update(array_merge($header,[
                'fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,
                'total_debit'=>$calculated['debit'],'total_credit'=>$calculated['credit'],
                'total_debit_base'=>$calculated['base_debit'],'total_credit_base'=>$calculated['base_credit'],
            ]));
            $this->replaceLines($journal,$lines,(float)$header['exchange_rate']);
            $this->action($journal,$request->user(),'EDIT','DRAFT','DRAFT',$calculated['base_debit'],'Draft updated.');
            AuditService::log($request,'journal.updated',$journal,$old,$this->snapshot($journal));
            return $journal->fresh(['lines.account']);
        });
    }

    public function submit(Request $request, JournalEntry $journal, ?string $remarks = null): void
    {
        if ($journal->status !== 'DRAFT') throw ValidationException::withMessages(['journal'=>'Only a draft journal may be submitted.']);
        $this->periodGuard->resolveOpen($journal->company_id,$journal->journal_date);
        $this->validatePersisted($journal,true);
        DB::transaction(function () use ($request,$journal,$remarks): void {
            $journal->update(['status'=>'PENDING_APPROVAL']);
            $this->action($journal,$request->user(),'SUBMIT','DRAFT','PENDING_APPROVAL',(float)$journal->total_debit_base,$remarks);
            AuditService::log($request,'journal.submitted',$journal,['status'=>'DRAFT'],['status'=>'PENDING_APPROVAL']);
        });
    }

    public function approve(Request $request, JournalEntry $journal, ?string $remarks = null): void
    {
        if ($journal->status !== 'PENDING_APPROVAL') throw ValidationException::withMessages(['journal'=>'Journal is not pending approval.']);
        $policy = ApprovalPolicy::query()->where('company_id',$journal->company_id)->where('key','JOURNAL_APPROVAL')->where('is_active',true)->first();
        if ($policy?->prevent_self_approval && $journal->created_by === $request->user()->id && ! $request->user()->is_super_admin) {
            throw ValidationException::withMessages(['approval'=>'Maker/checker policy prevents you from approving your own journal.']);
        }
        if (! $request->user()->canApprove('JOURNAL_APPROVAL',(float)$journal->total_debit_base)) {
            throw ValidationException::withMessages(['approval'=>'Your journal approval authority is insufficient for this amount.']);
        }
        $this->periodGuard->resolveOpen($journal->company_id,$journal->journal_date);
        $this->validatePersisted($journal,true);

        DB::transaction(function () use ($request,$journal,$remarks): void {
            $journal->update(['status'=>'APPROVED','approved_by'=>$request->user()->id,'approved_at'=>now()]);
            $this->action($journal,$request->user(),'APPROVE','PENDING_APPROVAL','APPROVED',(float)$journal->total_debit_base,$remarks,
                ['super_admin_override'=>$request->user()->is_super_admin && $journal->created_by === $request->user()->id]);
            AuditService::log($request,'journal.approved',$journal,['status'=>'PENDING_APPROVAL'],['status'=>'APPROVED','approved_by'=>$request->user()->id]);
        });
    }

    public function post(Request $request, JournalEntry $journal, ?string $remarks = null): void
    {
        if ($journal->status !== 'APPROVED') throw ValidationException::withMessages(['journal'=>'Only an approved journal may be posted.']);
        $this->periodGuard->resolveOpen($journal->company_id,$journal->journal_date);
        $this->validatePersisted($journal,true);
        DB::transaction(function () use ($request,$journal,$remarks): void {
            $locked = JournalEntry::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'APPROVED') throw ValidationException::withMessages(['journal'=>'Journal status changed before posting. Refresh and try again.']);
            $locked->update(['status'=>'POSTED','posted_by'=>$request->user()->id,'posted_at'=>now()]);
            $this->action($locked,$request->user(),'POST','APPROVED','POSTED',(float)$locked->total_debit_base,$remarks);
            AuditService::log($request,'journal.posted',$locked,['status'=>'APPROVED'],['status'=>'POSTED','posted_by'=>$request->user()->id]);
        });
    }

    public function reverse(Request $request, JournalEntry $journal, string $date, string $reason): JournalEntry
    {
        if ($journal->status !== 'POSTED') throw ValidationException::withMessages(['journal'=>'Only a posted journal may be reversed.']);
        if ($journal->reversalEntry()->exists()) throw ValidationException::withMessages(['journal'=>'This journal already has a reversal entry.']);
        [$fy,$period] = $this->periodGuard->resolveOpen($journal->company_id,$date);
        $journal->loadMissing('lines.account');

        return DB::transaction(function () use ($request,$journal,$date,$reason,$fy,$period): JournalEntry {
            $locked = JournalEntry::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'POSTED') throw ValidationException::withMessages(['journal'=>'Journal status changed before reversal.']);
            $number = $this->numbers->next('JOURNAL_VOUCHER',$locked->company_id,$locked->branch_id,$fy,$period->id);
            $reversal = JournalEntry::query()->create([
                'company_id'=>$locked->company_id,'branch_id'=>$locked->branch_id,'fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,
                'journal_no'=>$number,'journal_date'=>$date,'journal_type'=>'REVERSAL','origin'=>'SYSTEM','source_type'=>'JOURNAL_REVERSAL','source_id'=>$locked->id,
                'reference'=>'Reversal of '.$locked->journal_no,'description'=>'Reversal: '.$reason,'currency_code'=>$locked->currency_code,
                'exchange_rate'=>$locked->exchange_rate,'total_debit'=>$locked->total_credit,'total_credit'=>$locked->total_debit,
                'total_debit_base'=>$locked->total_credit_base,'total_credit_base'=>$locked->total_debit_base,
                'status'=>'POSTED','created_by'=>$request->user()->id,'approved_by'=>$request->user()->id,'approved_at'=>now(),
                'posted_by'=>$request->user()->id,'posted_at'=>now(),'reversal_of_id'=>$locked->id,'reversal_reason'=>$reason,
            ]);
            foreach ($locked->lines as $line) {
                $reversal->lines()->create([
                    'line_no'=>$line->line_no,'account_id'=>$line->account_id,'description'=>'Reversal · '.($line->description ?: $locked->description),
                    'debit'=>$line->credit,'credit'=>$line->debit,'base_debit'=>$line->base_credit,'base_credit'=>$line->base_debit,
                    'party_type'=>$line->party_type,'party_id'=>$line->party_id,'source_line_type'=>'JOURNAL_REVERSAL_LINE','source_line_id'=>$line->id,
                ]);
            }
            $locked->update(['status'=>'REVERSED','reversed_by'=>$request->user()->id,'reversed_at'=>now(),'reversal_reason'=>$reason]);
            $this->action($locked,$request->user(),'REVERSE','POSTED','REVERSED',(float)$locked->total_debit_base,$reason,['reversal_journal_id'=>$reversal->id,'reversal_journal_no'=>$reversal->journal_no]);
            $this->action($reversal,$request->user(),'POST',null,'POSTED',(float)$reversal->total_debit_base,'Automatic controlled reversal.',['original_journal_id'=>$locked->id]);
            AuditService::log($request,'journal.reversed',$locked,['status'=>'POSTED'],['status'=>'REVERSED','reversal_journal_id'=>$reversal->id]);
            return $reversal->fresh(['lines.account']);
        });
    }

    private function validateAndCalculate(int $companyId, array $header, array $lines, bool $manual): array
    {
        if (count($lines) < 2) throw ValidationException::withMessages(['lines'=>'A journal requires at least two lines.']);
        $rate = (float)($header['exchange_rate'] ?? 1);
        if ($rate <= 0) throw ValidationException::withMessages(['exchange_rate'=>'Exchange rate must be greater than zero.']);

        $accountIds = collect($lines)->pluck('account_id')->filter()->map(fn($v)=>(int)$v)->unique()->all();
        $accounts = Account::query()->where('company_id',$companyId)->whereIn('id',$accountIds)->get()->keyBy('id');
        $debit=$credit=$baseDebit=$baseCredit=0.0;

        foreach (array_values($lines) as $i=>$line) {
            $row=$i+1; $account=$accounts[(int)($line['account_id']??0)]??null;
            if (! $account || ! $account->is_active || ! $account->allow_posting) throw ValidationException::withMessages(["lines.{$i}.account_id"=>"Line {$row}: select an active posting account."]);
            if ($manual && $account->is_control) throw ValidationException::withMessages(["lines.{$i}.account_id"=>"Line {$row}: control account {$account->code} cannot be posted by a manual journal. Use the relevant subledger document workflow."]);
            $d=round((float)($line['debit']??0),2); $c=round((float)($line['credit']??0),2);
            if ($d < 0 || $c < 0 || ($d <= 0 && $c <= 0) || ($d > 0 && $c > 0)) throw ValidationException::withMessages(["lines.{$i}.debit"=>"Line {$row}: enter either a debit or a credit amount, not both."]);
            $debit += $d; $credit += $c; $baseDebit += round($d*$rate,2); $baseCredit += round($c*$rate,2);
        }
        $debit=round($debit,2); $credit=round($credit,2); $baseDebit=round($baseDebit,2); $baseCredit=round($baseCredit,2);
        if ((int)round($debit*100)!==(int)round($credit*100)) throw ValidationException::withMessages(['lines'=>"Journal is not balanced. Debit {$debit} must equal credit {$credit}."]);
        if ((int)round($baseDebit*100)!==(int)round($baseCredit*100)) throw ValidationException::withMessages(['exchange_rate'=>'Base-currency debit and credit are not balanced after conversion.']);
        if ($debit <= 0) throw ValidationException::withMessages(['lines'=>'Journal total must be greater than zero.']);
        return ['debit'=>$debit,'credit'=>$credit,'base_debit'=>$baseDebit,'base_credit'=>$baseCredit];
    }

    private function validatePersisted(JournalEntry $journal, bool $manual): void
    {
        $journal->loadMissing('lines.account');
        $header=['exchange_rate'=>$journal->exchange_rate];
        $lines=$journal->lines->map(fn($l)=>['account_id'=>$l->account_id,'debit'=>$l->debit,'credit'=>$l->credit])->all();
        $calc=$this->validateAndCalculate($journal->company_id,$header,$lines,$manual && $journal->origin==='MANUAL');
        $matches =
            (int)round($calc['debit']*100)===(int)round((float)$journal->total_debit*100) &&
            (int)round($calc['credit']*100)===(int)round((float)$journal->total_credit*100) &&
            (int)round($calc['base_debit']*100)===(int)round((float)$journal->total_debit_base*100) &&
            (int)round($calc['base_credit']*100)===(int)round((float)$journal->total_credit_base*100);
        if (! $matches) throw ValidationException::withMessages(['journal'=>'Stored journal totals do not match journal lines.']);
    }

    private function replaceLines(JournalEntry $journal, array $lines, float $rate): void
    {
        $journal->lines()->delete();
        foreach (array_values($lines) as $i=>$line) {
            $d=round((float)($line['debit']??0),2); $c=round((float)($line['credit']??0),2);
            $journal->lines()->create([
                'line_no'=>$i+1,'account_id'=>(int)$line['account_id'],'description'=>trim((string)($line['description']??'')) ?: null,
                'debit'=>$d,'credit'=>$c,'base_debit'=>round($d*$rate,2),'base_credit'=>round($c*$rate,2),
            ]);
        }
    }

    private function action(JournalEntry $journal, User $user, string $action, ?string $from, ?string $to, ?float $amount, ?string $remarks=null, array $metadata=[]): void
    {
        ApprovalAction::query()->create([
            'company_id'=>$journal->company_id,'document_type'=>'JOURNAL_ENTRY','document_id'=>$journal->id,'action'=>$action,
            'from_status'=>$from,'to_status'=>$to,'amount'=>$amount,'user_id'=>$user->id,'remarks'=>$remarks,'metadata'=>$metadata ?: null,'created_at'=>now(),
        ]);
    }

    private function snapshot(JournalEntry $journal): array
    {
        $journal->loadMissing('lines');
        return ['id'=>$journal->id,'journal_no'=>$journal->journal_no,'journal_date'=>$journal->journal_date?->toDateString(),'status'=>$journal->status,
            'branch_id'=>$journal->branch_id,'currency_code'=>$journal->currency_code,'exchange_rate'=>$journal->exchange_rate,
            'total_debit_base'=>$journal->total_debit_base,'lines'=>$journal->lines->map(fn($l)=>['account_id'=>$l->account_id,'debit'=>$l->debit,'credit'=>$l->credit])->all()];
    }
}
