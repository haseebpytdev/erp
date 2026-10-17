@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Choose Advance')
@section($layoutMeta['content_section'] ?? 'content')
<main class="mobile-accounts" style="max-width:560px;margin:0 auto;padding:16px"><a href="{{ route('mobile.accounts.index') }}">← Accounts</a><h1>Advance</h1><p>Choose the native advance voucher authority.</p><div class="mobile-grid" style="display:grid;gap:12px">@if($customer)<a class="mobile-card" href="{{ route('mobile.accounts.customer-advance') }}">Customer Advance</a>@endif @if($supplier)<a class="mobile-card" href="{{ route('mobile.accounts.supplier-advance') }}">Supplier Advance</a>@endif</div></main>
@endsection
