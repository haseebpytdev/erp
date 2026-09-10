@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title',$row->voucher_no)
@section($layoutMeta['content_section'] ?? 'content')
<style>
.cvs27{max-width:1500px;margin:0 auto;color:#17243a}.cvs27 *{box-sizing:border-box}.cvs27-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px;flex-wrap:wrap}
.cvs27-kicker{font-size:9.5px;font-weight:900;color:#0964df;text-transform:uppercase}.cvs27-title{font-size:26px;margin:4px 0}.cvs27-sub{font-size:11px;color:#718197}.cvs27-actions{display:flex;gap:8px;flex-wrap:wrap}
.cvs27-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border:1px solid #cad5e1;border-radius:7px;background:#fff;color:#26384e;text-decoration:none!important;font-size:10.5px;font-weight:850}.cvs27-primary{background:#0964df;border-color:#0964df;color:#fff!important}.cvs27-success{background:#147d64;border-color:#147d64;color:#fff!important}.cvs27-danger{border-color:#e2a7ad;color:#a12635!important}
.cvs27-card{background:#fff;border:1px solid #dfe6ef;border-radius:10px;margin-bottom:13px;overflow:hidden}.cvs27-card-head{padding:11px 13px;border-bottom:1px solid #e9eef4;font-weight:900;font-size:13px}.cvs27-body{padding:13px}
.cvs27-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cvs27-item{font-size:11px}.cvs27-item b{display:block;color:#68798e;font-size:8.8px;text-transform:uppercase;margin-bottom:3px}.cvs27-item strong{font-size:13px}.cvs27-badge{display:inline-flex;padding:4px 7px;background:#eef3f8;border-radius:999px;font-size:8.5px;font-weight:850;text-transform:capitalize}
.cvs27-table{width:100%;border-collapse:collapse;table-layout:fixed}.cvs27-table th,.cvs27-table td{padding:8px;border-bottom:1px solid #edf1f6;text-align:left;font-size:10px;overflow-wrap:anywhere}.cvs27-table th{background:#f8fafc;color:#526174;font-size:8.5px;text-transform:uppercase}
.cvs27-post{display:grid;grid-template-columns:1.35fr 1.55fr .65fr .65fr}.cvs27-post>div{padding:8px;border-bottom:1px solid #edf1f6;font-size:10px}.cvs27-reverse{display:flex;gap:8px}.cvs27-reverse input{flex:1;border:1px solid #d5dde7;border-radius:7px;padding:8px 9px}
@media(max-width:900px){.cvs27-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.cvs27-grid{grid-template-columns:1fr}.cvs27-actions{width:100%}.cvs27-btn{flex:1}.cvs27-reverse{display:block}}
</style>
<div class="cvs27" data-et-cash-voucher-show="ERP-11.3.27">
  <div class="cvs27-head">
    <div>
      <div class="cvs27-kicker">{{ strtoupper($definition['label']) }} · ERP-11.3.27</div>
      <h1 class="cvs27-title">{{ $row->voucher_no }}</h1>
      <div class="cvs27-sub">Status: <span class="cvs27-badge">{{ str_replace('_',' ',$row->status) }}</span></div>
    </div>
    <div class="cvs27-actions">
      <a class="cvs27-btn" href="{{ route('accounting.cash-vouchers.index') }}">Back to Register</a>
      <a class="cvs27-btn" target="_blank" href="{{ route('accounting.cash-vouchers.print',$row->id) }}">Print Voucher</a>
      @if($row->status==='draft')
        <a class="cvs27-btn" href="{{ route('accounting.cash-vouchers.edit',$row->id) }}">Edit Draft</a>
        <form method="post" action="{{ route('accounting.cash-vouchers.workflow',[$row->id,'submit']) }}">@csrf<button class="cvs27-btn cvs27-primary">Submit for Approval</button></form>
      @elseif($row->status==='pending_approval' && $canApprove)
        <form method="post" action="{{ route('accounting.cash-vouchers.workflow',[$row->id,'approve']) }}">@csrf<button class="cvs27-btn cvs27-primary">Approve</button></form>
      @elseif($row->status==='approved' && $canApprove)
        <form method="post" action="{{ route('accounting.cash-vouchers.workflow',[$row->id,'post']) }}">@csrf<button class="cvs27-btn cvs27-success">Post Voucher</button></form>
      @endif
    </div>
  </div>

  @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

  <section class="cvs27-card">
    <div class="cvs27-card-head">Voucher Information</div>
    <div class="cvs27-body">
      <div class="cvs27-grid">
        <div class="cvs27-item"><b>Type</b>{{ $definition['label'] }}</div>
        <div class="cvs27-item"><b>Voucher Date</b>{{ \Carbon\Carbon::parse($row->voucher_date)->format('d M Y') }}</div>
        <div class="cvs27-item"><b>{{ $row->voucher_type==='expense' ? 'Payee' : ($definition['party_type']==='supplier'?'Supplier':'Customer / Agent') }}</b>{{ $row->party_name ?: '—' }}</div>
        <div class="cvs27-item"><b>Booking</b>{{ $row->booking_id ? 'Booking #'.$row->booking_id : '—' }}</div>
        <div class="cvs27-item"><b>Amount</b><strong>{{ $row->currency_code }} {{ number_format($row->amount,2) }}</strong></div>
        @if($row->voucher_type!=='expense')
          <div class="cvs27-item"><b>Allocated</b>{{ $row->currency_code }} {{ number_format($row->allocated_amount,2) }}</div>
          <div class="cvs27-item"><b>Advance / Unallocated</b>{{ $row->currency_code }} {{ number_format($row->unallocated_amount,2) }}</div>
        @endif
        <div class="cvs27-item"><b>FX Rate</b>{{ number_format($row->exchange_rate,8) }}</div>
        <div class="cvs27-item"><b>Payment Method</b>{{ $row->payment_method }}</div>
        <div class="cvs27-item"><b>Cash / Bank Account</b>{{ $row->cash_bank_account_code }} · {{ $row->cash_bank_account_name }}</div>
        <div class="cvs27-item"><b>Bank Name</b>{{ $row->bank_name ?: '—' }}</div>
        <div class="cvs27-item"><b>Value / Bank Date</b>{{ $row->value_date ? \Carbon\Carbon::parse($row->value_date)->format('d M Y') : '—' }}</div>
        <div class="cvs27-item"><b>Transaction / Bank Ref</b>{{ $row->transaction_reference ?: '—' }}</div>
        <div class="cvs27-item"><b>Cheque / Instrument No.</b>{{ $row->instrument_no ?: '—' }}</div>
        <div class="cvs27-item"><b>Posting Reference</b>{{ $row->posting_reference ?: '—' }}</div>
        <div class="cvs27-item"><b>Payment Proof</b>@if($row->payment_proof_path)<a href="{{ route('accounting.cash-vouchers.proof',$row->id) }}">{{ $row->payment_proof_original_name ?: 'Open proof' }}</a>@else—@endif</div>
        <div class="cvs27-item" style="grid-column:1/-1"><b>Narration / Remarks</b>{{ $row->narration ?: '—' }}</div>
      </div>
    </div>
  </section>

  @if($row->voucher_type==='expense')
    <section class="cvs27-card">
      <div class="cvs27-card-head">Expense Lines</div>
      <table class="cvs27-table">
        <thead><tr><th>#</th><th>Expense Account</th><th>Description</th><th>Amount</th><th>Base Amount</th></tr></thead>
        <tbody>
          @foreach($expenseLines as $line)
            <tr><td>{{ $line->line_no }}</td><td><strong>{{ $line->expense_account_code }} · {{ $line->expense_account_name }}</strong></td><td>{{ $line->description ?: '—' }}</td><td>{{ $line->currency_code }} {{ number_format($line->amount,2) }}</td><td>PKR {{ number_format($line->base_amount,2) }}</td></tr>
          @endforeach
        </tbody>
        <tfoot><tr><th colspan="3">Total Expense</th><th>{{ $row->currency_code }} {{ number_format($expenseLines->sum('amount'),2) }}</th><th>PKR {{ number_format($expenseLines->sum('base_amount'),2) }}</th></tr></tfoot>
      </table>
    </section>
  @endif

  @if($allocations->isNotEmpty())
    <section class="cvs27-card">
      <div class="cvs27-card-head">Document Allocations</div>
      <table class="cvs27-table">
        <thead><tr><th>Target</th><th>Booking</th><th>Target Total</th><th>Outstanding Before</th><th>Allocated</th><th>Notes</th></tr></thead>
        <tbody>@foreach($allocations as $a)<tr><td><strong>{{ $a->target_number ?: ucfirst(str_replace('_',' ',$a->target_type)).' #'.$a->target_id }}</strong></td><td>{{ $a->booking_id ? 'Booking #'.$a->booking_id : '—' }}</td><td>{{ $a->currency_code }} {{ number_format($a->target_total_snapshot,2) }}</td><td>{{ $a->currency_code }} {{ number_format($a->outstanding_before_snapshot,2) }}</td><td><strong>{{ $a->currency_code }} {{ number_format($a->amount,2) }}</strong></td><td>{{ $a->notes ?: '—' }}</td></tr>@endforeach</tbody>
      </table>
    </section>
  @endif

  @if($row->voucher_type==='expense' && $postings->isEmpty())
    <section class="cvs27-card">
      <div class="cvs27-card-head">Accounting Preview</div>
      <div class="cvs27-post">
        <div><b>Account</b></div><div><b>Description</b></div><div style="text-align:right"><b>Debit (Base)</b></div><div style="text-align:right"><b>Credit (Base)</b></div>
        @foreach($expenseLines as $line)
          <div>{{ $line->expense_account_code }} · {{ $line->expense_account_name }}</div><div>{{ $line->description ?: $row->narration }}</div><div style="text-align:right">{{ number_format($line->base_amount,2) }}</div><div style="text-align:right">0.00</div>
        @endforeach
        <div>{{ $row->cash_bank_account_code }} · {{ $row->cash_bank_account_name }}</div><div>{{ $row->narration ?: 'Direct expense payment' }}</div><div style="text-align:right">0.00</div><div style="text-align:right">{{ number_format($expenseLines->sum('base_amount'),2) }}</div>
      </div>
    </section>
  @elseif($postings->isNotEmpty())
    <section class="cvs27-card">
      <div class="cvs27-card-head">Posted Journal · {{ $row->posting_reference ?: $row->reversal_reference }}</div>
      <div class="cvs27-post">
        <div><b>Account</b></div><div><b>Party / Narration</b></div><div style="text-align:right"><b>Debit</b></div><div style="text-align:right"><b>Credit</b></div>
        @foreach($postings as $p)
          <div>{{ $p->account_code }} · {{ $p->account_name }}@if($p->entry_type==='reversal') <span class="cvs27-badge">Reversal</span>@endif</div>
          <div>{{ $p->party_type ? ucfirst($p->party_type).' #'.$p->party_id.' · ' : '' }}{{ $p->narration }}</div>
          <div style="text-align:right">{{ number_format($p->debit,2) }}</div>
          <div style="text-align:right">{{ number_format($p->credit,2) }}</div>
        @endforeach
      </div>
    </section>
  @endif

  @if($row->status==='posted' && $canApprove)
    <section class="cvs27-card">
      <div class="cvs27-card-head" style="color:#9c2735">Controlled Reversal</div>
      <div class="cvs27-body">
        <div style="font-size:10.5px;color:#6d7888;margin-bottom:8px">Posted vouchers are never deleted. Reversal creates equal and opposite entries and releases allocations.</div>
        <form method="post" action="{{ route('accounting.cash-vouchers.reverse',$row->id) }}" class="cvs27-reverse">@csrf<input name="reason" required placeholder="Reason for reversal"><button class="cvs27-btn cvs27-danger">Reverse Posted Voucher</button></form>
      </div>
    </section>
  @endif

  @if($row->status==='reversed')
    <div class="cvs27-card"><div class="cvs27-body" style="background:#fffafa"><strong>Reversed:</strong> {{ $row->reversal_reference }} · {{ $row->reversal_reason }}</div></div>
  @endif

  <section class="cvs27-card">
    <div class="cvs27-card-head">Audit Trail</div>
    <table class="cvs27-table">
      <thead><tr><th>Time</th><th>Action</th><th>Status</th><th>User</th><th>Notes</th></tr></thead>
      <tbody>@foreach($activities as $a)<tr><td>{{ \Carbon\Carbon::parse($a->created_at)->format('d/m/Y H:i') }}</td><td>{{ ucfirst($a->action) }}</td><td>{{ $a->from_status ?: '—' }} → {{ $a->to_status ?: '—' }}</td><td>{{ $a->user_name ?: '—' }}</td><td>{{ $a->notes ?: '—' }}</td></tr>@endforeach</tbody>
    </table>
  </section>
</div>
@endsection
