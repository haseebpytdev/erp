@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Accounts Quick Posting')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.mobile-accounts{max-width:760px;margin:0 auto;padding:16px}.mobile-accounts h1{margin:0;color:#17243a}.mobile-accounts .sub{color:#66758a;margin:5px 0 18px}.mobile-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.mobile-card{display:block;padding:18px;border:1px solid #dce7f4;border-radius:12px;background:#fff;color:#17243a;text-decoration:none;box-shadow:0 5px 18px rgba(28,55,90,.06)}.mobile-card strong{display:block;font-size:15px}.mobile-card span{display:block;margin-top:5px;color:#66758a;font-size:12px}.mobile-section{margin-top:20px;padding:14px;border:1px solid #dce7f4;border-radius:12px;background:#fff}.mobile-table{width:100%;border-collapse:collapse;font-size:12px}.mobile-table th,.mobile-table td{padding:8px 5px;border-bottom:1px solid #edf1f6;text-align:left}.mobile-muted{color:#66758a}@media(max-width:640px){.mobile-grid{grid-template-columns:1fr}.mobile-accounts{padding:12px}.mobile-table{font-size:11px}}
</style>
<main class="mobile-accounts">
    <a href="{{ url('/dashboard') }}" class="mobile-muted">← Back to ERP</a>
    <h1>Accounts Quick Posting</h1>
    <p class="sub">Post payments, receive money and manage advances quickly.</p>
    <div class="mobile-grid">
        @if($cards['payment'])<a class="mobile-card" href="{{ route('mobile.accounts.payment') }}"><strong>Make Payment</strong><span>Supplier payment voucher</span></a>@endif
        @if($cards['receipt'])<a class="mobile-card" href="{{ route('mobile.accounts.receipt') }}"><strong>Receive Payment</strong><span>Customer receipt voucher</span></a>@endif
        @if($cards['advance'])<a class="mobile-card" href="{{ route('mobile.accounts.advance') }}"><strong>Advance</strong><span>Customer or supplier advance</span></a>@endif
        @if($cards['adjustment'])<a class="mobile-card" href="{{ route('mobile.accounts.adjustment') }}"><strong>Adjust Advance</strong><span>Apply an existing advance</span></a>@endif
        @if($cards['return'])<a class="mobile-card" href="{{ route('mobile.accounts.customer-return') }}"><strong>Customer Advance Return</strong><span>Controlled customer refund</span></a>@endif
    </div>
    @if($recent->isNotEmpty())
    <section class="mobile-section"><strong>Recent Postings</strong><table class="mobile-table"><thead><tr><th>Voucher</th><th>Type</th><th>Party</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead><tbody>@foreach($recent as $row)<tr><td>{{ $row->voucher_no }}</td><td>{{ ucwords(str_replace('_',' ',$row->voucher_type)) }}</td><td>{{ $row->party_name ?: '—' }}</td><td>{{ number_format((float)$row->amount,2) }}</td><td>{{ ucwords(str_replace('_',' ',$row->status)) }}</td><td>{{ $row->voucher_date }}</td></tr>@endforeach</tbody></table></section>
    @endif
</main>
@endsection
