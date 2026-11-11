@extends('layouts.app')
@section('content')
<div class="container">
    <h1>Draft Invoice Reconciliation Preview</h1>
    <p>Invoice {{ $invoice->invoice_no }} · Current PKR {{ number_format($preview['current_total'],2) }} · Expected PKR {{ number_format($preview['expected_total'],2) }}</p>
    <p>Difference: PKR {{ number_format($preview['amount_variance'],2) }}</p>
    <h3>Missing canonical services</h3>
    <ul>@forelse($preview['missing_lines'] as $line)<li>#{{ $line->id }} · {{ $line->category }} · {{ $line->description }} · PKR {{ number_format($line->line_total,2) }}</li>@empty<li>None</li>@endforelse</ul>
    @if(count($preview['missing_lines']))
    <form method="POST" action="{{ route('sales.invoices.draft-reconciliation.reconcile', $invoice) }}">
        @csrf
        <input type="hidden" name="preview_hash" value="{{ $preview['preview_hash'] }}">
        <label>Reason <textarea name="reason" required minlength="5" maxlength="1000"></textarea></label>
        <button type="submit">Confirm Draft Reconciliation</button>
    </form>
    @else
        <p>Invoice is already reconciled.</p>
    @endif
</div>
@endsection
