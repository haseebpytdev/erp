@extends('layouts.app')
@section('content')
<div class="container"><h1>{{ $row->return_no }}</h1><p>Customer: {{ $row->customer_name }}</p><p>Amount: {{ number_format($row->amount,2) }} {{ $row->currency_code }}</p><p>Status: {{ $row->status }}</p>@foreach(['submit','approve','post'] as $action)<form method="post" action="{{ route('accounting.customer-advance-returns.workflow',[$row->id,$action]) }}" style="display:inline">@csrf<button type="submit">{{ ucfirst($action) }}</button></form>@endforeach</div>
@endsection
