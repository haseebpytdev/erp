@extends('layouts.app')
@section('title','Organization')
@section('page_title','Organization & Fiscal Context')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ERP-01</span><h1>Accounting starts with controlled organization context.</h1><p>Company, branches, currencies, fiscal years, accounting periods and document numbering are configured here before the chart of accounts is introduced.</p></div></div>
<div class="metric-grid">
<div class="metric-card"><div class="metric-label">Company</div><div class="metric-value">{{ $company->code }}</div><div class="metric-note">{{ $company->name }}</div></div>
<div class="metric-card"><div class="metric-label">Branches</div><div class="metric-value">{{ $branchCount }}</div><div class="metric-note">Operational units</div></div>
<div class="metric-card"><div class="metric-label">Currencies</div><div class="metric-value">{{ $currencyCount }}</div><div class="metric-note">Base: {{ $company->base_currency }}</div></div>
<div class="metric-card"><div class="metric-label">Sequences</div><div class="metric-value">{{ $sequenceCount }}</div><div class="metric-note">Central numbering controls</div></div>
</div>
<div class="settings-grid">
<a class="settings-card" href="{{ route('organization.company.edit') }}"><strong>Company Profile</strong><span>Legal identity, base currency, timezone and contacts.</span><b>Open →</b></a>
<a class="settings-card" href="{{ route('organization.branches.index') }}"><strong>Branches</strong><span>Create and maintain operational branch master data.</span><b>Open →</b></a>
<a class="settings-card" href="{{ route('organization.currencies.index') }}"><strong>Currencies</strong><span>Activate the currencies the ERP may transact in.</span><b>Open →</b></a>
<a class="settings-card" href="{{ route('organization.fiscal-years.index') }}"><strong>Fiscal Years & Periods</strong><span>Create fiscal calendars and control period locking.</span><b>Open →</b></a>
<a class="settings-card" href="{{ route('organization.sequences.index') }}"><strong>Number Sequences</strong><span>Control prefixes and counters for future accounting documents.</span><b>Open →</b></a>
<a class="settings-card" href="{{ route('system.update') }}"><strong>System Health & Updates</strong><span>No-SSH database upgrades and application health checks.</span><b>Open →</b></a>
</div>
<div class="panel" style="margin-top:18px"><div class="panel-head"><div><h3>Fiscal Years</h3><p>At least one open fiscal year is required before accounting postings can be enabled.</p></div><a class="btn btn-primary" href="{{ route('organization.fiscal-years.index') }}">Manage Fiscal Years</a></div>
@if($fiscalYears->isEmpty())<div class="empty-state"><strong>No fiscal year configured.</strong><p>Create the first fiscal year and the system will generate month-bounded accounting periods automatically.</p></div>@else
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Dates</th><th>Periods</th><th>Status</th></tr></thead><tbody>@foreach($fiscalYears as $fy)<tr><td><strong>{{ $fy->code }}</strong><small>{{ $fy->name }}</small></td><td>{{ $fy->starts_on->format('d M Y') }} — {{ $fy->ends_on->format('d M Y') }}</td><td>{{ $fy->periods_count }}</td><td><span class="status-pill {{ strtolower($fy->status) }}">{{ $fy->status }}</span></td></tr>@endforeach</tbody></table></div>@endif
</div>
@endsection
