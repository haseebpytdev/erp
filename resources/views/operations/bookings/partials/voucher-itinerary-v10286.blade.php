@php
    $erp10286VoucherBookingId = (int) data_get($booking ?? null, 'id', 0);
    $erp10286VoucherSegments = collect();
    if ($erp10286VoucherBookingId && \Illuminate\Support\Facades\Schema::hasTable('booking_itinerary_segments')) {
        $erp10286VoucherSegments = \App\Models\BookingItinerarySegment::query()
            ->where('booking_id', $erp10286VoucherBookingId)
            ->where('status', '!=', 'cancelled')
            ->orderBy('sort_order')
            ->orderBy('departure_at')
            ->get();
    }
@endphp

@if($erp10286VoucherSegments->isNotEmpty())
<table class="voucher-table" style="width:100%;border-collapse:collapse;margin-top:8px;font-size:10px;">
    <thead><tr><th colspan="8" style="border:1px solid #444;background:#e7e7e7;padding:3px;">Flight Itinerary</th></tr>
    <tr><th style="border:1px solid #777;padding:3px;">Type</th><th style="border:1px solid #777;padding:3px;">Flight</th><th style="border:1px solid #777;padding:3px;">Sector</th><th style="border:1px solid #777;padding:3px;">Departure</th><th style="border:1px solid #777;padding:3px;">Arrival</th><th style="border:1px solid #777;padding:3px;">PNR</th><th style="border:1px solid #777;padding:3px;">Cabin</th><th style="border:1px solid #777;padding:3px;">Baggage</th></tr></thead>
    <tbody>
    @foreach($erp10286VoucherSegments as $segment)
        <tr>
            <td style="border:1px solid #777;padding:3px;text-transform:uppercase;">{{ $segment->segment_type }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ trim(($segment->airline_code ? $segment->airline_code.' ' : '').($segment->flight_number ?: '')) ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $segment->from_code }}-{{ $segment->to_code }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ optional($segment->departure_at)->format('d-M-y H:i') ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ optional($segment->arrival_at)->format('d-M-y H:i') ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $segment->pnr ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $segment->cabin ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $segment->baggage ?: '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
