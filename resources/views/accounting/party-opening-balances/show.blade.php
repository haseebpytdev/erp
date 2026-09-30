@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title',$row->document_no)
@section($layoutMeta['content_section'] ?? 'content')
<div style="max-width:900px;margin:auto"><h2>{{ $row->document_no }}</h2><p>{{ $row->party_name }} · {{ $row->opening_type }} · {{ number_format($row->amount,2) }} {{ $row->currency_code }} · {{ strtoupper($row->status) }}</p>@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
@foreach(['submit'=>'Submit','approve'=>'Approve','post'=>'Post'] as $a=>$label) @if(($a==='submit'&&$row->status==='draft')||($a==='approve'&&$row->status==='pending_approval')||($a==='post'&&$row->status==='approved'))<form method="post" action="{{ route('accounting.party-opening-balances.workflow',[$row->id,$a]) }}" style="display:inline">@csrf<button>{{ $label }}</button></form>@endif @endforeach
@if($row->status==='posted')<form method="post" action="{{ route('accounting.party-opening-balances.reverse',$row->id) }}">@csrf<input name="reason" placeholder="Reversal reason" required><button>Reverse</button></form>@endif</div>
@endsection
