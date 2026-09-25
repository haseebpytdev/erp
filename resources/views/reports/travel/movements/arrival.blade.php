@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Arrival Report')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.et-arrival-report{width:100%;max-width:none;min-width:0;overflow-x:hidden;color:#17243a}.et-arrival-report *{box-sizing:border-box}.et-arrival-card{background:#fff;border:1px solid #dfe7f0;border-radius:8px;padding:14px;margin-bottom:12px}.et-arrival-title{font-size:20px;font-weight:800;margin:3px 0}.et-arrival-sub{font-size:11px;color:#718096}.et-arrival-tabs{display:flex;gap:7px;flex-wrap:wrap;align-items:center;overflow-x:auto;flex-wrap:nowrap}.et-arrival-tabs a{white-space:nowrap;padding:7px 10px;border:1px solid #d7e1ec;border-radius:6px;color:#315273;text-decoration:none;font-size:10px;font-weight:700}.et-arrival-tabs a.active{background:#1769d2;border-color:#1769d2;color:#fff}.et-arrival-scroll{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}.et-arrival-table{width:100%;min-width:1650px;border-collapse:collapse;font-size:10px}.et-arrival-table th,.et-arrival-table td{padding:7px 8px;border-bottom:1px solid #e8eef5;text-align:left;white-space:nowrap}.et-arrival-table th{background:#f4f7fb;font-size:9px}.et-arrival-badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef5ff;color:#1769d2;font-weight:700}@media print{.sidebar,.navbar,.print-hide,.et-arrival-tabs{display:none!important}.et-arrival-report{overflow:visible}.et-arrival-scroll{overflow:visible}.et-arrival-table{min-width:0;font-size:9px}}
</style>
<style>
.et-arrival-report{padding-top:18px}
.et-report-filter-one-line .et-report-filter-form{display:flex;flex-wrap:nowrap;gap:8px;align-items:end}
.et-report-filter-one-line .et-report-filter-grid{display:flex;flex:1 1 auto;gap:8px;min-width:0}
.et-report-filter-one-line .et-report-filter-field{flex:0 1 120px}
.et-report-filter-one-line .et-report-filter-actions{grid-column:auto;flex:0 0 auto;white-space:nowrap}
@media(max-width:1050px){.et-report-filter-one-line .et-report-filter-form{flex-wrap:wrap}.et-report-filter-one-line .et-report-filter-grid{flex-basis:100%}.et-report-filter-one-line .et-report-filter-actions{margin-left:auto}}
@media(max-width:760px){.et-report-filter-one-line .et-report-filter-form,.et-report-filter-one-line .et-report-filter-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.et-report-filter-one-line .et-report-filter-actions{grid-column:1/-1;margin-left:0}}
@media(max-width:430px){.et-report-filter-one-line .et-report-filter-form,.et-report-filter-one-line .et-report-filter-grid{grid-template-columns:1fr}}
</style>
<div class="et-arrival-report" data-et-arrival-report="1">
 <div class="et-arrival-card"><div class="et-arrival-sub">Travel Reports / Movement Reports / Arrival</div><div class="et-arrival-title">Arrival Report</div><div class="et-arrival-sub">Operational inbound arrival movement reporting. No financial data.</div></div>
 @include('reports.travel.partials.movement-tabs',['movements'=>$movements,'report'=>$report])
 {{-- movementKey==='arrival'?'active':'' and $movementLabel are retained in the shared tab contract. --}}
 @include('reports.travel.partials.filter-form', [
     'filters' => [
         ['key' => 'from', 'label' => 'From Date', 'type' => 'date'],
         ['key' => 'to', 'label' => 'To Date', 'type' => 'date'],
         ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
         ['key' => 'branch', 'label' => 'Branch ID', 'type' => 'text'],
         ['key' => 'customer', 'label' => 'Customer ID', 'type' => 'text'],
         ['key' => 'destination', 'label' => 'Arrival Airport', 'type' => 'select', 'empty_label' => 'All Airports', 'options' => $airportOptions ?? []],
     ],
     'modifier' => 'et-report-filter-one-line',
     'actions' => [
         ['label' => 'Apply Filters', 'type' => 'submit'],
         ['label' => 'Reset', 'type' => 'link', 'class' => 'secondary', 'href' => route('travel-reports.group-umrah.arrival')],
         ['label' => 'Print', 'type' => 'button', 'class' => 'secondary', 'onclick' => 'window.print()'],
         ['label' => 'Export CSV', 'type' => 'link', 'class' => 'secondary', 'href' => route('travel-reports.group-umrah.arrival.export', request()->query())],
     ],
 ])
 <div class="et-arrival-card"><strong>Arrival Results</strong> <span class="et-arrival-sub">{{ method_exists($rows,'total')?$rows->total():0 }} records</span></div>
 <div class="et-arrival-card"><div class="et-arrival-scroll"><table class="et-arrival-table"><thead><tr><th>Arrival Date</th><th>Arrival Time</th><th>Booking No.</th><th>Customer / Group</th><th>Total Pax</th><th>Adult</th><th>Child</th><th>Infant</th><th>Flight</th><th>Sector</th><th>Makkah Hotel</th><th>Transport</th><th>Saudi Company</th><th>Pakistani IATA</th><th>Status</th><th>Branch</th><th>Agent / Salesperson</th><th>View</th></tr></thead><tbody>@forelse($rows as $row)<tr><td>{{ $row['arrival_date']??'—' }}</td><td>{{ $row['arrival_time']??'—' }}</td><td>{{ $row['booking_no']??'—' }}</td><td>{{ $row['customer']??'—' }}</td><td>{{ $row['total_pax']??'—' }}</td><td>{{ $row['adult']??'—' }}</td><td>{{ $row['child']??'—' }}</td><td>{{ $row['infant']??'—' }}</td><td>{{ $row['flight']??'—' }}</td><td>{{ $row['sector']??'—' }}</td><td>{{ $row['makkah_hotel']??'—' }}</td><td>{{ $row['transport']??'—' }}</td><td>{{ $row['saudi_company']??'—' }}</td><td>{{ $row['pakistani_iata']??'—' }}</td><td><span class="et-arrival-badge">{{ $row['status']??'—' }}</span></td><td>{{ $row['branch']??'—' }}</td><td>{{ $row['agent_salesperson']??'—' }}</td><td>@if(($row['action']??'—')!=='—')<a href="{{ $row['action'] }}">View</a>@else—@endif</td></tr>@empty<tr><td colspan="18">No arrival records match the selected filters.</td></tr>@endforelse</tbody></table></div>@if(is_object($rows) && method_exists($rows,'links')){{ $rows->links() }}@endif</div>
</div>
@endsection
