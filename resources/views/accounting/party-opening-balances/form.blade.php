@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Party Opening Balance')
@section($layoutMeta['content_section'] ?? 'content')
<div style="max-width:900px;margin:auto"><h2>Party Opening Balance</h2>@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('accounting.party-opening-balances.store') }}">@csrf
<p><label>Party Type * <select name="party_type" id="partyType"><option value="customer">Customer</option><option value="vendor">Vendor / Supplier</option></select></label></p>
<p><label>Party * <select name="party_id" id="party"><option value="">Select party</option></select></label></p>
<p><label>Opening Type * <select name="opening_type">@foreach($openingTypes as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label></p>
<p><label>Opening Date * <input type="date" name="opening_date" value="{{ now()->toDateString() }}" required></label> <label>Branch * <input type="number" name="branch_id" min="1" required></label></p>
<p><label>Amount * <input type="number" name="amount" min="0.01" step="0.01" required></label> <label>Currency <input name="currency_code" value="PKR" required></label> <label>Exchange Rate <input type="number" name="exchange_rate" value="1" step="0.00000001" min="0.00000001"></label></p>
<p><label>Reference <input name="reference"></label></p><p><label>Narration / Notes <textarea name="narration"></textarea></label></p><button type="submit">Save Draft</button></form></div>
<script>const c=@json($parties),v=@json($vendorParties),t=document.getElementById('partyType'),p=document.getElementById('party');function fill(){p.innerHTML='<option value="">Select party</option>'+((t.value==='vendor'?v:c).map(x=>'<option value="'+x.id+'">'+String(x.name).replace(/[&<>]/g,'')+'</option>').join(''))}t.addEventListener('change',fill);fill();</script>
@endsection
