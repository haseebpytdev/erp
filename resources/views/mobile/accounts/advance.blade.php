@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Choose Advance')
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<main class="mobile-accounts-page">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.accounts.index') }}">← Accounts</a></div>
    <h1 class="mobile-title">Advance</h1>
    <p class="mobile-sub">Choose the native advance voucher authority.</p>
    <div class="mobile-grid">
        @if($customer)<a class="mobile-card" href="{{ route('mobile.accounts.customer-advance') }}">Customer Advance</a>@endif
        @if($supplier)<a class="mobile-card" href="{{ route('mobile.accounts.supplier-advance') }}">Supplier Advance</a>@endif
    </div>
</main>
@endsection
