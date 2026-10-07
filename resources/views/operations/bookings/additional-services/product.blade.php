@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', ucfirst($product).' · Additional Services')
@section($layoutMeta['content_section'] ?? 'content')
@include('operations.bookings.partials.product-workspace-v113305', ['supplementaryState' => $state])
<script>
document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-etgp-dedicated-product="1"]');
    if (root && window.etgpMountDedicatedProduct113305) window.etgpMountDedicatedProduct113305(root);
});
</script>
@endsection
