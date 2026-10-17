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
    <form method="post" action="{{ route('mobile.accounts.'.$type.'.store') }}" enctype="multipart/form-data">
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
            <label>Proof / Receipt Image<input name="payment_proof" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
        </div></details>
        @if(!empty($branchOptions))
            <div class="mobile-actions"><button name="after_save" value="draft">Save Draft</button><button class="secondary" name="after_save" value="submit">Save &amp; Submit</button></div>
        @endif
    </form>
</main>
@endsection
