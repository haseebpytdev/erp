<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Customer &amp; Vendor Booking Report</title>
    <style>
        @page { size: A4 portrait; margin: 10mm; }
        @page { @bottom-right { content: "Page " counter(page) " of " counter(pages); font-size: 8pt; color: #53667c; } }
        * { box-sizing: border-box; }
        body { margin: 0; color: #17243a; font: 8.5pt Arial, sans-serif; }
        .report { width: 100%; }
        .heading { border-bottom: 2px solid #1769d2; padding-bottom: 8px; margin-bottom: 10px; }
        .kicker { color: #1769d2; font-size: 8pt; font-weight: 700; text-transform: uppercase; }
        h1 { margin: 3px 0; font-size: 18pt; }
        .meta { color: #53667c; font-size: 8pt; line-height: 1.5; }
        .metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 8px 0; }
        .metric { border: 1px solid #cbd7e5; padding: 7px; break-inside: avoid; }
        .metric small { display: block; color: #53667c; font-size: 7pt; text-transform: uppercase; }
        .metric strong { display: block; margin-top: 3px; font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th:nth-child(1), td:nth-child(1) { width: 5%; }
        th:nth-child(2), td:nth-child(2) { width: 16%; }
        th:nth-child(3), td:nth-child(3) { width: 12%; }
        th:nth-child(4), td:nth-child(4) { width: 22%; }
        th:nth-child(5), td:nth-child(5) { width: 13%; }
        th:nth-child(6), td:nth-child(6) { width: 8%; }
        th:nth-child(7), td:nth-child(7) { width: 10%; }
        th:nth-child(8), td:nth-child(8) { width: 14%; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th, td { border: .6pt solid #b8c5d5; padding: 4px 5px; vertical-align: top; overflow-wrap: anywhere; word-break: normal; }
        th { background: #eaf2fb; color: #29415f; font-size: 8pt; text-transform: uppercase; }
        td { font-size: 8.5pt; line-height: 1.18; }
        .num { text-align: right; white-space: nowrap; }
        .num { text-align: right; }
        .total { margin-top: 8px; padding-top: 7px; border-top: 1.2pt solid #1769d2; text-align: right; font-weight: 700; }
        .generated { margin-top: 10px; color: #53667c; font-size: 7pt; }
    </style>
</head>
<body>
@php($money=fn($v)=>'PKR '.number_format((float)$v,2))
@php($activeParty=collect($parties)->firstWhere('id',(int)$filters['party_id']))
<main class="report">
    <header class="heading">
        <div class="kicker">Easy Ticket · Accounting Reports</div>
        <h1>{{ $filters['tab']==='vendor' ? 'Vendor / Supplier Booking Report' : 'Customer / Agent Booking Report' }}</h1>
        <div class="meta">
            Date range: {{ $filters['from'] ?: 'All dates' }} — {{ $filters['to'] ?: 'All dates' }}
            · Status: {{ $filters['status'] ?: 'Active / non-cancelled' }}
            · Booking type: {{ $filters['booking_type'] ?: 'All types' }}
            @if($filters['search']) · Search: {{ $filters['search'] }} @endif
            @if($activeParty) · {{ $filters['tab']==='vendor' ? 'Vendor' : 'Customer' }}: {{ $activeParty['name'] }} @endif
        </div>
    </header>
    <section class="metrics" aria-label="Report totals">
        <div class="metric"><small>Distinct Bookings</small><strong>{{ number_format($metrics['bookings']) }}</strong></div>
        <div class="metric"><small>{{ $filters['tab']==='vendor' ? 'Relevant Active Passengers' : 'Active Passengers' }}</small><strong>{{ number_format($metrics['passengers']) }}</strong></div>
        @if($filters['tab']==='customer')
            <div class="metric"><small>Customer Sale Value</small><strong>{{ $money($metrics['sale']) }}</strong></div>
        @else
            <div class="metric"><small>Vendor Cost Value</small><strong>{{ $money($metrics['vendor']) }}</strong></div>
        @endif
    </section>
    <table>
        <thead><tr><th>#</th><th>Booking</th><th>Booking Date</th><th>{{ $filters['tab']==='vendor' ? 'Vendor / Supplier' : 'Customer / Agent' }}</th><th>Booking Type</th><th>Passengers</th><th>Status</th><th>{{ $filters['tab']==='vendor' ? 'Vendor Cost' : 'Total Sale Value' }}</th></tr></thead>
        <tbody>
        @forelse($rows as $index=>$row)
            <tr><td>{{ $index + 1 }}</td><td>{{ $row['booking_no'] }}</td><td>{{ $row['booking_date'] ?: '—' }}</td><td>{{ $filters['tab']==='vendor' ? $row['vendor'] : $row['customer'] }}</td><td>{{ $row['booking_type'] }}</td><td class="num">{{ $row['passengers'] }}</td><td>{{ $row['status'] }}</td><td class="num">{{ $filters['tab']==='vendor' ? ($row['vendor_cost']===null ? 'Unresolved' : $money($row['vendor_cost'])) : $money($row['sale']) }}</td></tr>
        @empty
            <tr><td colspan="8">No matching saved bookings found.</td></tr>
        @endforelse
        </tbody>
    </table>
    <div class="total">Grand Total: {{ $money($filters['tab']==='vendor' ? $metrics['vendor'] : $metrics['sale']) }}</div>
    <div class="generated">Generated {{ now()->format('Y-m-d H:i:s') }} · Full filtered authorized dataset</div>
</main>
</body>
</html>
