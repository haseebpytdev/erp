@php
    $documentNumber = $row->voucher_no ?? $row->adjustment_no ?? $row->return_no ?? ('#'.$id);
    $typeLabel = match ($kind) {
        'voucher' => ucwords(str_replace('_', ' ', (string) ($row->voucher_type ?? 'Voucher'))),
        'adjustment' => 'Advance Adjustment',
        'return' => 'Customer Advance Return',
        default => ucwords(str_replace('_', ' ', $kind)),
    };
    $statusLabel = ucwords(str_replace('_', ' ', (string) $row->status));
    $amount = $row->amount ?? null;
    $primary = array_filter([
        ['label' => $kind === 'return' ? 'Customer' : (($row->party_type ?? '') === 'supplier' ? 'Supplier' : 'Party'), 'value' => $row->party_name ?? $row->customer_name ?? null],
        ['label' => 'Branch', 'value' => $row->branch_id ?? null],
        ['label' => 'Cash / Bank Account', 'value' => $row->cash_bank_account_name ?? $row->cash_bank_account_code ?? null],
        ['label' => 'Date', 'value' => $row->voucher_date ?? $row->adjustment_date ?? $row->return_date ?? null],
        ['label' => 'Reference / Transaction No', 'value' => $row->transaction_reference ?? null],
        ['label' => 'Payment Method', 'value' => $row->payment_method ?? null],
        ['label' => 'Proof / Receipt', 'value' => $row->payment_proof_original_name ?? null],
    ], static fn (array $field): bool => $field['value'] !== null && $field['value'] !== '');
@endphp
@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Accounts Document')
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<main class="mobile-accounts-page mobile-document-page">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.accounts.index') }}">← Accounts</a></div>
    <p class="mobile-eyebrow">{{ $typeLabel }}</p>
    <h1 class="mobile-title">{{ $documentNumber }}</h1>
    <div class="mobile-document-hero"><span class="mobile-status-badge">{{ $statusLabel }}</span>@if($amount !== null)<strong>{{ number_format((float) $amount, 2) }} {{ $row->currency_code ?? 'PKR' }}</strong>@endif</div>
    @if(session('success'))<div class="mobile-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mobile-validation-error">{{ $errors->first() }}</div>@endif
    <section class="mobile-section mobile-primary-detail"><strong>Transaction Details</strong><div class="mobile-detail-grid">@foreach($primary as $field)<div><span class="mobile-detail-label">{{ $field['label'] }}</span><span class="mobile-readonly-value">{{ $field['value'] }}</span></div>@endforeach</div></section>
    <details class="mobile-more"><summary>More Details</summary><div class="mobile-detail-grid mobile-secondary-detail">
        @foreach([
            ['label' => 'Currency', 'value' => $row->currency_code ?? null],
            ['label' => 'Exchange Rate', 'value' => $row->exchange_rate ?? null],
            ['label' => 'Instrument Number', 'value' => $row->instrument_no ?? null],
            ['label' => 'Booking', 'value' => $row->booking_id ?? null],
            ['label' => 'Target', 'value' => $row->target_number ?? null],
            ['label' => 'Narration', 'value' => $row->narration ?? $row->remarks ?? null],
        ] as $field)
            @if($field['value'] !== null && $field['value'] !== '')<div><span class="mobile-detail-label">{{ $field['label'] }}</span><span class="mobile-readonly-value">{{ $field['value'] }}</span></div>@endif
        @endforeach
        @if(!empty($allocations))<div><span class="mobile-detail-label">Allocations</span><span class="mobile-readonly-value">{{ count($allocations) }} allocation(s)</span></div>@endif
    </div></details>
    @if(!empty($activities) && count($activities))<details class="mobile-more"><summary>Activity</summary><div class="mobile-activity-list">@foreach($activities as $activity)<div><strong>{{ ucwords(str_replace('_',' ', $activity->action ?? 'update')) }}</strong><span>{{ $activity->notes ?? $activity->remarks ?? '' }}</span></div>@endforeach</div></details>@endif
    <div class="mobile-actions">
        @if($row->status === 'draft' && $canSubmit)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.workflow', [$id, 'submit']) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.workflow', [$id, 'submit']) : route('mobile.accounts.customer-returns.workflow', [$id, 'submit'])) }}">@csrf<button type="submit">Submit for Approval</button></form>@endif
        @if($row->status === 'pending_approval' && $canApprove)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.workflow', [$id, 'approve']) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.workflow', [$id, 'approve']) : route('mobile.accounts.customer-returns.workflow', [$id, 'approve'])) }}">@csrf<button type="submit">Approve</button></form>@endif
        @if($row->status === 'approved' && $canPost)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.workflow', [$id, 'post']) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.workflow', [$id, 'post']) : route('mobile.accounts.customer-returns.workflow', [$id, 'post'])) }}">@csrf<button type="submit">Post</button></form>@endif
        @if($row->status === 'posted' && $canReverse)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.reverse', $id) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.reverse', $id) : route('mobile.accounts.customer-returns.reverse', $id)) }}">@csrf<input name="reason" required placeholder="Reversal reason"><button type="submit">Reverse</button></form>@endif
    </div>
</main>
@endsection
