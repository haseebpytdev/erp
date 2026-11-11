@extends('layouts.app')
@section('title','Group Travel Package · '.$booking->booking_no)
@section('page_title','Group Travel Package')
@section('content')
<style>
.gtp{max-width:1080px;margin:0 auto;padding-bottom:28px}.gtp *{box-sizing:border-box}
.gtp-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px}.gtp-head h1{margin:0;font-size:24px;line-height:1.15}.gtp-head p{margin:5px 0 0;color:var(--muted);font-size:10px}.gtp-actions{display:flex;gap:7px;flex-wrap:wrap}
.gtp-flow{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;margin-bottom:14px}.gtp-flow-step{border:1px solid var(--line);border-radius:10px;background:#fff;padding:8px 9px;min-width:0}.gtp-flow-step small{display:block;font-size:7px;font-weight:900;color:var(--muted);text-transform:uppercase}.gtp-flow-step strong{display:block;margin-top:3px;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gtp-flow-step.done{background:#f1faf5;border-color:#d1eadb}.gtp-flow-step.current{background:#eef5ff;border-color:#cbdff8}.gtp-flow-step.locked{opacity:.55}
.gtp-summary{display:grid;grid-template-columns:1.1fr repeat(3,1fr);gap:8px;margin-bottom:12px}.gtp-kpi{background:#fff;border:1px solid var(--line);border-radius:11px;padding:10px 11px;min-width:0}.gtp-kpi small{display:block;font-size:7px;font-weight:900;text-transform:uppercase;color:var(--muted)}.gtp-kpi strong{display:block;font-size:15px;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gtp-kpi span{display:block;font-size:8px;color:var(--muted);margin-top:3px}
.gtp-card{background:#fff;border:1px solid var(--line);border-radius:13px;padding:14px;margin-bottom:12px}.gtp-card h3{margin:0;font-size:14px}.gtp-sub{font-size:9px;color:var(--muted);margin-top:4px;line-height:1.4}.gtp-section-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:11px}.gtp-step{display:inline-flex;align-items:center;justify-content:center;width:23px;height:23px;border-radius:7px;background:#edf4fc;color:#21598f;font-size:9px;font-weight:900;margin-right:7px}
.gtp-fields{display:grid;grid-template-columns:1fr 1fr;gap:9px}.gtp-field{min-width:0}.gtp-field.wide{grid-column:1/-1}.gtp-field>span{display:block;font-size:8px;font-weight:850;margin-bottom:4px}.gtp-field input,.gtp-field select,.gtp-field textarea{width:100%;min-width:0;border:1px solid var(--line);border-radius:8px;padding:8px 9px;background:#fff;font-size:10px}.gtp-field textarea{min-height:65px;resize:vertical}
.gtp-visa{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid #cfe9da;background:#f1faf5;border-radius:9px;font-size:9px;color:#276244}.gtp-visa b{white-space:nowrap}.gtp-dot{width:8px;height:8px;border-radius:999px;background:#28a56a;flex:0 0 auto}
.gtp-rate-list{display:grid;gap:8px}.gtp-rate-row{display:grid;grid-template-columns:1.05fr .75fr 1fr 1fr .9fr .9fr .9fr;gap:7px;align-items:end;border:1px solid var(--line);border-radius:10px;padding:9px;background:#fbfcfe}.gtp-rate-row .type strong{display:block;font-size:11px}.gtp-rate-row .type span{display:block;font-size:8px;color:var(--muted);margin-top:2px}.gtp-rate-row label span,.gtp-total small{display:block;font-size:7px;font-weight:850;color:var(--muted);text-transform:uppercase;margin-bottom:3px}.gtp-rate-row input{width:100%;min-width:0;border:1px solid var(--line);border-radius:7px;padding:7px 8px;font-size:10px;background:#fff}.gtp-total strong{display:block;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-bottom:7px}
.gtp-advanced{border:1px solid var(--line);border-radius:11px;background:#fff;margin-bottom:12px;overflow:hidden}.gtp-advanced summary{list-style:none;cursor:pointer;padding:11px 13px;display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:10px;font-weight:850}.gtp-advanced summary::-webkit-details-marker{display:none}.gtp-advanced summary span{font-size:8px;color:var(--muted);font-weight:600}.gtp-advanced[open] summary{border-bottom:1px solid var(--line)}.gtp-advanced-body{padding:11px 12px}
.gtp-override-row{display:grid;grid-template-columns:1.4fr .55fr 1fr 1fr .75fr;gap:7px;align-items:center;padding:8px 0;border-top:1px solid var(--line);font-size:9px}.gtp-override-row:first-child{border-top:0}.gtp-passenger strong{display:block;font-size:9px}.gtp-passenger span{display:block;font-size:7px;color:var(--muted);margin-top:2px}.gtp-badge{display:inline-flex;align-items:center;justify-content:center;padding:3px 7px;border-radius:999px;background:#e9f1f9;font-size:8px;font-weight:900}.gtp-override-row input{width:100%;min-width:0;border:1px solid var(--line);border-radius:7px;padding:7px;font-size:9px}.gtp-right{text-align:right}.gtp-money{font-variant-numeric:tabular-nums}
.gtp-empty{border:1px dashed var(--line);border-radius:12px;background:#fff;padding:24px;text-align:center}.gtp-empty h3{margin:0 0 6px;font-size:16px}.gtp-empty p{margin:0 auto 12px;max-width:570px;color:var(--muted);font-size:10px;line-height:1.45}
.gtp-save{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid var(--line);border-radius:12px;background:#fff;padding:10px 12px}.gtp-save p{margin:0;font-size:8px;color:var(--muted);line-height:1.4}.gtp-save .btn{min-width:220px}
@media(max-width:980px){.gtp-flow{grid-template-columns:repeat(3,1fr)}.gtp-summary{grid-template-columns:repeat(2,1fr)}.gtp-rate-row{grid-template-columns:1fr 1fr 1fr}.gtp-rate-row .type{grid-column:1/-1}.gtp-override-row{grid-template-columns:1fr 1fr}.gtp-passenger{grid-column:1/-1}.gtp-right{text-align:left}}
@media(max-width:650px){.gtp-head{flex-direction:column}.gtp-flow,.gtp-summary,.gtp-fields,.gtp-rate-row{grid-template-columns:1fr}.gtp-field.wide{grid-column:auto}.gtp-save{flex-direction:column;align-items:stretch}.gtp-save .btn{width:100%;min-width:0}.gtp-override-row{grid-template-columns:1fr}}
</style>

<div class="gtp">
    <div class="gtp-head">
        <div>
            <h1>Step 3 · Create Group Package</h1>
            <p>{{ $booking->booking_no }} · {{ $booking->customer?->display_name }} · {{ str_replace('_',' ',$booking->booking_type) }}</p>
        </div>
        <div class="gtp-actions"><a class="btn btn-secondary" href="{{ route('operations.bookings.show',$booking) }}#booking-passengers">← Booking Workspace</a></div>
    </div>

    <div class="gtp-flow" aria-label="Group package booking steps">
        <div class="gtp-flow-step done"><small>Step 1</small><strong>✓ Booking</strong></div>
        <div class="gtp-flow-step {{ $activePassengers->isNotEmpty()?'done':'current' }}"><small>Step 2</small><strong>{{ $activePassengers->isNotEmpty()?'✓ ':'' }}Passengers</strong></div>
        <div class="gtp-flow-step current"><small>Step 3</small><strong>Package</strong></div>
        <div class="gtp-flow-step {{ $package?'current':'locked' }}"><small>Step 4</small><strong>Services</strong></div>
        <div class="gtp-flow-step locked"><small>Step 5</small><strong>Review</strong></div>
        <div class="gtp-flow-step locked"><small>Step 6</small><strong>Confirm</strong></div>
    </div>

    @if(session('status'))<div class="callout" style="margin-bottom:12px">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="callout warn" style="margin-bottom:12px"><strong>Package could not be saved.</strong> {{ $errors->first() }}</div>@endif

    @if(!$installed)
        <div class="callout warn"><strong>Group Travel Package database tables are not installed yet.</strong> Run System → Health & Updates → Safe Database Upgrade once.</div>
    @elseif($activePassengers->isEmpty())
        <div class="gtp-empty">
            <h3>Add passengers before creating the package</h3>
            <p>Package pricing is calculated from the actual Adult / Child / Infant passenger mix, so Step 2 must be completed first.</p>
            <a class="btn btn-primary" href="{{ route('operations.bookings.show',$booking) }}#booking-passengers">Go to Passengers</a>
        </div>
    @else
        <div class="gtp-summary">
            <div class="gtp-kpi"><small>Passenger Mix</small><strong>{{ $activePassengers->count() }} pax</strong><span>{{ $summary['counts']['ADULT'] }} Adult · {{ $summary['counts']['CHILD'] }} Child · {{ $summary['counts']['INFANT'] }} Infant</span></div>
            <div class="gtp-kpi"><small>Vendor Cost</small><strong class="gtp-money" data-summary-buy>{{ $summary['currency'] }} {{ number_format($summary['vendor_total'],2) }}</strong><span>Package purchase</span></div>
            <div class="gtp-kpi"><small>Customer Sale</small><strong class="gtp-money" data-summary-sale>{{ $summary['currency'] }} {{ number_format($summary['sale_total'],2) }}</strong><span>Package selling</span></div>
            <div class="gtp-kpi"><small>Gross Margin</small><strong class="gtp-money" data-summary-margin>{{ $summary['currency'] }} {{ number_format($summary['margin'],2) }}</strong><span><span data-summary-percent>{{ number_format($summary['margin_percent'],2) }}</span>% of sale</span></div>
        </div>

        <form method="POST" action="{{ route('operations.bookings.group-package.update',$booking) }}" data-gtp-form>
            @csrf
            @method('PUT')

            <div class="gtp-card">
                <div class="gtp-section-head"><div><h3><span class="gtp-step">3A</span>Package Details</h3><div class="gtp-sub">Only the basic package and supplier information staff need to continue.</div></div></div>
                <div class="gtp-fields">
                    <label class="gtp-field"><span>Package Name *</span><input name="package_name" maxlength="180" required value="{{ old('package_name',$package?->package_name ?: 'Umrah Group Package') }}" placeholder="e.g. 21 Days Umrah Package"></label>
                    <label class="gtp-field"><span>Package Vendor *</span><select name="vendor_party_id" required><option value="">Select Vendor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" {{ (string)old('vendor_party_id',$package?->vendor_party_id)===(string)$vendor->id?'selected':'' }}>{{ $vendor->display_name }}</option>@endforeach</select></label>
                    <label class="gtp-field"><span>Package Type</span><select name="package_type" required>@foreach(['UMRAH'=>'Umrah Package','TOUR'=>'Tour Package','GROUP_HOLIDAY'=>'Group Holiday','CUSTOM_GROUP'=>'Custom Group Package'] as $value=>$label)<option value="{{ $value }}" {{ old('package_type',$package?->package_type ?: 'UMRAH')===$value?'selected':'' }}>{{ $label }}</option>@endforeach</select></label>
                    <label class="gtp-field"><span>Vendor Reference</span><input name="vendor_reference" maxlength="100" value="{{ old('vendor_reference',$package?->vendor_reference) }}" placeholder="Optional supplier / group ref"></label>
                    <div class="gtp-field wide"><div class="gtp-visa"><span class="gtp-dot"></span><div><b>Visa: ISSUED / INCLUDED</b> · No separate visa-status workflow is required for this package.</div></div></div>
                </div>
            </div>

            <div class="gtp-card">
                <div class="gtp-section-head"><div><h3><span class="gtp-step">3B</span>Package Price per Passenger</h3><div class="gtp-sub">Only passenger types present in this booking are shown. Enter supplier cost and customer selling price per passenger.</div></div></div>
                <div class="gtp-rate-list">
                    @foreach(['ADULT'=>'Adult','CHILD'=>'Child','INFANT'=>'Infant'] as $type=>$label)
                        @php
                            $prefix=strtolower($type); $count=(int)($summary['counts'][$type] ?? 0);
                            $buyField=$prefix.'_vendor_cost'; $sellField=$prefix.'_customer_price';
                            $buy=(float)old($buyField,$package?->{$buyField} ?? 0); $sell=(float)old($sellField,$package?->{$sellField} ?? 0);
                        @endphp
                        @if($count>0)
                            <div class="gtp-rate-row" data-rate-row="{{ $type }}" data-pax-count="{{ $count }}">
                                <div class="type"><strong>{{ $label }}</strong><span>{{ $count }} passenger{{ $count===1?'':'s' }}</span></div>
                                <div class="gtp-total"><small>Pax</small><strong>{{ $count }}</strong></div>
                                <label><span>Vendor Cost / Pax</span><input type="number" step="0.01" min="0" name="{{ $buyField }}" value="{{ number_format($buy,2,'.','') }}" data-base-buy="{{ $type }}" required></label>
                                <label><span>Customer Price / Pax</span><input type="number" step="0.01" min="0" name="{{ $sellField }}" value="{{ number_format($sell,2,'.','') }}" data-base-sell="{{ $type }}" required></label>
                                <div class="gtp-total"><small>Cost Total</small><strong class="gtp-money" data-rate-buy-total>—</strong></div>
                                <div class="gtp-total"><small>Sale Total</small><strong class="gtp-money" data-rate-sale-total>—</strong></div>
                                <div class="gtp-total"><small>Margin</small><strong class="gtp-money" data-rate-margin>—</strong></div>
                            </div>
                        @else
                            <input type="hidden" name="{{ $buyField }}" value="{{ number_format($buy,2,'.','') }}" data-base-buy="{{ $type }}">
                            <input type="hidden" name="{{ $sellField }}" value="{{ number_format($sell,2,'.','') }}" data-base-sell="{{ $type }}">
                        @endif
                    @endforeach
                </div>
            </div>

            <details class="gtp-advanced" {{ $overrides->isNotEmpty() ? 'open' : '' }}>
                <summary><div>Advanced / Optional</div><span>Passenger price overrides and internal package notes</span></summary>
                <div class="gtp-advanced-body">
                    <label class="gtp-field wide" style="display:block;margin-bottom:10px"><span>Internal Package Notes</span><textarea name="notes" rows="2" placeholder="Optional internal note. Not printed as package pricing on the client voucher.">{{ old('notes',$package?->notes) }}</textarea></label>
                    @foreach($activePassengers as $passenger)
                        @php
                            $override=$overrides->get($passenger->id); $type=strtoupper((string)$passenger->pax_type);
                            $row=$summary['rows'][$passenger->id] ?? ['margin'=>0];
                        @endphp
                        <div class="gtp-override-row" data-passenger-row data-pax-type="{{ $type }}">
                            <div class="gtp-passenger"><strong>{{ $passenger->passenger_no }} · {{ $passenger->fullName() }}</strong><span>{{ $passenger->passport_no ?: 'No passport no.' }}</span></div>
                            <div><span class="gtp-badge">{{ ucfirst(strtolower($type)) }}</span></div>
                            <div><input type="number" step="0.01" min="0" name="overrides[{{ $passenger->id }}][vendor_cost]" value="{{ old('overrides.'.$passenger->id.'.vendor_cost',$override?->vendor_cost_override) }}" placeholder="Vendor override" data-override-buy></div>
                            <div><input type="number" step="0.01" min="0" name="overrides[{{ $passenger->id }}][customer_price]" value="{{ old('overrides.'.$passenger->id.'.customer_price',$override?->customer_price_override) }}" placeholder="Customer override" data-override-sell></div>
                            <div class="gtp-right gtp-money" data-passenger-margin>{{ $summary['currency'] }} {{ number_format($row['margin'],2) }}</div>
                        </div>
                    @endforeach
                </div>
            </details>

            <div class="gtp-save">
                <p>After saving, staff returns to the Booking Workspace at <strong>Step 4 · Services</strong> to enter Flights, Hotels and Transport. Visa remains issued automatically.</p>
                <button class="btn btn-primary">{{ $package ? 'Save Changes & Continue to Services' : 'Save Package & Continue to Services' }}</button>
            </div>
        </form>
    @endif
</div>

@if($installed && $activePassengers->isNotEmpty())
<script>
(function(){
    const form=document.querySelector('[data-gtp-form]'); if(!form)return;
    const currency=@json($summary['currency']);
    const money=v=>currency+' '+new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0));
    function base(type,kind){const el=form.querySelector(`[data-base-${kind}="${type}"]`);return Number(el?.value||0)}
    function refresh(){
        form.querySelectorAll('[data-rate-row]').forEach(row=>{
            const type=row.dataset.rateRow,count=Number(row.dataset.paxCount||0),buy=base(type,'buy'),sale=base(type,'sell');
            row.querySelector('[data-rate-buy-total]').textContent=money(buy*count);
            row.querySelector('[data-rate-sale-total]').textContent=money(sale*count);
            row.querySelector('[data-rate-margin]').textContent=money((sale-buy)*count);
        });
        let buyTotal=0,saleTotal=0;
        form.querySelectorAll('[data-passenger-row]').forEach(row=>{
            const type=row.dataset.paxType||'ADULT';
            const b=row.querySelector('[data-override-buy]'),s=row.querySelector('[data-override-sell]');
            const buy=b && b.value!==''?Number(b.value||0):base(type,'buy');
            const sale=s && s.value!==''?Number(s.value||0):base(type,'sell');
            buyTotal+=buy;saleTotal+=sale;
            row.querySelector('[data-passenger-margin]').textContent=money(sale-buy);
        });
        const margin=saleTotal-buyTotal,pct=saleTotal>0?(margin/saleTotal*100):0;
        document.querySelector('[data-summary-buy]').textContent=money(buyTotal);
        document.querySelector('[data-summary-sale]').textContent=money(saleTotal);
        document.querySelector('[data-summary-margin]').textContent=money(margin);
        document.querySelector('[data-summary-percent]').textContent=pct.toFixed(2);
    }
    form.querySelectorAll('input[type="number"]').forEach(el=>el.addEventListener('input',refresh)); refresh();
})();
</script>
@endif
@endsection
