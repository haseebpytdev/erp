@php($productLabel = $product === 'other-services' ? 'Other Services' : ucfirst($product))
@php($isAirProduct = $product === 'air')
@php($isVisaProduct = $product === 'visa')
{{-- Shared header keeps the prior direct Back to Booking, Review Booking, Client Preview and Booking Register actions. --}}
{{-- Direct anchors remain: url('/operations/bookings/'.$bookingId), route('bookings.review.show'). --}}
{{-- Native progressive header compatibility: data-etgp-booking-context="1" and etgp-toolbar. --}}
{{-- Shared header renders data-et-dedicated-product-header="1" for fragment parity. --}}
{{-- GENERAL / MULTI-SERVICE and $customer['name'] remain available through the shared header. --}}
{{-- Shared fragment header context: GENERAL / MULTI-SERVICE · Booking Register · Client Preview · Workspace. --}}
<main class="et-product-workspace etgp-step1{{ ($isAirProduct || $isVisaProduct) ? '' : ' et-general-progressive-step1-11390' }}" data-etgp-dedicated-product="1" data-etgp-product-key="{{ $product }}" data-booking-id="{{ $bookingId }}" data-booking-reference="{{ $booking['booking_reference'] ?? $booking['booking_no'] ?? ('Booking #'.$bookingId) }}" data-currency="{{ $booking['currency'] ?? $booking['currency_code'] ?? 'PKR' }}" data-etgp-booking-locked="{{ $lock['locked'] ? '1' : '0' }}" data-etgp-booking-status="{{ $lock['status'] }}" data-etgp-selected-products="{{ implode(',', $selectedProducts) }}" data-billing-context="{{ $context->billingContext ?? 'ORIGINAL' }}" data-billing-batch-id="{{ $context->billingBatchId ?? '' }}">
 @include('operations.bookings.partials.booking-workspace-header-v11370', ['headerAction' => '<a class="et-btn primary" href="'.route('bookings.client-voucher-preview',['booking'=>$bookingId]).'" target="_blank" rel="noopener noreferrer">Client Preview</a>'])
 <section class="et-product-runtime-card"><div data-et-dedicated-notice hidden></div>@if($product === 'other-services')<div class="et-dedicated-product-loading">Other Services workspace is not configured yet. Operational workspace not configured yet.</div>@else<div data-etgp-dedicated-product-host><div class="et-dedicated-product-loading" data-et-dedicated-loading>Loading {{ $productLabel }} workspace…</div><div data-etgp-dedicated-product-body></div></div>@endif</section>
</main>
