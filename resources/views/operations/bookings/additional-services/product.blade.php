@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', ucfirst($product).' · Additional Services')
@section($layoutMeta['content_section'] ?? 'content')
@include('operations.bookings.partials.product-workspace-v113305', ['supplementaryState' => $state])
@endsection
