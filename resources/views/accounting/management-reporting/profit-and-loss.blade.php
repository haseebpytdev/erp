@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Profit & Loss Statement')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.mr{max-width:1250px;margin:0 auto;color:#17243a}.mr *{box-sizing:border-box}.mr-kicker{font-size:10px;font-weight:850;color:#1769d2;text-transform:uppercase}.mr h2{margin:3px 0}.mr-sub{font-size:11px;color:#6d7c91}.mr-nav{display:flex;gap:5px;flex-wrap:wrap;margin:14px 0}.mr-nav a{padding:8px 11px;border:1px solid #d9e2ed;border-radius:7px;background:#fff;color:#34455c;text-decoration:none;font-size:10px;font-weight:800}.mr-nav a.active{background:#1769d2;color:#fff}.mr-filter{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:12px;border:1px solid #dfe7f1;border-radius:10px;background:#fff}.mr-field label{display:block;margin-bottom:4px;font-size:9px;font-weight:850;text-transform:uppercase;color:#68788d}.mr-field input,.mr-field select{padding:8px;border:1px solid #ced9e6;border-radius:6px}.mr-btn{padding:9px 13px;border:0;border-radius:6px;background:#1769d2;color:#fff;font-weight:800}.mr-card{margin-top:12px;border:1px solid #dfe7f1;border-radius:11px;background:#fff;overflow:hidden}.mr-card h3{padding:11px 14px;margin:0;border-bottom:1px solid #e8edf3;font-size:13px}.mr table{width:100%;border-collapse:collapse}.mr th,.mr td{padding:9px 12px;border-bottom:1px solid #edf1f5;font-size:10px}.mr th{text-align:left;background:#f8fafc;font-size:8px;text-transform:uppercase;color:#718096}.mr .num{text-align:right}.mr .total{font-weight:900;background:#f6f9fd}.mr .grand{font-size:12px;background:#eaf2ff}.mr .good{color:#128154}.mr .bad{color:#c13d3d}@media(max-width:700px){.mr-table{overflow:auto}.mr table{min-width:720px}}@media print{.mr-nav,.mr-filter{display:none}.mr{max-width:none}.mr-card{break-inside:avoid}}
</style>
<main class="mr" data-et-profit-loss="{{ config('et_erp_release.release','ERP-11.3') }}">
  <div class="mr-kicker">Accounting · {{ config('et_erp_release.release','ERP-11.3') }}</div><h2>Profit &amp; Loss Statement</h2><div class="mr-sub">Accounting / Posted Profit · {{ $filters['from'] }} — {{ $filters['to'] }}</div>
  @include('accounting.management-reporting._navigation')
  <form class="mr-filter" method="get"><div class="mr-field"><label>From</label><input type="date" name="from" value="{{ $filters['from'] }}"></div><div class="mr-field"><label>To</label><input type="date" name="to" value="{{ $filters['to'] }}"></div>@if($filters['branch_supported'])<div class="mr-field"><label>Branch</label><select name="branch_id"><option value="">All Branches</option>@foreach($filters['branches'] as $branch)<option value="{{ $branch['id'] }}" @selected((int)$filters['branch_id']===(int)$branch['id'])>{{ $branch['name'] }}</option>@endforeach</select></div>@endif<button class="mr-btn">Apply</button><button class="mr-btn" type="button" onclick="window.print()">Print</button></form>
  <section class="mr-card"><h3>Selected Period with Previous Comparable Period</h3><div class="mr-table"><table><thead><tr><th>Account</th><th class="num">Current Period</th><th class="num">Previous Period</th><th class="num">Variance</th><th class="num">Variance %</th></tr></thead><tbody>
  @foreach($sectionRows as $section)
    <tr class="total"><td colspan="5">{{ strtoupper($section['title']) }}</td></tr>
    @forelse($section['lines'] as $line)
      <tr><td>@if($line['account_url'])<a href="{{ $line['account_url'] }}">{{ $line['code'] }} · {{ $line['name'] }}</a>@else{{ $line['code'] }} · {{ $line['name'] }}@endif</td><td class="num">{{ $line['current_display'] }}</td><td class="num">{{ $line['previous_display'] }}</td><td class="num">{{ $line['variance_display'] }}</td><td class="num">{{ $line['variance_percent_display'] }}</td></tr>
    @empty
      <tr><td colspan="5">No posted activity in this section.</td></tr>
    @endforelse
  @endforeach
  @foreach($summaryRows as $summary)
    <tr class="{{ $summary['row_class'] }}"><td>{{ $summary['label'] }}</td><td class="num {{ $summary['current_class'] }}">{{ $summary['current_display'] }}</td><td class="num">{{ $summary['previous_display'] }}</td><td class="num">{{ $summary['variance_display'] }}</td><td class="num">{{ $summary['variance_percent_display'] }}</td></tr>
  @endforeach
  </tbody></table></div></section>
</main>
@endsection
