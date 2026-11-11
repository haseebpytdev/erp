<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Party;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LedgerController extends Controller
{
    public function index(Request $request): View
    {
        $company = Company::query()->firstOrFail();
        $branchIds = $this->allowedBranches($request, $company->id)->pluck('id')->all();
        [$from, $to] = $this->dateWindow($request, $company->id);

        $movementRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->whereIn('je.branch_id', $branchIds)
            ->whereIn('je.status', ['POSTED', 'REVERSED'])
            ->whereBetween('je.journal_date', [$from, $to])
            ->selectRaw('jl.account_id, SUM(jl.base_debit) AS debit, SUM(jl.base_credit) AS credit')
            ->groupBy('jl.account_id')->get()->keyBy('account_id');

        $openingRows = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)
            ->whereIn('je.branch_id', $branchIds)
            ->whereIn('je.status', ['POSTED', 'REVERSED'])
            ->where('je.journal_date', '<', $from)
            ->selectRaw('jl.account_id, SUM(jl.base_debit) AS debit, SUM(jl.base_credit) AS credit')
            ->groupBy('jl.account_id')->get()->keyBy('account_id');

        $accounts = Account::query()->where('company_id', $company->id)->orderBy('code')->get()->map(function (Account $account) use ($movementRows, $openingRows) {
            $opening = $openingRows->get($account->id);
            $movement = $movementRows->get($account->id);
            $openingNet = round((float)($opening->debit ?? 0) - (float)($opening->credit ?? 0), 2);
            $debit = round((float)($movement->debit ?? 0), 2);
            $credit = round((float)($movement->credit ?? 0), 2);
            $closingNet = round($openingNet + $debit - $credit, 2);
            $account->ledger_opening_net = $openingNet;
            $account->ledger_debit = $debit;
            $account->ledger_credit = $credit;
            $account->ledger_closing_net = $closingNet;
            return $account;
        });

        $customerBalances = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('parties as p', 'p.id', '=', 'jl.party_id')
            ->where('je.company_id', $company->id)
            ->whereIn('je.branch_id', $branchIds)
            ->whereIn('je.status', ['POSTED', 'REVERSED'])
            ->where('jl.party_type', 'CUSTOMER')
            ->where('je.journal_date', '<=', $to)
            ->selectRaw('p.id, p.code, p.display_name, SUM(jl.base_debit) AS debit, SUM(jl.base_credit) AS credit')
            ->groupBy('p.id', 'p.code', 'p.display_name')
            ->orderBy('p.display_name')->get()
            ->map(function ($row) {
                $row->balance = round((float)$row->debit - (float)$row->credit, 2);
                return $row;
            });

        $journalCount = DB::table('journal_entries')->where('company_id', $company->id)->whereIn('branch_id', $branchIds)
            ->whereIn('status', ['POSTED', 'REVERSED'])->whereBetween('journal_date', [$from, $to])->count();
        $periodDebit = round((float)$movementRows->sum(fn($r) => (float)$r->debit), 2);
        $periodCredit = round((float)$movementRows->sum(fn($r) => (float)$r->credit), 2);

        $arAccountId = DB::table('account_mappings')->where('company_id', $company->id)->where('mapping_key', 'CUSTOMER_RECEIVABLE')->value('account_id');
        $arAccount = $accounts->firstWhere('id', (int)$arAccountId);
        $arBalance = $arAccount ? (float)$arAccount->ledger_closing_net : 0.0;

        return view('accounting.ledgers.index', compact('company', 'accounts', 'customerBalances', 'from', 'to', 'journalCount', 'periodDebit', 'periodCredit', 'arBalance'));
    }

    public function account(Request $request, Account $account): View
    {
        $company = Company::query()->firstOrFail();
        abort_unless($account->company_id === $company->id, 404);
        $branchIds = $this->allowedBranches($request, $company->id)->pluck('id')->all();
        [$from, $to] = $this->dateWindow($request, $company->id);

        $opening = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.account_id', $account->id)->where('je.company_id', $company->id)->whereIn('je.branch_id', $branchIds)
            ->whereIn('je.status', ['POSTED', 'REVERSED'])->where('je.journal_date', '<', $from)
            ->selectRaw('COALESCE(SUM(jl.base_debit),0) AS debit, COALESCE(SUM(jl.base_credit),0) AS credit')->first();
        $openingNet = round((float)$opening->debit - (float)$opening->credit, 2);

        $rows = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('branches as b', 'b.id', '=', 'je.branch_id')
            ->where('jl.account_id', $account->id)->where('je.company_id', $company->id)->whereIn('je.branch_id', $branchIds)
            ->whereIn('je.status', ['POSTED', 'REVERSED'])->whereBetween('je.journal_date', [$from, $to])
            ->orderBy('je.journal_date')->orderBy('je.id')->orderBy('jl.line_no')
            ->select(['jl.id as line_id','jl.debit','jl.credit','jl.base_debit','jl.base_credit','jl.description as line_description','jl.party_type','jl.party_id','jl.source_line_type','jl.source_line_id',
                'je.id as journal_id','je.journal_no','je.journal_date','je.journal_type','je.source_type','je.source_id','je.reference','je.description as journal_description','je.currency_code','je.status as journal_status','b.name as branch_name'])
            ->get();

        $running = $openingNet;
        foreach ($rows as $row) {
            $running = round($running + (float)$row->base_debit - (float)$row->base_credit, 2);
            $row->running_net = $running;
        }
        $closingNet = $running;
        $sourceLinks = $this->sourceLinks($rows);

        return view('accounting.ledgers.account', compact('company', 'account', 'rows', 'from', 'to', 'openingNet', 'closingNet', 'sourceLinks'));
    }

    public function customer(Request $request, Party $party): View
    {
        $company = Company::query()->firstOrFail();
        abort_unless($party->company_id === $company->id, 404);
        $party->loadMissing('roles');
        abort_unless($party->roles->contains(fn($r) => $r->role === 'CUSTOMER' && $r->is_active), 404);
        $branchIds = $this->allowedBranches($request, $company->id)->pluck('id')->all();
        [$from, $to] = $this->dateWindow($request, $company->id);

        $opening = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)->whereIn('je.branch_id', $branchIds)->whereIn('je.status', ['POSTED', 'REVERSED'])
            ->where('jl.party_type', 'CUSTOMER')->where('jl.party_id', $party->id)->where('je.journal_date', '<', $from)
            ->selectRaw('COALESCE(SUM(jl.base_debit),0) AS debit, COALESCE(SUM(jl.base_credit),0) AS credit')->first();
        $openingNet = round((float)$opening->debit - (float)$opening->credit, 2);

        $rows = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'jl.account_id')->join('branches as b', 'b.id', '=', 'je.branch_id')
            ->where('je.company_id', $company->id)->whereIn('je.branch_id', $branchIds)->whereIn('je.status', ['POSTED', 'REVERSED'])
            ->where('jl.party_type', 'CUSTOMER')->where('jl.party_id', $party->id)->whereBetween('je.journal_date', [$from, $to])
            ->orderBy('je.journal_date')->orderBy('je.id')->orderBy('jl.line_no')
            ->select(['jl.id as line_id','jl.debit','jl.credit','jl.base_debit','jl.base_credit','jl.description as line_description','jl.source_line_type','jl.source_line_id',
                'je.id as journal_id','je.journal_no','je.journal_date','je.journal_type','je.source_type','je.source_id','je.reference','je.currency_code','a.code as account_code','a.name as account_name','b.name as branch_name'])
            ->get();

        $running = $openingNet;
        foreach ($rows as $row) {
            $running = round($running + (float)$row->base_debit - (float)$row->base_credit, 2);
            $row->running_net = $running;
        }
        $closingNet = $running;
        $sourceLinks = $this->sourceLinks($rows);

        return view('accounting.ledgers.customer', compact('company', 'party', 'rows', 'from', 'to', 'openingNet', 'closingNet', 'sourceLinks'));
    }

    public function trialBalance(Request $request): View
    {
        $company = Company::query()->firstOrFail();
        $branchIds = $this->allowedBranches($request, $company->id)->pluck('id')->all();
        $asOf = $this->safeDate((string)$request->query('as_of'), now()->toDateString());

        $totals = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $company->id)->whereIn('je.branch_id', $branchIds)->whereIn('je.status', ['POSTED', 'REVERSED'])
            ->where('je.journal_date', '<=', $asOf)
            ->selectRaw('jl.account_id, SUM(jl.base_debit) AS debit, SUM(jl.base_credit) AS credit')->groupBy('jl.account_id')->get()->keyBy('account_id');

        $accounts = Account::query()->where('company_id', $company->id)->orderBy('code')->get()->map(function (Account $account) use ($totals) {
            $row = $totals->get($account->id);
            $net = round((float)($row->debit ?? 0) - (float)($row->credit ?? 0), 2);
            $account->tb_debit = $net >= 0 ? $net : 0.0;
            $account->tb_credit = $net < 0 ? abs($net) : 0.0;
            return $account;
        });
        $debitTotal = round((float)$accounts->sum('tb_debit'), 2);
        $creditTotal = round((float)$accounts->sum('tb_credit'), 2);
        $difference = round($debitTotal - $creditTotal, 2);

        return view('accounting.trial-balance', compact('company', 'accounts', 'asOf', 'debitTotal', 'creditTotal', 'difference'));
    }

    private function sourceLinks(Collection $rows): array
    {
        $invoiceIds = collect();
        $lineIds = collect();
        foreach ($rows as $row) {
            if (($row->source_line_type ?? null) === 'SALES_INVOICE') $invoiceIds->push((int)$row->source_line_id);
            if (($row->source_line_type ?? null) === 'SALES_INVOICE_LINE') $lineIds->push((int)$row->source_line_id);
            if (($row->source_type ?? null) === 'SALES_INVOICE' && $row->source_id) $invoiceIds->push((int)$row->source_id);
        }
        if ($lineIds->isNotEmpty()) {
            $invoiceIds = $invoiceIds->merge(DB::table('sales_invoice_lines')->whereIn('id', $lineIds->unique()->all())->pluck('sales_invoice_id'));
        }
        $invoices = DB::table('sales_invoices')->whereIn('id', $invoiceIds->unique()->filter()->all())->pluck('invoice_no', 'id');
        $lineInvoice = $lineIds->isEmpty() ? collect() : DB::table('sales_invoice_lines')->whereIn('id', $lineIds->unique()->all())->pluck('sales_invoice_id', 'id');

        $links = [];
        foreach ($rows as $row) {
            $invoiceId = null;
            if (($row->source_line_type ?? null) === 'SALES_INVOICE') $invoiceId = (int)$row->source_line_id;
            elseif (($row->source_line_type ?? null) === 'SALES_INVOICE_LINE') $invoiceId = (int)($lineInvoice[$row->source_line_id] ?? 0);
            elseif (($row->source_type ?? null) === 'SALES_INVOICE') $invoiceId = (int)$row->source_id;
            if ($invoiceId && isset($invoices[$invoiceId])) $links[$row->line_id] = ['type'=>'SALES_INVOICE','id'=>$invoiceId,'label'=>$invoices[$invoiceId]];
        }
        return $links;
    }

    private function dateWindow(Request $request, int $companyId): array
    {
        $fy = DB::table('fiscal_years')->where('company_id', $companyId)->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->first()
            ?? DB::table('fiscal_years')->where('company_id', $companyId)->orderByDesc('starts_on')->first();
        $defaultFrom = $fy?->starts_on ?: now()->startOfYear()->toDateString();
        $defaultTo = min(now()->toDateString(), $fy?->ends_on ?: now()->toDateString());
        $from = $this->safeDate((string)$request->query('from'), $defaultFrom);
        $to = $this->safeDate((string)$request->query('to'), $defaultTo);
        if ($from > $to) [$from, $to] = [$to, $from];
        return [$from, $to];
    }

    private function safeDate(string $value, string $fallback): string
    {
        if ($value === '') return $fallback;
        try { return Carbon::parse($value)->toDateString(); } catch (\Throwable) { return $fallback; }
    }

    private function allowedBranches(Request $request, int $companyId)
    {
        $user = $request->user();
        if ($user->is_super_admin || $user->access_scope === 'ALL_BRANCHES') return Branch::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();
        $query = $user->branches()->where('branches.company_id', $companyId)->where('branches.is_active', true);
        if ($user->access_scope === 'PRIMARY_BRANCH') $query->wherePivot('is_primary', true);
        return $query->orderBy('branches.name')->get();
    }
}
