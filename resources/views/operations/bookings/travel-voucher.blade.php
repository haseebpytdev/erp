<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="referrer" content="no-referrer">
<title>{{ $booking->booking_no }} · {{ $documentTitle }}</title>
<style>
:root{--blue:#071f73;--brand:#0753a0;--ink:#111;--line:#111;--grey:#dedede;--head:#f2f4f7}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:#edf1f5;color:var(--ink);font-family:Arial,Helvetica,sans-serif;font-size:9px}
.toolbar{position:sticky;top:0;z-index:9;background:#111827;padding:8px;display:flex;justify-content:center;gap:7px}
.toolbar a,.toolbar button{border:0;border-radius:6px;padding:7px 11px;text-decoration:none;font-weight:800;cursor:pointer;font-size:10px}.toolbar form{margin:0}
.toolbar a{background:#fff;color:#24364d}.toolbar button{background:#1769d2;color:#fff}
.sheet{
    width:min(210mm,calc(100% - 18px));
    min-height:297mm;
    margin:10px auto 24px;
    background:#fff;
    border:1px solid #9da3aa;
    padding:4mm 5mm 4mm;
    box-shadow:0 8px 24px rgba(20,40,65,.12)
}
.voucher-content{position:relative;overflow:visible;isolation:isolate}
.voucher-content>.watermark{position:absolute;z-index:0;left:0;right:0;top:0;bottom:0;display:flex;align-items:center;justify-content:center;pointer-events:none;overflow:visible}
.voucher-content>.watermark span{position:absolute;left:50%;top:50%;z-index:0;transform:translate(-50%,-50%) rotate(-32deg);font-size:clamp(34px,5vw,40px);line-height:1;font-weight:900;letter-spacing:.08em;white-space:nowrap;opacity:.14}
.watermark .draft{color:#64748b}.watermark .pending{color:#b7791f}.watermark .approved{color:#3c8c57}
.voucher-content>*{position:relative;z-index:1}
.header{display:grid;grid-template-columns:1fr .85fr;gap:8px;align-items:start;min-height:26mm}
.brand-block{display:flex;gap:7px;align-items:flex-start}
.brand-logo{width:18mm;height:18mm;display:grid;place-items:center;flex:0 0 18mm}
.brand-logo img{max-width:18mm;max-height:18mm;object-fit:contain}
.brand-fallback{width:17mm;height:17mm;border:1.5px solid var(--blue);border-radius:50%;display:grid;place-items:center;color:#b51f2a;font-weight:900}
.brand-copy h1{margin:0;color:var(--brand);font-size:20px;line-height:1;font-weight:800}
.brand-copy .tag{font-size:8px;font-style:italic;margin:2px 0 5px}
.brand-meta{display:grid;grid-template-columns:auto 8px 1fr;gap:2px 4px;font-size:8px;line-height:1.3}
.brand-meta strong{font-weight:800}
.partner-block{text-align:center;padding-top:1px}
.partner-block .main{color:var(--blue);font-size:15px;font-weight:900;text-transform:uppercase;line-height:1.05}
.partner-block .sub{font-size:8.5px;margin-top:7px;text-transform:uppercase}
.partner-block.empty{visibility:hidden}
.accommodation-only{color:var(--blue);font-size:13px;font-weight:900;line-height:1.05;text-align:right;text-transform:uppercase;white-space:nowrap;padding-top:2px;align-self:start}
.doc-title{text-align:center;font-size:16px;font-weight:700;margin:-1mm 0 2mm;color:#111}
.draft{font-size:7px;text-align:center;color:#a12a2a;font-weight:900;margin:-1mm 0 1.5mm}.issued-note{font-size:7px;text-align:center;color:#1d6b47;font-weight:900;margin:-1mm 0 1.5mm}
.ident{width:100%;border-collapse:collapse;margin:0 0 2mm}
.ident td{border:1.4px solid #111;padding:3px 5px;font-size:9px}
.ident strong{font-size:9.5px}.ident .label{white-space:nowrap}
.sec{margin-top:2mm}
.sec-title{border:1px solid #111;border-bottom:0;background:var(--grey);text-align:center;font-size:9px;font-weight:900;padding:2px}
.tbl{width:100%;border-collapse:collapse;table-layout:auto}
.tbl th,.tbl td{border:1px solid #111;padding:2px 3px;font-size:7.5px;text-align:center;vertical-align:middle;line-height:1.2}
.tbl th{background:var(--head);font-weight:900}
.tbl td.left{text-align:left}
.total-row td{font-weight:900;font-style:italic;border-top:0}
.total-box{display:inline-block;min-width:16mm;border:1.5px solid #111;padding:2px 5px;font-style:normal;text-align:center}
.flight-qr-row{display:grid;grid-template-columns:1fr 1fr 25mm;grid-template-rows:auto auto;column-gap:2mm;row-gap:.8mm;align-items:start;margin-top:2mm}
.flight-box{border:1px solid #111}
.flight-title{background:#e3e3e3;color:var(--blue);text-align:center;font-family:Georgia,serif;font-size:11px;font-weight:900;padding:2px;border-bottom:1px solid #111}
.flight-box table{width:100%;border-collapse:collapse}
.flight-box th,.flight-box td{border-right:1px solid #111;padding:2.5px 2px;text-align:center;font-size:7.5px;line-height:1.15}
.flight-box th:last-child,.flight-box td:last-child{border-right:0}
.flight-box th{background:#f4f4f4;font-weight:900}
.flight-empty{min-height:15mm}
.qr-box{grid-column:3;grid-row:1 / span 2;width:25mm;height:25mm;border:1px solid #111;display:grid;place-items:center;padding:1.5mm;justify-self:end;align-self:start}
.qr-box img{width:100%;height:100%;display:block;object-fit:contain}.qr-pending{font-size:6.5px;line-height:1.25;text-align:center;font-weight:900;color:#8a2730;padding:2mm}
.flight-notes{grid-column:1 / 3;grid-row:2;margin:0;padding:0}.instructions{display:grid;grid-template-columns:34mm 1fr;gap:2mm;margin-top:.7mm;min-height:7mm;padding:2px 3px}
.instructions strong{font-family:Georgia,serif;color:var(--blue);font-style:italic;font-size:10px}
.instructions div{font-size:7.8px;white-space:pre-line;line-height:1.35}
.footer-notes{margin-top:.8mm;padding:1mm 3px 0;min-height:7mm;font-size:7.8px;line-height:1.35;text-align:left}
.footer-notes:empty{min-height:0;padding:0;margin:0}
.note-row{margin-top:.5mm;font-size:7.5px;padding:2px 3px;border-top:1px dotted #888;text-align:left}
.note-row strong{color:var(--blue)}
@page{size:A4 portrait;margin:0}
@media print{
    html,body{width:210mm!important;min-width:210mm!important;background:#fff!important}
    .toolbar{display:none!important}
    .sheet{
        width:210mm!important;
        min-width:210mm!important;
        max-width:210mm!important;
        min-height:297mm;
        margin:0!important;
        box-shadow:none!important;
        border:0!important;
        padding:4mm 5mm 4mm!important
    }
    .header{
        display:grid!important;
        grid-template-columns:1fr .85fr!important;
    }
    .partner-block{text-align:center!important}
    .flight-qr-row{
        display:grid!important;
        grid-template-columns:minmax(0,1fr) minmax(0,1fr) 25mm!important;
        grid-template-rows:auto auto!important;
        column-gap:2mm!important;
        row-gap:.8mm!important;
        align-items:start!important;
    }
    .qr-box{
        width:25mm!important;
        height:25mm!important;
        grid-column:3!important;
        grid-row:1 / span 2!important;
        justify-self:end!important;
        align-self:start!important;
    }
    .header,.ident,.sec,.flight-qr-row,.instructions,.footer-notes{break-inside:avoid!important}
}
@media screen and (max-width:760px){
    .sheet{width:calc(100% - 10px);padding:10px}
    .header{grid-template-columns:1fr}
    .partner-block{text-align:left}
    .flight-qr-row{grid-template-columns:1fr;grid-template-rows:auto}
    .flight-notes{grid-column:1;grid-row:auto}
    .qr-box{grid-column:1;grid-row:auto;justify-self:start}
}
</style>
</head>
<body>

<?php if (!$publicMode): ?>
<?php $clientVoucherIssueRouteReady=\Illuminate\Support\Facades\Route::has('operations.bookings.travel-voucher.issue'); ?>
<div class="toolbar">
    <a href="{{ route('operations.bookings.travel-voucher.edit',$booking) }}">Manage Voucher</a>
    <a href="{{ route('operations.bookings.show',$booking) }}">Booking</a>
    <?php if ($clientVoucherIssueRouteReady && auth()->user()?->hasPermission('service_details.manage') && $isFinal): ?>
        <form method="POST" action="{{ route('operations.bookings.travel-voucher.issue',$booking) }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">
            <button type="submit">{{ $isIssued ? 'Reissue Voucher' : ($isVoided ? 'Reissue Voucher' : 'Generate Client Voucher') }}</button>
        </form>
    <?php endif; ?>
    <?php if ($isIssued): ?>
        <button type="button" data-public-url="{{ $publicVoucherUrl }}" onclick="navigator.clipboard.writeText(this.dataset.publicUrl).then(()=>{this.textContent='Link Copied';setTimeout(()=>this.textContent='Copy Public Link',1800)}).catch(()=>{window.prompt('Copy public voucher link:',this.dataset.publicUrl)})">Copy Public Link</button>
    <?php endif; ?>
    <button onclick="window.print()">Print / Save PDF</button>
</div>
<?php endif; ?>

<?php
    $lead=$activePassengers->firstWhere('is_lead',true) ?: $activePassengers->first();
    $packageService=$umrahServices->first();
    $packageName=$groupPackage?->package_name ?: $packageService?->product?->name;
    $voucherVisaNotes=$voucherOnly->where('item_type','VISA');
    $voucherOtherNotes=$voucherOnly->where('item_type','OTHER');
    $printTitle=$documentType==='UMRAH' ? 'Hotel Voucher' : $documentTitle;
    $voucherDate=$voucher->issued_at ?: now();
    // A hotel-specific voucher may be opened from a mixed booking by hotel_service_id.
    // Treat that selected document as an Accommodation Voucher even when the parent
    // booking also contains flights, transport, visa or other services.
    $isAccommodationVoucher=(
        $documentType==='ACCOMMODATION'
        || (int)$requestedHotelService>0
        || strtoupper((string)$documentTitle)==='ACCOMMODATION VOUCHER'
    );
?>

<div class="sheet">
    <?php
        $voucherWatermarkText = $isVoided ? 'VOIDED' : (!$isFinal ? 'DRAFT' : (!$isIssued ? 'PENDING APPROVAL' : 'APPROVED'));
        $voucherWatermarkTone = $isVoided || !$isFinal ? 'draft' : (!$isIssued ? 'pending' : 'approved');
    ?>
    <div class="voucher-content">
        <div class="watermark" aria-hidden="true"><span class="{{ $voucherWatermarkTone }}">{{ $voucherWatermarkText }}</span></div>
    <div class="header">
        <div class="brand-block">
            <div class="brand-logo">
                <?php if ($booking->company?->logo_data_uri): ?>
                    <img src="{{ $booking->company->logo_data_uri }}" alt="Easy Ticket">
                <?php else: ?>
                    <div class="brand-fallback">ET</div>
                <?php endif; ?>
            </div>
            <div class="brand-copy">
                <h1>{{ $booking->company?->name ?: 'Easy Ticket' }}</h1>
                <div class="tag">{{ $booking->company?->legal_name ?: 'Easy Group Of Travels' }}</div>
                <div class="brand-meta">
                    <strong>Voucher Date</strong><span>:</span><span>{{ $voucherDate->format('d/m/Y') }}</span>
                    <?php if ($documentType==='UMRAH'): ?>
                        <strong>Package</strong><span>:</span><span>{{ $packageName ?: 'GROUP PACKAGE' }}</span>
                    <?php endif; ?>
                    <strong>PAX</strong><span>:</span><span>{{ $activePassengers->count() }} (A:{{ $activePassengers->where('pax_type','ADULT')->count() }},C:{{ $activePassengers->where('pax_type','CHILD')->count() }},I:{{ $activePassengers->where('pax_type','INFANT')->count() }}),Beds={{ $bedCount }}</span>
                </div>
            </div>
        </div>

        <?php if ($documentType==='UMRAH'): ?>
            <div class="partner-block">
                <div class="main">{{ $resolvedPakistanIataOperator?->name ?: 'PAKISTANI IATA / UMRAH OPERATOR' }}</div>
                <div class="sub">{{ $resolvedSaudiVisaCompany?->name ?: 'SAUDI VISA COMPANY' }}</div>
            </div>
        <?php elseif ($isAccommodationVoucher): ?>
            <div class="accommodation-only">ONLY ACCOMMODATION VOUCHER</div>
        <?php else: ?>
            <div class="partner-block empty"></div>
        <?php endif; ?>
    </div>

    <?php if (!$isAccommodationVoucher): ?>
        <div class="doc-title">{{ $printTitle }}</div>
    <?php endif; ?>
    <?php if ($isVoided): ?>
        <div class="draft">VOIDED · NOT VALID FOR TRAVEL</div>
    <?php elseif (!$isIssued && $isFinal): ?>
        <div class="draft">PREVIEW · CLIENT VOUCHER NOT ISSUED</div>
    <?php elseif (!$isFinal): ?>
        <div class="draft">DRAFT · REVIEW BEFORE TRAVEL</div>
    <?php elseif ($isIssued && $issueRevision>1): ?>
        <div class="issued-note">ISSUED · REVISION {{ $issueRevision }}</div>
    <?php endif; ?>

    <table class="ident">
        <tr>
            <td><span class="label">Family Head:</span> <strong>{{ strtoupper((string)($lead?->fullName() ?: $booking->customer?->display_name ?: '—')) }}</strong></td>
            <td><span class="label">Voucher No:</span> <strong>{{ $voucher->voucher_no }}</strong><?php if ($issueRevision>1): ?> <span style="font-size:7px">Rev {{ $issueRevision }}</span><?php endif; ?></td>
            <td><span class="label">Manual No:</span> <strong>{{ $voucher->manual_reference ?: '—' }}</strong></td>
        </tr>
    </table>

    <?php if ($activePassengers->isNotEmpty()): ?>
    <div class="sec">
        <div class="sec-title">{{ $documentType==='UMRAH'?'Mutamers':'Passengers / Guests' }}</div>
        <table class="tbl">
            <thead>
                <tr>
                    <th>SNO</th>
                    <th>Passport</th>
                    <th>{{ $documentType==='UMRAH'?'Mutamer Name':'Passenger Name' }}</th>
                    <th>G</th>
                    <th>PAX</th>
                    <th>Bed</th>
                    <th>Group #</th>
                    <th>Visa #</th>
                    <th>PNR</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($activePassengers as $passenger): ?>
                <tr>
                    <td>{{ $passenger->passenger_no }}</td>
                    <td>{{ $passenger->passport_no ?: '—' }}</td>
                    <td class="left">{{ strtoupper($passenger->fullName()) }}</td>
                    <td>{{ strtoupper(substr((string)$passenger->gender,0,1)) ?: '—' }}</td>
                    <td>{{ ucfirst(strtolower((string)$passenger->pax_type)) }}</td>
                    <td>{{ $hotelDetails->isEmpty() ? '—' : ($bedPassengerIds->contains((int)$passenger->id) ? 'Yes' : 'No') }}</td>
                    <td>{{ $booking->customer_reference ?: '—' }}</td>
                    <td>{{ $visaRefsByPassenger->get($passenger->id) ?: '—' }}</td>
                    <td>{{ $ticketRefsByPassenger->get($passenger->id) ?: '—' }}</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($hotelDetails->isNotEmpty()): ?>
    <div class="sec">
        <div class="sec-title">Accommodation</div>
        <table class="tbl">
            <thead>
                <tr>
                    <th>City</th><th>Hotel Name</th><th>View</th><th>Meal</th><th>Conf#</th>
                    <th>Room Type</th><th>Check-in</th><th>Check-out</th><th>Nights</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($hotelDetails as $hotelDetail): ?>
                <?php
                    $room=$hotelDetail->roomsList->first();
                ?>
                <tr>
                    <td>{{ $hotelDetail->hotel?->city ?: '—' }}</td>
                    <td class="left">{{ strtoupper((string)($hotelDetail->hotel?->name ?: '—')) }}</td>
                    <td>{{ $room?->room_view ?? $hotelDetail->room_view ?? 'Standard' }}</td>
                    <td>{{ $room?->meal_plan ?: $hotelDetail->meal_plan ?: 'RO' }}</td>
                    <td>{{ $hotelDetail->confirmation_number ?: '—' }}</td>
                    <td>{{ $room?->room_type ?: $hotelDetail->room_type ?: '—' }}</td>
                    <td>{{ $hotelDetail->check_in?->format('d-m-y') ?: '—' }}</td>
                    <td>{{ $hotelDetail->check_out?->format('d-m-y') ?: '—' }}</td>
                    <td>{{ $hotelDetail->nights ?: '—' }}</td>
                </tr>
            <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="8" style="text-align:right">Total Nights:</td>
                    <td><span class="total-box">{{ $totalNights }}</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($displayTransport->isNotEmpty()): ?>
    <div class="sec">
        <div class="sec-title">Transport/Services</div>
        <table class="tbl">
            <thead>
                <tr><th>Transporter</th><th>Service Date / Time</th><th>Route</th><th>Vehicle</th><th>Driver</th><th>BRN</th><th>Contact</th></tr>
            </thead>
            <tbody>
            <?php foreach ($displayTransport as $line): ?>
                <tr>
                    <td class="left">{{ strtoupper((string)($line['transporter'] ?: 'TRANSPORT')) }}</td>
                    <td>{{ $line['service_at'] ? $line['service_at']->format(($line['show_time']??false)?'d-M-y H:i':'d-M-y') : '—' }}</td>
                    <td class="left">
                        <?php if ($line['from'] || $line['to']): ?>
                            {{ $line['from'] ?: '—' }}{{ $line['from'] && $line['to']?' → ':'' }}{{ $line['to'] ?: '—' }}
                            <?php if ($line['description'] && !str_contains((string)$line['description'],'→')): ?><br><small>{{ $line['description'] }}</small><?php endif; ?>
                        <?php elseif ($line['description']): ?>
                            {{ $line['description'] }}
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>{{ $line['vehicle_type'] ?: '—' }}<?php if ($line['vehicle_number']): ?><br><small>{{ $line['vehicle_number'] }}</small><?php endif; ?></td>
                    <td>{{ $line['driver_name'] ?: '—' }}</td>
                    <td>{{ $line['brn'] ?: '—' }}</td>
                    <td>{{ $line['contact_number'] ?: '—' }}</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    @include('operations.bookings.partials.voucher-itinerary-v10286')
    @include('operations.bookings.partials.voucher-transport-v10286')

    <div class="flight-qr-row">
        <div>
            <?php if ($departureFlight): ?>
            <div class="flight-box">
                <div class="flight-title">Departure</div>
                <table>
                    <thead><tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th></tr></thead>
                    <tbody><tr>
                        <td>{{ $departureFlight['flight_number'] ?: '—' }}</td>
                        <td>{{ $departureFlight['origin'] ?: '—' }}-{{ $departureFlight['destination'] ?: '—' }}</td>
                        <td>{{ $departureFlight['departure_at']?->format('d-M H:i') ?: '—' }}</td>
                        <td>{{ $departureFlight['arrival_at']?->format('d-M H:i') ?: '—' }}</td>
                    </tr></tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div>
            <?php if ($arrivalFlight): ?>
            <div class="flight-box">
                <div class="flight-title">Arrival</div>
                <table>
                    <thead><tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th></tr></thead>
                    <tbody><tr>
                        <td>{{ $arrivalFlight['flight_number'] ?: '—' }}</td>
                        <td>{{ $arrivalFlight['origin'] ?: '—' }}-{{ $arrivalFlight['destination'] ?: '—' }}</td>
                        <td>{{ $arrivalFlight['departure_at']?->format('d-M H:i') ?: '—' }}</td>
                        <td>{{ $arrivalFlight['arrival_at']?->format('d-M H:i') ?: '—' }}</td>
                    </tr></tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="qr-box">
            <?php if ($isIssued || $publicMode): ?>
                <img src="{{ $qrDataUri }}" alt="Public voucher QR">
            <?php else: ?>
                <div class="qr-pending">ISSUE VOUCHER<br>TO ACTIVATE<br>PUBLIC QR</div>
            <?php endif; ?>
        </div>

        <div class="flight-notes">
            <?php foreach ($voucherVisaNotes as $note): ?>
                <div class="note-row"><strong>Visa:</strong> {{ $note->details }} <?php if ($note->reference): ?> · Ref: {{ $note->reference }} <?php endif; ?></div>
            <?php endforeach; ?>
            <?php if ($groupPackage && strtoupper((string)$groupPackage->package_type)==='UMRAH' && strtoupper((string)$groupPackage->visa_status)==='ISSUED' && $voucherVisaNotes->isEmpty()): ?>
                <div class="note-row"><strong>Visa:</strong> ISSUED / INCLUDED</div>
            <?php endif; ?>

            <?php foreach ($voucherOtherNotes as $note): ?>
                <div class="note-row"><strong>Other:</strong> {{ $note->details }} <?php if ($note->reference): ?> · Ref: {{ $note->reference }} <?php endif; ?></div>
            <?php endforeach; ?>

            <div class="instructions">
                <strong>Special Instructions:</strong>
                <div>{{ $voucher->special_instructions ?: '' }}</div>
            </div>
        </div>
    </div>

    <?php if ($resolvedFooterHtml): ?>
        <div class="footer-notes">{!! $resolvedFooterHtml !!}</div>
    <?php else: ?>
        <div class="footer-notes"></div>
    <?php endif; ?>
</div>
    </div>
</body>
</html>
