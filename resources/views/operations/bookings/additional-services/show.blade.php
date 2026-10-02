@extends($layoutMeta['layout'])

@section('content')
<div class="et-page et378-page">
    <div class="et-page-header"><div><div class="et-eyebrow">ADDITIONAL SERVICES</div><h1>Additional Services #{{ $state['batch']['batch_no'] ?? $batchId }}</h1><p>Draft workspace for a supplementary billing batch.</p></div><a class="et-btn secondary" href="{{ route('bookings.additional-services.index', $bookingId) }}">Back to booking</a></div>
    <div class="et-grid et-grid-3"><section class="et-card"><div class="et-card-label">Status</div><strong>{{ ucfirst(str_replace('_',' ', $state['batch']['status'] ?? 'draft')) }}</strong></section><section class="et-card"><div class="et-card-label">Booking</div><strong>{{ $state['booking']['booking_reference'] ?? $state['booking']['reference_no'] ?? ('#'.$bookingId) }}</strong></section><section class="et-card"><div class="et-card-label">Currency</div><strong>{{ $state['batch']['currency_code'] ?? 'PKR' }}</strong><small>Base invoice remains unchanged.</small></section></div>
    <section class="et-card"><h2>Choose a product for the next phase</h2><p class="et-muted">The C44 draft shell does not persist product items yet.</p><div class="et-grid et-grid-5">@foreach(['Air','Hotel','Transport','Visa','Other Service'] as $product)<button class="et-btn secondary" type="button" disabled>{{ $product }} <small>— Add — next phase</small></button>@endforeach</div></section>
</div>
@endsection
