@extends('layouts.app')
@section('title','Account Mappings')
@section('page_title','Account Mappings')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ERP-03</span><h1>Posting services will resolve account codes through mappings.</h1><p>Future invoices, vendor bills, receipts, payments, commissions, refunds and FX postings will request a mapping key. They will never depend on hard-coded database IDs.</p></div><a class="btn btn-ghost" href="{{ route('accounting.accounts.index') }}">Chart of Accounts</a></div>
<div class="metric-grid"><div class="metric-card"><div class="metric-label">Mapping Groups</div><div class="metric-value">{{ $groups->count() }}</div></div><div class="metric-card"><div class="metric-label">Required Mappings</div><div class="metric-value">{{ $groups->flatten(1)->where('is_required',true)->count() }}</div></div><div class="metric-card"><div class="metric-label">Missing</div><div class="metric-value {{ $missingRequired ? 'bad':'ok' }}">{{ $missingRequired }}</div><div class="metric-note">Must be zero before ERP-04</div></div><div class="metric-card"><div class="metric-label">Resolver Status</div><div class="metric-value {{ $missingRequired ? 'bad':'ok' }}">{{ $missingRequired ? 'Review':'Ready' }}</div></div></div>
<div class="callout"><strong>Control-account rule:</strong> Customers, vendors and agents are party subledgers. Their balances flow through AR/AP/advance/commission control accounts selected here.</div>
@foreach($groups as $group=>$mappings)
<div class="panel mapping-panel"><div class="panel-head"><div><h3>{{ str_replace('_',' ',$group) }}</h3><p>{{ $mappings->count() }} controlled account mappings</p></div></div><div class="mapping-grid">
@foreach($mappings as $mapping)
@if(auth()->user()->hasPermission('coa.manage'))<form method="POST" action="{{ route('accounting.mappings.update',$mapping) }}" class="mapping-card">@csrf @method('PUT')@else<div class="mapping-card">@endif
<div><span class="mapping-key">{{ $mapping->mapping_key }}</span><strong>{{ $mapping->label }}</strong><small>{{ $mapping->description ?: 'Mapped '.$mapping->expected_account_type.' posting account.' }}</small></div>
<div>@if(auth()->user()->hasPermission('coa.manage'))<label><span>{{ $mapping->expected_account_type }} Account</span><select name="account_id" required>@foreach($accountsByType->get($mapping->expected_account_type,collect()) as $account)<option value="{{ $account->id }}" @selected($mapping->account_id===$account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></label><button class="btn btn-small btn-primary">Save Mapping</button>@else<label><span>{{ $mapping->expected_account_type }} Account</span><strong>{{ $mapping->account?->code ?? '—' }} · {{ $mapping->account?->name ?? 'Unmapped' }}</strong></label>@endif</div>
@if(auth()->user()->hasPermission('coa.manage'))</form>@else</div>@endif
@endforeach
</div></div>
@endforeach
@endsection
