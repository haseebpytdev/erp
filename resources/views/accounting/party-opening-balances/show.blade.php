@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title',$row->opening_no)
@section($layoutMeta['content_section'] ?? 'content')
<div style="max-width:900px;margin:auto"><h2>{{ $row->opening_no }}</h2><p>{{ $row->party_name }} · {{ $row->balance_type }} · {{ number_format($row->amount,2) }} {{ $row->currency_code }} · {{ strtoupper($row->status) }}</p>@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@if($row->status==='draft'&&$canUpdate)<form method="post" action="{{ route('accounting.party-opening-balances.workflow',[$row->id,'submit']) }}" style="display:inline">@csrf<button>Submit</button></form>@endif
@if($row->status==='pending_approval'&&$canApprove)<form method="post" action="{{ route('accounting.party-opening-balances.workflow',[$row->id,'approve']) }}" style="display:inline">@csrf<button>Approve</button></form>@endif
@if($row->status==='approved'&&$canPost)<form method="post" action="{{ route('accounting.party-opening-balances.workflow',[$row->id,'post']) }}" style="display:inline">@csrf<button>Post</button></form>@endif
@if($row->status==='posted'&&$canReverse)<form method="post" action="{{ route('accounting.party-opening-balances.reverse',$row->id) }}">@csrf<input name="reason" placeholder="Reversal reason" required><button>Reverse</button></form>@endif</div>
@endsection
