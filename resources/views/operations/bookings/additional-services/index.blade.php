@extends($layoutMeta['layout'])

@section('content')
<div class="et-page et378-page">
    @include('operations.bookings.partials.booking-workspace-header-v11370', ['headerAction' => ($state['can_start'] ?? false) ? '<form method="POST" action="'.route('bookings.additional-services.start', $bookingId).'">'.csrf_field().'<button class="et-btn primary" type="submit">+ Add Service</button></form>' : null])
    <div class="et-page-header">
        <div><div class="et-eyebrow">GENERAL BOOKING</div><h1>Additional Services</h1><p>Start a supplementary service batch without reopening the approved booking.</p></div>
    </div>
    @if(($state['message'] ?? null))
        <div class="et-alert et-alert-warning">{{ $state['message'] }}</div>
    @endif
    @if(!($state['can_start_new_batch'] ?? false) && ($state['entry_message'] ?? null))
        <div class="et-alert et-alert-info">{{ $state['entry_message'] }}</div>
    @endif
    @if($errors->has('additional_services'))<div class="et-alert et-alert-danger">{{ $errors->first('additional_services') }}</div>@endif
    <div class="et-grid et-grid-3">
        <section class="et-card"><div class="et-card-label">Booking</div><strong>{{ $state['booking']['booking_reference'] ?? $state['booking']['booking_no'] ?? $state['booking']['reference_no'] ?? ('#'.$bookingId) }}</strong><div>{{ $customer['name'] ?? $customer['customer_name'] ?? 'Customer' }}</div><small>Status: {{ $state['booking_status'] ?? 'Unknown' }}</small></section>
        <section class="et-card"><div class="et-card-label">Base Invoice</div>@php($base=$state['base_batch'] ?? null)<strong>{{ $base['invoice']['invoice_no'] ?? 'Not linked' }}</strong><div>{{ number_format((float)($base['customer_total'] ?? 0), 2) }}</div><small>{{ $base['status'] ?? 'Awaiting adoption' }}</small></section>
        <section class="et-card"><div class="et-card-label">Billing Summary</div><div>Total Active Invoiced <strong>{{ number_format((float)($state['total_invoiced'] ?? 0), 2) }}</strong></div><div>Total Posted <strong>{{ number_format((float)($state['total_posted'] ?? 0), 2) }}</strong></div><div>Approved Uninvoiced <strong>{{ number_format((float)($state['approved_uninvoiced_total'] ?? 0), 2) }}</strong></div></section>
    </div>
    <section class="et-card">
        <div class="et-card-heading"><h2>Supplementary batches</h2>
            @if(($state['can_start'] ?? false))<form method="POST" action="{{ route('bookings.additional-services.start', $bookingId) }}">@csrf<button class="et-btn primary" type="submit">+ Add Product</button></form>@endif
        </div>
        @forelse(($state['supplementary_batches'] ?? []) as $batch)
            <div class="et-list-row"><div><strong>Additional Services #{{ $batch['batch_no'] }}</strong><small>{{ ucfirst(str_replace('_',' ', $batch['status'])) }}</small></div><div>@if($batch['status']==='draft')<a class="et-btn secondary" href="{{ route('bookings.additional-services.show',['booking'=>$bookingId,'batch'=>$batch['id']]) }}">Continue Additional Services #{{ $batch['batch_no'] }}</a>@elseif($batch['status']==='pending_approval')<span>Pending Approval</span>@elseif($batch['status']==='approved' && !$batch['has_invoice_link'])<span>Approved — Awaiting Supplementary Invoice</span>@endif</div></div>
        @empty
            <div class="et-empty-state">No supplementary batches yet. Add a product when the booking is ready.</div>
        @endforelse
    </section>
</div>
@endsection
