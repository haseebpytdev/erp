@php
    $erp10286VoucherBookingId = (int) data_get($booking ?? null, 'id', 0);
    $erp10286VoucherTransport = collect();
    if ($erp10286VoucherBookingId && \Illuminate\Support\Facades\Schema::hasTable('booking_transport_segments')) {
        $erp10286VoucherTransport = \App\Models\BookingTransportSegment::query()
            ->where('booking_id', $erp10286VoucherBookingId)
            ->where('status', '!=', 'cancelled')
            ->orderBy('sort_order')
            ->orderBy('transport_date')
            ->get();
    }
@endphp

@if($erp10286VoucherTransport->isNotEmpty())
<table class="voucher-table" style="width:100%;border-collapse:collapse;margin-top:8px;font-size:10px;">
    <thead><tr><th colspan="7" style="border:1px solid #444;background:#e7e7e7;padding:3px;">Transport</th></tr>
    <tr><th style="border:1px solid #777;padding:3px;">Date/Time</th><th style="border:1px solid #777;padding:3px;">Pickup</th><th style="border:1px solid #777;padding:3px;">Drop-off</th><th style="border:1px solid #777;padding:3px;">Vehicle</th><th style="border:1px solid #777;padding:3px;">Driver</th><th style="border:1px solid #777;padding:3px;">Contact</th><th style="border:1px solid #777;padding:3px;">BRN / Ref</th></tr></thead>
    <tbody>
    @foreach($erp10286VoucherTransport as $row)
        <tr>
            <td style="border:1px solid #777;padding:3px;">{{ optional($row->transport_date)->format('d-M-y') ?: '—' }} {{ $row->pickup_time ? \Illuminate\Support\Str::of($row->pickup_time)->substr(0,5) : '' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $row->pickup_location ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $row->dropoff_location ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $row->vehicle_type ?: '—' }}{{ $row->vehicle_number ? ' / '.$row->vehicle_number : '' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $row->driver_name ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $row->driver_contact ?: '—' }}</td>
            <td style="border:1px solid #777;padding:3px;">{{ $row->provider_reference ?: '—' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif
