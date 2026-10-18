@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Accounts Quick Posting')
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<main class="mobile-accounts-page">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="#" data-mobile-back-to-erp>← Back to ERP</a></div>
    <h1 class="mobile-title">Accounts Quick Posting</h1>
    <p class="mobile-sub">Post payments, receive money and manage advances quickly.</p>
    <div class="mobile-grid">
        <a class="mobile-card" href="{{ $movementsUrl ?? route('mobile.movements.index') }}"><strong>Movements &amp; Reports</strong><span>Today and upcoming travel movements</span></a>
        @if($cards['payment'])<a class="mobile-card" href="{{ route('mobile.accounts.payment') }}"><strong>Make Payment</strong><span>Supplier payment voucher</span></a>@endif
        @if($cards['receipt'])<a class="mobile-card" href="{{ route('mobile.accounts.receipt') }}"><strong>Receive Payment</strong><span>Customer receipt voucher</span></a>@endif
        @if($cards['advance'])<a class="mobile-card" href="{{ route('mobile.accounts.advance') }}"><strong>Advance</strong><span>Customer or supplier advance</span></a>@endif
        @if($cards['adjustment'])<a class="mobile-card" href="{{ route('mobile.accounts.adjustment') }}"><strong>Adjust Advance</strong><span>Apply an existing advance</span></a>@endif
        @if($cards['return'])<a class="mobile-card" href="{{ route('mobile.accounts.customer-return') }}"><strong>Customer Advance Return</strong><span>Controlled customer refund</span></a>@endif
    </div>
    @if($recent->isNotEmpty())
    <section class="mobile-section"><strong>Recent Transactions</strong><table class="mobile-table"><thead><tr><th>Voucher</th><th>Type</th><th>Party</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>@foreach($recent as $row)<tr class="mobile-posting-row"><td data-label="Voucher" class="mobile-posting-voucher"><a class="mobile-posting-summary" href="{{ route('mobile.accounts.vouchers.show', $row->id) }}"><span class="mobile-posting-number">{{ $row->voucher_no }}</span><span aria-hidden="true"> · </span><span class="mobile-posting-party">{{ $row->party_name ?: '—' }}</span></a><span class="desktop-posting-number">{{ $row->voucher_no }}</span></td><td data-label="Type" class="mobile-posting-type">{{ ucwords(str_replace('_',' ',$row->voucher_type)) }}</td><td data-label="Party" class="mobile-posting-party-desktop">{{ $row->party_name ?: '—' }}</td><td data-label="Amount" class="mobile-posting-amount">{{ number_format((float)$row->amount,2) }}</td><td data-label="Status" class="mobile-posting-status"><span class="mobile-status-badge">{{ ucwords(str_replace('_',' ',$row->status)) }}</span></td><td data-label="Date" class="mobile-posting-date">{{ $row->voucher_date }}</td></tr>@endforeach</tbody></table></section>
    @endif
</main>
@endsection
