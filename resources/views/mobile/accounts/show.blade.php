@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title', 'Accounts Document')
@section($layoutMeta['content_section'] ?? 'content')
@include('mobile.accounts._responsive')
<main class="mobile-accounts-page mobile-document-page">
    <div class="mobile-brand"><span>Easy Ticket ERP</span><a href="{{ route('mobile.accounts.index') }}">← Accounts</a></div>
    <h1 class="mobile-title">{{ ucwords(str_replace('_', ' ', $kind)) }}</h1>
    <p class="mobile-sub">{{ $row->voucher_no ?? $row->adjustment_no ?? $row->return_no ?? ('#'.$id) }} · {{ ucwords(str_replace('_', ' ', (string) $row->status)) }}</p>
    @if(session('success'))<div class="mobile-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="mobile-validation-error">{{ $errors->first() }}</div>@endif
    <section class="mobile-section"><div class="mobile-fields">
        @foreach((array) $row as $key => $value)
            @if(in_array($key, ['id','created_at','updated_at','created_by','submitted_by','approved_by','posted_by','reversed_by'], true)) @continue @endif
            <label>{{ ucwords(str_replace('_', ' ', $key)) }}<span class="mobile-readonly-value">{{ is_scalar($value) && $value !== '' ? $value : '—' }}</span></label>
        @endforeach
    </div></section>
    @if(!empty($activities) && count($activities))<section class="mobile-section"><strong>Activity</strong><div class="mobile-activity-list">@foreach($activities as $activity)<div><strong>{{ ucwords(str_replace('_',' ', $activity->action ?? 'update')) }}</strong><span>{{ $activity->notes ?? '' }}</span></div>@endforeach</div></section>@endif
    <div class="mobile-actions">
        @if($row->status === 'draft' && $canSubmit)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.workflow', [$id, 'submit']) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.workflow', [$id, 'submit']) : route('mobile.accounts.customer-returns.workflow', [$id, 'submit'])) }}">@csrf<button type="submit">Submit for Approval</button></form>@endif
        @if($row->status === 'pending_approval' && $canApprove)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.workflow', [$id, 'approve']) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.workflow', [$id, 'approve']) : route('mobile.accounts.customer-returns.workflow', [$id, 'approve'])) }}">@csrf<button type="submit">Approve</button></form>@endif
        @if($row->status === 'approved' && $canPost)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.workflow', [$id, 'post']) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.workflow', [$id, 'post']) : route('mobile.accounts.customer-returns.workflow', [$id, 'post'])) }}">@csrf<button type="submit">Post</button></form>@endif
        @if($row->status === 'posted' && $canReverse)<form method="post" action="{{ $kind === 'voucher' ? route('mobile.accounts.vouchers.reverse', $id) : ($kind === 'adjustment' ? route('mobile.accounts.adjustments.reverse', $id) : route('mobile.accounts.customer-returns.reverse', $id)) }}">@csrf<input name="reason" required placeholder="Reversal reason"><button type="submit">Reverse</button></form>@endif
    </div>
</main>
@endsection
