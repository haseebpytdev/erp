@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Payments, Receipts, Expenses, Contra & Advances')
@section($layoutMeta['content_section'] ?? 'content')
@php
    $money = static fn($value) => 'PKR '.number_format((float) $value, 2);
    $allocatedValue = (float) ($summary->allocated ?? 0);
    $unallocatedValue = (float) ($summary->unallocated ?? 0);
    $allocationBase = max(0.01, $allocatedValue + $unallocatedValue);
    $allocatedPct = max(0, min(100, ($allocatedValue / $allocationBase) * 100));
    $activeMode = strtolower((string) request('mode',''));
@endphp
<style>
.et-fin{max-width:1500px;margin:0 auto;color:#17243a}
.et-fin *{box-sizing:border-box}
.et-fin-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:18px}
.et-fin-kicker{font-size:11px;font-weight:900;letter-spacing:.045em;color:#0961cf;text-transform:uppercase}
.et-fin-title{margin:5px 0 5px;font-size:28px;line-height:1.1;font-weight:850;color:#18243a}
.et-fin-sub{font-size:13px;color:#6a7b92}
.et-fin-head-links{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.et-fin-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:8px 13px;border:1px solid #ccd7e5;border-radius:8px;background:#fff;color:#223249;text-decoration:none!important;font-size:12px;font-weight:800;white-space:nowrap}
.et-fin-btn:hover{border-color:#9eb4cf}
.et-fin-btn.primary{background:#0a63d8;border-color:#0a63d8;color:#fff!important}
.et-fin-btn.success{background:#148364;border-color:#148364;color:#fff!important}
.et-fin-btn.soft{background:#f8fafc}
.et-fin-modes{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 10px}
.et-fin-mode{display:inline-flex;align-items:center;justify-content:center;min-width:118px;min-height:34px;padding:7px 13px;border:1px solid #cbd7e6;border-radius:8px;background:#fff;color:#2d4057;text-decoration:none!important;font-size:11px;font-weight:900}
.et-fin-mode:hover{border-color:#8daed4;background:#f8fbff}
.et-fin-mode.active{background:#0a63d8;border-color:#0a63d8;color:#fff!important;box-shadow:0 3px 8px rgba(10,99,216,.16)}
.et-fin-mode-all{min-width:205px}
.et-fin-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
.et-fin-actions .et-fin-btn{min-width:145px}
.et-fin-alert{border-radius:8px;padding:10px 12px;margin:0 0 14px;font-size:12px}
.et-fin-filter{display:grid;grid-template-columns:minmax(200px,1.7fr) minmax(130px,.75fr) minmax(130px,.75fr) minmax(140px,.8fr) minmax(140px,.8fr) minmax(160px,.95fr) auto auto;gap:10px;align-items:end;background:#fff;border:1px solid #dce5ef;border-radius:11px;padding:14px;box-shadow:0 5px 16px rgba(28,45,68,.05);margin-bottom:16px}
.et-field label{display:block;margin:0 0 5px;font-size:10px;font-weight:850;color:#5e6e83;text-transform:uppercase;letter-spacing:.035em}
.et-field input,.et-field select{width:100%;height:38px;padding:7px 11px;border:1px solid #d4dde8;border-radius:7px;background:#fff;color:#24354c;font-size:12px;outline:none}
.et-field input:focus,.et-field select:focus{border-color:#4e8fe2;box-shadow:0 0 0 2px rgba(42,118,218,.08)}
.et-card{background:#fff;border:1px solid #dce5ef;border-radius:11px;box-shadow:0 4px 14px rgba(28,45,68,.04);overflow:hidden}
.et-card + .et-card{margin-top:16px}
.et-card-head{min-height:47px;padding:12px 14px;border-bottom:1px solid #e8edf3;display:flex;align-items:center;justify-content:space-between;gap:12px}
.et-card-head strong{font-size:14px}
.et-card-head a{font-size:11px;font-weight:800;text-decoration:none;color:#0b63d8}
.et-summary-strip{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:12px;margin:0 0 16px}
.et-summary-tile{background:#fff;border:1px solid #dce5ef;border-radius:11px;box-shadow:0 4px 14px rgba(28,45,68,.04);padding:13px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:78px}
.et-summary-left{display:flex;align-items:center;gap:10px;min-width:0}
.et-summary-icon{width:30px;height:30px;border-radius:8px;background:#f1f6fc;display:inline-flex;align-items:center;justify-content:center;color:#0b63d8;font-weight:900;flex:0 0 auto}
.et-summary-meta{min-width:0}
.et-summary-meta strong{display:block;font-size:11px;color:#304258;margin:0 0 4px}
.et-summary-meta span{display:block;font-size:10px;color:#8190a3}
.et-summary-amount{font-size:14px;font-weight:900;text-align:right;white-space:nowrap}
.et-summary-amount.receipt{color:#13825c}.et-summary-amount.payment{color:#cc5050}.et-summary-amount.advance{color:#5d65cc}.et-summary-amount.pending{color:#a56c11}
.et-table{width:100%;border-collapse:collapse;table-layout:fixed}
.et-table th,.et-table td{border-bottom:1px solid #edf1f5;padding:10px 9px;text-align:left;vertical-align:middle;overflow-wrap:anywhere}
.et-table th{background:#f8fafc;font-size:9px;font-weight:900;color:#53647a;text-transform:uppercase;letter-spacing:.025em}
.et-table td{font-size:10.5px;color:#2b3b50}
.et-table tbody tr:hover{background:#fbfdff}
.et-table .voucher{color:#0961cf;font-weight:850;text-decoration:none}
.et-table .money{font-weight:800;white-space:nowrap}
.et-muted{color:#75859a;font-size:10px}
.et-type,.et-status{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 7px;font-size:8.5px;font-weight:900;line-height:1;text-transform:capitalize;white-space:nowrap}
.et-type.receipt{background:#e8f7ef;color:#128055}
.et-type.payment{background:#fdeced;color:#c74646}
.et-type.expense{background:#fff2d8;color:#9a6208}
.et-type.contra{background:#e7f4ff;color:#1769a8}
.et-type.advance{background:#f1ecff;color:#7448bd}
.et-status.posted{background:#e6f7ec;color:#158454}
.et-status.approved{background:#e8f1ff;color:#246dc8}
.et-status.pending_approval{background:#fff2d8;color:#a76a08}
.et-status.draft{background:#eef2f6;color:#5c6c80}
.et-status.reversed{background:#fdebed;color:#b43d49}
.et-action{display:inline-flex;width:26px;height:26px;align-items:center;justify-content:center;border:1px solid #e1e7ef;border-radius:6px;text-decoration:none!important;color:#3d4d61;font-size:15px;line-height:1}
.et-action-col{white-space:nowrap!important;text-align:center!important;overflow-wrap:normal!important;word-break:normal!important}
.et-action-head{white-space:nowrap!important;text-align:center!important;overflow-wrap:normal!important;word-break:normal!important}
.et-table-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:11px 13px;font-size:10px;color:#718096}
.et-pages{display:flex;align-items:center;gap:5px}
.et-pages a,.et-pages span{min-width:28px;height:28px;padding:0 8px;border:1px solid #e0e7ef;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;font-size:10px;color:#415269;background:#fff}
.et-pages .active{background:#0b63d8;border-color:#0b63d8;color:#fff;font-weight:900}
.et-empty{text-align:center!important;padding:34px 12px!important;color:#7a899d!important}
.et-bottom-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:stretch}
.et-bottom-row>.et-card{margin-top:0}
.et-workflow{padding:17px 13px 15px}
.et-flow-line{display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative}
.et-flow-line:before{content:"";position:absolute;top:14px;left:12%;right:12%;height:2px;background:#d7e3f3}
.et-step{text-align:center;position:relative;z-index:1}
.et-step-num{width:29px;height:29px;margin:0 auto 8px;border:1px solid #7fabdf;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#fff;color:#0b63d8;font-size:10px;font-weight:900}
.et-step:first-child .et-step-num{background:#0b63d8;color:#fff;border-color:#0b63d8}
.et-step strong{display:block;font-size:9px;color:#2d3d52}
.et-step span{display:block;margin-top:3px;font-size:8px;color:#8793a3;line-height:1.25}
.et-insight{padding:15px}
.et-insight-grid{display:grid;grid-template-columns:120px 1fr;gap:14px;align-items:center}
.et-donut{width:90px;height:90px;border-radius:50%;background:conic-gradient(#0b63d8 0 {{ number_format($allocatedPct,2,'.','') }}%,#48bf84 {{ number_format($allocatedPct,2,'.','') }}% 100%);position:relative;margin:auto}
.et-donut:after{content:"";position:absolute;inset:18px;border-radius:50%;background:#fff}
.et-insight-line{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #edf1f5;font-size:10px}
.et-insight-line:last-child{border-bottom:0}
.et-dot{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px}
.et-dot.blue{background:#0b63d8}.et-dot.green{background:#48bf84}
.et-insight-total{display:flex;justify-content:space-between;gap:10px;padding:12px 14px;border-top:1px solid #edf1f5;font-size:10px;font-weight:900}
@media(max-width:1280px){
  .et-fin-filter{grid-template-columns:repeat(4,minmax(0,1fr))}
  .et-summary-strip{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:980px){
  .et-fin-head{display:block}
  .et-fin-head-links{justify-content:flex-start;margin-top:12px}
  .et-fin-filter{grid-template-columns:repeat(2,minmax(0,1fr))}
  .et-summary-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
  .et-bottom-row{grid-template-columns:1fr}
}
@media(max-width:620px){
  .et-fin-title{font-size:23px}
  .et-fin-modes .et-fin-mode{flex:1 1 calc(33.333% - 8px);min-width:0}
  .et-fin-modes .et-fin-mode-all{flex:1 1 100%}
  .et-fin-actions .et-fin-btn{flex:1 1 calc(50% - 8px);min-width:0}
  .et-fin-filter{grid-template-columns:1fr}
  .et-summary-strip{grid-template-columns:1fr}
  .et-table th:nth-child(5),.et-table td:nth-child(5),.et-table th:nth-child(7),.et-table td:nth-child(7),.et-table th:nth-child(8),.et-table td:nth-child(8){display:none}
  .et-bottom-row .et-card{min-width:0}
}
</style>

<div class="et-fin" data-et-finance-runtime="{{ config('et_erp_release.release', 'ERP-11.3') }}">
  <div class="et-fin-head">
    <div>
      <div class="et-fin-kicker">Accounting</div>
      <h1 class="et-fin-title">Payments, Receipts, Expenses, Contra & Advances</h1>
      <div class="et-fin-sub">Cash/bank movement, internal transfers, direct expenses, document settlement and controlled advance application</div>
    </div>
    <div class="et-fin-head-links">
      @if(app('router')->has('accounting.chart-of-accounts.workspace'))
        <a class="et-fin-btn soft" href="{{ route('accounting.chart-of-accounts.workspace') }}">Chart of Accounts</a>
      @endif
      @if(app('router')->has('accounting.mappings.index'))
        <a class="et-fin-btn soft" href="{{ route('accounting.mappings.index') }}">Account Mappings</a>
      @endif
    </div>
  </div>

  <div class="et-fin-modes" aria-label="Accounting voucher mode">
    <a class="et-fin-mode et-fin-mode-all {{ $activeMode==='' ? 'active' : '' }}"
       href="{{ route('accounting.cash-vouchers.index') }}">All Cash / Bank Vouchers</a>
    @if(in_array('payment',$allowedTypes,true))
      <a class="et-fin-mode {{ $activeMode==='payments' ? 'active' : '' }}"
         href="{{ route('accounting.cash-vouchers.index',['mode'=>'payments']) }}">Payments</a>
    @endif
    @if(in_array('receipt',$allowedTypes,true))
      <a class="et-fin-mode {{ $activeMode==='receipts' ? 'active' : '' }}"
         href="{{ route('accounting.cash-vouchers.index',['mode'=>'receipts']) }}">Receipts</a>
    @endif
    @if(in_array('expense',$allowedTypes,true))
      <a class="et-fin-mode {{ $activeMode==='expenses' ? 'active' : '' }}"
         href="{{ route('accounting.cash-vouchers.index',['mode'=>'expenses']) }}">Expense Vouchers</a>
    @endif
    @if(in_array('contra',$allowedTypes,true))
      <a class="et-fin-mode {{ $activeMode==='contra' ? 'active' : '' }}"
         href="{{ route('accounting.cash-vouchers.index',['mode'=>'contra']) }}">Contra Vouchers</a>
    @endif
    @if(array_intersect(['customer_advance','supplier_advance'],$allowedTypes))
      <a class="et-fin-mode {{ $activeMode==='advances' ? 'active' : '' }}"
         href="{{ route('accounting.cash-vouchers.index',['mode'=>'advances']) }}">Advances</a>
    @endif
  </div>

  <div class="et-fin-actions">
    @if(in_array('receipt',$allowedTypes,true))
      <a class="et-fin-btn primary" href="{{ route('accounting.cash-vouchers.create',['type'=>'receipt']) }}">＋ Receipt Voucher</a>
    @endif
    @if(in_array('payment',$allowedTypes,true))
      <a class="et-fin-btn primary" href="{{ route('accounting.cash-vouchers.create',['type'=>'payment']) }}">＋ Payment Voucher</a>
    @endif
    @if(in_array('expense',$allowedTypes,true))
      <a class="et-fin-btn primary" href="{{ route('accounting.cash-vouchers.create',['type'=>'expense']) }}">＋ Expense Voucher</a>
    @endif
    @if(in_array('contra',$allowedTypes,true))
      <a class="et-fin-btn primary" href="{{ route('accounting.cash-vouchers.create',['type'=>'contra']) }}">⇄ Contra Voucher</a>
    @endif
    @if(in_array('customer_advance',$allowedTypes,true))
      <a class="et-fin-btn" href="{{ route('accounting.cash-vouchers.create',['type'=>'customer_advance']) }}">♙ Customer Advance</a>
    @endif
    @if(in_array('supplier_advance',$allowedTypes,true))
      <a class="et-fin-btn" href="{{ route('accounting.cash-vouchers.create',['type'=>'supplier_advance']) }}">▣ Supplier Advance</a>
    @endif
    <a class="et-fin-btn success" href="{{ route('accounting.advance-adjustments.create') }}">⇄ Adjust Advance</a>
  </div>

  @if(session('success'))<div class="alert alert-success et-fin-alert">{{ session('success') }}</div>@endif
  @if($errors->any())<div class="alert alert-danger et-fin-alert">{{ $errors->first() }}</div>@endif

  <form class="et-fin-filter" method="get">
    @if(request()->filled('mode'))<input type="hidden" name="mode" value="{{ request('mode') }}">@endif
    <div class="et-field">
      <label>Search</label>
      <input name="q" value="{{ request('q') }}" placeholder="Voucher, party, reference">
    </div>
    <div class="et-field">
      <label>Type</label>
      <select name="type">
        <option value="">All Types</option>
        @foreach(['receipt'=>'Receipt','payment'=>'Payment','expense'=>'Expense','contra'=>'Contra','customer_advance'=>'Customer Advance','supplier_advance'=>'Supplier Advance'] as $k=>$v)
          @if(in_array($k,$allowedTypes,true))<option value="{{ $k }}" @selected(request('type')===$k)>{{ $v }}</option>@endif
        @endforeach
      </select>
    </div>
    <div class="et-field">
      <label>Status</label>
      <select name="status">
        <option value="">All Statuses</option>
        @foreach(['draft'=>'Draft','pending_approval'=>'Pending Approval','approved'=>'Approved','posted'=>'Posted','reversed'=>'Reversed'] as $k=>$v)
          <option value="{{ $k }}" @selected(request('status')===$k)>{{ $v }}</option>
        @endforeach
      </select>
    </div>
    <div class="et-field">
      <label>From</label>
      <input type="date" name="date_from" value="{{ request('date_from') }}">
    </div>
    <div class="et-field">
      <label>To</label>
      <input type="date" name="date_to" value="{{ request('date_to') }}">
    </div>
    <div class="et-field">
      <label>Bank / Cash</label>
      <select name="account">
        <option value="">All Accounts</option>
        @foreach($cashBankAccounts as $account)
          <option value="{{ $account['code'] }}" @selected(request('account')===$account['code'])>{{ $account['code'] }} · {{ $account['name'] }}</option>
        @endforeach
      </select>
    </div>
    <button class="et-fin-btn primary" type="submit">⌕ Filter</button>
    <a class="et-fin-btn" href="{{ route('accounting.cash-vouchers.index', request()->filled('mode') ? ['mode'=>request('mode')] : []) }}">↻ Reset</a>
  </form>

  <section class="et-summary-strip" aria-label="Voucher summary">
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">↓</span>
        <div class="et-summary-meta"><strong>Total Receipts</strong><span>Base value</span></div>
      </div>
      <div class="et-summary-amount receipt">{{ $money($summary->receipts ?? 0) }}</div>
    </div>
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">↑</span>
        <div class="et-summary-meta"><strong>Total Payments</strong><span>Base value</span></div>
      </div>
      <div class="et-summary-amount payment">{{ $money($summary->payments ?? 0) }}</div>
    </div>
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">E</span>
        <div class="et-summary-meta"><strong>Direct Expenses</strong><span>Base value</span></div>
      </div>
      <div class="et-summary-amount payment">{{ $money($summary->expenses ?? 0) }}</div>
    </div>
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">⇄</span>
        <div class="et-summary-meta"><strong>Contra Transfers</strong><span>Internal asset movement</span></div>
      </div>
      <div class="et-summary-amount advance">{{ $money($summary->contra ?? 0) }}</div>
    </div>
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">C</span>
        <div class="et-summary-meta"><strong>Customer Advances</strong><span>Controlled balance</span></div>
      </div>
      <div class="et-summary-amount advance">{{ $money($summary->customer_advances ?? 0) }}</div>
    </div>
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">S</span>
        <div class="et-summary-meta"><strong>Supplier Advances</strong><span>Controlled balance</span></div>
      </div>
      <div class="et-summary-amount advance">{{ $money($summary->supplier_advances ?? 0) }}</div>
    </div>
    <div class="et-summary-tile">
      <div class="et-summary-left">
        <span class="et-summary-icon">◷</span>
        <div class="et-summary-meta"><strong>Pending Approval</strong><span>Awaiting posting</span></div>
      </div>
      <div class="et-summary-amount pending">{{ $money($summary->pending_approval ?? 0) }}</div>
    </div>
  </section>

  <section class="et-card">
    <div class="et-card-head">
      <strong>Cash Voucher Register</strong>
      <span class="et-muted">Receipt / Payment / Expense / Contra / Advance</span>
    </div>
    <table class="et-table">
      <colgroup>
        <col style="width:13%"><col style="width:10%"><col style="width:9%"><col style="width:14%">
        <col style="width:17%"><col style="width:11%"><col style="width:10%"><col style="width:9%"><col style="width:7%">
      </colgroup>
      <thead>
        <tr><th>Voucher</th><th>Date</th><th>Type</th><th>Party / Transfer</th><th>Method / Account</th><th>Amount</th><th>Allocated</th><th>Status</th><th class="et-action-head">Action</th></tr>
      </thead>
      <tbody>
        @forelse($rows as $r)
          @php($def=$service->voucherDefinition($r->voucher_type))
            @php($typeClass=in_array($r->voucher_type,['customer_advance','supplier_advance'],true)?'advance':$r->voucher_type)
            @php($contra=$r->voucher_type==='contra' ? ($contraDetails[$r->id] ?? null) : null)
          <tr>
            <td><a class="voucher" href="{{ route('accounting.cash-vouchers.show',$r->id) }}">{{ $r->voucher_no }}</a></td>
            <td>{{ \Carbon\Carbon::parse($r->voucher_date)->format('d M Y') }}</td>
            <td><span class="et-type {{ $typeClass }}">{{ $def['short'] }}</span></td>
            <td>@if($contra){{ $r->cash_bank_account_code }} {{ $r->cash_bank_account_name }} → {{ $contra->destination_account_code }} {{ $contra->destination_account_name }}@else{{ $r->party_name ?: '—' }}@if($r->booking_id)<br><span class="et-muted">Booking #{{ $r->booking_id }}</span>@endif @endif</td>
            <td>{{ $r->payment_method }}<br><span class="et-muted">{{ $r->cash_bank_account_code }} · {{ $r->cash_bank_account_name }}@if($contra) → {{ $contra->destination_account_code }} · {{ $contra->destination_account_name }}@endif</span></td>
            <td class="money">{{ $r->currency_code }} {{ number_format($r->amount,2) }}</td>
            <td>{{ $r->currency_code }} {{ number_format($r->allocated_amount,2) }}</td>
            <td><span class="et-status {{ $r->status }}">{{ str_replace('_',' ',$r->status) }}</span>@if($r->posting_reference)<br><span class="et-muted">{{ $r->posting_reference }}</span>@endif</td>
            <td class="et-action-col"><a class="et-action" href="{{ route('accounting.cash-vouchers.show',$r->id) }}" title="Open voucher" aria-label="Open voucher">⋮</a></td>
          </tr>
        @empty
          <tr><td class="et-empty" colspan="9">No cash vouchers match the current filters.</td></tr>
        @endforelse
      </tbody>
    </table>
    <div class="et-table-foot">
      <span>Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ $rows->total() }} vouchers</span>
      <div class="et-pages">
        @if($rows->onFirstPage())<span>‹</span>@else<a href="{{ $rows->previousPageUrl() }}">‹</a>@endif
        @foreach($rows->getUrlRange(max(1,$rows->currentPage()-2),min($rows->lastPage(),$rows->currentPage()+2)) as $page=>$url)
          @if($page===$rows->currentPage())<span class="active">{{ $page }}</span>@else<a href="{{ $url }}">{{ $page }}</a>@endif
        @endforeach
        @if($rows->hasMorePages())<a href="{{ $rows->nextPageUrl() }}">›</a>@else<span>›</span>@endif
      </div>
    </div>
  </section>

  <section class="et-card">
    <div class="et-card-head">
      <strong>Recent Advance Adjustments</strong>
      <a href="{{ route('accounting.advance-adjustments.create') }}">Apply an advance</a>
    </div>
    <table class="et-table">
      <colgroup><col style="width:19%"><col style="width:14%"><col style="width:20%"><col style="width:24%"><col style="width:14%"><col style="width:9%"></colgroup>
      <thead><tr><th>Adjustment</th><th>Date</th><th>Party</th><th>Target</th><th>Amount</th><th>Status</th></tr></thead>
      <tbody>
        @forelse($adjustments as $a)
          <tr>
            <td><a class="voucher" href="{{ route('accounting.advance-adjustments.show',$a->id) }}">{{ $a->adjustment_no }}</a></td>
            <td>{{ \Carbon\Carbon::parse($a->adjustment_date)->format('d M Y') }}</td>
            <td>{{ $a->party_name ?: '—' }}</td>
            <td>{{ $a->target_number ?: ucfirst(str_replace('_',' ',$a->target_type)).' #'.$a->target_id }}</td>
            <td class="money">{{ $a->currency_code }} {{ number_format($a->amount,2) }}</td>
            <td><span class="et-status {{ $a->status }}">{{ str_replace('_',' ',$a->status) }}</span></td>
          </tr>
        @empty
          <tr><td class="et-empty" colspan="6">No advance adjustments yet.</td></tr>
        @endforelse
      </tbody>
    </table>
    <div class="et-table-foot">
      <span>Showing {{ $adjustments->firstItem() ?? 0 }}–{{ $adjustments->lastItem() ?? 0 }} of {{ $adjustments->total() }} adjustments</span>
      <div class="et-pages">
        @if($adjustments->onFirstPage())<span>‹</span>@else<a href="{{ $adjustments->previousPageUrl() }}">‹</a>@endif
        @foreach($adjustments->getUrlRange(max(1,$adjustments->currentPage()-2),min($adjustments->lastPage(),$adjustments->currentPage()+2)) as $page=>$url)
          @if($page===$adjustments->currentPage())<span class="active">{{ $page }}</span>@else<a href="{{ $url }}">{{ $page }}</a>@endif
        @endforeach
        @if($adjustments->hasMorePages())<a href="{{ $adjustments->nextPageUrl() }}">›</a>@else<span>›</span>@endif
      </div>
    </div>
  </section>

  <div class="et-bottom-row">
    <section class="et-card">
      <div class="et-card-head"><strong>Quick Workflow</strong></div>
      <div class="et-workflow">
        <div class="et-flow-line">
          <div class="et-step"><div class="et-step-num">1</div><strong>Draft</strong><span>Create voucher</span></div>
          <div class="et-step"><div class="et-step-num">2</div><strong>Pending</strong><span>Submit for review</span></div>
          <div class="et-step"><div class="et-step-num">3</div><strong>Approved</strong><span>Authorised</span></div>
          <div class="et-step"><div class="et-step-num">4</div><strong>Posted</strong><span>Affects ledger</span></div>
        </div>
      </div>
    </section>

    <section class="et-card">
      <div class="et-card-head"><strong>Allocation Insight</strong><span class="et-muted">Current vouchers</span></div>
      <div class="et-insight">
        <div class="et-insight-grid">
          <div class="et-donut" title="Allocated {{ number_format($allocatedPct,1) }}%"></div>
          <div>
            <div class="et-insight-line"><span><i class="et-dot blue"></i>Allocated</span><strong>{{ $money($allocatedValue) }}</strong></div>
            <div class="et-insight-line"><span><i class="et-dot green"></i>Advance / Unallocated</span><strong>{{ $money($unallocatedValue) }}</strong></div>
          </div>
        </div>
      </div>
      <div class="et-insight-total"><span>Total Voucher Value</span><span>{{ $money($summary->total_value ?? 0) }}</span></div>
    </section>
  </div>
</div>
@endsection
