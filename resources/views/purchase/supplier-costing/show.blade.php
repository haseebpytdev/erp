@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Supplier Costing '.$row->costing_no)
@section($layoutMeta['content_section'] ?? 'content')
<style>
.scs{width:100%;max-width:1500px;min-width:0;margin:0 auto;color:#17243a}.scs *{box-sizing:border-box}.scs-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;min-width:0;flex-wrap:wrap}.scs-head>div:first-child{min-width:0;overflow-wrap:anywhere}.scs-kicker{font-size:11px;color:#0d5bd7;font-weight:800;text-transform:uppercase}.scs-actions{display:flex;gap:8px;flex:0 1 auto;flex-wrap:wrap;min-width:0}.scs-actions form{display:flex;max-width:100%}.scs-btn{padding:9px 14px;border:1px solid #ccd6e2;border-radius:8px;background:#fff;text-decoration:none;max-width:100%;white-space:normal}.scs-primary{background:#0d5bd7;color:#fff;border-color:#0d5bd7}.scs-card{background:#fff;border:1px solid #dfe6ef;border-radius:12px;padding:16px;margin-top:14px;max-width:100%;min-width:0;overflow:hidden}.scs-card h4{overflow-wrap:anywhere}.scs-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:14px;min-width:0}.scs-kpi{background:#fff;border:1px solid #dfe6ef;border-radius:12px;padding:15px;min-width:0}.scs-kpi small,.scs-kpi strong{overflow-wrap:anywhere}.scs-kpi small{font-weight:800;color:#68778a}.scs-kpi strong{display:block;font-size:18px;margin-top:7px}.scs-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,320px);gap:14px;min-width:0;align-items:start}.scs-grid>div,.scs-grid>aside{min-width:0;max-width:100%}.scs-table-wrap{width:100%;max-width:100%;min-width:0;overflow-x:visible}.scs-table{width:100%;max-width:100%;min-width:0;border-collapse:collapse;table-layout:fixed}.scs-table th,.scs-table td{padding:8px 7px;border-bottom:1px solid #edf1f5;text-align:left}.scs-table th{font-size:11px;background:#f8fafc}.scs-table td{overflow-wrap:anywhere;word-break:break-word}.scs-num{text-align:right!important;white-space:nowrap;word-break:normal}.scs-source-table th:nth-child(1),.scs-source-table td:nth-child(1){width:4%}.scs-source-table th:nth-child(2),.scs-source-table td:nth-child(2){width:11%}.scs-source-table th:nth-child(3),.scs-source-table td:nth-child(3){width:18%}.scs-source-table th:nth-child(4),.scs-source-table td:nth-child(4){width:25%}.scs-source-table th:nth-child(5),.scs-source-table td:nth-child(5){width:12%}.scs-source-table th:nth-child(6),.scs-source-table td:nth-child(6){width:8%}.scs-source-table th:nth-child(7),.scs-source-table td:nth-child(7){width:9%}.scs-source-table th:nth-child(8),.scs-source-table td:nth-child(8){width:13%}.scs-accounting-table th:nth-child(1),.scs-accounting-table td:nth-child(1){width:45%}.scs-accounting-table th:nth-child(2),.scs-accounting-table td:nth-child(2){width:25%}.scs-accounting-table th:nth-child(3),.scs-accounting-table td:nth-child(3),.scs-accounting-table th:nth-child(4),.scs-accounting-table td:nth-child(4){width:15%}.scs-wf{display:flex;gap:4px;align-items:center;flex-wrap:wrap;margin-top:10px}.scs-wf span{padding:8px 10px;border-radius:7px;background:#f1f4f8;font-size:12px;white-space:nowrap}.scs-wf .on{background:#e7f0ff;color:#0d5bd7;font-weight:800}@media(max-width:1120px){.scs-grid{grid-template-columns:minmax(0,1fr)}.scs-grid>aside{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.scs-grid>aside .scs-card{margin-top:0}}@media(max-width:760px){.scs-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.scs-grid>aside{grid-template-columns:minmax(0,1fr)}.scs-actions{width:100%}.scs-actions form,.scs-actions .scs-btn{flex:1 1 auto}}@media(max-width:620px){.scs-head{display:block}.scs-actions{margin-top:10px}.scs-kpis{grid-template-columns:minmax(0,1fr)}.scs-table-wrap{overflow-x:auto}}
</style>
<div class="scs" data-et-supplier-costing-show="{{ config('et_erp_release.release', 'ERP-11.3') }}">
  <div class="scs-head">
    <div>
      <div class="scs-kicker">Supplier Costing</div>
      <h2 style="margin:4px 0">{{ $row->costing_no }}</h2>
      <div>
        @if($bookingUrl)<a href="{{ $bookingUrl }}">Booking #{{ $row->booking_id }}</a>@else Booking {{ $row->booking_id ? '#'.$row->booking_id : '—' }}@endif
        ·
        @if($supplierLedgerUrl)<a href="{{ $supplierLedgerUrl }}">{{ $row->supplier_name }}</a>@else{{ $row->supplier_name ?: 'Supplier not selected' }}@endif
      </div>
    </div>
    <div class="scs-actions">
      @if($row->status==='draft')
        <a class="scs-btn" href="{{ route('purchase.supplier-costing.edit',$row->id) }}">Edit Draft</a>
        <form method="post" action="{{ route('purchase.supplier-costing.workflow',[$row->id,'submit']) }}">@csrf<button class="scs-btn scs-primary">Submit for Approval</button></form>
      @elseif($row->status==='pending_approval' && $canApprove)
        <form method="post" action="{{ route('purchase.supplier-costing.workflow',[$row->id,'approve']) }}">@csrf<button class="scs-btn scs-primary">Approve</button></form>
      @elseif($row->status==='approved' && $canApprove)
        <form method="post" action="{{ route('purchase.supplier-costing.workflow',[$row->id,'post']) }}">@csrf<button class="scs-btn scs-primary">Post to Payables</button></form>
      @endif
    </div>
  </div>
  @if(session('success'))<div class="alert alert-success" style="margin-top:12px">{{ session('success') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger" style="margin-top:12px">{{ $errors->first() }}</div>@endif

  <div class="scs-kpis">
    <div class="scs-kpi"><small>TOTAL SUPPLIER COST</small><strong>{{ $row->currency_code }} {{ number_format($row->total_cost,2) }}</strong></div>
    <div class="scs-kpi"><small>SUPPLIER</small><strong>{{ $row->supplier_name ?: '—' }}</strong></div>
    <div class="scs-kpi"><small>PRODUCTS</small><strong>{{ $row->service_type }}</strong></div>
    <div class="scs-kpi"><small>STATUS</small><strong>{{ ucwords(str_replace('_',' ',$row->status)) }}</strong></div>
  </div>

  <div class="scs-grid"><div>
    <section class="scs-card">
      <h4>Product / Source Lines</h4>
      <div class="scs-table-wrap"><table class="scs-table scs-source-table"><thead><tr><th>#</th><th>Product</th><th>Source Identity</th><th>Reference / Detail</th><th class="scs-num">Base Cost</th><th class="scs-num">Tax</th><th class="scs-num">Other</th><th class="scs-num">Total</th></tr></thead><tbody>
      @foreach($lines as $line)
        @php($source=$sourceLinks[$line->id] ?? null)
        <tr><td>{{ $line->line_no }}</td><td>{{ $line->service_type }}</td><td>{{ $source ? $source->source_key : 'Legacy line' }}</td><td><strong>{{ $line->description }}</strong><br><small>{{ $line->passenger_name ?: '—' }}</small></td><td class="scs-num">{{ number_format($line->base_cost,2) }}</td><td class="scs-num">{{ number_format($line->tax_amount,2) }}</td><td class="scs-num">{{ number_format($line->other_charges,2) }}</td><td class="scs-num"><strong>{{ number_format($line->total_cost,2) }}</strong></td></tr>
      @endforeach
      </tbody></table></div>
    </section>

    <section class="scs-card">
      <h4>{{ $postings->isEmpty() ? 'Accounting Preview' : 'Actual Posted Journal' }}</h4>
      <p style="color:#66758a">Debit product Cost Account(s); credit Vendor Payable for this document's single supplier.</p>
      @if($previewError)<div class="alert alert-warning">Accounting preview is unavailable: {{ $previewError }}</div>@endif
      <div class="scs-table-wrap"><table class="scs-table scs-accounting-table"><thead><tr><th>Account</th><th>Party</th><th class="scs-num">Debit</th><th class="scs-num">Credit</th></tr></thead><tbody>
      @foreach($accountingRows as $posting)
        <tr><td>@if(!empty($accountLedgerUrls[$posting->account_code]))<a href="{{ $accountLedgerUrls[$posting->account_code] }}">{{ $posting->account_code }} · {{ $posting->account_name }}</a>@else{{ $posting->account_code }} · {{ $posting->account_name }}@endif</td><td>@if(in_array(strtolower((string) $posting->party_type), ['supplier','vendor'], true) && (int) $posting->party_id === (int) $row->supplier_id && trim((string) $row->supplier_name) !== ''){{ $row->supplier_name }}@elseif($posting->party_type){{ ucfirst($posting->party_type).' #'.$posting->party_id }}@else—@endif</td><td class="scs-num">{{ number_format($posting->debit,2) }}</td><td class="scs-num">{{ number_format($posting->credit,2) }}</td></tr>
      @endforeach
      </tbody><tfoot><tr><th colspan="2">Total</th><th class="scs-num">{{ number_format($accountingRows->sum('debit'),2) }}</th><th class="scs-num">{{ number_format($accountingRows->sum('credit'),2) }}</th></tr></tfoot></table></div>
      @if($row->posting_reference)<div style="margin-top:9px"><strong>Posting Reference:</strong> @if($journalUrl)<a href="{{ $journalUrl }}">{{ $row->posting_reference }}</a>@else{{ $row->posting_reference }}@endif</div>@endif
    </section>
  </div><aside>
    <section class="scs-card"><h4>Document Information</h4><div><strong>Cost Date:</strong> {{ $row->cost_date }}</div><div><strong>Due Date:</strong> {{ $row->due_date ?: '—' }}</div><div><strong>Supplier Invoice:</strong> {{ $row->supplier_invoice_no ?: '—' }}</div><div><strong>Supplier Ref:</strong> {{ $row->supplier_reference ?: '—' }}</div><div><strong>Currency / FX:</strong> {{ $row->currency_code }} / {{ $row->exchange_rate }}</div><div><strong>Payment Terms:</strong> {{ $row->payment_terms ?: '—' }}</div></section>
    <section class="scs-card"><h4>Status & Workflow</h4><div class="scs-wf">@foreach(['draft'=>'Draft','pending_approval'=>'Pending Approval','approved'=>'Approved','posted'=>'Posted'] as $key=>$label)<span class="{{ $row->status===$key ? 'on':'' }}">{{ $label }}</span>@endforeach</div></section>
    <section class="scs-card"><h4>Audit Trail</h4>@forelse($activities as $activity)<div style="padding:9px 0;border-bottom:1px solid #edf1f5"><strong>{{ strtoupper($activity->action) }}</strong><div style="font-size:12px;color:#69788b">{{ $activity->user_name ?: 'System' }} · {{ $activity->created_at }}</div><div>{{ $activity->notes }}</div></div>@empty<div>No activity yet.</div>@endforelse</section>
  </aside></div>
</div>
@endsection
