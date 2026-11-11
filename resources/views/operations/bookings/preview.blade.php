<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $booking->booking_no }} · Booking Confirmation</title>
<style>
:root{--blue:#123a73;--blue2:#1f5ca8;--red:#cf2b36;--ink:#172033;--muted:#657085;--line:#dfe5ee;--soft:#f5f7fb;--good:#16794b;--warn:#986a00}
*{box-sizing:border-box} body{margin:0;background:#eef2f7;color:var(--ink);font:14px/1.45 Arial,Helvetica,sans-serif}.toolbar{position:sticky;top:0;z-index:5;display:flex;justify-content:center;gap:10px;padding:12px;background:#111827}.toolbar a,.toolbar button{border:0;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none}.toolbar .back{background:white;color:#24324a}.toolbar .print{background:#2f6fdb;color:white}.sheet{width:min(980px,calc(100% - 28px));margin:24px auto 48px;background:white;box-shadow:0 12px 35px rgba(20,37,63,.12);border-radius:14px;overflow:hidden}.top{padding:26px 30px 22px;border-bottom:4px solid var(--red);display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.brand{display:flex;gap:14px;align-items:center}.mark{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:var(--blue);color:white;font-size:21px;font-weight:900}.brand h1{margin:0;color:var(--blue);font-size:26px;letter-spacing:.2px}.brand p{margin:4px 0 0;color:var(--muted)}.doc{text-align:right}.doc h2{margin:0 0 8px;color:var(--blue);font-size:22px}.status{display:inline-block;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:900;letter-spacing:.6px;background:#fff3cc;color:#765600}.status.confirmed{background:#dff5e9;color:var(--good)}.status.cancelled{background:#fde7e8;color:#9c2330}.draftmark{padding:9px 30px;background:#fff8df;border-bottom:1px solid #f0df9f;color:#6f5a11;font-weight:700}.body{padding:26px 30px 32px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.card{border:1px solid var(--line);border-radius:10px;padding:13px 14px}.label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);font-weight:800}.value{margin-top:4px;font-size:15px;font-weight:800}.section{margin-top:24px}.section h3{margin:0 0 12px;font-size:16px;color:var(--blue);padding-bottom:7px;border-bottom:2px solid #e8edf5}.table{width:100%;border-collapse:collapse}.table th{background:var(--soft);text-align:left;color:#4d5a70;font-size:11px;text-transform:uppercase;letter-spacing:.5px;padding:9px;border-bottom:1px solid var(--line)}.table td{padding:10px 9px;border-bottom:1px solid #edf0f5;vertical-align:top}.service{border:1px solid var(--line);border-radius:10px;margin-bottom:12px;overflow:hidden}.service-head{display:flex;justify-content:space-between;gap:16px;padding:13px 15px;background:var(--soft)}.service-head strong{font-size:15px;color:var(--blue)}.service-body{padding:14px 15px}.mini{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:9px}.mini div{min-width:0}.mini b{display:block;font-size:11px;color:var(--muted);margin-bottom:2px}.detail{margin-top:12px;padding:11px 12px;border-left:3px solid #a9b9d1;background:#fafbfd;border-radius:4px}.money{text-align:right}.total-box{margin-left:auto;width:min(420px,100%);border:1px solid var(--line);border-radius:10px;overflow:hidden}.total-row{display:flex;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--line)}.total-row:last-child{border:0;background:var(--blue);color:white;font-size:16px;font-weight:900}.notice{margin-top:24px;padding:13px 15px;background:#f7f9fc;border:1px solid var(--line);border-radius:9px;color:#536076}.footer{padding:18px 30px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:20px;color:var(--muted);font-size:11px}.muted{color:var(--muted)}
@media(max-width:760px){.top{display:block}.doc{text-align:left;margin-top:16px}.grid,.mini{grid-template-columns:1fr 1fr}.sheet{width:100%;margin:0;border-radius:0}.body,.top,.footer{padding-left:18px;padding-right:18px}.toolbar{position:static}}
@media print{body{background:white}.toolbar{display:none}.sheet{width:100%;margin:0;box-shadow:none;border-radius:0}.top{padding-top:10mm}.footer{page-break-inside:avoid}.service{page-break-inside:avoid}@page{size:A4;margin:9mm}}
</style>
</head>
<body>
<div class="toolbar">
    <a class="back" href="{{ route('operations.bookings.show',$booking) }}">Back to Booking</a>
    <button class="print" onclick="window.print()">Print / Save PDF</button>
</div>
<div class="sheet">
    <div style="padding:22px 30px 4px">@include('partials.document-header',['company'=>$booking->company,'branch'=>$booking->branch,'documentTitle'=>'Booking Confirmation','documentNumber'=>$booking->booking_no,'documentDate'=>$booking->booking_date,'documentStatus'=>$booking->status])</div>
    @if($booking->status!=='CONFIRMED')<div class="draftmark">PREVIEW ONLY — this booking is {{ str_replace('_',' ',$booking->status) }} and is not a final confirmation.</div>@endif
    <div class="body">
        <div class="grid">
            <div class="card"><div class="label">Customer / Account</div><div class="value">{{ $booking->customer?->display_name ?: '—' }}</div></div>
            <div class="card"><div class="label">Booking Type</div><div class="value">{{ str_replace('_',' ',$booking->booking_type) }}</div></div>
            <div class="card"><div class="label">Travel Start</div><div class="value">{{ $booking->travel_start_date?->format('d M Y') ?: 'To be confirmed' }}</div></div>
            <div class="card"><div class="label">Travel End</div><div class="value">{{ $booking->travel_end_date?->format('d M Y') ?: 'To be confirmed' }}</div></div>
        </div>

        <div class="section">
            <h3>Passengers</h3>
            <table class="table">
                <thead><tr><th>#</th><th>Passenger Name</th><th>Type</th><th>Passport</th><th>Nationality</th></tr></thead>
                <tbody>
                @forelse($activePassengers as $p)
                    <tr><td>{{ $p->passenger_no }}</td><td><strong>{{ $p->fullName() }}</strong>@if($p->is_lead)<div class="muted">Lead / Family Head</div>@endif</td><td>{{ $p->pax_type }}</td><td>{{ $p->passport_no ?: '—' }}</td><td>{{ $p->nationality_country_code ?: '—' }}</td></tr>
                @empty
                    <tr><td colspan="5">No active passengers.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="section">
            <h3>Booked Services</h3>
            @forelse($activeServices as $service)
                @php
                    $category=strtoupper((string)$service->product?->category);
                    $linked=$service->passengers->where('is_active',true);
                    $air=$airTickets->get($service->id);
                    $hotel=$hotelDetails->get($service->id);
                    $visas=$visaDetails->get($service->id,collect());
                    $transport=$transportDetails->get($service->id);
                @endphp
                <div class="service">
                    <div class="service-head">
                        <div><strong>{{ $service->product?->name ?: $service->description }}</strong><div class="muted">{{ $service->description }}</div></div>
                        <div class="money"><strong>{{ $service->currency_code }} {{ number_format((float)$service->line_total,2) }}</strong><div class="muted">{{ $service->pricing_basis_snapshot }} · Qty {{ rtrim(rtrim(number_format((float)$service->quantity,3,'.',''),'0'),'.') }}</div></div>
                    </div>
                    <div class="service-body">
                        <div><b>Travellers:</b> {{ $linked->map(fn($p)=>$p->fullName())->implode(', ') ?: 'Not linked' }}</div>
                        @if($service->service_from || $service->service_to)<div class="mini"><div><b>From</b>{{ $service->service_from?->format('d M Y') ?: '—' }}</div><div><b>To</b>{{ $service->service_to?->format('d M Y') ?: '—' }}</div></div>@endif

                        @if($category==='AIR_TICKET' && $air)
                            <div class="detail"><div class="mini"><div><b>Airline</b>{{ $airlineNames[$air->airline_id] ?? '—' }}</div><div><b>Sector</b>{{ $air->sector ?: trim(($air->origin ?: '').' → '.($air->destination ?: '')) ?: '—' }}</div><div><b>PNR</b>{{ $air->pnr ?: '—' }}</div><div><b>Ticket No.</b>{{ $air->ticket_number ?: '—' }}</div><div><b>Flight</b>{{ $air->flight_number ?: '—' }}</div><div><b>Departure</b>{{ $air->departure_at?->format('d M Y H:i') ?: '—' }}</div><div><b>Cabin</b>{{ $air->cabin_class ?: '—' }}</div><div><b>Baggage</b>{{ $air->baggage ?: '—' }}</div></div></div>
                        @elseif($category==='HOTEL' && $hotel)
                            @php $hotelMaster=$hotelRows->get($hotel->hotel_id); @endphp
                            <div class="detail"><div class="mini"><div><b>Hotel</b>{{ $hotelMaster?->name ?: '—' }}</div><div><b>City</b>{{ $hotelMaster?->city ?: '—' }}</div><div><b>Confirmation</b>{{ $hotel->confirmation_number ?: '—' }}</div><div><b>Room Type</b>{{ $hotel->room_type ?: '—' }}</div><div><b>Check-in</b>{{ $hotel->check_in?->format('d M Y') ?: '—' }}</div><div><b>Check-out</b>{{ $hotel->check_out?->format('d M Y') ?: '—' }}</div><div><b>Nights</b>{{ $hotel->nights ?? '—' }}</div><div><b>Meal Plan</b>{{ $hotel->meal_plan ?: '—' }}</div></div></div>
                        @elseif($category==='VISA' && $visas->isNotEmpty())
                            <div class="detail"><table class="table"><thead><tr><th>Passenger</th><th>Visa Type</th><th>Status</th><th>Visa / Application</th></tr></thead><tbody>@foreach($visas as $visa)<tr><td>{{ $passengerNames[$visa->booking_passenger_id] ?? 'Passenger' }}</td><td>{{ $visa->visa_type ?: '—' }}</td><td>{{ $visa->status }}</td><td>{{ $visa->visa_number ?: ($visa->application_number ?: '—') }}</td></tr>@endforeach</tbody></table></div>
                        @elseif($category==='TRANSPORT' && $transport)
                            <div class="detail"><div class="mini"><div><b>Pickup</b>{{ $transport->pickup_location ?: '—' }}</div><div><b>Drop-off</b>{{ $transport->dropoff_location ?: '—' }}</div><div><b>Pickup Time</b>{{ $transport->pickup_at?->format('d M Y H:i') ?: '—' }}</div><div><b>Vehicle</b>{{ $transport->vehicle_type ?: '—' }}</div></div></div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="muted">No active services.</div>
            @endforelse
        </div>

        <div class="section">
            <h3>Booking Value</h3>
            <div class="total-box">
                <div class="total-row"><span>Confirmed / Active Services</span><strong>{{ $activeServices->count() }}</strong></div>
                <div class="total-row"><span>Total Booking Value</span><span>{{ $booking->currency_code }} {{ number_format($bookingValue,2) }}</span></div>
            </div>
        </div>

        @if($booking->notes)
        <div class="section"><h3>Booking Notes</h3><div class="notice">{!! nl2br(e($booking->notes)) !!}</div></div>
        @endif

        <div class="notice"><strong>Important:</strong> This Booking Confirmation is an operational summary of the services currently recorded against {{ $booking->booking_no }}. It is <strong>not a Sales Invoice, Receipt, proof of payment, Hotel Voucher or Travel Voucher</strong>. Those documents are generated through their own controlled workflows.</div>
    </div>
    <div class="footer">
        <div>
            <strong>{{ $booking->company?->name ?: 'Easy Ticket' }}</strong><br>
            {{ $booking->branch?->name }}@if($booking->branch?->city) · {{ $booking->branch->city }}@endif
            @if($booking->company?->phone)<br>{{ $booking->company->phone }}@endif
            @if($booking->company?->email)<br>{{ $booking->company->email }}@endif
        </div>
        <div style="text-align:right">Generated {{ now()->format('d M Y H:i') }}<br>Booking Reference {{ $booking->booking_no }}</div>
    </div>
</div>
</body>
</html>
