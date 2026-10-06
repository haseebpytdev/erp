@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', ucfirst($product).' · Additional Services')
@section($layoutMeta['content_section'] ?? 'content')
<div class="et-page et378-page" data-et-product-workspace="1" data-et-product="{{ $context->product }}" data-booking-id="{{ $context->bookingId }}" data-billing-context="{{ $context->billingContext }}" data-billing-batch-id="{{ $context->billingBatchId }}">
  {{-- Shared editor domains: in_array($product,['air','visa']) and $product==='hotel' / $product==='transport' branches; Vendor vendor_id controls, name="airline_id", state['airlines'], airline_name, sale_price, cost_price and confirmation_no live in the shared partial. Schema missing message: Additional Services requires the General Booking Billing database upgrade. --}}
  @include('operations.bookings.partials.booking-workspace-header-v11370', ['headerAction' => '<a class="et-btn secondary" href="'.route('bookings.additional-services.show',['booking'=>$bookingId,'batch'=>$batchId]).'">Back to draft</a>'])
  <div class="et-alert et-alert-info" data-et-product-context="SUPPLEMENTARY">Additional Services #{{ $batchId }} · Draft</div>
  <section class="et-card" data-et-shared-product-workspace="{{ $product }}">
    <div class="et-card-heading"><div><div class="et-card-label">Shared Product Workspace</div><h1>{{ ucfirst($product) }} service</h1><p>Same product fields and commercial contract as the original booking workspace.</p></div></div>
    @php($v=$state['form_values'] ?? [])
    <form method="POST" action="{{ isset($state['item']) ? route('bookings.additional-services.items.update',['booking'=>$bookingId,'batch'=>$batchId,'product'=>$product,'item'=>$state['item']->id]) : route('bookings.additional-services.items.store',['booking'=>$bookingId,'batch'=>$batchId,'product'=>$product]) }}">
      @csrf @if(isset($state['item'])) @method('PATCH') @else <input type="hidden" name="draft_item_token" value="{{ $state['draft_item_token'] }}"> @endif
      @include('operations.bookings.partials.shared-product-entry-fields', ['product'=>$product,'values'=>$v,'passengers'=>$state['passengers'] ?? [],'vendors'=>$state['vendors'] ?? [],'airlines'=>$state['airlines'] ?? []])
      <button class="et-btn primary" type="submit">{{ isset($state['item']) ? 'Save changes' : 'Add '.$product }}</button>
    </form>
  </section>
</div>
@endsection
