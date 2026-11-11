<?php

namespace App\Services\Sales;

use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\DocumentNumberService;
use App\Services\Accounting\PeriodGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceAccountingService
{
    public function __construct(
        private readonly PeriodGuard $periodGuard,
        private readonly DocumentNumberService $numbers,
        private readonly AccountResolver $accounts,
    ) {}

    public function post(SalesInvoice $invoice, int $userId): JournalEntry
    {
        if ($invoice->journal_entry_id) return JournalEntry::query()->findOrFail($invoice->journal_entry_id);

        $invoice->loadMissing(['lines','company','booking']);
        [$fy,$period] = $this->periodGuard->resolveOpen($invoice->company_id,$invoice->invoice_date);
        $receivable = $this->accounts->resolve($invoice->company_id,'CUSTOMER_RECEIVABLE');
        $rate = strtoupper($invoice->currency_code) === strtoupper($invoice->company->base_currency) ? 1.0 : (float)$invoice->exchange_rate;
        if ($rate <= 0) throw ValidationException::withMessages(['exchange_rate'=>'A valid exchange rate is required before posting.']);

        $revenueLines=[];$creditTotal=0.0;
        foreach($invoice->lines as $line){
            $amount=round((float)$line->line_total,2);if($amount<=0)continue;
            $revenueLines[]=['line'=>$line,'account'=>$this->accounts->resolve($invoice->company_id,$line->revenue_mapping_key),'amount'=>$amount];
            $creditTotal+=$amount;
        }
        if(!$revenueLines)throw ValidationException::withMessages(['invoice'=>'Invoice has no positive revenue lines to post.']);
        $total=round((float)$invoice->grand_total,2);$creditTotal=round($creditTotal,2);
        if(abs($total-$creditTotal)>0.01)throw ValidationException::withMessages(['invoice'=>'Invoice line totals do not reconcile to the invoice grand total.']);
        $totalBase=round($total*$rate,2);

        return DB::transaction(function()use($invoice,$userId,$fy,$period,$receivable,$rate,$revenueLines,$total,$totalBase):JournalEntry{
            $existing=SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if($existing->journal_entry_id)return JournalEntry::query()->findOrFail($existing->journal_entry_id);
            if($existing->status!=='APPROVED')throw ValidationException::withMessages(['invoice'=>'Invoice status changed before accounting posting. Refresh and try again.']);

            $journal=JournalEntry::query()->create([
                'company_id'=>$invoice->company_id,'branch_id'=>$invoice->branch_id,'fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,
                'journal_no'=>$this->numbers->next('JOURNAL_VOUCHER',$invoice->company_id,$invoice->branch_id,$fy,$period->id),
                'journal_date'=>$invoice->invoice_date,'journal_type'=>'SALES_INVOICE','origin'=>'SYSTEM','source_type'=>'SALES_INVOICE','source_id'=>$invoice->id,
                'reference'=>$invoice->invoice_no,'description'=>'Sales Invoice '.$invoice->invoice_no.($invoice->booking?->booking_no?' · Booking '.$invoice->booking->booking_no:''),
                'currency_code'=>$invoice->currency_code,'exchange_rate'=>$rate,'total_debit'=>$total,'total_credit'=>$total,
                'total_debit_base'=>$totalBase,'total_credit_base'=>$totalBase,'status'=>'POSTED','created_by'=>$userId,'approved_by'=>$userId,'approved_at'=>now(),'posted_by'=>$userId,'posted_at'=>now(),
            ]);

            $lineNo=1;
            $journal->lines()->create([
                'line_no'=>$lineNo++,'account_id'=>$receivable->id,'description'=>'Customer receivable · '.$invoice->invoice_no,
                'debit'=>$total,'credit'=>0,'base_debit'=>$totalBase,'base_credit'=>0,
                'party_type'=>'CUSTOMER','party_id'=>$invoice->customer_party_id,'source_line_type'=>'SALES_INVOICE','source_line_id'=>$invoice->id,
            ]);

            $baseAllocated=0.0;$lastIndex=count($revenueLines)-1;
            foreach($revenueLines as $idx=>$item){
                $base=$idx===$lastIndex?round($totalBase-$baseAllocated,2):round($item['amount']*$rate,2);$baseAllocated+=$base;
                $line=$item['line'];
                $journal->lines()->create([
                    'line_no'=>$lineNo++,'account_id'=>$item['account']->id,'description'=>'Revenue · Line '.$line->line_no.' · '.$line->description,
                    'debit'=>0,'credit'=>$item['amount'],'base_debit'=>0,'base_credit'=>$base,
                    'party_type'=>null,'party_id'=>null,'source_line_type'=>'SALES_INVOICE_LINE','source_line_id'=>$line->id,
                ]);
            }

            $existing->update(['fiscal_year_id'=>$fy->id,'accounting_period_id'=>$period->id,'journal_entry_id'=>$journal->id,'status'=>'POSTED','posted_by'=>$userId,'posted_at'=>now()]);
            return $journal->fresh(['lines.account']);
        },3);
    }
}
