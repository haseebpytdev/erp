@if($tab==='transport-rates')
@if(!$rateCardLifecycleUpgradeInstalled)
<div class="tm-alert"><strong>Rate Card lifecycle upgrade required:</strong> Run <strong>System → Health & Updates → Safe Database Upgrade</strong> once. Existing vendor rates are preserved.</div>
@endif

<div class="tm-card">
    <div class="tm-card-head">
        <div><h3>Transport Vendor Rate Cards</h3><p>Create new cards as Drafts, edit the full route × vehicle matrix, then activate only when the new rates are ready.</p></div>
        @if($rateCardLifecycleUpgradeInstalled)
        <form method="GET" action="{{ route('master-data.travel.index') }}" class="tm-right">
            <input type="hidden" name="tab" value="transport-rates">
            <label class="tm-field"><span>Status</span><select name="rate_status" onchange="this.form.submit()">
                @foreach(['all'=>'All','draft'=>'Draft','active'=>'Active','upcoming'=>'Upcoming','expired'=>'Expired','inactive'=>'Inactive'] as $key=>$label)
                <option value="{{ $key }}" {{ $rateStatus===$key?'selected':'' }}>{{ $label }}</option>
                @endforeach
            </select></label>
        </form>
        @endif
    </div>
    @if($canManage && $rateCardLifecycleUpgradeInstalled)
    <div class="tm-body">
        <div class="tm-note"><strong>New Rate Card:</strong> creates a Draft only. Bookings do not use it until <strong>Activate Rate Card</strong> is pressed.</div>
        <form method="POST" action="{{ route('master-data.transport-rate-cards.store') }}" class="tm-form">@csrf
            <label class="tm-field"><span>Transport Company *</span><select name="transport_company_id" required><option value="">Select company</option>@foreach($transportCompanies as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ $c->name }}</option>@endforeach</select></label>
            <label class="tm-field"><span>Rate Card Name *</span><input name="name" required placeholder="Umrah Season 2027–28"></label>
            <label class="tm-field"><span>Currency *</span><select name="currency_code" required>@foreach($currencies->isNotEmpty()?$currencies:collect([(object)['code'=>'SAR']]) as $c)<option value="{{ $c->code }}" {{ $c->code==='SAR'?'selected':'' }}>{{ $c->code }}</option>@endforeach</select></label>
            <label class="tm-field"><span>Effective From *</span><input type="date" name="effective_from" required></label>
            <label class="tm-field"><span>Effective Until</span><input type="date" name="effective_until"></label>
            <label class="tm-field tm-span2"><span>Notes</span><input name="notes"></label>
            <div><button class="tm-btn success">+ New Draft Rate Card</button></div>
        </form>
    </div>
    @endif

    <div style="overflow:auto"><table class="tm-table"><thead><tr><th>Company / Rate Card</th><th>Currency</th><th>Effective</th><th>Rates</th><th>Status</th><th>Manage</th></tr></thead><tbody>
    @forelse($transportRateCards as $card)
        @php($lifeStatus=$rateCardLifecycleUpgradeInstalled?$card->lifecycleStatus():($card->is_active?'ACTIVE':'INACTIVE'))
        <tr>
            <td><strong>{{ $card->transportCompany?->name ?: '—' }}</strong><small>{{ $card->name }}@if($card->source_rate_card_id) · duplicated from #{{ $card->source_rate_card_id }}@endif</small></td>
            <td><strong>{{ $card->currency_code }}</strong></td>
            <td>{{ $card->effective_from?->format('d M Y') ?: '—' }}<small>{{ $card->effective_until ? 'to '.$card->effective_until->format('d M Y') : 'No end date' }}</small></td>
            <td><strong>{{ number_format((int)($card->rates_count ?? 0)) }}</strong></td>
            <td><span class="tm-badge status-{{ strtolower($lifeStatus) }}">{{ $lifeStatus }}</span></td>
            <td><div class="tm-right">
                <a class="tm-btn secondary" href="{{ route('master-data.travel.index',['tab'=>'transport-rates','card'=>$card->id,'rate_status'=>$rateStatus]) }}">{{ $lifeStatus==='DRAFT'?'Edit Rates':'View Rates' }}</a>
                @if($canManage && $rateCardLifecycleUpgradeInstalled)
                <form method="POST" action="{{ route('master-data.transport-rate-cards.duplicate',$card) }}">@csrf<button class="tm-btn secondary">Duplicate</button></form>
                @if(in_array($lifeStatus,['ACTIVE','UPCOMING','EXPIRED'],true))<form method="POST" action="{{ route('master-data.transport-rate-cards.deactivate',$card) }}">@csrf<button class="tm-btn secondary">Deactivate</button></form>@endif
                @endif
            </div></td>
        </tr>
    @empty<tr><td colspan="6" class="tm-empty">No Rate Cards match this status.</td></tr>@endforelse
    </tbody></table></div>
</div>

@if($selectedRateCard)
@php
    $selectedLife=$rateCardLifecycleUpgradeInstalled?$selectedRateCard->lifecycleStatus():($selectedRateCard->is_active?'ACTIVE':'INACTIVE');
    $selectedDraft=$selectedLife==='DRAFT';
