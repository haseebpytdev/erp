<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $booking->booking_no }} · Hotel Voucher</title>
<style>
:root{--navy:#123761;--blue:#1769d2;--red:#cf3341;--green:#148151;--ink:#17243a;--muted:#66758a;--line:#dce4ed;--soft:#f6f8fb}
*{box-sizing:border-box}
body{margin:0;background:#edf2f7;color:var(--ink);font:13px/1.45 Arial,Helvetica,sans-serif}
.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:center;gap:8px;padding:10px;background:#111827}
.toolbar a,.toolbar button{border:0;border-radius:8px;padding:9px 13px;font-weight:800;text-decoration:none;cursor:pointer}
.toolbar a{background:#fff;color:#223149}.toolbar button{background:#1769d2;color:#fff}
.sheet{width:min(980px,calc(100% - 28px));margin:22px auto 45px;background:#fff;border-radius:14px;box-shadow:0 14px 36px rgba(22,43,73,.12);overflow:hidden}
.doc-head{display:grid;grid-template-columns:auto 1fr 265px;gap:15px;align-items:center;padding:23px 28px 18px;border-bottom:4px solid var(--red)}
.logo{width:64px;height:64px;display:grid;place-items:center}.logo img{max-width:64px;max-height:64px;object-fit:contain}
.logo-fallback{width:55px;height:55px;border-radius:13px;background:#fff;border:1px solid var(--line);display:grid;place-items:center;color:var(--red);font-weight:900;font-size:20px}
.brand h1{margin:0;color:var(--navy);font-size:23px}.brand p{margin:3px 0 0;color:var(--muted);font-size:11px}
.doc-meta{text-align:right}.doc-meta h2{margin:0 0 8px;color:var(--navy);font-size:20px}
.doc-meta-grid{display:grid;grid-template-columns:105px 1fr;gap:4px 8px;font-size:11px}.doc-meta-grid strong{color:#4b5d75}.doc-meta-grid span{font-weight:800}
.status{display:inline-block;padding:5px 9px;border-radius:999px;font-weight:900;font-size:10px}
.status.final{background:#def5e8;color:var(--green)}.status.draft{background:#fff2c9;color:#765600}
.watermark{padding:9px 28px;background:#fff7da;color:#725b08;border-bottom:1px solid #eedc99;font-weight:800}
.watermark.final{background:#e9f8ef;color:#146a45;border-bottom-color:#c7e9d5}
.body{padding:24px 28px 30px}
.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}
.info{border:1px solid var(--line);border-radius:9px;padding:10px 11px}.info small{display:block;text-transform:uppercase;color:var(--muted);font-size:8px;font-weight:900}.info strong{display:block;margin-top:4px;font-size:12px}
.section{margin-top:20px}.section-title{display:flex;justify-content:space-between;gap:10px;border-bottom:2px solid #e9eef4;padding-bottom:6px;margin-bottom:10px}.section-title h3{margin:0;color:var(--navy);font-size:15px}.section-title span{font-size:9px;color:var(--muted)}
.readiness{display:grid;grid-template-columns:repeat(5,1fr);gap:7px}.ready-card{border:1px solid var(--line);border-radius:8px;padding:8px 9px}.ready-card.ready{background:#f0faf5;border-color:#cfeadb}.ready-card.notready{background:#fff9e9;border-color:#efdfb4}.ready-card small{display:block;font-size:8px;color:var(--muted);font-weight:900;text-transform:uppercase}.ready-card strong{display:block;margin-top:3px;font-size:9px}
.passenger-table{width:100%;border-collapse:collapse;border:1px solid var(--line)}.passenger-table th{background:var(--soft);font-size:9px;text-transform:uppercase;color:#536277;text-align:left;padding:8px}.passenger-table td{padding:8px;border-top:1px solid var(--line);font-size:10px}
.lead{display:inline-block;background:#eaf3ff;color:#1769d2;border-radius:999px;padding:2px 5px;font-size:7px;font-weight:900}
.stay{border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:11px}.stay-head{display:grid;grid-template-columns:42px 1fr auto;gap:10px;align-items:center;background:var(--soft);padding:10px 12px}.stay-no{width:31px;height:31px;border-radius:999px;background:#1769d2;color:#fff;display:grid;place-items:center;font-weight:900}.stay-head strong{font-size:13px;color:var(--navy)}.stay-head p{margin:2px 0 0;color:var(--muted);font-size:9px}.stay-body{padding:11px 12px}
.stay-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.kv{border:1px solid #e5ebf1;border-radius:7px;padding:7px 8px;min-height:49px}.kv small{display:block;font-size:7.5px;color:var(--muted);text-transform:uppercase;font-weight:900}.kv strong{display:block;margin-top:3px;font-size:10px}
.note{margin-top:8px;border-left:3px solid #b9c8da;background:#fafbfd;padding:8px 9px;border-radius:4px;color:#536277;font-size:9px}
.notice{margin-top:18px;padding:10px 12px;border:1px solid var(--line);background:#f8fafc;border-radius:8px;color:#536277;font-size:9px}
.footer{padding:15px 28px 20px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:18px;color:var(--muted);font-size:9px}
.no-price{font-weight:900;color:var(--navy)}
@page{size:A4 portrait;margin:0}
@media print{body{background:#fff}.toolbar{display:none}.sheet{width:210mm;min-height:297mm;margin:0;border-radius:0;box-shadow:none}.doc-head{padding:12mm 12mm 7mm}.body{padding:7mm 12mm 8mm}.footer{padding:6mm 12mm}}
@media(max-width:760px){.doc-head{grid-template-columns:auto 1fr}.doc-meta{grid-column:1/-1;text-align:left}.summary,.readiness,.stay-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>

<div class="toolbar">
    <a href="{{ route('operations.bookings.show',$booking) }}">Back to Booking</a>
    <button onclick="window.print()">Print / Save PDF</button>
</div>

<div class="sheet">
    <header class="doc-head">
        <div class="logo">
            @if($booking->company?->logo_data_uri)
                <img src="{{ $booking->company->logo_data_uri }}" alt="{{ $booking->company->name }} logo">
            @else
                <div class="logo-fallback">ET</div>
            @endif
        </div>
        <div class="brand">
            <h1>{{ $booking->company?->name ?: 'Easy Ticket' }}</h1>
            <p>{{ $booking->company?->legal_name ?: 'Easy Group Of Travels' }}</p>
            <p>
                {{ $booking->branch?->name ?: 'Head Office' }}
                @if($booking->branch?->phone) · {{ $booking->branch->phone }}@elseif($booking->company?->phone) · {{ $booking->company->phone }}@endif
                @if($booking->branch?->email) · {{ $booking->branch->email }}@elseif($booking->company?->email) · {{ $booking->company->email }}@endif
            </p>
        </div>
        <div class="doc-meta">
            <h2>HOTEL VOUCHER</h2>
            <div style="margin-bottom:7px"><span class="status {{ $isFinal?'final':'draft' }}">{{ $voucherStatus }}</span></div>
            <div class="doc-meta-grid">
                <strong>Booking No.</strong><span>{{ $booking->booking_no }}</span>
                <strong>Document</strong><span>{{ $singleStay?'Individual Stay':'Combined Hotel Voucher' }}</span>
                <strong>Booking Status</strong><span>{{ str_replace('_',' ',$booking->status) }}</span>
                <strong>Printed</strong><span>{{ now()->format('d M Y H:i') }}</span>
            </div>
        </div>
    </header>

    <div class="watermark {{ $isFinal?'final':'' }}">
        @if($isFinal)
            Final client travel document. Hotel stay(s), confirmation number(s) and booking status are ready for travel.
        @else
            DRAFT — For review only. Do not treat this as the final travel voucher until all readiness checks are complete.
        @endif
    </div>

    <main class="body">
        <div class="summary">
            <div class="info"><small>Customer</small><strong>{{ $booking->customer?->display_name ?: '—' }}</strong></div>
            <div class="info"><small>Travellers</small><strong>{{ $activePassengers->count() }}</strong></div>
            <div class="info"><small>Hotel Stays</small><strong>{{ $stays->count() }}</strong></div>
            <div class="info"><small>Travel Window</small><strong>{{ $firstCheckIn?->format('d M Y') ?: '—' }}@if($lastCheckOut) → {{ $lastCheckOut->format('d M Y') }}@endif</strong></div>
        </div>

        <section class="section">
            <div class="section-title"><h3>Travel Readiness</h3><span>Operational checks only — internal commercial values are excluded.</span></div>
            <div class="readiness">
                @foreach($readiness as $key=>$check)
                    <div class="ready-card {{ $check['ready']?'ready':'notready' }}">
                        <small>{{ str_replace('_',' ',$key) }}</small>
                        <strong>{{ $check['ready']?'✓ ': '• ' }}{{ $check['label'] }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="section">
            <div class="section-title"><h3>Travellers</h3><span>Passengers from the Booking Passenger Master.</span></div>
            <table class="passenger-table">
                <thead><tr><th>#</th><th>Passenger</th><th>Type</th><th>Passport No.</th><th>Passport Expiry</th><th>Nationality</th></tr></thead>
                <tbody>
                    @foreach($activePassengers as $passenger)
                        <tr>
                            <td>{{ $passenger->passenger_no }}</td>
                            <td><strong>{{ $passenger->fullName() }}</strong> @if($passenger->is_lead)<span class="lead">LEAD</span>@endif</td>
                            <td>{{ $passenger->pax_type }}</td>
                            <td>{{ $passenger->passport_no ?: '—' }}</td>
                            <td>{{ $passenger->passport_expiry?->format('d M Y') ?: '—' }}</td>
                            <td>{{ $passenger->nationality_country_code ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="section">
            <div class="section-title"><h3>Hotel Accommodation</h3><span>{{ $singleStay?'Individual Hotel Stay':'All active Hotel Stays in travel sequence' }}</span></div>

            @foreach($stays as $stay)
                @php
                    $detail=$stay['detail'];
                    $hotel=$stay['hotel'];
                    $room=$stay['room'];
                @endphp
                <article class="stay">
                    <div class="stay-head">
                        <div class="stay-no">{{ str_pad((string)$stay['no'],2,'0',STR_PAD_LEFT) }}</div>
                        <div>
                            <strong>{{ $hotel?->name ?: 'Hotel Stay details pending' }}</strong>
                            <p>{{ $hotel?->city ?: 'City pending' }}@if($stay['city_code']) · {{ $stay['city_code'] }}@endif @if($stay['country_code']) · {{ $stay['country_code'] }}@endif</p>
                        </div>
                        <span class="status {{ $stay['confirmed']?'final':'draft' }}">{{ $detail?->hotel_status ?: 'NOT ENTERED' }}</span>
                    </div>

                    <div class="stay-body">
                        <div class="stay-grid">
                            <div class="kv"><small>Confirmation / Voucher No.</small><strong>{{ $detail?->confirmation_number ?: 'Pending' }}</strong></div>
                            <div class="kv"><small>Check-in</small><strong>{{ $detail?->check_in?->format('d M Y · l') ?: 'Pending' }}</strong></div>
                            <div class="kv"><small>Check-out</small><strong>{{ $detail?->check_out?->format('d M Y · l') ?: 'Pending' }}</strong></div>
                            <div class="kv"><small>Nights</small><strong>{{ $detail?->nights ?: '—' }}</strong></div>
                            <div class="kv"><small>Room Type</small><strong>{{ $room?->room_type ?: $detail?->room_type ?: 'Pending' }}</strong></div>
                            <div class="kv"><small>No. of Rooms</small><strong>{{ $room?->room_count ?: $detail?->rooms ?: '—' }}</strong></div>
                            <div class="kv"><small>Occupancy / Capacity</small><strong>{{ $room?->total_capacity ? $room->total_capacity.' Pax' : ($detail?->occupancy ?: '—') }}</strong></div>
                            <div class="kv"><small>Meal Plan</small><strong>{{ $room?->meal_plan ?: $detail?->meal_plan ?: '—' }}</strong></div>
                        </div>

                        @if($hotel?->address || $hotel?->phone)
                            <div class="note"><strong>Hotel Contact:</strong> {{ $hotel?->address }} @if($hotel?->phone) · {{ $hotel->phone }}@endif</div>
                        @endif
                        @if($room?->notes || $detail?->special_requests)
                            <div class="note"><strong>Special Request / Room Note:</strong> {{ $room?->notes }} @if($room?->notes && $detail?->special_requests) · @endif {{ $detail?->special_requests }}</div>
                        @endif
                    </div>
                </article>
            @endforeach
        </section>

        <div class="notice">
            <strong>Travel Notice:</strong>
            Please carry valid travel documents and present this Hotel Voucher at check-in.
            Hotel check-in / check-out policies, security deposits and local requirements remain subject to the accommodation provider.
            <span class="no-price">Customer price, supplier price, vendor cost, commission and margin are intentionally excluded from this travel document.</span>
        </div>
    </main>

    <footer class="footer">
        <div><strong>{{ $booking->company?->name ?: 'Easy Ticket' }}</strong><br>{{ $booking->branch?->address ?: $booking->company?->address }}</div>
        <div style="text-align:right">@if($booking->company?->website){{ $booking->company->website }}<br>@endif @if($booking->branch?->phone){{ $booking->branch->phone }}@elseif($booking->company?->phone){{ $booking->company->phone }}@endif</div>
    </footer>
</div>
</body>
</html>
