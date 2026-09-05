@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title',($row ? 'Edit ' : 'New ').$definition['label'])
@section($layoutMeta['content_section'] ?? 'content')
@php
    $isIncoming = $definition['direction'] === 'in';
    $partyLabel = $definition['party_type'] === 'supplier' ? 'Supplier' : 'Customer / Agent';
    $subtitle = $isIncoming ? 'Money received into Cash / Bank' : 'Money paid from Cash / Bank';
@endphp
<style>
.cvf27{max-width:1500px;margin:0 auto;color:#17243a}.cvf27 *{box-sizing:border-box}
.cvf27-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}
.cvf27-kicker{font-size:9.5px;font-weight:900;color:#0863d8;text-transform:uppercase;letter-spacing:.04em}
.cvf27-title{font-size:26px;line-height:1.1;margin:4px 0;color:#17243a;font-weight:900}.cvf27-sub{font-size:12px;color:#718197}
.cvf27-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.cvf27-btn{min-height:38px;padding:8px 13px;border:1px solid #ccd8e6;border-radius:7px;background:#fff;color:#26384e;text-decoration:none!important;font-size:11px;font-weight:850;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;white-space:nowrap}
.cvf27-btn.primary{background:#0964df;border-color:#0964df;color:#fff!important}.cvf27-btn:disabled{opacity:.45;cursor:not-allowed}
.cvf27-card{background:#fff;border:1px solid #dce5ef;border-radius:10px;box-shadow:0 4px 13px rgba(28,45,68,.035);margin-bottom:13px;overflow:hidden}
.cvf27-card-head{padding:11px 14px;border-bottom:1px solid #e8edf3;display:flex;align-items:center;justify-content:space-between;gap:12px}
.cvf27-card-title{font-size:14px;font-weight:900}.cvf27-help{font-size:10px;color:#74849a}.cvf27-body{padding:14px}
.cvf27-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px 13px}
.cvf27-field label{display:block;margin:0 0 5px;font-size:9.5px;font-weight:900;color:#465970}
.cvf27-field input,.cvf27-field select,.cvf27-field textarea{width:100%;min-height:40px;border:1px solid #d4deea;border-radius:7px;background:#fff;padding:8px 10px;color:#24354c;font-size:11.5px;outline:none}
.cvf27-field textarea{height:72px;resize:vertical}.cvf27-span2{grid-column:span 2}.cvf27-span4{grid-column:1/-1}
.cvf27-upload{position:relative;min-height:72px;border:1px dashed #bfd0e5;border-radius:7px;background:#fbfdff;display:flex;align-items:center;justify-content:center;text-align:center;padding:9px;color:#60738b}
.cvf27-upload input{position:absolute;inset:0;opacity:0;cursor:pointer}.cvf27-upload strong{display:block;color:#0964df;font-size:11px}.cvf27-upload span{font-size:9.5px}
.cvf27-table{width:100%;border-collapse:collapse;table-layout:fixed}.cvf27-table th,.cvf27-table td{border-bottom:1px solid #e9eef4;padding:8px;text-align:left;vertical-align:middle;font-size:10.5px;overflow-wrap:anywhere}
.cvf27-table th{background:#f8fafc;color:#526174;font-size:8.8px;text-transform:uppercase;letter-spacing:.02em}
.cvf27-table select,.cvf27-table input{width:100%;border:0;background:transparent;outline:none;font-size:10.5px}
.cvf27-action{width:8%;text-align:center!important;white-space:nowrap!important;word-break:normal!important;overflow-wrap:normal!important}
.cvf27-rm{width:27px;height:27px;border:0;border-radius:6px;background:#fcebec;color:#a32f3c;font-weight:900}
.cvf27-empty{text-align:center;padding:25px 12px;color:#7d8b9d;font-size:10.5px}
.cvf27-totals{display:flex;justify-content:flex-end;gap:25px;padding:10px 13px;font-size:10.5px;color:#516278}.cvf27-totals strong{font-size:13px;color:#17243a}
.cvf27-bottom{padding:11px 13px;background:#fff;border:1px solid #dce5ef;border-radius:10px;display:flex;align-items:center;justify-content:space-between;gap:10px}.cvf27-bottom-actions{display:flex;gap:8px;flex-wrap:wrap}
.cvf27-note{padding:10px 12px;border:1px solid #dbe5f1;background:#f7faff;border-radius:8px;font-size:10.5px;color:#52687f;margin-bottom:13px}
@media(max-width:1050px){.cvf27-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cvf27-span4{grid-column:1/-1}}
@media(max-width:650px){.cvf27-head{display:block}.cvf27-actions{justify-content:flex-start;margin-top:10px}.cvf27-grid{grid-template-columns:1fr}.cvf27-span2,.cvf27-span4{grid-column:span 1}.cvf27-bottom{display:block}.cvf27-bottom-actions{margin-top:9px}.cvf27-bottom-actions .cvf27-btn{flex:1}}
</style>

<div class="cvf27" data-et-cash-voucher-form="ERP-11.3.27">
  <div class="cvf27-head">
    <div>
      <div class="cvf27-kicker">Accounting · ERP-11.3.27</div>
      <h1 class="cvf27-title">{{ $row ? 'Edit '.$row->voucher_no : 'New '.$definition['label'] }}</h1>
      <div class="cvf27-sub">{{ $subtitle }}</div>
    </div>
    <div class="cvf27-actions">
      <a class="cvf27-btn" href="{{ route('accounting.cash-vouchers.index') }}">Back to Register</a>
      @if($row)
        <a class="cvf27-btn" target="_blank" href="{{ route('accounting.cash-vouchers.print',$row->id) }}">Print Voucher</a>
      @endif
      <button class="cvf27-btn" type="submit" form="cashVoucherForm" name="after_save" value="draft">Save Draft</button>
      <button class="cvf27-btn primary" type="submit" form="cashVoucherForm" name="after_save" value="submit">Submit</button>
    </div>
  </div>

  @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

  <form id="cashVoucherForm" method="post" enctype="multipart/form-data" action="{{ $row ? route('accounting.cash-vouchers.update',$row->id) : route('accounting.cash-vouchers.store') }}">
    @csrf
    @if($row)@method('PUT')@endif
    <input type="hidden" name="voucher_type" value="{{ $type }}">

    <section class="cvf27-card">
      <div class="cvf27-card-head">
        <div class="cvf27-card-title">Voucher Information</div>
        <div class="cvf27-help">Receipt and Payment use the same controlled accounting workflow.</div>
      </div>
      <div class="cvf27-body">
        <div class="cvf27-grid">
          <div class="cvf27-field">
            <label>{{ $partyLabel }} *</label>
            <select name="party_id" id="partySelect">
              <option value="">Select {{ strtolower($partyLabel) }}</option>
              @foreach($parties as $p)
                <option value="{{ $p['id'] }}" @selected((string)old('party_id',$row->party_id ?? '')===(string)$p['id'])>{{ $p['name'] }}</option>
              @endforeach
            </select>
          </div>

          <div class="cvf27-field">
            <label>Manual Party Name</label>
            <input name="party_name" value="{{ old('party_name',$row->party_name ?? '') }}" placeholder="Use only when party is not in master">
          </div>

          <div class="cvf27-field">
            <label>Booking Reference</label>
            <select name="booking_id">
              <option value="">Optional</option>
              @foreach($bookings as $b)
                <option value="{{ $b['id'] }}" @selected((string)old('booking_id',$row->booking_id ?? '')===(string)$b['id'])>{{ $b['reference'] }}</option>
              @endforeach
            </select>
          </div>

          <div class="cvf27-field">
            <label>Voucher Date *</label>
            <input type="date" name="voucher_date" value="{{ old('voucher_date',$row->voucher_date ?? now()->toDateString()) }}" required>
          </div>

          <div class="cvf27-field">
            <label>Value / Bank Date</label>
            <input type="date" name="value_date" value="{{ old('value_date',$row->value_date ?? '') }}">
          </div>

          <div class="cvf27-field">
            <label>Total Amount *</label>
            <input type="number" id="voucherAmount" step="0.01" min="0.01" name="amount" value="{{ old('amount',$row->amount ?? '0.00') }}" required>
          </div>

          <div class="cvf27-field">
            <label>Currency *</label>
            <input name="currency_code" value="{{ old('currency_code',$row->currency_code ?? 'PKR') }}" required>
          </div>

          <div class="cvf27-field">
            <label>Exchange Rate *</label>
            <input type="number" step="0.00000001" min="0.00000001" name="exchange_rate" value="{{ old('exchange_rate',$row->exchange_rate ?? '1.00000000') }}" required>
          </div>

          <div class="cvf27-field">
            <label>Payment Method *</label>
            <select name="payment_method">
              @foreach($paymentMethods as $m)
                <option value="{{ $m }}" @selected(old('payment_method',$row->payment_method ?? 'Bank Transfer')===$m)>{{ $m }}</option>
              @endforeach
            </select>
          </div>

          <div class="cvf27-field">
            <label>Cash / Bank Account *</label>
            <select name="cash_bank_account" required>
              <option value="">Select account</option>
              @foreach($cashBankAccounts as $a)
                <option value="{{ $a['code'] }}" @selected(old('cash_bank_account',$row->cash_bank_account_code ?? '')===$a['code'])>{{ $a['code'] }} · {{ $a['name'] }}</option>
              @endforeach
            </select>
          </div>

          <div class="cvf27-field">
            <label>Bank Name</label>
            <input name="bank_name" value="{{ old('bank_name',$row->bank_name ?? '') }}">
          </div>

          <div class="cvf27-field">
            <label>Transaction / Bank Reference</label>
            <input name="transaction_reference" value="{{ old('transaction_reference',$row->transaction_reference ?? '') }}">
          </div>

          <div class="cvf27-field">
            <label>Cheque / Instrument No.</label>
            <input name="instrument_no" value="{{ old('instrument_no',$row->instrument_no ?? '') }}">
          </div>

          <div class="cvf27-field cvf27-span2">
            <label>Narration / Remarks</label>
            <textarea name="narration" placeholder="Enter the narration that should appear in ledgers and reports">{{ old('narration',$row->narration ?? '') }}</textarea>
          </div>

          <div class="cvf27-field cvf27-span2">
            <label>Payment Proof (PDF/JPG/PNG/WEBP, max 5MB)</label>
            <div class="cvf27-upload">
              <input type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp">
              <div><strong>Choose payment proof</strong><span>Click or drop a file here</span></div>
            </div>
            @if($row && $row->payment_proof_original_name)
              <div style="font-size:9.5px;color:#65758a;margin-top:4px">Current: {{ $row->payment_proof_original_name }}</div>
            @endif
          </div>
        </div>
      </div>
    </section>

    @if($definition['target_type'])
      <section class="cvf27-card">
        <div class="cvf27-card-head">
          <div>
            <div class="cvf27-card-title">Allocate to {{ $definition['target_label'] }}</div>
            <div class="cvf27-help">Optional. Unallocated balance becomes {{ $definition['party_type']==='customer'?'Customer Advance':'Vendor Advance' }} automatically.</div>
          </div>
          <button type="button" class="cvf27-btn" id="addAllocation">+ Add Allocation</button>
        </div>

        <table class="cvf27-table">
          <colgroup><col style="width:29%"><col style="width:18%"><col style="width:23%"><col style="width:22%"><col style="width:8%"></colgroup>
          <thead><tr><th>{{ $definition['target_label'] }}</th><th>Outstanding</th><th>Allocation Amount</th><th>Notes</th><th class="cvf27-action">Action</th></tr></thead>
          <tbody id="allocationBody"></tbody>
        </table>

        <div id="allocationEmpty" class="cvf27-empty">No allocations added. Use “Add Allocation” only when settling an existing document.</div>

        <div class="cvf27-totals">
          <span>Allocated: <strong id="allocatedTotal">0.00</strong></span>
          <span>Advance / Unallocated: <strong id="unallocatedTotal">0.00</strong></span>
        </div>
      </section>
    @else
      <div class="cvf27-note">{{ $definition['label'] }} is recorded as an advance and may be applied later through Advance Adjustment.</div>
    @endif

    <div class="cvf27-bottom">
      <a class="cvf27-btn" href="{{ route('accounting.cash-vouchers.index') }}">Cancel</a>
      <div class="cvf27-bottom-actions">
        <button class="cvf27-btn" type="submit" name="after_save" value="draft">Save Draft</button>
        <button class="cvf27-btn" type="submit" name="after_save" value="print">Save & Print</button>
        <button class="cvf27-btn primary" type="submit" name="after_save" value="submit">Submit</button>
      </div>
    </div>
  </form>
</div>

@if($definition['target_type'])
<script>
(()=>{
const docs=@json($documents);
const existing=@json(($allocations ?? collect())->map(fn($a)=>(array)$a)->values());
const body=document.getElementById('allocationBody');
const empty=document.getElementById('allocationEmpty');
const party=document.getElementById('partySelect');
const amount=document.getElementById('voucherAmount');
const esc=v=>String(v??'').replace(/[&<>\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function eligible(d){const pid=Number(party.value||0);return !pid||!d.party_id||Number(d.party_id)===pid;}
function opts(selected){return '<option value="">Select document</option>'+docs.filter(eligible).map(d=>`<option value="${d.id}" ${Number(selected)===Number(d.id)?'selected':''}>${esc(d.number)} · ${esc(d.party_name||'')} · ${esc(d.currency_code)} ${Number(d.outstanding||0).toFixed(2)} open</option>`).join('')}
function updateEmpty(){empty.style.display=body.children.length?'none':'block'}
function row(v={}){const i=body.children.length;const tr=document.createElement('tr');tr.innerHTML=`<td><select class="doc" name="allocations[${i}][target_id]" required>${opts(v.target_id)}</select></td><td class="outstanding">0.00</td><td><input class="alloc" type="number" min="0.01" step="0.01" name="allocations[${i}][amount]" value="${Number(v.amount||0).toFixed(2)}" required></td><td><input name="allocations[${i}][notes]" value="${esc(v.notes||'')}"></td><td class="cvf27-action"><button type="button" class="cvf27-rm">×</button></td>`;body.appendChild(tr);refreshRow(tr);calc();updateEmpty()}
function refreshRow(tr){const id=Number(tr.querySelector('.doc').value||0);const d=docs.find(x=>Number(x.id)===id);tr.querySelector('.outstanding').textContent=d?`${d.currency_code} ${Number(d.outstanding||0).toFixed(2)}`:'0.00'}
function renumber(){[...body.children].forEach((tr,i)=>tr.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/allocations\[\d+\]/,`allocations[${i}]`)))}
function calc(){const allocated=[...body.querySelectorAll('.alloc')].reduce((s,e)=>s+Number(e.value||0),0);const total=Number(amount.value||0);document.getElementById('allocatedTotal').textContent=allocated.toFixed(2);document.getElementById('unallocatedTotal').textContent=Math.max(0,total-allocated).toFixed(2)}
existing.forEach(row);
document.getElementById('addAllocation').onclick=()=>row();
body.addEventListener('change',e=>{if(e.target.classList.contains('doc'))refreshRow(e.target.closest('tr'));calc()});
body.addEventListener('input',calc);
body.addEventListener('click',e=>{if(e.target.classList.contains('cvf27-rm')){e.target.closest('tr').remove();renumber();calc();updateEmpty()}});
amount.addEventListener('input',calc);
party.addEventListener('change',()=>{[...body.children].forEach(tr=>{const s=tr.querySelector('.doc');const cur=s.value;s.innerHTML=opts(cur);refreshRow(tr)});calc()});
calc();updateEmpty();
})();
</script>
@endif
@endsection
