@extends('layouts.app')
@section('content')
<div class="container"><h1>Customer Advance Return</h1><form method="post" action="{{ route('accounting.customer-advance-returns.store') }}">@csrf
<label>Customer <select name="customer_party_id" required>@foreach($parties as $party)<option value="{{ $party['id'] ?? $party->id }}">{{ $party['name'] ?? $party->party_name }}</option>@endforeach</select></label>
<label>Return Date <input type="date" name="return_date" required></label><label>Amount <input type="number" step="0.01" name="amount" required></label><label>Currency <input name="currency_code" value="PKR" required></label><label>Branch <input type="number" name="branch_id" required></label><label>Cash/Bank Account <input name="cash_bank_account_code" required></label><label>Account Name <input name="cash_bank_account_name" required></label><label>Narration <textarea name="narration"></textarea></label><button type="submit">Save Customer Advance Return</button></form></div>
@endsection
