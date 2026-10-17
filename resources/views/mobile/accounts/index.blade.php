@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Accounts Quick Posting')
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<main class="mobile-accounts-page">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="#" data-mobile-back-to-erp>← Back to ERP</a></div>
    <h1 class="mobile-title">Accounts Quick Posting</h1>
    <p class="mobile-sub">Post payments, receive money and manage advances quickly.</p>
    <div class="mobile-grid">
        @if($cards['payment'])<a class="mobile-card" href="{{ route('mobile.accounts.payment') }}"><strong>Make Payment</strong><span>Supplier payment voucher</span></a>@endif
        @if($cards['receipt'])<a class="mobile-card" href="{{ route('mobile.accounts.receipt') }}"><strong>Receive Payment</strong><span>Customer receipt voucher</span></a>@endif
        @if($cards['advance'])<a class="mobile-card" href="{{ route('mobile.accounts.advance') }}"><strong>Advance</strong><span>Customer or supplier advance</span></a>@endif
        @if($cards['adjustment'])<a class="mobile-card" href="{{ route('mobile.accounts.adjustment') }}"><strong>Adjust Advance</strong><span>Apply an existing advance</span></a>@endif
        @if($cards['return'])<a class="mobile-card" href="{{ route('mobile.accounts.customer-return') }}"><strong>Customer Advance Return</strong><span>Controlled customer refund</span></a>@endif
    </div>
    @if($recent->isNotEmpty())
    <section class="mobile-section"><strong>Recent Postings</strong><table class="mobile-table"><thead><tr><th>Voucher</th><th>Type</th><th>Party</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>@foreach($recent as $row)<tr><td data-label="Voucher">{{ $row->voucher_no }}</td><td data-label="Type">{{ ucwords(str_replace('_',' ',$row->voucher_type)) }}</td><td data-label="Party">{{ $row->party_name ?: '—' }}</td><td data-label="Amount">{{ number_format((float)$row->amount,2) }}</td><td data-label="Status">{{ ucwords(str_replace('_',' ',$row->status)) }}</td><td data-label="Date">{{ $row->voucher_date }}</td></tr>@endforeach</tbody></table></section>
    @endif
</main>
@endsection
