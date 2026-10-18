@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Movements & Reports')
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<style>
.mobile-movements-page{color:#17243a}.mobile-movements-page .movement-section{margin-top:20px}.mobile-movements-page .movement-section h2{margin:0 0 9px;color:#17243a;font-size:13px;font-weight:800}.mobile-movements-page .movement-card{display:block}.mobile-movements-page .movement-card strong{display:block;font-size:15px}.mobile-movements-page .movement-card span{display:block;margin-top:5px;color:#66758a;font-size:12px}.mobile-movements-page .movement-grid,.mobile-movements-page .movement-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.mobile-movements-page .movement-summary .movement-card{min-height:86px}.mobile-movements-page .movement-card .count{margin-top:8px;color:#1769d2;font-size:24px;font-weight:850}.mobile-movements-page .movement-list{display:grid;gap:10px}.mobile-movements-page .movement-event{display:block;padding:14px;border:1px solid #dce7f4;border-radius:9px;background:#fff;box-shadow:0 5px 18px rgba(28,55,90,.06);text-decoration:none;color:#17243a}.mobile-movements-page .movement-event .badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef5ff;color:#1769d2;font-size:10px;font-weight:800}.mobile-movements-page .movement-event .meta{margin-top:5px;color:#66758a;font-size:12px}.mobile-movements-page .movement-event .booking{margin-top:5px;font-weight:850}.mobile-movements-page .movement-event .details{margin-top:5px;font-size:12px;line-height:1.45}.mobile-movements-page .empty{padding:14px;border:1px dashed #cbd5e1;border-radius:9px;color:#66758a;background:#f8fafc}@media(max-width:760px){.mobile-movements-page .movement-grid,.mobile-movements-page .movement-summary{grid-template-columns:1fr}}
</style>
<main class="mobile-accounts-page mobile-movements-page">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.accounts.index') }}">← Accounts</a></div>
    <h1 class="mobile-title">Movements &amp; Reports</h1><p class="mobile-sub">Today and upcoming travel movements from the existing operational reports.</p>
    <section class="movement-section"><h2>Today</h2><div class="movement-grid">
        @foreach($movements as $key => $label)
            <a class="mobile-card movement-card" href="{{ route('mobile.movements.'.$key, ['from'=>now()->toDateString(),'to'=>now()->toDateString()]) }}">
                <strong>{{ $label }}</strong><span>Today</span><span class="count">{{ $todayCounts[$key] ?? 0 }}</span>
            </a>
        @endforeach
    </div></section>
    <section class="movement-section"><h2>Upcoming</h2><div class="movement-summary"><div class="mobile-card movement-card"><strong>Tomorrow</strong><span>Total movements</span><span class="count">{{ $tomorrowCount }}</span></div><div class="mobile-card movement-card"><strong>Next 3 Days</strong><span>Tomorrow through day three</span><span class="count">{{ $nextThreeDaysCount }}</span></div></div></section>
    <section class="movement-section"><h2>Next Movements</h2><div class="movement-list">
        @forelse($upcoming as $event)
            <a class="movement-event" href="{{ $event['action'] ?: route('mobile.movements.'.$event['key']) }}">
                <span class="badge">{{ $event['movement'] }}</span>
                <div class="meta">{{ $event['date'] ?: 'Date unavailable' }}
                    @if($event['time']) · {{ $event['time'] }} @endif
                </div>
                <div class="booking">{{ $event['booking_no'] }}</div>
                <div class="details">{{ $event['customer'] }} · {{ $event['pax'] }} pax
                    @if($event['hotel'] && $event['hotel'] !== '—') · {{ $event['hotel'] }} @endif
                    @if($event['flight']) · {{ $event['flight'] }} @endif
                </div>
            </a>
        @empty
            <div class="empty">No upcoming movements in the next 3 days.</div>
        @endforelse
    </div></section>
</main>
@endsection
