@extends('layouts.app')
@section('title','Reports & Print Center')
@section('page_title','Ledger Reports & Print Center')
@section('content')
@php
$type=request('type','customer_ledger');
$selectedAccount=(int)request('account_id',0);
$selectedParty=(int)request('party_id',0);
$selectedBranch=(int)request('branch_id',0);
$partyOptions=$type==='vendor_ledger' ? $vendors : $customers;
$partyLabel=$type==='vendor_ledger' ? 'Vendor' : 'Customer';
$q=request()->query(); unset($q['run']);
@endphp
<div class="page-intro"><div><span class="eyebrow">ERP-09.3 · REPORTS & PRINT CENTER</span><h1>Preview, print and export accounting reports.</h1><p>Report headers use Organization & Fiscal Context / Company Profile data. Figures come only from posted/reversed Journal Lines and every web reference drills back to the original source document.</p></div></div>
@if($report)
<div class="metric-grid">
<div class="metric-card"><div class="metric-label">Opening Balance</div><div class="metric-value">{{ $company->base_currency }} {{ number_format(abs((float)$report['metrics']['opening']),2) }}</div><div class="metric-note">{{ (float)$report['metrics']['opening']>=0?'Debit':'Credit' }} as of {{ $report['from'] }}</div></div>
<div class="metric-card"><div class="metric-label">Total Debit</div><div class="metric-value">{{ $company->base_currency }} {{ number_format((float)$report['metrics']['debit'],2) }}</div><div class="metric-note">Selected report period</div></div>
<div class="metric-card"><div class="metric-label">Total Credit</div><div class="metric-value">{{ $company->base_currency }} {{ number_format((float)$report['metrics']['credit'],2) }}</div><div class="metric-note">Selected report period</div></div>
<div class="metric-card"><div class="metric-label">{{ in_array($report['type'],['customer_ledger','vendor_ledger'],true) ? 'Closing Balance' : 'Closing / Difference' }}</div><div class="metric-value">{{ $company->base_currency }} {{ number_format(abs((float)$report['metrics']['closing']),2) }}</div><div class="metric-note">{{ (float)$report['metrics']['closing']>=0?'Debit / positive':'Credit / negative' }}</div></div>
</div>
@endif
<div class="panel" style="margin-bottom:18px"><form method="GET"><input type="hidden" name="run" value="1"><div class="compact-grid" style="grid-template-columns:repeat(4,minmax(0,1fr))">
<label><span>Report Type</span><select name="type"><option value="general_ledger" @selected($type==='general_ledger')>General Ledger</option><option value="account_ledger" @selected($type==='account_ledger')>Account Ledger</option><option value="customer_ledger" @selected($type==='customer_ledger')>Customer Ledger</option><option value="vendor_ledger" @selected($type==='vendor_ledger')>Vendor Ledger</option><option value="trial_balance" @selected($type==='trial_balance')>Trial Balance</option></select></label>
<label><span>Account</span><select name="account_id"><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($selectedAccount===$account->id)>{{ $account->code }} - {{ $account->name }}</option>@endforeach</select></label>
<label><span>{{ $partyLabel }}</span><select name="party_id"><option value="">Select {{ strtolower($partyLabel) }}</option>@foreach($partyOptions as $party)<option value="{{ $party->id }}" @selected($selectedParty===$party->id)>{{ $party->display_name }} · {{ $party->code }}</option>@endforeach</select></label>
<label><span>Branch</span><select name="branch_id"><option value="">All permitted branches</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($selectedBranch===$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
<label><span>Date From</span><input type="date" name="from" value="{{ request('from',$from) }}"></label><label><span>Date To / As Of</span><input type="date" name="to" value="{{ request('to',$to) }}"></label>
</div><div class="form-actions"><button class="btn btn-primary">Apply & Preview</button><a class="btn btn-ghost" href="{{ route('accounting.reports.index') }}">Reset</a></div></form></div>
@if($report)
@php $params=array_merge(request()->query(),['run'=>1]); @endphp
<div class="panel"><div class="panel-head"><div><h3>{{ $report['title'] }}</h3><p>{{ $report['subtitle'] }}</p></div><div class="action-row"><a class="btn btn-ghost" href="{{ route('accounting.reports.preview',$params) }}">Preview</a><a class="btn btn-ghost" target="_blank" href="{{ route('accounting.reports.print',$params) }}">Print</a><a class="btn btn-ghost" href="{{ route('accounting.reports.pdf',$params) }}">Export PDF</a><a class="btn btn-ghost" href="{{ route('accounting.reports.excel',$params) }}">Export Excel</a></div></div>
@include('accounting.reports.partials.report-table',['report'=>$report])
</div>
@else
<div class="panel"><div class="empty-state">Choose a report type and filters, then click <strong>Apply & Preview</strong>.</div></div>
@endif
@endsection
