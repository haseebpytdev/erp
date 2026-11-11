@extends('layouts.app')

@section('title', 'Foundation')
@section('page_title', 'Company & Branch Foundation')

@section('content')
<div class="page-intro">
    <div>
        <span class="eyebrow">ERP-00</span>
        <h1>Foundation settings</h1>
        <p>Keep organization identity separate from accounting configuration. Fiscal year and period controls will be completed in ERP-01.</p>
    </div>
</div>

@if ($errors->any())
    <div class="alert danger">{{ $errors->first() }}</div>
@endif

<div class="grid-2">
    <form class="panel form-panel" method="POST" action="{{ route('foundation.update') }}">
        @csrf
        @method('PUT')
        <div class="panel-head">
            <div><h3>Organization</h3><p>Primary company and operating branch.</p></div>
        </div>

        <div class="form-grid">
            <label><span>Company Name</span><input name="company_name" value="{{ old('company_name', $company?->name) }}" required></label>
            <label><span>Company Code</span><input name="company_code" value="{{ old('company_code', $company?->code) }}" required></label>
            <label><span>Base Currency</span><input name="base_currency" maxlength="3" value="{{ old('base_currency', $company?->base_currency ?? 'PKR') }}" required></label>
            <label><span>Branch Name</span><input name="branch_name" value="{{ old('branch_name', $branch?->name) }}" required></label>
            <label><span>Branch Code</span><input name="branch_code" value="{{ old('branch_code', $branch?->code) }}" required></label>
            <label><span>Branch City</span><input name="branch_city" value="{{ old('branch_city', $branch?->city) }}"></label>
        </div>

        <div class="form-actions"><button class="btn btn-primary" type="submit">Save Foundation</button></div>
    </form>

    <div class="panel">
        <div class="panel-head">
            <div><h3>Fiscal Context</h3><p>Intentionally not guessed during installation.</p></div>
        </div>
        @if ($fiscalYear)
            <dl class="details">
                <div><dt>Fiscal Year</dt><dd>{{ $fiscalYear->name }}</dd></div>
                <div><dt>Start</dt><dd>{{ $fiscalYear->starts_on->format('d M Y') }}</dd></div>
                <div><dt>End</dt><dd>{{ $fiscalYear->ends_on->format('d M Y') }}</dd></div>
                <div><dt>Status</dt><dd>{{ $fiscalYear->status }}</dd></div>
            </dl>
        @else
            <div class="empty-state">
                <strong>Fiscal year is not configured yet.</strong>
                <p>This is deliberate. ERP-01 will create the fiscal-year wizard, accounting periods, period locks and opening controls before any financial posting begins.</p>
            </div>
        @endif
    </div>
</div>
@endsection
