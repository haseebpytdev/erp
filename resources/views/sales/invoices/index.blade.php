@extends('layouts.app')
@section('title','Sales Invoices')
@section('page_title','Sales Invoices')
@section('content')
<div class="page-intro"><div><span class="eyebrow">COMMERCIAL DOCUMENTS · ERP-09</span><h1>Sales Invoice Register</h1><p>Confirmed bookings become customer receivables only after an approved Sales Invoice is posted.</p></div></div>
<div class="metric-grid">
<div class="metric-card"><div class="metric-label">Draft</div><div class="metric-value">{{ $counts['draft'] }}</div><div class="metric-note">Commercial review</div></div>
<div class="metric-card"><div class="metric-label">Pending Approval</div><div class="metric-value">{{ $counts['pending'] }}</div><div class="metric-note">Maker / checker</div></div>
<div class="metric-card"><div class="metric-label">Approved</div><div class="metric-value">{{ $counts['approved'] }}</div><div class="metric-note">Ready to post</div></div>
<div class="metric-card"><div class="metric-label">Posted</div><div class="metric-value">{{ $counts['posted'] }}</div><div class="metric-note">In Customer Receivables</div></div>
</div>
<div class="panel">
<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end"><label style="flex:1;min-width:220px"><span>Search</span><input name="q" value="{{ $search }}" placeholder="Invoice, booking, customer, reference"></label><label style="min-width:190px"><span>Status</span><select name="status"><option value="">All</option>@foreach(['DRAFT','PENDING_APPROVAL','APPROVED','POSTED','CANCELLED'] as $s)<option value="{{ $s }}" @selected($statusFilter===$s)>{{ str_replace('_',' ',$s) }}</option>@endforeach</select></label><button class="btn btn-ghost">Filter</button></form>
</div>
<div class="panel">
<div class="panel-head"><div><h3>Invoices</h3><p>Sales Invoice ≠ Booking Confirmation ≠ Receipt ≠ Travel Voucher.</p></div></div>
<div style="overflow:auto"><table class="data-table"><thead><tr><th>Invoice</th><th>Date</th><th>Booking</th><th>Customer</th><th>Status</th><th style="text-align:right">Amount</th><th></th></tr></thead><tbody>
@forelse($invoices as $i)<tr><td><strong>{{ $i->invoice_no }}</strong></td><td>{{ $i->invoice_date?->format('d M Y') }}</td><td>{{ $i->booking?->booking_no ?: '—' }}</td><td>{{ $i->customer?->display_name }}</td><td><span class="status-pill {{ $i->status==='POSTED'?'active':($i->status==='CANCELLED'?'closed':'open') }}">{{ str_replace('_',' ',$i->status) }}</span></td><td style="text-align:right"><strong>{{ $i->currency_code }} {{ number_format((float)$i->grand_total,2) }}</strong></td><td><a class="btn btn-ghost btn-small" href="{{ route('sales.invoices.show',$i) }}">Open</a></td></tr>
@empty<tr><td colspan="7"><div class="empty-state"><strong>No Sales Invoices yet.</strong><p>Open a confirmed booking and choose Create Sales Invoice.</p></div></td></tr>@endforelse
</tbody></table></div>
</div>
@endsection
