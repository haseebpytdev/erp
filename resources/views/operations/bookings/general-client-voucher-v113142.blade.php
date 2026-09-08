<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $voucherNumber }} · {{ $bookingReference }}</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#edf1f5;color:#111;font-family:Arial,Helvetica,sans-serif}
.toolbar{position:sticky;top:0;z-index:20;background:#18243a;padding:8px;text-align:center}
.toolbar button,.toolbar a{display:inline-block;border:0;border-radius:5px;padding:8px 12px;margin:0 3px;background:#fff;color:#17233b;font-size:12px;font-weight:700;text-decoration:none;cursor:pointer}
.sheet{width:210mm;min-height:297mm;margin:12px auto;background:#fff;border:1px solid #bbc4cf;padding:8mm 9mm 11mm;box-shadow:0 3px 18px rgba(20,30,45,.08)}
.header{display:grid;grid-template-columns:84px 1fr auto;gap:9px;align-items:start;min-height:80px}
.logo-box{width:80px;height:80px;display:flex;align-items:center;justify-content:center}
.logo{display:block;max-width:80px;max-height:80px;width:auto;height:auto;object-fit:contain}
.logo-fallback{width:80px;height:80px;border:1px solid #d7dde5;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;color:#be2a2a;font-size:18px}
.company{font-size:21px;font-weight:800;color:#075aa8;line-height:1.05;margin-top:2px}
.company-sub{font-size:9px;color:#374151;margin-top:3px;font-style:italic}
.header-meta{font-size:9px;margin-top:4px;line-height:1.45}.header-meta strong{display:inline-block;min-width:74px}
.header-right{text-align:right;font-size:8.5px;color:#374151;min-width:170px;line-height:1.4}.header-right strong{color:#17233b}.only-accommodation{display:inline-block;border:1px solid #17396c;color:#17396c;padding:4px 7px;font-size:9px;font-weight:800;text-transform:uppercase;margin-bottom:5px}
.title{text-align:center;font-size:16px;font-weight:800;margin:4px 0 7px}
.summary{display:grid;grid-template-columns:1.2fr 1.25fr .8fr;border:1px solid #111;font-size:8.5px;margin-bottom:7px}.summary>div{padding:4px 6px;border-right:1px solid #111}.summary>div:last-child{border-right:0}
.section-title{background:#dedede;border:1px solid #111;border-bottom:0;text-align:center;font-size:9px;font-weight:800;padding:3px 5px;color:#111}
.section-title.blue{color:#25457b;font-size:10px}
table{width:100%;border-collapse:collapse;font-size:7.7px;margin:0 0 6px}
th,td{border:1px solid #111;padding:3px 4px;vertical-align:middle}th{background:#eee;font-size:7px;text-align:center;font-weight:800}td.center{text-align:center}.right{text-align:right}.nowrap{white-space:nowrap}.strong{font-weight:800}
.total-row td{font-style:italic;font-weight:700}.total-box{display:inline-block;min-width:55px;text-align:center;border:2px solid #111;padding:2px 7px;font-style:normal;background:#fff}
.flight-pair{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px;align-items:stretch;margin-top:2px}.flight-pair.single{grid-template-columns:1fr}.flight-pair>.section-block{min-width:0;display:flex;flex-direction:column}.flight-pair>.section-block .scroll{flex:1}.flight-pair>.section-block table{height:100%;margin-bottom:0;table-layout:fixed}.flight-table th,.flight-table td{text-align:center}.flight-table td:nth-child(2){font-weight:700}
.passenger-table{table-layout:fixed}.passenger-table th:nth-child(1){width:5%}.passenger-table th:nth-child(2){width:25%}.passenger-table th:nth-child(3){width:14%}.passenger-table th:nth-child(4){width:8%}.passenger-table th:nth-child(5){width:7%}.passenger-table th:nth-child(6){width:15%}.passenger-table th:nth-child(7){width:14%}.passenger-table th:nth-child(8){width:12%}
.transport-table{table-layout:fixed}.transport-table th,.transport-table td{font-size:7px;line-height:1.16}.transport-table td{vertical-align:top}.transport-table th:nth-child(1){width:16%}.transport-table th:nth-child(2){width:17%}.transport-table th:nth-child(3){width:12%}.transport-table th:nth-child(4){width:10%}.transport-table th:nth-child(5){width:14%}.transport-table th:nth-child(6){width:13%}.transport-table th:nth-child(7){width:18%}
.voucher-lower{display:grid;grid-template-columns:minmax(0,1fr) 86px;gap:12px;align-items:start;margin-top:7px}.voucher-lower-copy{min-width:0}.qr-box{width:86px;height:86px;display:flex;align-items:center;justify-content:center}.qr-box img{display:block;width:82px;height:82px;object-fit:contain}.qr-placeholder{width:82px;height:82px;border:1px solid #111;display:flex;align-items:center;justify-content:center;text-align:center;font-size:7.5px;color:#667085;background:#fff;padding:5px}.qr-placeholder[hidden]{display:none!important}.instructions{min-height:34px;border-bottom:1px dashed #cbd5e1;padding:5px 0;font-size:8px}.instructions strong{color:#203d75;font-style:italic;font-size:9px;margin-right:18px}.voucher-footer-text{padding:6px 0 2px;font-size:7.8px;line-height:1.35;color:#334155;white-space:normal;overflow-wrap:anywhere}.footer{margin-top:6px;padding-top:4px;border-top:1px dotted #d1d5db;font-size:7.5px;color:#475569;display:flex;justify-content:flex-end;gap:10px}
@page{size:A4 portrait;margin:0}
@media(max-width:760px){body{background:#fff}.sheet{width:100%;min-height:0;margin:0;border:0;box-shadow:none;padding:12px}.header{grid-template-columns:84px 1fr}.header-right{grid-column:1/-1;text-align:left}.summary{grid-template-columns:1fr}.summary>div{border-right:0;border-bottom:1px solid #111}.summary>div:last-child{border-bottom:0}.scroll{overflow:auto}.scroll table{min-width:680px}.flight-pair,.flight-pair.single{grid-template-columns:1fr}.flight-pair .scroll table{min-width:520px}.voucher-lower{grid-template-columns:minmax(0,1fr) 76px}.qr-box{width:76px}.qr-box img,.qr-placeholder{width:72px;height:72px}}
@media print{body{background:#fff}.toolbar{display:none}.sheet{margin:0;border:0;box-shadow:none;width:210mm;min-height:297mm;padding:8mm 9mm 10mm}.section-block{break-inside:avoid}thead{display:table-header-group}tr{break-inside:avoid}.voucher-lower,.footer{break-inside:avoid}}
</style>
</head>
<body>
@if(empty($publicMode))<div class="toolbar">
    <a href="{{ url('/operations/bookings/'.$bookingId) }}">Back to Booking</a>
    <button type="button" onclick="window.print()">Print / Save PDF</button>
</div>@endif
<div class="sheet">
    <div class="header">
        <div class="logo-box">
            @if(!empty($company['logo']))
                <img class="logo" src="{{ $company['logo'] }}" alt="Company Logo" onerror="this.hidden=true">
            @else
                <div class="logo-fallback">{{ $companyInitials }}</div>
            @endif
        </div>
        <div>
            @if(!empty($company['name']))<div class="company">{{ $company['name'] }}</div>@endif
            @if(!empty($company['subtitle']))<div class="company-sub">{{ $company['subtitle'] }}</div>@endif
            <div class="header-meta">
                <div><strong>Voucher Date :</strong> {{ $generatedAt->format('d/m/Y') }}</div>
                <div><strong>PAX :</strong> {{ $paxTotal }} (A:{{ $adultCount }},C:{{ $childCount }},I:{{ $infantCount }})</div>
            </div>
        </div>
        <div class="header-right">
            @if($accommodationOnly)<div class="only-accommodation">Only Accommodation</div>@endif
            @foreach($visaRelationships as $relationship)
                @if(!empty($relationship['saudi_company_name']))<div><strong>Saudi Company:</strong> {{ $relationship['saudi_company_name'] }}</div>@endif
                @if(!empty($relationship['pakistani_iata_name']))<div><strong>Pakistani IATA:</strong> {{ $relationship['pakistani_iata_name'] }}</div>@endif
            @endforeach
        </div>
    </div>

    <div class="title">{{ $accommodationOnly ? 'Hotel Voucher' : 'Travel Voucher' }}</div>

    <div class="summary">
        <div><strong>Family Head:</strong> {{ $familyHead }}</div>
        <div><strong>Voucher No:</strong> {{ $voucherNumber }}</div>
        <div><strong>Manual No:</strong> {{ $manualNumber ?: '—' }}</div>
    </div>

    @if(count($passengers))
    <div class="section-block">
        <div class="section-title">Passengers / Guests</div>
        <div class="scroll"><table class="passenger-table">
            <thead><tr><th>SNO</th><th>Passenger Name</th><th>Passport</th><th>PAX</th><th>Bed</th><th>Ticket No.</th><th>Visa No.</th><th>PNR</th></tr></thead>
            <tbody>
            @foreach($passengers as $i => $p)
                @php($ticket = $ticketByPassenger[(int)($p['id'] ?? 0)] ?? null)
                @php($passengerVisa = $visaByPassenger[(int)($p['id'] ?? 0)] ?? null)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td class="strong">{{ strtoupper($p['name'] ?? $p['passenger_name'] ?? 'Passenger') }}</td>
                    <td class="center">{{ $p['passport_number'] ?? $p['passport_no'] ?? '—' }}</td>
                    <td class="center">{{ ucfirst(strtolower($p['fare_type'] ?? 'Adult')) }}</td>
                    <td class="center">—</td>
                    <td class="center">{{ $ticket['ticket_number'] ?? '—' }}</td>
                    <td class="center">{{ $passengerVisa['visa_number'] ?? $passengerVisa['visa_no'] ?? '' }}</td>
                    <td class="center">{{ $airCommon['pnr'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
    @endif

    @if(count($hotelStays))
    <div class="section-block">
        <div class="section-title">Accommodation</div>
        <div class="scroll"><table>
            <thead><tr><th>City</th><th>Hotel Name</th><th>Meal</th><th>Conf#</th><th>Room Type</th><th>Check-in</th><th>Check-out</th><th>Nights</th></tr></thead>
            <tbody>
            @foreach($hotelStays as $h)
                <tr>
                    <td class="center">{{ $h['city'] ?? '—' }}</td>
                    <td class="strong">{{ strtoupper($h['hotel_name'] ?? '—') }}</td>
                    <td class="center">{{ strtoupper($h['board'] ?? '—') }}</td>
                    <td class="center">{{ $h['confirmation_no'] ?: '—' }}</td>
                    <td class="center">{{ strtoupper($h['room_type'] ?? '—') }}</td>
                    <td class="center nowrap">{{ !empty($h['check_in']) ? \Carbon\Carbon::parse($h['check_in'])->format('d-m-y') : '—' }}</td>
                    <td class="center nowrap">{{ !empty($h['check_out']) ? \Carbon\Carbon::parse($h['check_out'])->format('d-m-y') : '—' }}</td>
                    <td class="center">{{ (int)($h['nights'] ?? 0) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="total-row"><td colspan="6"></td><td class="right">Total Nights:</td><td class="center"><span class="total-box">{{ $totalHotelNights }}</span></td></tr></tfoot>
        </table></div>
    </div>
    @endif

    @if(count($outboundSegments) || count($returnSegments))
    <div class="flight-pair {{ (count($outboundSegments) && count($returnSegments)) ? '' : 'single' }}">
        @if(count($outboundSegments))
        <div class="section-block">
            <div class="section-title blue">Departure / Outbound</div>
            <div class="scroll"><table class="flight-table">
                <thead><tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th></tr></thead>
                <tbody>
                @foreach($outboundSegments as $s)
                    <tr>
                        <td>{{ trim(($s['airline_code'] ?? '').' '.($s['flight_number'] ?? '')) ?: '—' }}</td>
                        <td>{{ strtoupper(($s['from'] ?? '—').' - '.($s['to'] ?? '—')) }}</td>
                        <td>{{ !empty($s['departure_at']) ? \Carbon\Carbon::parse($s['departure_at'])->format('d-M H:i') : '—' }}</td>
                        <td>{{ !empty($s['arrival_at']) ? \Carbon\Carbon::parse($s['arrival_at'])->format('d-M H:i') : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
        @endif

        @if(count($returnSegments))
        <div class="section-block">
            <div class="section-title blue">Return / Arrival</div>
            <div class="scroll"><table class="flight-table">
                <thead><tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th></tr></thead>
                <tbody>
                @foreach($returnSegments as $s)
                    <tr>
                        <td>{{ trim(($s['airline_code'] ?? '').' '.($s['flight_number'] ?? '')) ?: '—' }}</td>
                        <td>{{ strtoupper(($s['from'] ?? '—').' - '.($s['to'] ?? '—')) }}</td>
                        <td>{{ !empty($s['departure_at']) ? \Carbon\Carbon::parse($s['departure_at'])->format('d-M H:i') : '—' }}</td>
                        <td>{{ !empty($s['arrival_at']) ? \Carbon\Carbon::parse($s['arrival_at'])->format('d-M H:i') : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
        @endif
    </div>
    @endif

    @if(count($transportRows))
    <div class="section-block" style="margin-top:7px">
        <div class="section-title blue">Transport</div>
        <div class="scroll"><table class="transport-table">
            <thead><tr><th>Transport Company</th><th>Route</th><th>Vehicle Type</th><th>B Number</th><th>Driver Name</th><th>Phone Number</th><th>Note</th></tr></thead>
            <tbody>
            @foreach($transportRows as $t)
                <tr>
                    <td class="strong">{{ strtoupper($t['company_name'] ?? '—') }}</td>
                    <td>{{ $t['route_name'] ?? '—' }}</td>
                    <td class="center">{{ $t['vehicle_type'] ?? '—' }}</td>
                    <td class="center">{{ $t['brn_number'] ?? '—' }}</td>
                    <td>{{ $t['driver_name'] ?? '—' }}</td>
                    <td class="center nowrap">{{ $t['driver_cell'] ?? '—' }}</td>
                    <td>{{ $t['notes'] ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
    @endif

    @if(!count($passengers) && !count($hotelStays) && !count($outboundSegments) && !count($returnSegments) && !count($transportRows))
        <div style="border:1px dashed #bbb;padding:10px;text-align:center;font-size:9px">No saved client-facing travel data is available for this voucher yet.</div>
    @endif

    <div class="voucher-lower">
        <div class="voucher-lower-copy">
            <div class="instructions"><strong>Special Instructions:</strong> {{ $specialInstructions ?: '—' }}</div>
            @if($resolvedVoucherFooter !== '')<div class="voucher-footer-text">{!! nl2br($resolvedVoucherFooter) !!}</div>@endif
        </div>
        <div class="qr-box">
            @if($voucherQrImage !== '')
                <div class="qr-image">
                    @if($publicVoucherUrl !== '')<a href="{{ $publicVoucherUrl }}" target="_blank" rel="noopener noreferrer" aria-label="Open public voucher view">@endif
                    <img src="{{ $voucherQrImage }}" alt="Public voucher QR code" onerror="this.closest('.qr-image').hidden=true;this.closest('.qr-box').querySelector('.qr-placeholder').hidden=false">
                    @if($publicVoucherUrl !== '')</a>@endif
                </div>
                <div class="qr-placeholder" hidden>Public voucher QR unavailable</div>
            @else
                <div class="qr-placeholder">Public voucher QR unavailable</div>
            @endif
        </div>
    </div>

    <div class="footer">
        <div>{{ $voucherNumber }} · {{ $bookingReference }}</div>
    </div>
</div>
</body>
</html>
