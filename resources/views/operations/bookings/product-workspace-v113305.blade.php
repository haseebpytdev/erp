@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', $booking['booking_reference'] ?? $booking['booking_no'] ?? ('Booking #'.$bookingId))
@section($layoutMeta['content_section'] ?? 'content')
@php($isAirProduct = $product === 'air')
@php($isDedicatedVisaProduct = $product === 'visa')
@include('operations.bookings.partials.product-workspace-v113305')
@if(!$isAirProduct && !$isDedicatedVisaProduct)<script>document.addEventListener('DOMContentLoaded',function(){var root=document.querySelector('[data-etgp-dedicated-product="1"]');if(root&&window.etgpMountDedicatedProduct113305)window.etgpMountDedicatedProduct113305(root);});</script>@endif
@endsection
