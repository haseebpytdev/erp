@php($bookingReference = $booking['booking_reference'] ?? $booking['booking_no'] ?? ('Booking #'.$bookingId))
<header class="et-booking-workspace-header" data-et-booking-workspace-header="1">
  {{-- Native shell compatibility: etgp-toolbar / data-etgp-booking-context remain the dedicated context authority. --}}
  {{-- Booking identity/customer/status authorities: booking_reference, customer, $lock['status']. --}}
  <div class="et-booking-header-left">
    <div class="et-booking-header-kicker">Booking Workspace</div>
    <h1>{{ $bookingReference }}</h1>
    <p>{{ $customer['name'] ?? 'Customer pending' }} · {{ $booking['booking_type'] ?? $booking['type'] ?? 'General' }} · {{ $booking['branch_name'] ?? $booking['office_name'] ?? 'Head Office' }}</p>
    <nav class="et-booking-progress et-ph-steps" aria-label="Booking workflow" data-et-booking-workflow="1">
      <a class="done et-ph-step" href="{{ url('/operations/bookings/'.$bookingId) }}"><span>1</span>Booking &amp; Passengers</a><b>→</b><a class="current et-ph-step" href="{{ url('/operations/bookings/'.$bookingId.'/products') }}" aria-current="step"><span>2</span>Products</a><b>→</b><a class="et-ph-step" href="{{ route('bookings.review.show',['booking'=>$bookingId]) }}"><span>3</span>Review</a>
    </nav>
  </div>
  <div class="et-booking-header-right">
    <div class="et-booking-header-menu"><a class="et-btn secondary" href="{{ url('/operations/bookings/'.$bookingId) }}">Menu</a><a class="et-btn secondary" href="{{ url('/operations/bookings') }}">Booking Register</a></div>
    <span class="et-status" data-et-booking-status="1">{{ $lock['status'] ?? 'Draft' }}</span>
    @if(!empty($lock['locked']))<span class="et-status" data-et-status="draft">Read-only</span>@endif
    @if(($headerAction ?? null)){!! $headerAction !!}@endif
  </div>
</header>
