@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title',($row ? 'Edit ' : 'New ').$definition['label'])
@section($layoutMeta['content_section'] ?? 'content')
@php
    $isIncoming = $definition['direction'] === 'in';
    $isExpense = $type === 'expense';
    $isContra = $type === 'contra';
    $partyLabel = $definition['party_type'] === 'supplier' ? 'Supplier' : 'Customer';
    $bookingPlaceholder = $isContra ? 'Not applicable' : ($isExpense ? 'Optional' : ($row && $row->party_id ? 'Optional' : 'Select '.strtolower($partyLabel).' first'));
    $subtitle = $isContra
        ? 'Internal transfer between active posting Cash / Bank accounts'
        : ($isExpense
            ? 'Direct business expenses paid from Cash / Bank'
            : ($isIncoming ? 'Money received into Cash / Bank' : 'Money paid from Cash / Bank'));
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
.cvf27-expense-grid [data-expense-field="payee"]{grid-column:1;grid-row:1}.cvf27-expense-grid [data-expense-field="booking"]{grid-column:2;grid-row:1}.cvf27-expense-grid [data-expense-field="voucher-date"]{grid-column:3;grid-row:1}.cvf27-expense-grid [data-expense-field="value-date"]{grid-column:4;grid-row:1}.cvf27-expense-grid [data-expense-field="currency"]{grid-column:1;grid-row:2}.cvf27-expense-grid [data-expense-field="exchange"]{grid-column:2;grid-row:2}.cvf27-expense-grid [data-expense-field="payment-method"]{grid-column:3;grid-row:2}.cvf27-expense-grid [data-expense-field="cash-bank"]{grid-column:4;grid-row:2}.cvf27-expense-grid [data-expense-field="bank-name"]{grid-column:1;grid-row:3}.cvf27-expense-grid [data-expense-field="transaction-reference"]{grid-column:2;grid-row:3}.cvf27-expense-grid [data-expense-field="instrument"]{grid-column:3;grid-row:3}.cvf27-expense-grid [data-expense-field="payment-proof"]{grid-column:4;grid-row:3}.cvf27-expense-grid [data-expense-field="narration"]{grid-column:1/-1;grid-row:4}
.cvf27-field label{display:block;margin:0 0 5px;font-size:9.5px;font-weight:900;color:#465970}
.cvf27-field input,.cvf27-field select,.cvf27-field textarea{width:100%;min-height:40px;border:1px solid #d4deea;border-radius:7px;background:#fff;padding:8px 10px;color:#24354c;font-size:11.5px;outline:none}
.cvf27-field textarea{height:72px;resize:vertical}.cvf27-span2{grid-column:span 2}.cvf27-span4{grid-column:1/-1}
.cvf27-upload{position:relative;min-height:72px;border:1px dashed #bfd0e5;border-radius:7px;background:#fbfdff;display:flex;align-items:center;justify-content:center;text-align:center;padding:9px;color:#60738b}
.cvf27-upload-compact{min-height:40px;height:40px;justify-content:flex-start;text-align:left;padding:6px 10px}.cvf27-upload-compact strong{display:inline;margin-right:8px}.cvf27-upload-compact span{font-size:10px}
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
.cvf27-warning{display:none;grid-column:1/-1;padding:8px 10px;border:1px solid #edcf88;background:#fff9e8;border-radius:7px;color:#805b09;font-size:10px}
.cvf27-accountPicker{position:relative}.cvf27-accountPicker input.accountSearch{border:1px solid #d4deea;background:#fff;padding:7px 8px;border-radius:6px;width:100%}.cvf27-accountResults{position:absolute;z-index:20;left:0;right:0;top:100%;background:#fff;border:1px solid #cfdbea;border-radius:6px;box-shadow:0 8px 18px rgba(19,43,73,.16);max-height:210px;overflow:auto}.cvf27-account-option{display:block;width:100%;border:0;background:#fff;text-align:left;padding:7px 9px;font-size:10.5px;cursor:pointer}.cvf27-account-option:hover,.cvf27-account-option:focus{background:#eef5ff}.cvf27-account-empty{display:block;padding:8px;color:#74849a;font-size:10px}
@media(max-width:1050px){.cvf27-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cvf27-span4{grid-column:1/-1}.cvf27-expense-grid [data-expense-field]{grid-column:auto;grid-row:auto}}
@media(max-width:650px){.cvf27-head{display:block}.cvf27-actions{justify-content:flex-start;margin-top:10px}.cvf27-grid{grid-template-columns:1fr}.cvf27-span2,.cvf27-span4{grid-column:span 1}.cvf27-expense-grid [data-expense-field]{grid-column:auto;grid-row:auto}.cvf27-bottom{display:block}.cvf27-bottom-actions{margin-top:9px}.cvf27-bottom-actions .cvf27-btn{flex:1}}
</style>

<div class="cvf27" data-et-cash-voucher-form="{{ config('et_erp_release.release', 'ERP-11.3') }}">
  <div class="cvf27-head">
    <div>
      <div class="cvf27-kicker">Accounting</div>
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
        <div class="cvf27-help">All vouchers use the same controlled Draft → Approval → Posted workflow.</div>
      </div>
      <div class="cvf27-body">
        <div class="cvf27-grid{{ $isExpense ? ' cvf27-expense-grid' : '' }}">
          @if($isContra)
            <input type="hidden" name="party_id" value="">
            <input type="hidden" name="party_name" value="">
            <input type="hidden" name="booking_id" value="">
          @elseif($isExpense)
            <input type="hidden" name="party_id" value="">
            <div class="cvf27-field cvf27-span2" data-expense-field="payee">
              <label>Payee Name</label>
              <input name="party_name" value="{{ old('party_name',$row->party_name ?? '') }}" placeholder="Optional person or organization paid">
            </div>
          @else
            <div class="cvf27-field">
              <label>{{ $partyLabel }} *</label>
              <select name="party_id" id="partySelect" required>
                <option value="">Select {{ strtolower($partyLabel) }}</option>
                @foreach($parties as $p)
                  <option value="{{ $p['id'] }}" @selected((string)old('party_id',$row->party_id ?? '')===(string)$p['id'])>{{ $p['name'] }}</option>
                @endforeach
              </select>
              @if(empty($parties))<small class="text-muted">No eligible {{ $definition['party_type'] === 'supplier' ? 'Vendors / Suppliers' : 'Customers' }} are available from Party Master.</small>@endif
            </div>

            <input type="hidden" name="party_name" value="">
          @endif

          @unless($isContra)
            <div class="cvf27-field" data-expense-field="booking">
              <label>Booking Reference</label>
              <select name="booking_id" id="bookingSelect" data-booking-domain="{{ $isExpense ? 'expense' : $definition['party_type'] }}">
                <option value="">{{ $bookingPlaceholder }}</option>
                @foreach($bookings as $b)
                  <option value="{{ $b['id'] }}" @selected((string)old('booking_id',$row->booking_id ?? '')===(string)$b['id'])>{{ $b['reference'] }}</option>
                @endforeach
              </select>
            </div>
          @endunless

          <div class="cvf27-field" data-expense-field="voucher-date">
            <label>Voucher Date *</label>
            <input type="date" name="voucher_date" value="{{ old('voucher_date',$row->voucher_date ?? now()->toDateString()) }}" required>
          </div>

          <div class="cvf27-field" data-expense-field="value-date">
            <label>Value / Bank Date</label>
            <input type="date" name="value_date" value="{{ old('value_date',$row->value_date ?? '') }}">
          </div>

          @unless($isExpense)
            <div class="cvf27-field">
              <label>{{ $isContra ? 'Transfer Amount' : 'Total Amount' }} *</label>
              <input type="number" id="voucherAmount" step="0.01" min="0.01" name="amount" value="{{ old('amount',$row->amount ?? '0.00') }}" required>
            </div>
          @else
            <input type="hidden" id="voucherAmount" name="amount" value="{{ old('amount',$row->amount ?? '0.00') }}">
          @endunless

          <div class="cvf27-field" data-expense-field="currency">
            <label>Currency *</label>
            <input name="currency_code" value="{{ old('currency_code',$row->currency_code ?? 'PKR') }}" required>
          </div>

          <div class="cvf27-field" data-expense-field="exchange">
            <label>Exchange Rate *</label>
            <input type="number" step="0.00000001" min="0.00000001" name="exchange_rate" value="{{ old('exchange_rate',$row->exchange_rate ?? '1.00000000') }}" required>
          </div>

          <div class="cvf27-field" data-expense-field="payment-method">
            <label>{{ $isContra ? 'Transfer Method' : 'Payment Method' }} *</label>
            <select name="payment_method" id="paymentMethod">
              @foreach($paymentMethods as $m)
                <option value="{{ $m }}" @selected(old('payment_method',$row->payment_method ?? 'Bank Transfer')===$m)>{{ $m }}</option>
              @endforeach
            </select>
          </div>

          <div class="cvf27-field" data-expense-field="cash-bank">
            <label>{{ $isContra ? 'From Cash / Bank Account' : 'Cash / Bank Account' }} *</label>
            <select name="cash_bank_account" id="cashBankAccount" required>
              <option value="">Select account</option>
              @foreach($cashBankAccounts as $a)
                <option value="{{ $a['code'] }}" data-account-subtype="{{ $a['subtype'] ?? '' }}" @selected(old('cash_bank_account',$row->cash_bank_account_code ?? '')===$a['code'])>{{ $a['code'] }} · {{ $a['name'] }}</option>
              @endforeach
            </select>
            @if(empty($cashBankAccounts))<small class="text-muted">No active posting Cash / Bank account is available.</small>@endif
          </div>

          @if($isContra)
            <div class="cvf27-field">
              <label>To Cash / Bank Account *</label>
              <select name="destination_account" id="destinationAccount" required>
                <option value="">Select destination account</option>
                @foreach($cashBankAccounts as $a)
                  <option value="{{ $a['code'] }}" data-account-subtype="{{ $a['subtype'] ?? '' }}" @selected(old('destination_account',$contraDetail->destination_account_code ?? '')===$a['code'])>{{ $a['code'] }} · {{ $a['name'] }}</option>
                @endforeach
              </select>
            </div>
          @endif

          @if($isContra)
            <input type="hidden" name="bank_name" value="">
          @else
            <div class="cvf27-field" data-expense-field="bank-name">
              <label>Bank Name</label>
              <input name="bank_name" value="{{ old('bank_name',$row->bank_name ?? '') }}">
            </div>
          @endif

          <div id="paymentMethodWarning" class="cvf27-warning"></div>

          <div class="cvf27-field" data-expense-field="transaction-reference">
            <label>Transaction / Bank Reference</label>
            <input name="transaction_reference" value="{{ old('transaction_reference',$row->transaction_reference ?? '') }}">
          </div>

          <div class="cvf27-field" data-expense-field="instrument">
            <label>Cheque / Instrument No.</label>
            <input name="instrument_no" value="{{ old('instrument_no',$row->instrument_no ?? '') }}">
          </div>

          <div class="cvf27-field cvf27-span2" data-expense-field="narration">
            <label>Narration / Remarks</label>
            <textarea name="narration" placeholder="Enter the narration that should appear in ledgers and reports">{{ old('narration',$row->narration ?? '') }}</textarea>
          </div>

          <div class="cvf27-field cvf27-span2" data-expense-field="payment-proof">
            <label>Payment Proof (PDF/JPG/PNG/WEBP, max 5MB)</label>
            <div class="cvf27-upload cvf27-upload-compact">
              <input type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp">
              <div><strong>Attach File</strong><span class="cvf27-proof-name">No file selected</span></div>
            </div>
            @if($row && $row->payment_proof_original_name)
              <div style="font-size:9.5px;color:#65758a;margin-top:4px">Current: {{ $row->payment_proof_original_name }}</div>
            @endif
          </div>
        </div>
      </div>
    </section>

    @if($isExpense)
      <section class="cvf27-card" data-et-expense-lines>
        <div class="cvf27-card-head">
          <div>
            <div class="cvf27-card-title">Expense Lines</div>
            <div class="cvf27-help">Distribute this payment only to active posting Expense accounts.</div>
          </div>
          <button type="button" class="cvf27-btn" id="addExpenseLine">+ Add Expense Line</button>
        </div>
        <table class="cvf27-table">
          <colgroup><col style="width:6%"><col style="width:34%"><col style="width:38%"><col style="width:14%"><col style="width:8%"></colgroup>
          <thead><tr><th>#</th><th>Expense Account</th><th>Description</th><th>Amount</th><th class="cvf27-action">Action</th></tr></thead>
          <tbody id="expenseLineBody"></tbody>
        </table>
        <div id="expenseLineEmpty" class="cvf27-empty">At least one expense line is required.</div>
        <div class="cvf27-totals"><span>Total Expense: <strong><span id="expenseCurrency">{{ old('currency_code',$row->currency_code ?? 'PKR') }}</span> <span id="expenseTotal">0.00</span></strong></span></div>
      </section>
    @elseif($definition['target_type'])
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
    @elseif($isContra)
      <div class="cvf27-note">Contra transfers move funds only between the selected Cash / Bank accounts. They do not create expense, revenue, payable, receivable, advance, Sales Invoice or Supplier Costing allocations.</div>
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

<script>
(()=>{
const method=document.getElementById('paymentMethod');
const source=document.getElementById('cashBankAccount');
const destination=document.getElementById('destinationAccount');
const warning=document.getElementById('paymentMethodWarning');
if(!method||!source||!warning)return;
const kind=select=>{const option=select?.selectedOptions?.[0];const subtype=String(option?.dataset?.accountSubtype||'').toLowerCase();const label=String(option?.textContent||'').toLowerCase();if(subtype.includes('bank')||label.includes('bank'))return 'bank';if(subtype.includes('cash')||label.includes('cash'))return 'cash';return ''};
function refresh(){const selected=String(method.value||'').toLowerCase();const sourceKind=kind(source);let message='';if(selected.includes('cash')&&sourceKind==='bank')message='The selected method refers to Cash but the source account appears to be a Bank account. Please confirm before saving.';if(selected.includes('bank')&&sourceKind==='cash')message='The selected method refers to Bank but the source account appears to be Cash. Please confirm before saving.';if(destination&&source.value&&destination.value&&source.value===destination.value)message='From and To accounts must be different.';warning.textContent=message;warning.style.display=message?'block':'none'}
[method,source,destination].filter(Boolean).forEach(element=>element.addEventListener('change',refresh));refresh();
})();
</script>

@if($definition['target_type'])
<script>
(()=>{
let docs=@json($documents);
const existing=@json(($allocations ?? collect())->map(fn($a)=>(array)$a)->values());
const body=document.getElementById('allocationBody');
const empty=document.getElementById('allocationEmpty');
const party=document.getElementById('partySelect');
const amount=document.getElementById('voucherAmount');
const esc=v=>String(v??'').replace(/[&<>\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function eligible(d){const pid=Number(party.value||0);return pid>0&&d.party_id&&Number(d.party_id)===pid;}
function opts(selected,selectedType){return '<option value="">Select document</option>'+docs.filter(eligible).map(d=>`<option value="${d.id}" data-target-type="${esc(d.target_type||'')}" ${Number(selected)===Number(d.id)&&String(selectedType||'')===String(d.target_type||'')?'selected':''}>${esc(d.number)} · ${esc(d.target_type||'')} · ${esc(d.party_name||'')} · ${esc(d.currency_code)} ${Number(d.outstanding||0).toFixed(2)} open</option>`).join('')}
function updateEmpty(){empty.style.display=body.children.length?'none':'block'}
function row(v={}){const i=body.children.length;const tr=document.createElement('tr');tr.innerHTML=`<td><select class="doc" name="allocations[${i}][target_id]" required>${opts(v.target_id,v.target_type)}</select><input type="hidden" class="targetType" name="allocations[${i}][target_type]" value="${esc(v.target_type||'')}"></td><td class="outstanding">0.00</td><td><input class="alloc" type="number" min="0.01" step="0.01" name="allocations[${i}][amount]" value="${Number(v.amount||0).toFixed(2)}" required></td><td><input name="allocations[${i}][notes]" value="${esc(v.notes||'')}"></td><td class="cvf27-action"><button type="button" class="cvf27-rm">×</button></td>`;body.appendChild(tr);tr.querySelector('.doc').addEventListener('change',e=>{tr.querySelector('.targetType').value=e.target.selectedOptions[0]?.dataset.targetType||'';refreshRow(tr);calc()});refreshRow(tr);calc();updateEmpty()}
function refreshRow(tr){const id=Number(tr.querySelector('.doc').value||0);const d=docs.find(x=>Number(x.id)===id);tr.querySelector('.outstanding').textContent=d?`${d.currency_code} ${Number(d.outstanding||0).toFixed(2)}`:'0.00'}
function renumber(){[...body.children].forEach((tr,i)=>tr.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/allocations\[\d+\]/,`allocations[${i}]`)))}
function calc(){const allocated=[...body.querySelectorAll('.alloc')].reduce((s,e)=>s+Number(e.value||0),0);const total=Number(amount.value||0);document.getElementById('allocatedTotal').textContent=allocated.toFixed(2);document.getElementById('unallocatedTotal').textContent=Math.max(0,total-allocated).toFixed(2)}
const documentUrl=@json(route('accounting.cash-vouchers.documents'));
let initialHydration=true;
async function loadDocuments({restoreExisting=false}={}){const pid=Number(party.value||0);docs=[];[...body.children].forEach(tr=>tr.remove());calc();updateEmpty();if(!pid){initialHydration=false;return;}const response=await fetch(documentUrl+'?voucher_type='+encodeURIComponent(@json($type))+'&party_id='+pid,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});if(!response.ok){initialHydration=false;return;}const payload=await response.json();docs=Array.isArray(payload.documents)?payload.documents:[];if(restoreExisting&&initialHydration)existing.forEach(row);initialHydration=false;}
if(Number(party.value||0))loadDocuments({restoreExisting:true});
document.getElementById('addAllocation').onclick=()=>row();
body.addEventListener('change',e=>{if(e.target.classList.contains('doc'))refreshRow(e.target.closest('tr'));calc()});
body.addEventListener('input',calc);
body.addEventListener('click',e=>{if(e.target.classList.contains('cvf27-rm')){e.target.closest('tr').remove();renumber();calc();updateEmpty()}});
amount.addEventListener('input',calc);
party.addEventListener('change',()=>loadDocuments({restoreExisting:false}));
calc();updateEmpty();
})();
</script>
@endif
@if(!$isExpense && !$isContra)
<script>
(()=>{
 const party=document.getElementById('partySelect'); const booking=document.getElementById('bookingSelect');
 if(!party||!booking)return;
 const type=@json($type); const endpoint=@json(route('accounting.cash-vouchers.bookings'));
 const esc=v=>String(v??'').replace(/[&<>\"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c]));
 async function loadBookings(){const partyId=Number(party.value||0);booking.innerHTML='<option value="">'+(partyId?'Loading related bookings…':'Select '+(type==='payment'||type==='supplier_advance'?'supplier':'customer')+' first')+'</option>';booking.disabled=!partyId;if(!partyId)return;const response=await fetch(endpoint+'?voucher_type='+encodeURIComponent(type)+'&party_id='+partyId,{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});const payload=response.ok?await response.json():{};const rows=Array.isArray(payload.bookings)?payload.bookings:[];booking.innerHTML='<option value="">'+(rows.length?'Optional':'No related bookings available')+'</option>'+rows.map(b=>'<option value="'+Number(b.id)+'">'+esc(b.reference)+'</option>').join('');booking.disabled=false}
 party.addEventListener('change',loadBookings);
 if(!party.value)loadBookings();
})();
</script>
@endif
<script>
(()=>{const proof=document.querySelector('input[name="payment_proof"]');const proofName=document.querySelector('.cvf27-proof-name');proof?.addEventListener('change',()=>{if(proofName)proofName.textContent=proof.files?.[0]?.name||'No file selected'});})();
</script>
@if($isExpense)
<script>
(()=>{
const accounts=@json($expenseAccounts);
const existing=@json(old('expense_lines',($expenseLines ?? collect())->map(fn($line)=>(array)$line)->values()->all()));
const body=document.getElementById('expenseLineBody');
const empty=document.getElementById('expenseLineEmpty');
const amount=document.getElementById('voucherAmount');
const currency=document.querySelector('[name="currency_code"]');
const esc=v=>String(v??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
 function accountLabel(a){return `${a.code} · ${a.name}`}
 function matches(term){term=String(term||'').trim().toLowerCase();return accounts.filter(a=>!term||String(a.code).toLowerCase().includes(term)||String(a.name).toLowerCase().includes(term)).slice(0,12)}
 function picker(tr,selected){const input=tr.querySelector('.accountSearch');const hidden=tr.querySelector('.accountId');const results=tr.querySelector('.accountResults');const current=accounts.find(a=>Number(a.id)===Number(selected));if(current)input.value=accountLabel(current);function render(){results.innerHTML=matches(input.value).map(a=>`<button type="button" class="cvf27-account-option" data-id="${a.id}">${esc(accountLabel(a))}</button>`).join('')||'<span class="cvf27-account-empty">No eligible expense account</span>';results.hidden=false}function choose(id){const a=accounts.find(x=>Number(x.id)===Number(id));if(!a)return;hidden.value=a.id;input.value=accountLabel(a);results.hidden=true}function focusResult(delta){const options=[...results.querySelectorAll('.cvf27-account-option')];const index=options.indexOf(document.activeElement);(options[index+delta]||options[delta>0?0:options.length-1])?.focus()}input.addEventListener('input',()=>{hidden.value='';render()});input.addEventListener('focus',render);input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();focusResult(1)}else if(e.key==='ArrowUp'){e.preventDefault();focusResult(-1)}else if(e.key==='Escape'){results.hidden=true}});results.addEventListener('keydown',e=>{if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();focusResult(e.key==='ArrowDown'?1:-1)}else if(e.key==='Enter'&&e.target.dataset.id){e.preventDefault();choose(e.target.dataset.id)}else if(e.key==='Escape'){e.preventDefault();results.hidden=true;input.focus()}});results.addEventListener('click',e=>{if(e.target.dataset.id)choose(e.target.dataset.id)});tr.querySelector('.cvf27-accountPicker').addEventListener('focusout',()=>setTimeout(()=>{if(!tr.querySelector('.cvf27-accountPicker').contains(document.activeElement))results.hidden=true},0))}
function renumber(){[...body.children].forEach((tr,i)=>{tr.querySelector('.lineNo').textContent=i+1;tr.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/expense_lines\[\d+\]/,`expense_lines[${i}]`))})}
function calculate(){const total=[...body.querySelectorAll('.expenseAmount')].reduce((sum,input)=>sum+Number(input.value||0),0);amount.value=total.toFixed(2);document.getElementById('expenseTotal').textContent=total.toFixed(2);document.getElementById('expenseCurrency').textContent=String(currency.value||'PKR').toUpperCase();empty.style.display=body.children.length?'none':'block'}
 function addLine(value={}){const i=body.children.length;const tr=document.createElement('tr');tr.innerHTML=`<td class="lineNo">${i+1}</td><td><div class="cvf27-accountPicker"><input class="accountSearch" type="search" placeholder="Search code or name" autocomplete="off" role="combobox"><input class="accountId" type="hidden" name="expense_lines[${i}][expense_account_id]" value="${Number(value.expense_account_id||0)||''}" required><div class="accountResults" hidden></div></div></td><td><input name="expense_lines[${i}][description]" value="${esc(value.description||'')}" placeholder="Purpose of expense"></td><td><input class="expenseAmount" type="number" name="expense_lines[${i}][amount]" min="0.01" step="0.01" value="${Number(value.amount||0).toFixed(2)}" required></td><td class="cvf27-action"><button type="button" class="cvf27-rm" aria-label="Remove expense line">×</button></td>`;body.appendChild(tr);picker(tr,value.expense_account_id);calculate();return tr}
 existing.forEach(addLine);if(!body.children.length)addLine();
 document.getElementById('addExpenseLine').addEventListener('click',()=>{const tr=addLine();tr.querySelector('.accountSearch')?.focus()});
body.addEventListener('input',calculate);
body.addEventListener('click',event=>{if(event.target.classList.contains('cvf27-rm')){event.target.closest('tr').remove();renumber();calculate()}});
currency.addEventListener('input',calculate);calculate();
})();
</script>
@endif
@endsection
