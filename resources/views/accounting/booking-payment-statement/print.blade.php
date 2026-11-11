<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $filters['mode']==='vendor' ? 'Vendor Account Statement' : 'Customer Account Statement' }}</title>
<style>
@page { size: A4 portrait; margin: 12mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; background: #fff; }
body { font: 8px Arial; color: #182536; }
.print-canvas { min-height: 100vh; padding: 16px; background: #f2f5f8; }
.print-page { width: 210mm; min-height: 297mm; max-width: 100%; margin: 0 auto; padding: 8mm; background: #fff; }
.head { display: flex; align-items: center; gap: 10px; border-bottom: 2px solid #182536; padding-bottom: 6px; margin-bottom: 8px; }
.head img { width: 72px; height: 72px; flex: 0 0 72px; object-fit: contain; }
.company-info { min-width: 0; }
.company-name { display: block; font-size: 18px; font-weight: 700; line-height: 1.1; }
.company-line { display: block; font-size: 8px; line-height: 1.25; }
.head h1 { font-size: 13px; margin: 3px 0 0; }
.meta { display: flex; justify-content: space-between; margin-bottom: 8px; gap: 8px; }
.table { width: 100%; border-collapse: collapse; table-layout: fixed; line-height: 1.25; }
.table col:nth-child(1) { width: 9%; } .table col:nth-child(2) { width: 11%; } .table col:nth-child(3) { width: 13%; } .table col:nth-child(4) { width: 34%; } .table col:nth-child(5) { width: 11%; } .table col:nth-child(6) { width: 11%; } .table col:nth-child(7) { width: 11%; }
.table th, .table td { border: .7pt solid #000; padding: 3px 4px; vertical-align: top; overflow-wrap: anywhere; }
.table th { background: #edf3f9; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
.table th:nth-last-child(-n+3), .table td:nth-last-child(-n+3) { text-align: right; white-space: nowrap; }
.table td:nth-child(4) { white-space: normal; }
.table thead { display: table-header-group; }
.table tr { break-inside: avoid; page-break-inside: avoid; }
.summary { display: grid; grid-template-columns: repeat(4,1fr); gap: 6px; margin-top: 10px; break-inside: avoid; page-break-inside: avoid; }
.summary div { border: .7pt solid #000; padding: 5px 7px; }
.summary strong, .summary span { display: block; }
.summary span { margin-top: 4px; }
@media print {
  html, body { margin: 0; padding: 0; background: #fff; }
  .print-canvas { min-height: auto; padding: 0; background: #fff; }
  .print-page { width: auto; min-height: auto; max-width: none; margin: 0; padding: 0; }
}
</style>
</head>
<body>
<div class="print-canvas"><main class="print-page">
<div class="head">
  @if(!empty($companyProfile['logo']))<img src="{{ $companyProfile['logo'] }}" alt="Company logo">@endif
  <div class="company-info">
    <strong class="company-name">{{ $companyProfile['name'] ?? 'Easy Ticket' }}</strong>
    @if(!empty($companyProfile['subtitle']))<span class="company-line">{{ $companyProfile['subtitle'] }}</span>@endif
    @if(!empty($companyProfile['address']))<span class="company-line">{{ $companyProfile['address'] }}</span>@endif
    @if(!empty($companyProfile['phone']) || !empty($companyProfile['email']) || !empty($companyProfile['website']))<span class="company-line">{{ implode(' · ', array_filter([$companyProfile['phone'] ?? '', $companyProfile['email'] ?? '', $companyProfile['website'] ?? ''])) }}</span>@endif
    <h1>{{ $filters['mode']==='vendor' ? 'Vendor Account Statement' : 'Customer Account Statement' }}</h1>
  </div>
</div>
@if($filters['party_id'] > 0)
<div class="meta"><span>{{ ucfirst($filters['mode']) }}: {{ $filters['party_name'] }}</span><span>Period: {{ $filters['from'] }} to {{ $filters['to'] }}</span><span>Generated: {{ now()->format('Y-m-d H:i') }}</span></div>
<table class="table"><colgroup><col><col><col><col><col><col><col></colgroup><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Particulars</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
<tbody>@foreach($rows as $row)
<tr><td>{{ $row['date'] }}</td><td>{{ $row['type'] }}</td><td>{{ $row['reference'] }}</td><td>{{ $row['particulars'] }}</td><td>{{ number_format($row['debit'],2) }}</td><td>{{ number_format($row['credit'],2) }}</td><td>{{ number_format(abs($row['balance']),2) }} {{ $row['balance'] < 0 ? 'Cr' : 'Dr' }}</td></tr>
@endforeach</tbody></table>
<div class="summary"><div><strong>Opening</strong><span>{{ number_format(abs($opening),2) }}</span></div><div><strong>Total Debit</strong><span>{{ number_format($total_debit,2) }}</span></div><div><strong>Total Credit</strong><span>{{ number_format($total_credit,2) }}</span></div><div><strong>Closing</strong><span>{{ number_format(abs($closing),2) }} {{ $closing_side }}</span></div></div>
@endif
</main></div>
</body>
</html>
