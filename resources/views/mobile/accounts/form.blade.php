@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', $definition['label'])
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<main class="mobile-accounts-page mobile-form">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.accounts.index') }}">← Accounts</a></div>
    <h1 class="mobile-title">{{ $definition['label'] }}</h1>
    @if(empty($branchOptions))
        <div class="mobile-validation-error" role="alert">No authorized branch is available for this transaction.</div>
    @endif
    <form method="post" action="{{ route('mobile.accounts.'.$type.'.store') }}" enctype="multipart/form-data" data-mobile-submit-guard>
        @csrf
        <input type="hidden" name="voucher_type" value="{{ $voucherType }}">
        <div class="mobile-fields">
            <label>Branch *<select name="branch_id" required @disabled(empty($branchOptions))><option value="">Select branch</option>@foreach($branchOptions as $branch)<option value="{{ $branch['id'] }}" @selected(count($branchOptions) === 1)>{{ $branch['name'] }}</option>@endforeach</select></label>
            <label>{{ $definition['party_type']==='supplier'?'Pay To':'Receive From' }} *<select name="party_id" required><option value="">Select party</option>@foreach($parties as $party)<option value="{{ $party['id'] }}">{{ $party['name'] }}</option>@endforeach</select></label>
            <label>Amount *<input name="amount" type="number" step="0.01" min="0.01" required></label>
            <label>Cash / Bank Account *<select name="cash_bank_account" required><option value="">Select account</option>@foreach($cashBankAccounts as $a)<option value="{{ $a['code'] }}">{{ $a['code'] }} — {{ $a['name'] }}</option>@endforeach</select></label>
            <label>Voucher Date *<input name="voucher_date" type="date" value="{{ now()->toDateString() }}" required></label>
            <label>Currency *<input name="currency_code" value="PKR" required></label>
            <label>Exchange Rate *<input name="exchange_rate" type="number" step="0.00000001" value="1" required></label>
            <label>Payment Method *<select name="payment_method" required>@foreach($paymentMethods as $method)<option value="{{ $method }}">{{ $method }}</option>@endforeach</select></label>
        </div>
        <details class="mobile-more"><summary>More Options</summary><div class="mobile-fields" style="margin-top:12px">
            <label>Booking ID<input name="booking_id" type="number" min="1"></label>
            <label>Value Date<input name="value_date" type="date"></label>
            <label>Bank Name<input name="bank_name"></label>
            <label>Instrument Number<input name="instrument_no"></label>
            <label>Transaction Reference<input name="transaction_reference"></label>
            <label>Narration<textarea name="narration" rows="3"></textarea></label>
            <label>Proof / Receipt Image<input id="mobile-payment-proof" name="payment_proof" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
            <div class="mobile-ai-receipt" data-ai-receipt>
                <button type="button" class="secondary" data-ai-scan>Scan with AI</button>
                <p data-ai-status class="mobile-help" role="status">AI suggestions — review before using.</p>
                <div data-ai-panel hidden></div>
            </div>
        </div></details>
        @if(!empty($branchOptions))
            <div class="mobile-actions"><button type="submit" name="after_save" value="draft">Save Draft</button><button type="submit" class="secondary" name="after_save" value="submit">Save &amp; Submit</button></div>
        @endif
    </form>
</main>
<script>
(() => {
    const form = document.querySelector('[data-mobile-submit-guard]');
    const file = document.getElementById('mobile-payment-proof');
    const scan = document.querySelector('[data-ai-scan]');
    const status = document.querySelector('[data-ai-status]');
    const panel = document.querySelector('[data-ai-panel]');
    if (!form || !file || !scan || !status || !panel) return;
    const endpoint = @json(route('mobile.accounts.receipt-capture.preview'));
    const fields = { receipt_date: 'voucher_date', amount: 'amount', currency: 'currency_code', transaction_reference: 'transaction_reference', bank_name: 'bank_name', payment_method: 'payment_method', narration: 'narration' };
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const apply = (name, value) => { const input = form.elements[name]; if (input && !input.value && value !== null && value !== '') input.value = value; };
    scan.addEventListener('click', async () => {
        if (!file.files[0]) { status.textContent = 'Select a receipt first.'; return; }
        scan.disabled = true; status.textContent = 'Reading receipt…'; panel.hidden = true;
        const body = new FormData(); body.append('_token', form.querySelector('[name="_token"]').value); body.append('payment_proof', file.files[0]); body.append('voucher_type', form.elements.voucher_type.value); if (form.elements.branch_id?.value) body.append('branch_id', form.elements.branch_id.value);
        try {
            const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const result = await response.json();
            if (!result.ok) { status.textContent = result.message || 'AI could not read this receipt. You can continue manually.'; return; }
            const suggestions = result.suggestions || {};
            panel.innerHTML = '<strong>AI Receipt Suggestions</strong><p>Review each value before applying.</p>' + Object.entries(fields).map(([key, target]) => { const item = suggestions[key] || {}; return `<div class="mobile-ai-suggestion"><span>${esc(key.replaceAll('_',' '))}: ${esc(item.value ?? '—')} (${esc(item.level || 'Low')})</span><button type="button" data-ai-apply="${esc(target)}" data-ai-value="${esc(item.value ?? '')}">Apply</button></div>`; }).join('') + `<p>Voucher type suggestion: ${esc(suggestions.voucher_type_suggestion?.value || 'UNKNOWN')} — choose the route/type yourself.</p>`;
            panel.hidden = false; status.textContent = 'AI suggestions ready. No voucher was saved.';
            panel.querySelectorAll('[data-ai-apply]').forEach(button => button.addEventListener('click', () => apply(button.dataset.aiApply, button.dataset.aiValue)));
        } catch (error) { status.textContent = 'AI could not read this receipt. You can continue manually.'; }
        finally { scan.disabled = false; }
    });
})();
</script>
@endsection
