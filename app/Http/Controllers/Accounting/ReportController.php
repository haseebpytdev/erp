<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use App\Services\AccountingReportPdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $company = Company::query()->firstOrFail();
        [$from, $to] = $this->dateWindow($request, $company->id);
        $branches = $this->allowedBranches($request, $company->id);
        $accounts = Account::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('code')->get();
        $customers = $this->partiesByRole($company->id, 'CUSTOMER');
        $vendors = $this->partiesByRole($company->id, 'VENDOR');

        $report = null;
        if ($request->boolean('run')) {
            $report = $this->buildReport($request, $company, $branches, $accounts, $customers, $vendors, $from, $to);
        }

        return view('accounting.reports.index', compact('company', 'branches', 'accounts', 'customers', 'vendors', 'from', 'to', 'report'));
    }

    public function preview(Request $request): View
    {
        [$report, $company] = $this->resolvedReport($request);
        return view('accounting.reports.preview', compact('report', 'company'));
    }

    public function print(Request $request): View
    {
        [$report, $company] = $this->resolvedReport($request);
        return view('accounting.reports.print', ['report' => $report, 'company' => $company, 'autoPrint' => true]);
    }

    public function excel(Request $request): Response
    {
        [$report, $company] = $this->resolvedReport($request);
        $filename = $this->safeFilename($report['title']).'-'.now()->format('Ymd-His').'.xls';
        $html = view('accounting.reports.excel', compact('report', 'company'))->render();
        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function pdf(Request $request, AccountingReportPdf $pdf): Response
    {
        [$report, $company] = $this->resolvedReport($request);
        $filename = $this->safeFilename($report['title']).'-'.now()->format('Ymd-His').'.pdf';
        $bytes = $pdf->render($report, $company, $request->user()?->name ?? 'ERP User');
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function resolvedReport(Request $request): array
    {
        $company = Company::query()->firstOrFail();
        [$from, $to] = $this->dateWindow($request, $company->id);
        $branches = $this->allowedBranches($request, $company->id);
        $accounts = Account::query()->where('company_id', $company->id)->where('is_active', true)->orderBy('code')->get();
        $customers = $this->partiesByRole($company->id, 'CUSTOMER');
        $vendors = $this->partiesByRole($company->id, 'VENDOR');
        return [$this->buildReport($request, $company, $branches, $accounts, $customers, $vendors, $from, $to), $company];
    }

    private function buildReport(Request $request, Company $company, Collection $branches, Collection $accounts, Collection $customers, Collection $vendors, string $from, string $to): array
    {
        $type = (string)$request->query('type', 'customer_ledger');
        if (!in_array($type, ['general_ledger','account_ledger','customer_ledger','vendor_ledger','trial_balance'], true)) $type = 'customer_ledger';

        $permittedBranchIds = $branches->pluck('id')->map(fn($id)=>(int)$id)->all();
        $selectedBranchId = (int)$request->query('branch_id', 0);
        if ($selectedBranchId && !in_array($selectedBranchId, $permittedBranchIds, true)) abort(403);
        $branchIds = $selectedBranchId ? [$selectedBranchId] : $permittedBranchIds;
        $selectedBranch = $selectedBranchId ? $branches->firstWhere('id', $selectedBranchId) : null;
        $headerBranch = $selectedBranch ?: ($branches->count() === 1 ? $branches->first() : null);
        $branchLabel = $selectedBranch ? $selectedBranch->name : ($branches->count() === 1 ? $branches->first()->name : 'All permitted branches');

        $report = match ($type) {
            'account_ledger' => $this->accountReport($request, $company, $accounts, $branchIds, $branchLabel, $from, $to),
            'customer_ledger' => $this->customerReport($request, $company, $customers, $branchIds, $branchLabel, $from, $to),
            'vendor_ledger' => $this->vendorReport($request, $company, $vendors, $branchIds, $branchLabel, $from, $to),
            'trial_balance' => $this->trialBalanceReport($company, $branchIds, $branchLabel, $to),
            default => $this->generalLedgerReport($company, $branchIds, $branchLabel, $from, $to),
        };
        $report['header_branch'] = $headerBranch;
        return $report;
    }

    private function generalLedgerReport(Company $company, array $branchIds, string $branchLabel, string $from, string $to): array
    {
        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')
            ->join('branches as b', 'b.id', '=', 'je.branch_id')
            ->leftJoin('parties as p', 'p.id', '=', 'jl.party_id')
            ->where('je.company_id', $company->id)->whereIn('je.branch_id', $branchIds)
            ->whereIn('je.status', ['POSTED','REVERSED'])->whereBetween('je.journal_date', [$from, $to])
            ->orderBy('je.journal_date')->orderBy('je.id')->orderBy('jl.line_no')
            ->select(['jl.id as line_id','jl.base_debit','jl.base_credit','jl.description as line_description','jl.source_line_type','jl.source_line_id',
                'je.id as journal_id','je.journal_no','je.journal_date','je.source_type','je.source_id','je.reference','je.description as journal_description',
                'a.id as account_id','a.code as account_code','a.name as account_name','b.name as branch_name','p.display_name as party_name'])
            ->get();
        $links = $this->sourceLinks($rows);
        $debit = round((float)$rows->sum(fn($r)=>(float)$r->base_debit),2);
        $credit = round((float)$rows->sum(fn($r)=>(float)$r->base_credit),2);
        $data = [];
        foreach ($rows as $row) {
            $src = $links[$row->line_id] ?? null;
            $data[] = [
                'cells' => [
                    Carbon::parse($row->journal_date)->format('d-M-Y'),
                    $row->account_code.' · '.$row->account_name,
                    $src['label'] ?? ($row->reference ?: $row->journal_no),
                    $row->line_description ?: $row->journal_description,
                    $row->party_name ?: '—',
                    $row->branch_name,
                    $this->moneyCell($row->base_debit),
                    $this->moneyCell($row->base_credit),
                    $row->journal_no,
                ],
                'source_url' => $src['url'] ?? null,
                'journal_url' => route('accounting.journals.show', $row->journal_id),
                'account_url' => route('accounting.ledgers.account', ['account'=>$row->account_id,'from'=>$from,'to'=>$to]),
            ];
        }
        return $this->baseReport('general_ledger', 'General Ledger', 'Posted journal movements across the selected accounts and branches.', $company, $branchLabel, $from, $to,
            ['Date','Account','Reference','Description','Party','Branch','Debit','Credit','Journal'], $data,
            ['opening'=>0.0,'debit'=>$debit,'credit'=>$credit,'closing'=>round($debit-$credit,2)],
            ['','','','','','TOTAL',number_format($debit,2),number_format($credit,2),'']);
    }

    private function accountReport(Request $request, Company $company, Collection $accounts, array $branchIds, string $branchLabel, string $from, string $to): array
    {
        $accountId = (int)$request->query('account_id', 0);
        $account = $accounts->firstWhere('id', $accountId) ?: $accounts->first();
        if (!$account) abort(422, 'No active account is available.');

        $opening = DB::table('journal_lines as jl')->join('journal_entries as je','je.id','=','jl.journal_entry_id')
            ->where('jl.account_id',$account->id)->where('je.company_id',$company->id)->whereIn('je.branch_id',$branchIds)->whereIn('je.status',['POSTED','REVERSED'])
            ->where('je.journal_date','<',$from)->selectRaw('COALESCE(SUM(jl.base_debit),0) debit, COALESCE(SUM(jl.base_credit),0) credit')->first();
        $openingNet = round((float)$opening->debit-(float)$opening->credit,2);
        $rows = DB::table('journal_lines as jl')->join('journal_entries as je','je.id','=','jl.journal_entry_id')->join('branches as b','b.id','=','je.branch_id')->leftJoin('parties as p','p.id','=','jl.party_id')
            ->where('jl.account_id',$account->id)->where('je.company_id',$company->id)->whereIn('je.branch_id',$branchIds)->whereIn('je.status',['POSTED','REVERSED'])->whereBetween('je.journal_date',[$from,$to])
            ->orderBy('je.journal_date')->orderBy('je.id')->orderBy('jl.line_no')
            ->select(['jl.id as line_id','jl.base_debit','jl.base_credit','jl.description as line_description','jl.source_line_type','jl.source_line_id','je.id as journal_id','je.journal_no','je.journal_date','je.source_type','je.source_id','je.reference','je.description as journal_description','b.name as branch_name','p.display_name as party_name'])->get();
        $links = $this->sourceLinks($rows);
        $running = $openingNet; $debit=0.0; $credit=0.0; $data=[];
        foreach ($rows as $row) {
            $debit += (float)$row->base_debit; $credit += (float)$row->base_credit;
            $running = round($running+(float)$row->base_debit-(float)$row->base_credit,2);
            $src = $links[$row->line_id] ?? null;
            $data[] = ['cells'=>[
                Carbon::parse($row->journal_date)->format('d-M-Y'),
                $src['label'] ?? ($row->reference ?: $row->journal_no),
                $row->line_description ?: $row->journal_description,
                $row->party_name ?: '—',$row->branch_name,$this->moneyCell($row->base_debit),$this->moneyCell($row->base_credit),$this->balanceCell($running),$row->journal_no,
            ],'source_url'=>$src['url']??null,'journal_url'=>route('accounting.journals.show',$row->journal_id)];
        }
        $debit=round($debit,2); $credit=round($credit,2); $closing=round($running,2);
        return $this->baseReport('account_ledger','Account Ledger Statement',$account->code.' · '.$account->name,$company,$branchLabel,$from,$to,
            ['Date','Reference','Description','Party','Branch','Debit','Credit','Running Balance','Journal'],$data,
            ['opening'=>$openingNet,'debit'=>$debit,'credit'=>$credit,'closing'=>$closing],
            ['','','','','TOTAL',number_format($debit,2),number_format($credit,2),$this->balanceCell($closing),''],
            ['account'=>$account]);
    }

    private function customerReport(Request $request, Company $company, Collection $customers, array $branchIds, string $branchLabel, string $from, string $to): array
    {
        return $this->partyLedgerReport($request, $company, $customers, 'CUSTOMER', 'CUSTOMER_RECEIVABLE', 'Customer Ledger Statement', $branchIds, $branchLabel, $from, $to);
    }

    private function vendorReport(Request $request, Company $company, Collection $vendors, array $branchIds, string $branchLabel, string $from, string $to): array
    {
        return $this->partyLedgerReport($request, $company, $vendors, 'VENDOR', 'VENDOR_PAYABLE', 'Vendor Ledger Statement', $branchIds, $branchLabel, $from, $to);
    }

    private function partyLedgerReport(Request $request, Company $company, Collection $parties, string $partyType, string $mappingKey, string $title, array $branchIds, string $branchLabel, string $from, string $to): array
    {
        $partyId = (int)$request->query('party_id', 0);
        $party = $parties->firstWhere('id', $partyId) ?: $parties->first();
        if (!$party) {
            abort(422, 'Create at least one '.ucfirst(strtolower($partyType)).' party before running this ledger.');
        }

        $opening = DB::table('journal_lines as jl')
            ->join('journal_entries as je','je.id','=','jl.journal_entry_id')
            ->where('je.company_id',$company->id)
            ->whereIn('je.branch_id',$branchIds)
            ->whereIn('je.status',['POSTED','REVERSED'])
            ->where('jl.party_type',$partyType)
            ->where('jl.party_id',$party->id)
            ->where('je.journal_date','<',$from)
            ->selectRaw('COALESCE(SUM(jl.base_debit),0) debit, COALESCE(SUM(jl.base_credit),0) credit')
            ->first();
        $openingNet = round((float)$opening->debit-(float)$opening->credit,2);

        $rows = DB::table('journal_lines as jl')
            ->join('journal_entries as je','je.id','=','jl.journal_entry_id')
            ->join('accounts as a','a.id','=','jl.account_id')
            ->join('branches as b','b.id','=','je.branch_id')
            ->where('je.company_id',$company->id)
            ->whereIn('je.branch_id',$branchIds)
            ->whereIn('je.status',['POSTED','REVERSED'])
            ->where('jl.party_type',$partyType)
            ->where('jl.party_id',$party->id)
            ->whereBetween('je.journal_date',[$from,$to])
            ->orderBy('je.journal_date')->orderBy('je.id')->orderBy('jl.line_no')
            ->select([
                'jl.id as line_id','jl.base_debit','jl.base_credit','jl.description as line_description','jl.source_line_type','jl.source_line_id',
                'je.id as journal_id','je.journal_no','je.journal_date','je.source_type','je.source_id','je.reference','je.description as journal_description',
                'a.code as account_code','a.name as account_name','b.name as branch_name'
            ])->get();

        $controlAccount = DB::table('account_mappings as am')
            ->leftJoin('accounts as a','a.id','=','am.account_id')
            ->where('am.company_id',$company->id)
            ->where('am.mapping_key',$mappingKey)
            ->select(['a.code','a.name'])
            ->first();

        $links = $this->sourceLinks($rows);
        $running = $openingNet;
        $debit = 0.0;
        $credit = 0.0;
        $data = [[
            'cells'=>['BAL',Carbon::parse($from)->format('d/m/Y'),'—','—','Opening Balance','—','—',$this->partyBalanceCell($openingNet)],
            'source_url'=>null,
            'journal_url'=>null,
            'is_opening'=>true,
        ]];

        foreach($rows as $row){
            $debit += (float)$row->base_debit;
            $credit += (float)$row->base_credit;
            $running = round($running+(float)$row->base_debit-(float)$row->base_credit,2);
            $src = $links[$row->line_id] ?? null;
            $journalUrl = route('accounting.journals.show',$row->journal_id);
            $reference = $src['label'] ?? ($row->reference ?: $row->journal_no);
            $description = $row->line_description ?: $row->journal_description ?: $this->documentDescription($row, $src);
            $data[] = [
                'cells'=>[
                    $this->documentTypeCode($row, $src),
                    Carbon::parse($row->journal_date)->format('d/m/Y'),
                    $reference,
                    $reference,
                    $description,
                    $this->moneyCell($row->base_debit),
                    $this->moneyCell($row->base_credit),
                    $this->partyBalanceCell($running),
                ],
                // The business-document reference is always clickable. If the source document
                // has no dedicated route yet, drill down to the exact posted journal instead.
                'source_url'=>$src['url'] ?? $journalUrl,
                'journal_url'=>$journalUrl,
            ];
        }

        $debit = round($debit,2);
        $credit = round($credit,2);
        $closing = round($running,2);
        $reportType = $partyType === 'VENDOR' ? 'vendor_ledger' : 'customer_ledger';

        return $this->baseReport($reportType,$title,$party->display_name.' · '.$party->code,$company,$branchLabel,$from,$to,
            ['Type','Date','Reference','Document No.','Description','Debit','Credit','Balance'],$data,
            ['opening'=>$openingNet,'debit'=>$debit,'credit'=>$credit,'closing'=>$closing],
            ['','','','','TOTAL',number_format($debit,2),number_format($credit,2),$this->partyBalanceCell($closing)],
            [
                'party'=>$party,
                'party_role'=>$partyType,
                'party_label'=>$partyType === 'VENDOR' ? 'Vendor' : 'Customer',
                'control_account'=>$controlAccount,
                'statement_style'=>'party_ledger',
            ]);
    }

    private function trialBalanceReport(Company $company, array $branchIds, string $branchLabel, string $asOf): array
    {
        $totals=DB::table('journal_lines as jl')->join('journal_entries as je','je.id','=','jl.journal_entry_id')->where('je.company_id',$company->id)->whereIn('je.branch_id',$branchIds)->whereIn('je.status',['POSTED','REVERSED'])->where('je.journal_date','<=',$asOf)
            ->selectRaw('jl.account_id, SUM(jl.base_debit) debit, SUM(jl.base_credit) credit')->groupBy('jl.account_id')->get()->keyBy('account_id');
        $accounts=Account::query()->where('company_id',$company->id)->orderBy('code')->get(); $data=[];$debitTotal=0.0;$creditTotal=0.0;
        foreach($accounts as $account){$row=$totals->get($account->id);$net=round((float)($row->debit??0)-(float)($row->credit??0),2);$debit=$net>=0?$net:0.0;$credit=$net<0?abs($net):0.0;$debitTotal+=$debit;$creditTotal+=$credit;
            $data[]=['cells'=>[$account->code,$account->name,$account->account_type,$this->moneyCell($debit),$this->moneyCell($credit)],'account_url'=>route('accounting.ledgers.account',['account'=>$account->id,'to'=>$asOf])];}
        $debitTotal=round($debitTotal,2);$creditTotal=round($creditTotal,2);$difference=round($debitTotal-$creditTotal,2);
        return $this->baseReport('trial_balance','Trial Balance','Account balances as of '.Carbon::parse($asOf)->format('d M Y'),$company,$branchLabel,$asOf,$asOf,
            ['Code','Account','Type','Debit','Credit'],$data,['opening'=>0.0,'debit'=>$debitTotal,'credit'=>$creditTotal,'closing'=>$difference],['','TOTAL','',number_format($debitTotal,2),number_format($creditTotal,2)],['difference'=>$difference]);
    }

    private function baseReport(string $type,string $title,string $subtitle,Company $company,string $branchLabel,string $from,string $to,array $headers,array $rows,array $metrics,array $totals,array $extra=[]): array
    {
        return array_merge([
            'type'=>$type,'title'=>$title,'subtitle'=>$subtitle,'company'=>$company,'branch_label'=>$branchLabel,'from'=>$from,'to'=>$to,'currency'=>$company->base_currency,
            'headers'=>$headers,'rows'=>$rows,'metrics'=>$metrics,'totals'=>$totals,'generated_at'=>now(),'generated_by'=>auth()->user()?->name ?? 'ERP User',
        ],$extra);
    }

    private function sourceLinks(Collection $rows): array
    {
        $invoiceIds=collect();$lineIds=collect();
        foreach($rows as $row){if(($row->source_line_type??null)==='SALES_INVOICE')$invoiceIds->push((int)$row->source_line_id);if(($row->source_line_type??null)==='SALES_INVOICE_LINE')$lineIds->push((int)$row->source_line_id);if(($row->source_type??null)==='SALES_INVOICE'&&$row->source_id)$invoiceIds->push((int)$row->source_id);}
        $lineInvoice=collect();
        if($lineIds->isNotEmpty()){$lineInvoice=DB::table('sales_invoice_lines')->whereIn('id',$lineIds->unique()->all())->pluck('sales_invoice_id','id');$invoiceIds=$invoiceIds->merge($lineInvoice->values());}
        $invoiceIds=$invoiceIds->unique()->filter()->values();
        $invoices=$invoiceIds->isEmpty()?collect():DB::table('sales_invoices')->whereIn('id',$invoiceIds->all())->pluck('invoice_no','id');
        $links=[];
        foreach($rows as $row){$invoiceId=null;if(($row->source_line_type??null)==='SALES_INVOICE')$invoiceId=(int)$row->source_line_id;elseif(($row->source_line_type??null)==='SALES_INVOICE_LINE')$invoiceId=(int)($lineInvoice[$row->source_line_id]??0);elseif(($row->source_type??null)==='SALES_INVOICE')$invoiceId=(int)$row->source_id;
            if($invoiceId&&isset($invoices[$invoiceId]))$links[$row->line_id]=['type'=>'SALES_INVOICE','id'=>$invoiceId,'label'=>$invoices[$invoiceId],'url'=>route('sales.invoices.show',$invoiceId)];}
        return $links;
    }

    private function partiesByRole(int $companyId, string $role): Collection
    {
        return Party::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereExists(function ($q) use ($role) {
                $q->selectRaw('1')
                    ->from('party_roles')
                    ->whereColumn('party_roles.party_id', 'parties.id')
                    ->where('party_roles.role', $role)
                    ->where('party_roles.is_active', true);
            })
            ->orderBy('display_name')
            ->get();
    }

    private function documentTypeCode(object $row, ?array $source): string
    {
        $type = strtoupper((string)($source['type'] ?? $row->source_type ?? $row->source_line_type ?? ''));
        return match ($type) {
            'SALES_INVOICE','SALES_INVOICE_LINE' => 'INV',
            'RECEIPT_VOUCHER','RECEIPT' => 'RV',
            'VENDOR_BILL','PURCHASE_INVOICE' => 'VB',
            'PAYMENT_VOUCHER','PAYMENT' => 'PV',
            'CREDIT_NOTE' => 'CN',
            'REFUND','REFUND_CASE' => 'RF',
            default => 'JV',
        };
    }

    private function documentDescription(object $row, ?array $source): string
    {
        $type = strtoupper((string)($source['type'] ?? $row->source_type ?? $row->source_line_type ?? ''));
        return match ($type) {
            'SALES_INVOICE','SALES_INVOICE_LINE' => 'Sales Invoice',
            'RECEIPT_VOUCHER','RECEIPT' => 'Customer Receipt',
            'VENDOR_BILL','PURCHASE_INVOICE' => 'Vendor Bill',
            'PAYMENT_VOUCHER','PAYMENT' => 'Vendor / Party Payment',
            'CREDIT_NOTE' => 'Credit Note',
            'REFUND','REFUND_CASE' => 'Refund / Credit',
            default => 'Journal Entry',
        };
    }

    private function dateWindow(Request $request,int $companyId): array
    {
        $fy=DB::table('fiscal_years')->where('company_id',$companyId)->where('starts_on','<=',now()->toDateString())->where('ends_on','>=',now()->toDateString())->first()??DB::table('fiscal_years')->where('company_id',$companyId)->orderByDesc('starts_on')->first();
        $defaultFrom=$fy?->starts_on?:now()->startOfYear()->toDateString();$defaultTo=min(now()->toDateString(),$fy?->ends_on?:now()->toDateString());$from=$this->safeDate((string)$request->query('from'),$defaultFrom);$to=$this->safeDate((string)$request->query('to'),$defaultTo);if($from>$to)[$from,$to]=[$to,$from];return[$from,$to];
    }

    private function safeDate(string $value,string $fallback): string{if($value==='')return$fallback;try{return Carbon::parse($value)->toDateString();}catch(\Throwable){return$fallback;}}
    private function allowedBranches(Request $request,int $companyId): Collection{$user=$request->user();if($user->is_super_admin||$user->access_scope==='ALL_BRANCHES')return Branch::query()->where('company_id',$companyId)->where('is_active',true)->orderBy('name')->get();$query=$user->branches()->where('branches.company_id',$companyId)->where('branches.is_active',true);if($user->access_scope==='PRIMARY_BRANCH')$query->wherePivot('is_primary',true);return$query->orderBy('branches.name')->get();}
    private function moneyCell($amount): string{return abs((float)$amount)<0.005?'—':number_format((float)$amount,2);}
    private function balanceCell($net): string{return number_format(abs((float)$net),2).' '.((float)$net>=0?'Dr':'Cr');}
    private function partyBalanceCell($net): string{return number_format(abs((float)$net),2);}
    private function safeFilename(string $value): string{return trim(preg_replace('/[^A-Za-z0-9_-]+/','-',strtolower($value)),'-')?:'report';}
}
