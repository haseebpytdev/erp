<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $voucherNumber }} — Client Voucher</title>
<style>
:root{--ink:#12213a;--muted:#62738f;--line:#dce5ef;--soft:#f6f9fc;--blue:#0b63ce;--green:#11834f}
*{box-sizing:border-box}body{margin:0;background:#eef3f8;color:var(--ink);font-family:Inter,Arial,sans-serif;font-size:12px;line-height:1.4}.page{width:min(210mm,100%);min-height:297mm;margin:16px auto;background:#fff;padding:12mm 12mm 14mm;box-shadow:0 8px 30px rgba(25,48,79,.12)}.toolbar{width:min(210mm,100%);margin:14px auto 0;display:flex;gap:8px;justify-content:flex-end}.btn{border:1px solid #cfdbe8;background:#fff;border-radius:7px;padding:8px 12px;color:var(--ink);font-weight:700;text-decoration:none;cursor:pointer}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.header{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:start;padding-bottom:10px;border-bottom:2px solid var(--ink)}.brand{display:flex;gap:10px;align-items:center}.logo{width:58px;height:58px;object-fit:contain}.company-name{font-size:21px;font-weight:900}.company-sub{font-size:11px;color:var(--muted);margin-top:1px}.company-contact{font-size:10.5px;color:var(--muted);margin-top:5px}.doc-title{text-align:right}.doc-title h1{font-size:20px;margin:0 0 4px}.doc-no{font-size:12px;font-weight:800;color:var(--blue)}.meta{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:12px 0}.meta-card{border:1px solid var(--line);background:var(--soft);border-radius:7px;padding:7px 8px}.meta-label{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:800}.meta-value{font-size:11.5px;font-weight:800;margin-top:2px;word-break:break-word}.section{margin-top:12px}.section-head{display:flex;justify-content:space-between;align-items:end;border-bottom:1px solid var(--line);padding-bottom:5px;margin-bottom:6px}.section-title{font-size:14px;font-weight:900}.section-note{font-size:9.5px;color:var(--muted)}table{width:100%;border-collapse:collapse}th{background:#eef3f8;color:#53647f;text-transform:uppercase;font-size:8.7px;letter-spacing:.03em;text-align:left;padding:6px;border-bottom:1px solid #d6e0eb}td{padding:7px 6px;border-bottom:1px solid #e6edf4;vertical-align:top}.nowrap{white-space:nowrap}.badge{display:inline-block;border:1px solid #bcdccc;background:#effaf4;color:var(--green);border-radius:999px;padding:2px 7px;font-size:9px;font-weight:800}.route{font-weight:900}.small{font-size:9.5px;color:var(--muted)}.empty{padding:10px;border:1px dashed var(--line);border-radius:7px;color:var(--muted);background:#fafcff}.footer{margin-top:18px;padding-top:9px;border-top:1px solid var(--line);font-size:9.5px;color:var(--muted);display:flex;justify-content:space-between;gap:16px}.footer-note{max-width:72%}.confidential{font-weight:800;color:#50617c}.ticket-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.pnrbox{border:1px solid var(--line);border-radius:7px;background:var(--soft);padding:7px 8px;display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.pnrbox .meta-label{font-size:8.5px}.pnrbox .meta-value{font-size:10.5px}@media(max-width:760px){body{background:#fff}.toolbar{padding:0 10px}.page{margin:0;box-shadow:none;padding:14px}.meta{grid-template-columns:1fr 1fr}.ticket-grid{grid-template-columns:1fr}.header{grid-template-columns:1fr}.doc-title{text-align:left}.footer{display:block}.footer-note{max-width:100%}.scroll{overflow:auto}.scroll table{min-width:720px}}@media print{@page{size:A4 portrait;margin:0}body{background:#fff}.toolbar{display:none}.page{width:210mm;min-height:297mm;margin:0;box-shadow:none;padding:11mm 12mm 13mm}.section{break-inside:avoid}.footer{break-inside:avoid}}
</style>
</head>
<body>
<div class="toolbar"><a class="btn" href="{{ url('/operations/bookings/'.$bookingId) }}">Back to Booking</a><button class="btn primary" type="button" onclick="window.print()">Print Voucher</button></div>
<main class="page">
<header class="header">
    <div class="brand">
        @if(!empty($company['logo']))<img class="logo" src="{{ $company['logo'] }}" alt="Logo">@endif
        <div>
            <div class="company-name">{{ $company['name'] ?? 'Easy Ticket' }}</div>
            @if(!empty($company['subtitle']))<div class="company-sub">{{ $company['subtitle'] }}</div>@endif
            <div class="company-contact">
                {{ $company['address'] ?? '' }}
                @if(!empty($company['phone'])) · {{ $company['phone'] }}@endif
                @if(!empty($company['email'])) · {{ $company['email'] }}@endif
                @if(!empty($company['website'])) · {{ $company['website'] }}@endif
            </div>
        </div>
    </div>
    <div class="doc-title">
        <h1>Travel Voucher</h1>
        <div class="doc-no">{{ $voucherNumber }}</div>
        <div class="small">Client / Operational Copy</div>
    </div>
</header>

<section class="meta">
    <div class="meta-card"><div class="meta-label">Booking Reference</div><div class="meta-value">{{ $bookingReference }}</div></div>
    <div class="meta-card"><div class="meta-label">Customer</div><div class="meta-value">{{ $customerName }}</div></div>
    <div class="meta-card"><div class="meta-label">Branch</div><div class="meta-value">{{ $branchName }}</div></div>
    <div class="meta-card"><div class="meta-label">Generated</div><div class="meta-value">{{ $generatedAt->format('d M Y, h:i A') }}</div></div>
</section>

@if(count($passengers))
<section class="section">
    <div class="section-head"><div class="section-title">Passengers</div><div class="section-note">Travellers linked to this booking</div></div>
    <div class="scroll"><table><thead><tr><th>#</th><th>Name</th><th>Fare Type</th><th>Passport No.</th></tr></thead><tbody>
    @foreach($passengers as $i => $p)
        <tr><td>{{ $i+1 }}</td><td><strong>{{ $p['name'] ?? $p['passenger_name'] ?? 'Passenger' }}</strong></td><td>{{ strtoupper($p['fare_type'] ?? 'ADULT') }}</td><td>{{ $p['passport_number'] ?? $p['passport_no'] ?? '—' }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endif

@if(count($airSegments) || count($airTickets))
<section class="section">
    <div class="section-head"><div class="section-title">Flight / Ticket Details</div><div class="section-note">PNR, itinerary and issued ticket information</div></div>
    <div class="pnrbox">
        <div><div class="meta-label">PNR</div><div class="meta-value">{{ $airCommon['pnr'] ?? '—' }}</div></div>
        <div><div class="meta-label">Airline PNR</div><div class="meta-value">{{ $airCommon['airline_pnr'] ?? '—' }}</div></div>
        <div><div class="meta-label">Ticket Status</div><div class="meta-value">{{ strtoupper($airCommon['ticket_status'] ?? 'BOOKED') }}</div></div>
    </div>
    @if(count($airSegments))
    <div class="scroll" style="margin-top:7px"><table><thead><tr><th>Type</th><th>Airline / Flight</th><th>Route</th><th>Departure</th><th>Arrival</th></tr></thead><tbody>
    @foreach($airSegments as $s)
        <tr>
            <td>{{ ucfirst($s['segment_type'] ?? 'Outbound') }}</td>
            <td><strong>{{ $s['airline'] ?: ($s['airline_code'] ?? '') }}</strong> {{ $s['flight_number'] ?? '' }}</td>
            <td class="route">{{ $s['from'] ?? '—' }} → {{ $s['to'] ?? '—' }}</td>
            <td class="nowrap">{{ !empty($s['departure_at']) ? \Carbon\Carbon::parse($s['departure_at'])->format('d M Y, H:i') : '—' }}</td>
            <td class="nowrap">{{ !empty($s['arrival_at']) ? \Carbon\Carbon::parse($s['arrival_at'])->format('d M Y, H:i') : '—' }}</td>
        </tr>
    @endforeach
    </tbody></table></div>
    @endif
    @if(count($airTickets))
    <div class="scroll" style="margin-top:7px"><table><thead><tr><th>Passenger</th><th>Fare Type</th><th>Ticket No.</th><th>Class</th><th>Baggage</th><th>Status</th></tr></thead><tbody>
    @foreach($airTickets as $t)
        <tr><td><strong>{{ $t['passenger_name'] ?? 'Passenger' }}</strong></td><td>{{ $t['fare_type'] ?? '—' }}</td><td>{{ $t['ticket_number'] ?: '—' }}</td><td>{{ $t['booking_class'] ?: '—' }}</td><td>{{ $t['baggage'] ?: '—' }}</td><td><span class="badge">{{ ucfirst(strtolower($t['status'] ?? 'Booked')) }}</span></td></tr>
    @endforeach
    </tbody></table></div>
    @endif
</section>
@endif

@if(count($hotelStays))
<section class="section">
    <div class="section-head"><div class="section-title">Hotel Accommodation</div><div class="section-note">Saved Hotel Data for this booking</div></div>
    <div class="scroll"><table><thead><tr><th>City</th><th>Hotel</th><th>Confirmation</th><th>Room</th><th>Board</th><th>Check In</th><th>Check Out</th><th>Nights</th></tr></thead><tbody>
    @foreach($hotelStays as $h)
        <tr>
            <td>{{ $h['city'] ?? '—' }}</td>
            <td><strong>{{ $h['hotel_name'] ?? '—' }}</strong></td>
            <td>{{ $h['confirmation_no'] ?: '—' }}</td>
            <td>{{ $h['room_type'] ?? '—' }}</td>
            <td>{{ $h['board'] ?? '—' }}</td>
            <td class="nowrap">{{ !empty($h['check_in']) ? \Carbon\Carbon::parse($h['check_in'])->format('d M Y') : '—' }}</td>
            <td class="nowrap">{{ !empty($h['check_out']) ? \Carbon\Carbon::parse($h['check_out'])->format('d M Y') : '—' }}</td>
            <td>{{ $h['nights'] ?? '—' }}</td>
        </tr>
    @endforeach
    </tbody></table></div>
</section>
@endif

@if(!count($airSegments) && !count($airTickets) && !count($hotelStays))
<div class="empty">No saved operational Air or Hotel product data is available for this booking yet.</div>
@endif

<footer class="footer">
    <div class="footer-note">{!! nl2br(e($company['footer'] ?? '')) !!}</div>
    <div class="confidential">Voucher {{ $voucherNumber }} · {{ $bookingReference }}</div>
</footer>
</main>
</body>
</html>
