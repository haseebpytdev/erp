@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', $label)
@section($layoutMeta['content_section'] ?? 'content')
<style>
.mobile-movement-report,.mobile-movement-report *{box-sizing:border-box}.mobile-movement-report{width:100%;max-width:820px;margin:0 auto;padding:16px;color:#17243a}.mobile-movement-report .brand{display:flex;justify-content:space-between;gap:12px;margin-bottom:14px;font-weight:800}.mobile-movement-report a{color:#1769d2}.mobile-movement-report h1{margin:0;font-size:clamp(1.35rem,5vw,2rem)}.mobile-movement-report .sub{margin:6px 0 16px;color:#66758a}.movement-filter{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;padding:12px;border:1px solid #dce7f4;border-radius:9px;background:#fff;margin-bottom:14px}.movement-filter label{display:grid;gap:4px;font-size:11px;font-weight:800;color:#40506a}.movement-filter input{width:100%;min-height:40px;border:1px solid #cfdae8;border-radius:7px;padding:8px;font-size:14px}.movement-filter .actions{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.movement-filter button,.movement-filter .reset{min-height:40px;padding:9px 14px;border:0;border-radius:7px;background:#1769d2;color:#fff;font-weight:800;text-decoration:none}.movement-filter .reset{background:#eef3f8;color:#24344b}.movement-list{display:grid;gap:10px}.movement-card{display:block;padding:13px;border:1px solid #dce7f4;border-radius:10px;background:#fff;box-shadow:0 4px 14px rgba(28,55,90,.05);text-decoration:none;color:#17243a}.movement-card .badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef5ff;color:#1769d2;font-size:10px;font-weight:800}.movement-card .date{margin-top:6px;color:#66758a;font-size:12px}.movement-card .booking{margin-top:4px;font-weight:850}.movement-card .line{margin-top:5px;font-size:12px;line-height:1.45}.empty{padding:16px;border:1px dashed #cbd5e1;border-radius:9px;color:#66758a;background:#f8fafc}@media(max-width:520px){.movement-filter{grid-template-columns:1fr}.movement-filter .actions{grid-column:auto}}
</style>
<main class="mobile-movement-report">
    <div class="brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.movements.index') }}">← Movements</a></div>
    <h1>{{ $label }}</h1><p class="sub">Operational movement report. Financial data is excluded.</p>
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
                <a class="movement-card" href="{{ $row['action'] }}">
            @else
                <div class="movement-card">
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
