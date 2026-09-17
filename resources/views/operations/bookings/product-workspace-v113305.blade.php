@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', ucfirst($product).' Product Workspace')
@section($layoutMeta['content_section'] ?? 'content')
<main class="et-product-workspace etgp-step1 et-general-progressive-step1-11390" data-etgp-dedicated-product="1" data-etgp-product-key="{{ $product }}" data-booking-id="{{ $bookingId }}" data-booking-reference="{{ $booking['booking_reference'] ?? $booking['booking_no'] ?? ('Booking #'.$bookingId) }}" data-currency="{{ $booking['currency'] ?? $booking['currency_code'] ?? 'PKR' }}" data-etgp-booking-locked="{{ $lock['locked'] ? '1' : '0' }}" data-etgp-booking-status="{{ $lock['status'] }}" data-etgp-selected-products="{{ implode(',', $selectedProducts) }}">
 <header class="et-product-workspace-head"><div><h1>{{ ucfirst($product) }} Workspace</h1><p>{{ $booking['booking_reference'] ?? $booking['booking_no'] ?? ('Booking #'.$bookingId) }} · {{ $customer['name'] ?? '—' }} · {{ $lock['status'] }}</p></div><nav><a href="{{ url('/operations/bookings/'.$bookingId) }}">← Back to Booking</a><a href="{{ route('bookings.review.show',['booking'=>$bookingId]) }}">Review Booking →</a></nav></header>
 <section class="et-product-runtime-card"><h2>{{ $product === 'other-services' ? 'Other Services' : ucfirst($product) }}</h2>@if($product === 'other-services')<p>Operational workspace not configured yet.</p>@else<div data-etgp-dedicated-product-host><div data-etgp-dedicated-product-body></div></div>@endif</section>
</main>
<link rel="stylesheet" href="{{ route('system.erp-assets.general-progressive-step1-css') }}?v=11.3.305">
<script src="{{ route('system.erp-assets.general-progressive-step1-js') }}?v=11.3.305" defer></script>
<script>document.addEventListener('DOMContentLoaded',function(){var root=document.querySelector('[data-etgp-dedicated-product="1"]');if(root&&window.etgpMountDedicatedProduct113305)window.etgpMountDedicatedProduct113305(root);});</script>
@endsection
