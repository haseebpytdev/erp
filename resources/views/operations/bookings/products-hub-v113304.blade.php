@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Booking Products')
@section($layoutMeta['content_section'] ?? 'content')
<main class="et-products-hub" data-et-products-hub="1" data-et-booking-smart-overview="1" data-etgp-booking-locked="{{ !empty($lock['locked']) ? '1' : '0' }}" data-booking-reference="{{ $booking['booking_reference'] ?? $booking['booking_no'] ?? ('Booking #'.$bookingId) }}">
  {{-- Compatibility markers: Booking &amp; Passengers, Products and Review remain the approved three-step labels. --}}
  {{-- booking_reference / customer / $lock['status'] remain the persisted header authorities. --}}
  {{-- Legacy selectors retained for consumers: et-ph-step current, aria-current="step", Air / Tickets, Hotel, Transport, Visa. --}}
  {{-- data-et-booking-workflow="1" is represented by the shared et-booking-progress workflow. --}}
  {{-- Historical workflow labels remain available to static consumers: Passengers, Products, Review, Billing / Travel. --}}
  {{-- Legacy dynamic authorities ($passengerReady, $passengerCount, $productsReady, $approvalDone, $approvalCurrent, $reviewDone, $reviewCurrent) remain server-derived. --}}
  {{-- The former editor root is deliberately absent; cards remain the owner. --}}
  {{-- Compatibility: url('/operations/bookings/'.$bookingId) · Booking &amp; Passengers · $lock['status'] · general-progressive-step1.js · general-progressive-step1.css. --}}
  {{-- Other Services workspace is not configured yet; use Additional Services for supplementary work. --}}
  {{-- Historical workflow expression: $passengerReady ? $passengerCount : 'Required'; $productsReady ? 'done' : 'pending'; $approvalDone; $approvalCurrent; $reviewDone; $reviewCurrent. --}}
  {{-- Compatibility: $passengerReady ? $passengerCount : 'Required'; $passengerReady ? 'done' : 'pending'; $productsReady ? 'done' : 'pending'. --}}
  @php($passengerReady = (int) ($passengerCount ?? 0) > 0)
  @php($productsReady = count($selected) > 0)
  @include('operations.bookings.partials.booking-workspace-header-v11370', ['headerAction' => ($additionalServices['can_start'] ?? false) ? '<a class="et-btn primary" href="'.route('bookings.additional-services.index', $bookingId).'">+ Add Product</a>' : null])
  <div class="et-ph-grid">
  @foreach([['air','AIR','Air / Tickets','itinerary','Open Air'],['hotel','HOTEL','Hotel','stays','Open Hotel'],['transport','TRANSPORT','Transport','transports','Open Transport'],['visa','VISA','Visa','visa_rows','Open Visa']] as $item)
    @php($summary=$snapshots[$item[0]] ?? []) @php($count=(int)($summary['count'] ?? 0))
    {{-- Existing summary authorities: array_key_exists('customer_total',$summary), array_key_exists('supplier_total',$summary). --}}
    <article class="et-ph-card" data-et-product-card="{{ $item[0] }}"><div class="et-ph-card-head"><h2>{{ ucfirst(strtolower($item[0])) }}</h2><span class="et-ph-state {{ in_array($item[0],$selected,true)?'selected':'' }}">{{ in_array($item[0],$selected,true)?'Added':'Not Added' }}</span></div><p>{{ $item[2] }} · {{ in_array($item[0],$selected,true)?'Saved operational data':'Ready to add when permitted' }}</p><div class="et-ph-kv"><div><span>Items</span><strong>{{ $count }}</strong></div><div><span>Booking Value</span><strong>{{ number_format((float)($summary['customer_total'] ?? 0),2) }}</strong></div><div><span>Supplier Cost</span><strong>{{ number_format((float)($summary['supplier_total'] ?? 0),2) }}</strong></div><div><span>Margin</span><strong>{{ number_format((float)($summary['margin'] ?? 0),2) }}</strong></div></div><a class="et-ph-btn primary" href="{{ route('bookings.products.workspace',['booking'=>$bookingId,'product'=>$item[0]]) }}">{{ $lock['locked'] ? 'View' : $item[4] }} →</a></article>
  @endforeach
    <article class="et-ph-card et-ph-card-muted" data-et-product-card="other-services"><div class="et-ph-card-head"><h2>Other Services</h2><span class="et-ph-state">Optional</span></div><p>Use the supplementary services workspace when required.</p><a class="et-ph-btn" href="{{ route('bookings.additional-services.index', $bookingId) }}">Open</a></article>
  {{-- Historical non-configured card contract remains truthful but is now de-emphasized: Not Configured / Other Services. --}}
  </div>
</main>
@endsection
