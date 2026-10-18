@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', $label)
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<style>
.mobile-movement-report{color:#17243a}.mobile-movement-report .movement-filter{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:0 0 14px;padding:14px;border:1px solid #dce7f4;border-radius:9px;background:#fff}.mobile-movement-report .movement-filter label{display:grid;gap:5px;min-width:0;font-weight:700;font-size:12px;color:#2f4058}.mobile-movement-report .movement-filter input{width:100%;min-width:0;min-height:42px;padding:9px;border:1px solid #cfdae8;border-radius:7px;font-size:14px}.mobile-movement-report .movement-filter .actions{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.mobile-movement-report .movement-filter button,.mobile-movement-report .movement-filter .reset{min-height:42px;padding:10px 16px;border:0;border-radius:7px;background:#1769d2;color:#fff;font-weight:800;text-decoration:none}.mobile-movement-report .movement-filter .reset{background:#eef3f8;color:#24344b}.mobile-movement-report .movement-list{display:grid;gap:10px}.mobile-movement-report .movement-card{display:block}.mobile-movement-report .movement-card .badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef5ff;color:#1769d2;font-size:10px;font-weight:800}.mobile-movement-report .movement-card .date{margin-top:6px;color:#66758a;font-size:12px}.mobile-movement-report .movement-card .booking{margin-top:4px;font-weight:850}.mobile-movement-report .movement-card .line{margin-top:5px;font-size:12px;line-height:1.45}.mobile-movement-report .empty{padding:16px;border:1px dashed #cbd5e1;border-radius:9px;color:#66758a;background:#f8fafc}@media(max-width:760px){.mobile-movement-report .movement-filter{grid-template-columns:1fr}.mobile-movement-report .movement-filter .actions{grid-column:auto}.mobile-movement-report .movement-filter button,.mobile-movement-report .movement-filter .reset{flex:1 1 140px}}
</style>
<main class="mobile-accounts-page mobile-movement-report">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.movements.index') }}">← Movements</a></div>
    <h1 class="mobile-title">{{ $label }}</h1><p class="mobile-sub">Operational movement report. Financial data is excluded.</p>
    <form class="movement-filter" method="get">
        <label>From Date<input type="date" name="from" value="{{ request('from') }}"></label>
        <label>To Date<input type="date" name="to" value="{{ request('to') }}"></label>
        @if(in_array($movement,['arrival','departure'],true))
            <label>Airport<input type="text" name="{{ $movement==='arrival'?'destination':'origin' }}" value="{{ request($movement==='arrival'?'destination':'origin') }}" placeholder="Airport"></label>
        @else
            <label>Hotel Name<input type="text" name="hotel" value="{{ request('hotel') }}" placeholder="Hotel name"></label>
        @endif
        <div class="actions"><button type="submit">Apply</button><a class="reset" href="{{ route('mobile.movements.'.$movement) }}">Reset</a></div>
    </form>
    <div class="movement-list">
        @forelse($rows as $row)
            @php($dateKey=in_array($movement,['arrival','departure'],true)?($movement==='arrival'?'arrival_date':'departure_date'):(str_contains($movement,'checkin')?'check_in':'check_out'))
            @php($hotel=$row['makkah_hotel']??$row['madinah_hotel']??'')
            @if(($row['action'] ?? '') && $row['action'] !== '—')
                <a class="mobile-card movement-card" href="{{ $row['action'] }}">
            @else
                <div class="mobile-card movement-card">
            @endif
                <span class="badge">{{ $label }}</span>
                <div class="date">{{ $row[$dateKey] ?? '—' }}
                    @if($movement === 'arrival') · {{ $row['arrival_time'] ?? '' }}
                    @elseif($movement === 'departure') · {{ $row['departure_time'] ?? '' }}
                    @endif
                </div>
                <div class="booking">{{ $row['booking_no'] ?? '—' }}</div>
                <div class="line">{{ $row['customer'] ?? '—' }} · {{ $row['total_pax'] ?? '—' }} pax</div>
                @if($hotel && $hotel !== '—')
                    <div class="line">Hotel: {{ $hotel }}</div>
                @endif
                @if(($row['flight'] ?? '') && $row['flight'] !== '—')
                    <div class="line">Flight: {{ $row['flight'] }}
                        @if(($row['sector'] ?? '') && $row['sector'] !== '—') · {{ $row['sector'] }} @endif
                    </div>
                @endif
                @if(($row['nights'] ?? '') && $row['nights'] !== '—')
                    <div class="line">Nights: {{ $row['nights'] }}</div>
                @endif
            @if(($row['action'] ?? '') && $row['action'] !== '—')
                </a>
            @else
                </div>
            @endif
        @empty
            <div class="empty">No {{ strtolower($label) }} match the selected filters.</div>
        @endforelse
    </div>
    @if(is_object($rows) && method_exists($rows,'links'))
        <div style="margin-top:14px">{{ $rows->links() }}</div>
    @endif
</main>
@endsection
