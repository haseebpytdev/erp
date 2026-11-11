@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title',($row ? 'Edit ' : 'New ').$definition['label'])
@section($layoutMeta['content_section'] ?? 'content')
<style>
.cv-wrap{max-width:1500px;margin:0 auto}.cv-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:15px}.cv-card{background:#fff;border:1px solid #dfe6ef;border-radius:10px;padding:15px;margin-bottom:13px}.cv-card h4{font-size:14px;margin:0 0 12px}.cv-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.cv-field label{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:5px}.cv-field input,.cv-field select,.cv-field textarea{width:100%;border:1px solid #d3dce7;border-radius:7px;padding:8px 9px;font-size:12px;background:#fff}.cv-field textarea{min-height:70px}.cv-table-wrap{overflow:auto}.cv-table{width:100%;border-collapse:collapse;min-width:870px}.cv-table th,.cv-table td{border:1px solid #e4e9ef;padding:7px}.cv-table th{background:#f8fafc;font-size:11px;color:#526174}.cv-table input,.cv-table select{width:100%;border:0;outline:0;background:transparent;font-size:12px}.cv-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border:1px solid #cad5e1;border-radius:7px;background:#fff;color:#26384e;text-decoration:none!important;font-size:12px;font-weight:700}.cv-primary{background:#0d5bd7;border-color:#0d5bd7;color:#fff!important}.cv-actions{display:flex;justify-content:flex-end;gap:8px}.cv-total{display:flex;justify-content:flex-end;gap:28px;margin-top:12px;font-size:12px}.cv-total strong{font-size:15px}.cv-note{padding:9px 11px;border:1px solid #d9e5f2;background:#f6f9fd;border-radius:7px;font-size:11px;color:#536a83}.rm{border:0;background:#fcebec;color:#a32f3c;border-radius:5px;width:28px;height:28px}@media(max-width:980px){.cv-grid{grid-template-columns:1fr 1fr}}@media(max-width:620px){.cv-grid{grid-template-columns:1fr}.cv-actions{flex-direction:column}.cv-btn{width:100%}}
</style>
<div class="cv-wrap">
  <div class="cv-head"><div><div style="font-size:11px;font-weight:800;color:#0d5bd7">ACCOUNTING · ERP-11.3</div><h2 style="margin:3px 0">{{ $row ? 'Edit '.$row->voucher_no : 'New '.$definition['label'] }}</h2><div style="font-size:12px;color:#718096">{{ $definition['direction']==='in' ? 'Money received into Cash / Bank' : 'Money paid from Cash / Bank' }}</div></div><a class="cv-btn" href="{{ route('accounting.cash-vouchers.index') }}">Back to Register</a></div>
  @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
  <form method="post" enctype="multipart/form-data" action="{{ $row ? route('accounting.cash-vouchers.update',$row->id) : route('accounting.cash-vouchers.store') }}">@csrf @if($row)@method('PUT')@endif
    <input type="hidden" name="voucher_type" value="{{ $type }}">
    <div class="cv-card"><h4>Voucher Information</h4><div class="cv-grid">
      <div class="cv-field"><label>{{ $definition['party_type']==='supplier'?'Supplier':'Customer / Agent' }} *</label><select name="party_id" id="partySelect"><option value="">Select {{ $definition['party_type'] }}</option>@foreach($parties as $p)<option value="{{ $p['id'] }}" @selected((string)old('party_id',$row->party_id ?? '')===(string)$p['id'])>{{ $p['name'] }}</option>@endforeach</select><input style="margin-top:5px" name="party_name" value="{{ old('party_name',$row->party_name ?? '') }}" placeholder="Or enter party name manually"></div>
      <div class="cv-field"><label>Booking Reference</label><select name="booking_id"><option value="">Optional</option>@foreach($bookings as $b)<option value="{{ $b['id'] }}" @selected((string)old('booking_id',$row->booking_id ?? '')===(string)$b['id'])>{{ $b['reference'] }}</option>@endforeach</select></div>
      <div class="cv-field"><label>Voucher Date *</label><input type="date" name="voucher_date" value="{{ old('voucher_date',$row->voucher_date ?? now()->toDateString()) }}" required></div>
      <div class="cv-field"><label>Value / Bank Date</label><input type="date" name="value_date" value="{{ old('value_date',$row->value_date ?? '') }}"></div>
      <div class="cv-field"><label>Currency *</label><input name="currency_code" id="currencyCode" value="{{ old('currency_code',$row->currency_code ?? 'PKR') }}" required></div>
      <div class="cv-field"><label>Exchange Rate *</label><input type="number" step="0.00000001" min="0.00000001" name="exchange_rate" value="{{ old('exchange_rate',$row->exchange_rate ?? '1.00000000') }}" required></div>
      <div class="cv-field"><label>Total Amount *</label><input type="number" id="voucherAmount" step="0.01" min="0.01" name="amount" value="{{ old('amount',$row->amount ?? '0.00') }}" required></div>
      <div class="cv-field"><label>Payment Method *</label><select name="payment_method">@foreach($paymentMethods as $m)<option value="{{ $m }}" @selected(old('payment_method',$row->payment_method ?? 'Bank Transfer')===$m)>{{ $m }}</option>@endforeach</select></div>
      <div class="cv-field"><label>Cash / Bank Account *</label><select name="cash_bank_account" required><option value="">Select account</option>@foreach($cashBankAccounts as $a)<option value="{{ $a['code'] }}" @selected(old('cash_bank_account',$row->cash_bank_account_code ?? '')===$a['code'])>{{ $a['code'] }} · {{ $a['name'] }}</option>@endforeach</select></div>
      <div class="cv-field"><label>Bank Name</label><input name="bank_name" value="{{ old('bank_name',$row->bank_name ?? '') }}"></div>
      <div class="cv-field"><label>Cheque / Instrument No.</label><input name="instrument_no" value="{{ old('instrument_no',$row->instrument_no ?? '') }}"></div>
      <div class="cv-field"><label>Transaction / Bank Reference</label><input name="transaction_reference" value="{{ old('transaction_reference',$row->transaction_reference ?? '') }}"></div>
      <div class="cv-field" style="grid-column:span 2"><label>Narration / Remarks</label><textarea name="narration">{{ old('narration',$row->narration ?? '') }}</textarea></div>
      <div class="cv-field" style="grid-column:span 2"><label>Payment Proof (PDF/JPG/PNG/WEBP, max 5MB)</label><input type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp">@if($row && $row->payment_proof_original_name)<div style="font-size:11px;color:#65758a;margin-top:5px">Current: {{ $row->payment_proof_original_name }} · upload another file to replace it</div>@endif</div>
    </div></div>

    @if($definition['target_type'])
    <div class="cv-card"><div style="display:flex;justify-content:space-between;gap:10px;align-items:center"><div><h4 style="margin-bottom:3px">Allocate to {{ $definition['target_label'] }}</h4><div style="font-size:11px;color:#718096">Optional. Any unallocated balance becomes a {{ $definition['party_type']==='customer'?'customer advance':'supplier advance' }} automatically.</div></div><button type="button" class="cv-btn" id="addAllocation">+ Add Allocation</button></div>
      <div class="cv-table-wrap" style="margin-top:10px"><table class="cv-table"><thead><tr><th>{{ $definition['target_label'] }}</th><th>Outstanding</th><th>Allocation Amount</th><th>Notes</th><th></th></tr></thead><tbody id="allocationBody"></tbody></table></div>
      <div class="cv-total"><span>Allocated: <strong id="allocatedTotal">0.00</strong></span><span>Unallocated / Advance: <strong id="unallocatedTotal">0.00</strong></span></div>
    </div>
    @else
    <div class="cv-note" style="margin-bottom:13px"><strong>{{ $definition['label'] }}:</strong> this voucher is intentionally not linked to an invoice/costing document. After it is Posted, use <strong>Advance Adjustment</strong> to apply it to a future {{ $definition['party_type']==='customer'?'Sales Invoice':'Supplier Costing' }} document.</div>
    @endif

    <div class="cv-actions"><a class="cv-btn" href="{{ route('accounting.cash-vouchers.index') }}">Cancel</a><button class="cv-btn cv-primary">Save Draft</button></div>
  </form>
</div>
@if($definition['target_type'])
<script>
(()=>{
const docs=@json($documents);const existing=@json(($allocations ?? collect())->map(fn($a)=>(array)$a)->values());const body=document.getElementById('allocationBody');const party=document.getElementById('partySelect');const amount=document.getElementById('voucherAmount');
const esc=v=>String(v??'').replace(/[&<>\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function eligible(d){const pid=Number(party.value||0);return !pid||!d.party_id||Number(d.party_id)===pid;}
function opts(selected){return '<option value="">Select document</option>'+docs.filter(eligible).map(d=>`<option value="${d.id}" ${Number(selected)===Number(d.id)?'selected':''}>${esc(d.number)} · ${esc(d.party_name||'')} · ${esc(d.currency_code)} ${Number(d.outstanding||0).toFixed(2)} open</option>`).join('')}
function row(v={}){const i=body.children.length;const tr=document.createElement('tr');tr.innerHTML=`<td><select class="doc" name="allocations[${i}][target_id]" required>${opts(v.target_id)}</select></td><td class="outstanding">0.00</td><td><input class="alloc" type="number" min="0.01" step="0.01" name="allocations[${i}][amount]" value="${Number(v.amount||0).toFixed(2)}" required></td><td><input name="allocations[${i}][notes]" value="${esc(v.notes||'')}"></td><td><button type="button" class="rm">×</button></td>`;body.appendChild(tr);refreshRow(tr);calc()}
function refreshRow(tr){const id=Number(tr.querySelector('.doc').value||0);const d=docs.find(x=>Number(x.id)===id);tr.querySelector('.outstanding').textContent=d?`${d.currency_code} ${Number(d.outstanding||0).toFixed(2)}`:'0.00'}
function renumber(){[...body.children].forEach((tr,i)=>tr.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/allocations\[\d+\]/,`allocations[${i}]`)))}
function calc(){const allocated=[...body.querySelectorAll('.alloc')].reduce((s,e)=>s+Number(e.value||0),0);const total=Number(amount.value||0);document.getElementById('allocatedTotal').textContent=allocated.toFixed(2);document.getElementById('unallocatedTotal').textContent=Math.max(0,total-allocated).toFixed(2)}
(existing.length?existing:[]).forEach(row);document.getElementById('addAllocation').onclick=()=>row();body.addEventListener('change',e=>{if(e.target.classList.contains('doc'))refreshRow(e.target.closest('tr'));calc()});body.addEventListener('input',calc);body.addEventListener('click',e=>{if(e.target.classList.contains('rm')){e.target.closest('tr').remove();renumber();calc()}});amount.addEventListener('input',calc);party.addEventListener('change',()=>{[...body.children].forEach(tr=>{const s=tr.querySelector('.doc');const cur=s.value;s.innerHTML=opts(cur);refreshRow(tr)});calc()});calc();
})();
</script>
@endif
@endsection