@endphp
<div class="tm-card" id="rate-card-editor">
    <div class="tm-card-head">
        <div><h3>{{ $selectedDraft?'Edit Draft Rate Card':'Rate Card Matrix' }}</h3><p>{{ $selectedRateCard->transportCompany?->name }} · {{ $selectedRateCard->name }} · {{ $selectedRateCard->currency_code }}</p></div>
        <div class="tm-right"><span class="tm-badge status-{{ strtolower($selectedLife) }}">{{ $selectedLife }}</span><a class="tm-btn secondary" href="{{ route('master-data.travel.index',['tab'=>'transport-rates','rate_status'=>$rateStatus]) }}">Close</a></div>
    </div>

    @if(!$selectedDraft)
    <div class="tm-body"><div class="tm-note"><strong>Protected Rate Card.</strong> Existing active/inactive cards are read-only here. Use <strong>Duplicate</strong> to create a new Draft with all rates copied, then edit only the changed prices.</div></div>
    @endif

    @if($selectedDraft && $canManage)
    <div class="tm-body" style="border-bottom:1px solid #eaecf0">
        <form method="POST" action="{{ route('master-data.transport-rate-cards.bulk-adjust',$selectedRateCard) }}" class="tm-form">@csrf
            <label class="tm-field"><span>Bulk Adjustment</span><select name="adjustment_type"><option value="PERCENT">Percentage %</option><option value="FIXED">Fixed {{ $selectedRateCard->currency_code }}</option></select></label>
            <label class="tm-field"><span>Increase / Decrease</span><input type="number" step="0.01" name="adjustment_value" placeholder="e.g. 5 or -10" required></label>
            <div><button class="tm-btn secondary">Apply to All Existing Rates</button></div>
            <div class="tm-note tm-span4" style="margin:0">Example: <strong>5%</strong> increases every copied rate by 5%; <strong>-10 SAR</strong> decreases every rate by SAR 10. You can then edit individual cells below.</div>
        </form>
    </div>
    @endif

    @if($selectedDraft && $canManage)
    <form method="POST" action="{{ route('master-data.transport-rate-cards.update',$selectedRateCard) }}">@csrf @method('PUT')
        <div class="tm-body"><div class="tm-form">
            <label class="tm-field"><span>Transport Company *</span><select name="transport_company_id" required>@foreach($transportCompanies as $c)<option value="{{ $c->id }}" {{ (int)$selectedRateCard->transport_company_id===(int)$c->id?'selected':'' }}>{{ $c->code }} · {{ $c->name }}</option>@endforeach</select></label>
            <label class="tm-field"><span>Rate Card Name *</span><input name="name" value="{{ $selectedRateCard->name }}" required></label>
            <label class="tm-field"><span>Currency *</span><select name="currency_code" required>@foreach($currencies->isNotEmpty()?$currencies:collect([(object)['code'=>'SAR']]) as $c)<option value="{{ $c->code }}" {{ $selectedRateCard->currency_code===$c->code?'selected':'' }}>{{ $c->code }}</option>@endforeach</select></label>
            <label class="tm-field"><span>Effective From *</span><input type="date" name="effective_from" value="{{ $selectedRateCard->effective_from?->format('Y-m-d') }}" required></label>
            <label class="tm-field"><span>Effective Until</span><input type="date" name="effective_until" value="{{ $selectedRateCard->effective_until?->format('Y-m-d') }}"></label>
            <label class="tm-field tm-span2"><span>Notes</span><input name="notes" value="{{ $selectedRateCard->notes }}"></label>
        </div></div>
        <div class="tm-rate-wrap"><table class="tm-rate-matrix"><thead><tr><th class="route">Route / Service</th>@foreach($vehicleTypes as $vehicle)<th>{{ $vehicle->name }}<small>{{ $vehicle->min_pax && $vehicle->max_pax ? $vehicle->min_pax.'–'.$vehicle->max_pax.' Pax' : '' }}</small></th>@endforeach</tr></thead><tbody>
        @foreach($transportRoutes as $route)<tr><td class="route"><strong>{{ $route->name }}</strong><small>{{ $route->pickup_location ?: '' }}{{ $route->dropoff_location ? ' → '.$route->dropoff_location : '' }}</small></td>@foreach($vehicleTypes as $vehicle)@php($matrixKey=$route->id.'_'.$vehicle->id)<td><input type="number" step="0.01" min="0" name="rates[{{ $route->id }}][{{ $vehicle->id }}]" value="{{ $selectedRateValues[$matrixKey] ?? '' }}" placeholder="—"></td>@endforeach</tr>@endforeach
        </tbody></table></div>
        <div class="tm-rate-actions"><button class="tm-btn primary">Save Draft</button></div>
    </form>
    <div class="tm-body tm-rate-footer-actions">
        <form method="POST" action="{{ route('master-data.transport-rate-cards.activate',$selectedRateCard) }}">@csrf<button class="tm-btn success">Activate Rate Card</button></form>
        <form method="POST" action="{{ route('master-data.transport-rate-cards.destroy-draft',$selectedRateCard) }}" onsubmit="return confirm('Cancel this Draft Rate Card? The source/old Rate Card is not affected.')">@csrf @method('DELETE')<button class="tm-btn secondary">Cancel Draft</button></form>
    </div>
    @else
    <div class="tm-rate-wrap"><table class="tm-rate-matrix"><thead><tr><th class="route">Route / Service</th>@foreach($vehicleTypes as $vehicle)<th>{{ $vehicle->name }}<small>{{ $vehicle->min_pax && $vehicle->max_pax ? $vehicle->min_pax.'–'.$vehicle->max_pax.' Pax' : '' }}</small></th>@endforeach</tr></thead><tbody>
    @foreach($transportRoutes as $route)<tr><td class="route"><strong>{{ $route->name }}</strong></td>@foreach($vehicleTypes as $vehicle)@php($matrixKey=$route->id.'_'.$vehicle->id)<td>{{ isset($selectedRateValues[$matrixKey]) ? $selectedRateCard->currency_code.' '.number_format((float)$selectedRateValues[$matrixKey],2) : '—' }}</td>@endforeach</tr>@endforeach
    </tbody></table></div>
    @endif
</div>
@endif
@endif

