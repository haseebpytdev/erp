@extends('layouts.app')
@section('title','Supplier Costing')
@section('page_title','Supplier Costing & Margin')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ERP-10 · COMMERCIAL COSTING</span><h1>Supplier Costing & Gross Margin</h1><p>Track estimated and confirmed direct supplier costs by booking without creating Vendor Payables before a Vendor Bill exists.</p></div></div>
<form method="GET" class="panel" style="margin-bottom:18px"><div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"><label style="flex:1;min-width:260px"><span>Booking / Customer</span><input name="q" value="{{ $q }}" placeholder="Search BK number or customer"></label><button class="btn btn-primary">Filter</button><a class="btn btn-ghost" href="{{ route('operations.costing.index') }}">Reset</a></div></form>
<div class="panel"><div class="panel-head"><div><h3>Booking Profitability Register</h3><p>All figures below are in {{ $company->base_currency }} base currency.</p></div></div>
<div style="overflow:auto"><table class="data-table"><thead><tr><th>Booking</th><th>Customer</th><th>Sale</th><th>Forecast Cost</th><th>Confirmed</th><th>Gross Margin</th><th>Margin %</th><th></th></tr></thead><tbody>
@forelse($bookings as $b) @php $s=$summaries[$b->id]; @endphp
<tr><td><strong>{{ $b->booking_no }}</strong><div class="muted-copy">{{ $b->booking_date?->format('d M Y') }} · {{ $b->branch?->name }}</div></td><td>{{ $b->customer?->display_name }}</td><td>{{ $s['base_currency'] }} {{ number_format($s['sales_base'],2) }}<div class="muted-copy">{{ $s['sales_label'] }}</div></td><td>{{ $s['base_currency'] }} {{ number_format($s['forecast_cost'],2) }}</td><td>{{ $s['base_currency'] }} {{ number_format($s['confirmed_cost'],2) }}</td><td><strong>{{ $s['base_currency'] }} {{ number_format($s['gross_margin'],2) }}</strong></td><td>{{ $s['gross_margin_pct']===null?'—':number_format($s['gross_margin_pct'],2).'%' }}</td><td><a class="btn btn-ghost btn-small" href="{{ route('operations.bookings.costing.show',$b) }}">Open</a></td></tr>
@empty<tr><td colspan="8"><div class="empty-state"><strong>No confirmed bookings found.</strong><p>Confirmed/Completed bookings appear here for costing.</p></div></td></tr>@endforelse
</tbody></table></div></div>
@endsection
