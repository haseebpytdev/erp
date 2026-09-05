<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $voucherNumber }} · {{ $bookingReference }}</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f6;color:#111827;font-family:Arial,Helvetica,sans-serif}
.toolbar{position:sticky;top:0;z-index:10;background:#111827;padding:8px;text-align:center}
.toolbar button,.toolbar a{display:inline-block;border:0;border-radius:5px;padding:8px 12px;margin:0 3px;background:#fff;color:#17233b;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer}
.sheet{width:210mm;min-height:297mm;margin:12px auto;background:#fff;border:1px solid #b9c2ce;padding:12mm 11mm;box-shadow:0 3px 18px rgba(20,30,45,.08)}
.header{display:grid;grid-template-columns:1fr auto;gap:16px;align-items:start;border-bottom:2px solid #172c55;padding-bottom:8px}
.brand{display:flex;gap:10px;align-items:center}
.logo{width:45px;height:45px;border:1px solid #d7dde5;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;color:#c92d2d;font-size:15px}
.company{font-size:23px;font-weight:800;color:#075aa8;line-height:1.05}
.sub{font-size:9px;color:#475569;margin-top:3px}
.operator{text-align:right;font-weight:800;color:#0b2370;font-size:15px}
.meta{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:0;border:1px solid #111;margin-top:10px;font-size:9px}
.meta div{padding:5px 7px;border-right:1px solid #111}.meta div:last-child{border-right:0}
.title{text-align:center;font-size:17px;font-weight:800;margin:15px 0 2px}
.status{text-align:center;font-size:9px;font-weight:800;color:#9b1c1c;text-transform:uppercase;margin-bottom:9px}
.section-title{background:#e5e7eb;border:1px solid #111;border-bottom:0;text-align:center;font-size:10px;font-weight:800;padding:4px;margin-top:8px}
table{width:100%;border-collapse:collapse;font-size:8.5px}
th,td{border:1px solid #111;padding:4px 5px;vertical-align:top}
th{background:#f3f4f6;font-size:8px;text-transform:uppercase}
.right{text-align:right}.center{text-align:center}
.instructions{border-top:1px solid #cbd5e1;margin-top:10px;padding-top:8px;font-size:9px;min-height:40px}
.instructions strong{font-size:11px;color:#102a6b}
.footer{margin-top:12px;border-top:1px solid #cbd5e1;padding-top:6px;font-size:8px;color:#475569;text-align:center}
@page{size:A4 portrait;margin:0}
@media print{
 body{background:#fff}
 .toolbar{display:none}
 .sheet{margin:0;border:0;box-shadow:none;width:210mm;min-height:297mm}
}
</style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Print / Save PDF</button>
    <a href="{{ route('operations.bookings.group-package-unified.edit', $bookingId) }}">Back to Group Umrah</a>
</div>
<div class="sheet">
    <div class="header">
        <div class="brand">
            <div class="logo">ET</div>
            <div>
                <div class="company">Easy Group Of Travels</div>
                <div class="sub">Easy Ticket · {{ $branchName }}</div>
            </div>
        </div>
        <div class="operator">
            PAKISTANI IATA / UMRAH OPERATOR
            <div class="sub">CUSTOMER TRAVEL DOCUMENT</div>
        </div>
    </div>

    <div class="meta">
        <div><strong>Booking:</strong> {{ $bookingReference }}</div>
        <div><strong>Voucher:</strong> {{ $voucherNumber }}</div>
        <div><strong>Customer:</strong> {{ $customerName }}</div>
        <div><strong>PAX:</strong> {{ count($passengers) }}</div>
    </div>

    <div class="title">Travel Voucher</div>
    <div class="status">{{ str_replace('_',' ', strtoupper((string)($package['voucher_status'] ?? 'draft'))) }}</div>

    <div class="section-title">Passengers</div>
    <table>
        <thead><tr><th>#</th><th>Passenger</th><th>Pax</th><th>Passport</th><th>Nationality</th><th>Ticket No.</th></tr></thead>
        <tbody>
        @forelse($passengers as $i => $p)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td>{{ trim(($p['title'] ?? '').' '.($p['first_name'] ?? '').' '.($p['last_name'] ?? '')) }}</td>
                <td>{{ strtoupper((string)($p['fare_as'] ?? '')) }}</td>
                <td>{{ $p['passport_no'] ?? '—' }}</td>
                <td>{{ $p['nationality'] ?? '—' }}</td>
                <td>{{ $p['ticket_number'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="center">No passenger data</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Flight Itinerary</div>
    <table>
        <thead><tr><th>Type</th><th>Airline</th><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th><th>PNR</th></tr></thead>
        <tbody>
        @forelse($flights as $f)
            <tr>
                <td>{{ ucfirst((string)($f['segment_type'] ?? '')) }}</td>
                <td>{{ $f['airline_name'] ?? '—' }}</td>
                <td>{{ $f['flight_number'] ?? '—' }}</td>
                <td>{{ strtoupper((string)($f['from_code'] ?? '')) }} → {{ strtoupper((string)($f['to_code'] ?? '')) }}</td>
                <td>{{ $f['departure_date'] ?? '—' }} {{ isset($f['departure_time']) ? substr((string)$f['departure_time'],0,5) : '' }}</td>
                <td>{{ $f['arrival_date'] ?? '—' }} {{ isset($f['arrival_time']) ? substr((string)$f['arrival_time'],0,5) : '' }}</td>
                <td>{{ $f['pnr'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="center">No flight data</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Accommodation</div>
    <table>
        <thead><tr><th>City</th><th>Hotel</th><th>Room</th><th>Meal</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Confirmation</th></tr></thead>
        <tbody>
        @forelse($hotels as $h)
            <tr>
                <td>{{ $h['city'] ?? '—' }}</td>
                <td>{{ $h['hotel_name'] ?? '—' }}</td>
                <td>{{ $h['room_type'] ?? '—' }}</td>
                <td>{{ $h['meal_plan'] ?? '—' }}</td>
                <td>{{ $h['check_in'] ?? '—' }}</td>
                <td>{{ $h['check_out'] ?? '—' }}</td>
                <td class="center">{{ $h['nights'] ?? 0 }}</td>
                <td>{{ $h['confirmation_no'] ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="center">No accommodation data</td></tr>
        @endforelse
        <tfoot>
            <tr><td colspan="6" class="right"><strong>Total Nights</strong></td><td class="center"><strong>{{ (int)($package['total_hotel_nights'] ?? 0) }}</strong></td><td></td></tr>
        </tfoot>
    </table>

    @if(count($transports))
    <div class="section-title">Transport</div>
    <table>
        <thead><tr><th>Route</th><th>Vehicle</th><th>Company</th><th>Contact</th><th>BRN / Ref</th><th>Notes</th></tr></thead>
        <tbody>
        @foreach($transports as $t)
            <tr>
                <td>{{ $t['route_name'] ?? trim(($t['from_location'] ?? '').' → '.($t['to_location'] ?? '')) }}</td>
                <td>{{ $t['vehicle_type'] ?? '—' }}</td>
                <td>{{ $t['company_name'] ?? '—' }}</td>
                <td>{{ $t['contact_number'] ?? '—' }}</td>
                <td>{{ $t['brn_number'] ?? $t['provider_reference'] ?? '—' }}</td>
                <td>{{ $t['notes'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    @if(count($services))
    <div class="section-title">Other Services / Products</div>
    <table>
        <thead><tr><th>Service</th><th>Details</th><th>Qty</th><th>Notes</th></tr></thead>
        <tbody>
        @foreach($services as $s)
            <tr>
                <td>{{ $s['service_name'] ?? '—' }}</td>
                <td>{{ $s['details'] ?? '—' }}</td>
                <td>{{ $s['quantity'] ?? 1 }}</td>
                <td>{{ $s['notes'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    <div class="instructions">
        <strong>Special Instructions:</strong><br>
        {!! nl2br(e((string)($package['notes'] ?? ''))) !!}
    </div>

    <div class="footer">
        {{ $voucherNumber }} · {{ $bookingReference }} · Customer-facing operational document. Commercial supplier cost and internal margin are not displayed.
    </div>
</div>
</body>
</html>
