@extends('layouts.app')
@section('title','Service Details · '.$booking->booking_no)
@section('page_title','Specialized Service Details')
@section('content')
<div class="page-intro"><div><span class="eyebrow">{{ $category==='VISA' ? 'ERP-10.24 · VISA · Smart Operator Linkage' : 'ERP-10.22 · '.$category.' · Live Master Linkage' }}</span><h1>{{ $service->product?->name ?: $service->description }}</h1><p>{{ $booking->booking_no }} · {{ $booking->customer?->display_name }} · {{ $service->description }}</p></div><a class="btn btn-ghost" href="{{ route('operations.bookings.show',$booking) }}">← Back to Booking</a></div>
<div class="panel"><div class="panel-head"><div><h3>Operational detail</h3><p>This page records service-specific travel data. It does not create a Sales Invoice, supplier cost, payment or journal entry.</p></div></div>
<div class="callout" style="margin-bottom:14px"><strong>Master data linkage:</strong> Hotel, Airline, Visa Company and Transport Company selections use <strong>Master Data → Travel Masters</strong>. The ERP also stores a transaction snapshot so later edits to a master record do not rewrite old booking history.</div>
@if($category==='AIR_TICKET')
@php
    $savedAgent = $agentCommission?->agent_party_id ?: $booking->agent_party_id;
    $savedSalesperson = $staffCommission?->salesperson_staff_id ?: $booking->salesperson_staff_id;
    $grossMargin = (float)($airTicket?->selling_total ?? 0) - (float)($airTicket?->net_supplier_cost ?? 0);
    $agentAmount = (float)($agentCommission?->commission_amount ?? 0);
    $staffAmount = (float)($staffCommission?->commission_amount ?? 0);
    $netContribution = $grossMargin - $agentAmount - $staffAmount;
@endphp
<div class="panel" style="margin-bottom:16px">
<div class="panel-head"><div><h3>Ticket Register</h3><p>One passenger may have multiple tickets. Each row is an atomic ticket/PNR commercial record.</p></div><a class="btn btn-primary btn-small" href="{{ route('operations.bookings.services.details.edit',[$booking,$service,'new'=>1]) }}">+ Add Another Ticket</a></div>
@if($airTickets->isEmpty())<div class="empty-state">No ticket details saved yet.</div>@else
<div style="overflow:auto"><table class="data-table"><thead><tr><th>Passenger</th><th>PNR</th><th>Ticket</th><th>Sector</th><th>Status</th><th style="text-align:right">Sale</th><th style="text-align:right">Supplier Cost</th><th></th></tr></thead><tbody>
@foreach($airTickets as $t)<tr><td>{{ $t->passenger?->fullName() ?: '—' }}</td><td>{{ $t->pnr ?: '—' }}</td><td>{{ $t->ticket_number ?: 'Not issued' }}</td><td>{{ $t->sector ?: trim(($t->origin??'').'–'.($t->destination??''),'–') }}</td><td><span class="status-pill {{ in_array($t->ticket_status,['ISSUED','BOOKED'])?'active':'open' }}">{{ $t->ticket_status }}</span></td><td style="text-align:right">{{ $booking->currency_code }} {{ number_format((float)$t->selling_total,2) }}</td><td style="text-align:right">{{ $booking->currency_code }} {{ number_format((float)$t->net_supplier_cost,2) }}</td><td><a href="{{ route('operations.bookings.services.details.edit',[$booking,$service,'ticket'=>$t->id]) }}">Edit →</a></td></tr>@endforeach
</tbody></table></div>
<div class="callout" style="margin-top:12px"><strong>Ticket totals:</strong> Customer Sale {{ $booking->currency_code }} {{ number_format((float)$airTickets->sum('selling_total'),2) }} · Supplier Cost {{ $booking->currency_code }} {{ number_format((float)$airTickets->sum('net_supplier_cost'),2) }} · Booking service line {{ $booking->currency_code }} {{ number_format((float)$service->line_total,2) }}.</div>
@endif</div>

@if($activeInvoice)<div class="callout {{ $activeInvoice->status==='POSTED'?'warn':'' }}" style="margin-bottom:16px"><strong>Invoice link:</strong> {{ $activeInvoice->invoice_no }} is {{ $activeInvoice->status }}. Ticket commercial detail is operational/costing data; it does not silently rewrite an existing Sales Invoice. A posted invoice remains immutable.</div>@endif

<div class="panel"><div class="panel-head"><div><h3>{{ $airTicket?'Edit Ticket':'Add Air Ticket' }}</h3><p>Passenger/ticket identity, customer sale, supplier purchase and forecast commissions in one controlled workspace.</p></div></div>
@if(auth()->user()->hasPermission('travel_masters.manage'))
<details class="record-card" style="margin-bottom:14px"><summary style="cursor:pointer;font-weight:800">+ Quick Add Airline / Booking Source</summary>
<div class="compact-grid" style="margin-top:12px">
<form method="POST" action="{{ route('master-data.airlines.store') }}">@csrf
<input type="hidden" name="is_active" value="1">
<div class="compact-grid"><label><span>Airline Name *</span><input name="name" required placeholder="e.g. Riyadh Air"></label><label><span>IATA (2-letter)</span><input name="iata_code" maxlength="2" placeholder="RX"></label></div>
<div class="compact-grid"><label><span>ICAO</span><input name="icao_code" maxlength="3"></label><label><span>Internal Code *</span><input name="code" required maxlength="20" placeholder="Usually IATA"></label></div>
<div class="form-actions"><button class="btn btn-primary btn-small">Add Airline</button></div>
</form>
<form method="POST" action="{{ route('master-data.booking-sources.store') }}">@csrf
<input type="hidden" name="is_active" value="1">
<div class="compact-grid"><label><span>Source Name *</span><input name="name" required placeholder="e.g. Sabre"></label><label><span>Source Code *</span><input name="code" required maxlength="30" placeholder="SABRE"></label></div>
<label><span>Source Type *</span><select name="source_type" required>@foreach(['GDS','NDC','AIRLINE_API','AGGREGATOR','MANUAL','OTHER'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select></label>
<div class="form-actions"><button class="btn btn-primary btn-small">Add Booking Source</button></div>
</form>
</div>
<small>After adding, the page reloads and the new master is immediately available in the dropdown.</small>
</details>
@endif
<form method="POST" action="{{ route('operations.bookings.services.details.update',[$booking,$service]) }}">@csrf @method('PUT')
<input type="hidden" name="air_ticket_detail_id" value="{{ $airTicket?->id }}">
<h3>1. Passenger & Ticket</h3>
<div class="compact-grid"><label><span>Passenger *</span><select name="booking_passenger_id" required><option value="">Select passenger</option>@foreach($service->passengers as $p)<option value="{{ $p->id }}" {{ old('booking_passenger_id',$airTicket?->booking_passenger_id)==$p->id?'selected':'' }}>#{{ $p->passenger_no }} · {{ $p->fullName() }}</option>@endforeach</select></label><label><span>Ticket Status *</span><select name="ticket_status" required>@foreach(['BOOKED','ISSUED','VOID','REFUNDED','REISSUED'] as $s)<option {{ old('ticket_status',$airTicket?->ticket_status ?? 'BOOKED')===$s?'selected':'' }}>{{ $s }}</option>@endforeach</select></label></div>
<div class="compact-grid"><label><span>PNR *</span><input name="pnr" maxlength="20" value="{{ old('pnr',$airTicket?->pnr) }}" required></label><label><span>Ticket Number</span><input name="ticket_number" value="{{ old('ticket_number',$airTicket?->ticket_number) }}" placeholder="214-1234567890"><small>Required once Issued / Refunded / Reissued.</small></label></div>

<h3 style="margin-top:22px">2. Airline & Flight</h3>
<div class="compact-grid"><label><span>Airline *</span><select name="airline_id" required><option value="">Select airline</option>@foreach($airlines as $a)<option value="{{ $a->id }}" {{ old('airline_id',$airTicket?->airline_id)==$a->id?'selected':'' }}>{{ $a->iata_code ?: $a->code }} · {{ $a->name }}</option>@endforeach</select></label><label><span>Booking Source / GDS *</span><select name="booking_source_id" required><option value="">Select source</option>@foreach($sources as $s)<option value="{{ $s->id }}" {{ old('booking_source_id',$airTicket?->booking_source_id)==$s->id?'selected':'' }}>{{ $s->name }} · {{ $s->source_type }}</option>@endforeach</select></label></div>
<div class="compact-grid"><label><span>From *</span><input name="origin" value="{{ old('origin',$airTicket?->origin) }}" placeholder="LHE" required></label><label><span>To *</span><input name="destination" value="{{ old('destination',$airTicket?->destination) }}" placeholder="JED" required></label></div>
<div class="compact-grid"><label><span>Sector / Routing</span><input name="sector" value="{{ old('sector',$airTicket?->sector) }}" placeholder="LHE-JED"></label><label><span>Flight Number</span><input name="flight_number" value="{{ old('flight_number',$airTicket?->flight_number) }}" placeholder="SV-739"></label></div>
<div class="compact-grid"><label><span>Departure *</span><input type="datetime-local" name="departure_at" value="{{ old('departure_at',$airTicket?->departure_at?->format('Y-m-d\\TH:i')) }}" required></label><label><span>Arrival</span><input type="datetime-local" name="arrival_at" value="{{ old('arrival_at',$airTicket?->arrival_at?->format('Y-m-d\\TH:i')) }}"></label></div>
<div class="compact-grid"><label><span>Cabin</span><input name="cabin_class" value="{{ old('cabin_class',$airTicket?->cabin_class) }}" placeholder="Economy"></label><label><span>Fare Basis</span><input name="fare_basis" value="{{ old('fare_basis',$airTicket?->fare_basis) }}"></label></div>
<label><span>Baggage</span><input name="baggage" value="{{ old('baggage',$airTicket?->baggage) }}" placeholder="30 KG"></label>

<h3 style="margin-top:22px">3. Customer Selling</h3><p class="muted-copy">These are customer-facing commercial values. Saving ticket detail does not post accounting by itself.</p>
<div class="compact-grid"><label><span>Base Fare</span><input id="sale_base" type="number" step="0.01" min="0" name="base_fare" value="{{ old('base_fare',$airTicket?->base_fare ?? 0) }}" required></label><label><span>Airline Taxes</span><input id="sale_tax" type="number" step="0.01" min="0" name="airline_taxes" value="{{ old('airline_taxes',$airTicket?->airline_taxes ?? 0) }}" required></label></div>
<div class="compact-grid"><label><span>Customer Service Fee</span><input id="sale_fee" type="number" step="0.01" min="0" name="customer_service_fee" value="{{ old('customer_service_fee',$airTicket?->customer_service_fee ?? 0) }}" required></label><label><span>Markup</span><input id="sale_markup" type="number" step="0.01" min="0" name="markup" value="{{ old('markup',$airTicket?->markup ?? 0) }}" required></label></div>
<div class="compact-grid"><label><span>Discount</span><input id="sale_discount" type="number" step="0.01" min="0" name="discount" value="{{ old('discount',$airTicket?->discount ?? 0) }}" required></label><label><span>Saved Sell Total</span><input disabled value="{{ $booking->currency_code }} {{ number_format((float)($airTicket?->selling_total ?? 0),2) }}"></label></div>

<h3 style="margin-top:22px">4. Supplier / Purchase</h3><p class="muted-copy">Supplier commission and discount reduce purchase cost. This forecast syncs to ERP-10 Supplier Costing but does not create Vendor Payables until a Vendor Bill posts.</p>
<div class="compact-grid"><label><span>Vendor / Supplier</span><select name="supplier_vendor_party_id"><option value="">Select vendor</option>@foreach($vendors as $v)<option value="{{ $v->id }}" {{ old('supplier_vendor_party_id',$airTicket?->supplier_vendor_party_id)==$v->id?'selected':'' }}>{{ $v->code }} · {{ $v->display_name }}</option>@endforeach</select></label><label><span>GDS PNR</span><input name="supplier_reference" value="{{ old('supplier_reference',$airTicket?->supplier_reference) }}"></label></div>
<div class="compact-grid"><label><span>Supplier Base Fare</span><input id="buy_base" type="number" step="0.01" min="0" name="supplier_base_fare" value="{{ old('supplier_base_fare',$airTicket?->supplier_base_fare ?? 0) }}" required></label><label><span>Supplier Taxes</span><input id="buy_tax" type="number" step="0.01" min="0" name="supplier_taxes" value="{{ old('supplier_taxes',$airTicket?->supplier_taxes ?? 0) }}" required></label></div>
<div class="compact-grid"><label><span>Supplier Charges</span><input id="buy_charges" type="number" step="0.01" min="0" name="supplier_charges" value="{{ old('supplier_charges',$airTicket?->supplier_charges ?? 0) }}" required></label><label><span>Other Purchase Charges</span><input id="buy_other" type="number" step="0.01" min="0" name="supplier_other_charges" value="{{ old('supplier_other_charges',$airTicket?->supplier_other_charges ?? 0) }}" required></label></div>
<div class="compact-grid"><label><span>Less: Airline / Supplier Commission</span><input id="buy_comm" type="number" step="0.01" min="0" name="supplier_commission" value="{{ old('supplier_commission',$airTicket?->supplier_commission ?? 0) }}" required></label><label><span>Less: Supplier Discount</span><input id="buy_disc" type="number" step="0.01" min="0" name="supplier_discount" value="{{ old('supplier_discount',$airTicket?->supplier_discount ?? 0) }}" required></label></div>
<input type="hidden" name="supplier_incentive" value="0"><div class="compact-grid"><label><span>Cost Status</span><select name="supplier_cost_status">@foreach(['ESTIMATED','CONFIRMED'] as $s)<option {{ old('supplier_cost_status',$airTicket?->supplier_cost_status ?? 'ESTIMATED')===$s?'selected':'' }}>{{ $s }}</option>@endforeach</select></label></div>
<div class="callout"><strong>Saved Net Supplier Cost:</strong> {{ $booking->currency_code }} {{ number_format((float)($airTicket?->net_supplier_cost ?? 0),2) }}</div>

<h3 style="margin-top:22px">5. Agent / Service Partner Commission</h3>
<div class="compact-grid"><label><span>Agent</span><select name="agent_party_id"><option value="">No agent commission</option>@foreach($agents as $a)<option value="{{ $a->id }}" {{ old('agent_party_id',$savedAgent)==$a->id?'selected':'' }}>{{ $a->code }} · {{ $a->display_name }}</option>@endforeach</select></label><label><span>Commission Basis</span><select name="agent_commission_basis">@foreach(['FIXED','PER_TICKET','PERCENT_OF_SALE','PERCENT_OF_BASE_FARE','PERCENT_OF_GROSS_MARGIN','MANUAL'] as $b)<option {{ old('agent_commission_basis',$agentCommission?->basis ?? 'FIXED')===$b?'selected':'' }}>{{ $b }}</option>@endforeach</select></label></div>
<div class="compact-grid"><label><span>Rate / Fixed Amount</span><input type="number" step="0.0001" min="0" name="agent_commission_rate" value="{{ old('agent_commission_rate',$agentCommission?->rate ?? 0) }}"></label><label><span>Saved Commission</span><input disabled value="{{ $booking->currency_code }} {{ number_format($agentAmount,2) }}"></label></div>

<h3 style="margin-top:22px">6. Internal Salesperson Commission</h3>
<div class="compact-grid"><label><span>Salesperson</span><select name="salesperson_staff_id"><option value="">No staff commission</option>@foreach($salespeople as $sp)<option value="{{ $sp->id }}" {{ old('salesperson_staff_id',$savedSalesperson)==$sp->id?'selected':'' }}>{{ $sp->employee_code }} · {{ $sp->name }}</option>@endforeach</select></label><label><span>Commission Basis</span><select name="salesperson_commission_basis">@foreach(['FIXED','PER_TICKET','PERCENT_OF_SALE','PERCENT_OF_BASE_FARE','PERCENT_OF_GROSS_MARGIN','MANUAL'] as $b)<option {{ old('salesperson_commission_basis',$staffCommission?->basis ?? 'FIXED')===$b?'selected':'' }}>{{ $b }}</option>@endforeach</select></label></div>
<div class="compact-grid"><label><span>Rate / Fixed Amount</span><input type="number" step="0.0001" min="0" name="salesperson_commission_rate" value="{{ old('salesperson_commission_rate',$staffCommission?->rate ?? 0) }}"></label><label><span>Saved Commission</span><input disabled value="{{ $booking->currency_code }} {{ number_format($staffAmount,2) }}"></label></div>

<h3 style="margin-top:22px">Commercial Summary</h3>
<div class="metric-grid" style="grid-template-columns:repeat(5,minmax(0,1fr))"><div class="metric-card"><div class="metric-label">Customer Sale</div><div class="metric-value" style="font-size:18px">{{ number_format((float)($airTicket?->selling_total ?? 0),2) }}</div></div><div class="metric-card"><div class="metric-label">Supplier Cost</div><div class="metric-value" style="font-size:18px">{{ number_format((float)($airTicket?->net_supplier_cost ?? 0),2) }}</div></div><div class="metric-card"><div class="metric-label">Gross Margin</div><div class="metric-value" style="font-size:18px">{{ number_format($grossMargin,2) }}</div></div><div class="metric-card"><div class="metric-label">Commissions</div><div class="metric-value" style="font-size:18px">{{ number_format($agentAmount+$staffAmount,2) }}</div></div><div class="metric-card"><div class="metric-label">Net Contribution</div><div class="metric-value" style="font-size:18px">{{ number_format($netContribution,2) }}</div></div></div>
<div class="callout" style="margin-top:12px"><strong>Accounting boundary:</strong> Customer Sales Invoice posts revenue/AR. Supplier cost remains a costing allocation until Vendor Bill. Agent/staff commissions remain forecast allocations until ERP-11 approval/accrual.</div>
<label style="margin-top:14px"><span>Notes</span><textarea name="notes" rows="3">{{ old('notes',$airTicket?->notes) }}</textarea></label><div class="form-actions"><button class="btn btn-primary">{{ $airTicket?'Save Ticket Changes':'Add Ticket' }}</button></div></form></div>
@elseif($category==='HOTEL')
@if(auth()->user()->hasPermission('travel_masters.manage'))
<details class="record-card" style="margin-bottom:14px"><summary style="cursor:pointer;font-weight:800">+ Quick Add Hotel Master</summary>
<form method="POST" action="{{ route('master-data.hotels.store') }}" style="margin-top:12px">@csrf
<input type="hidden" name="is_active" value="1">
<div class="compact-grid"><label><span>Hotel Name *</span><input name="name" required></label><label><span>Internal Code *</span><input name="code" required maxlength="30"></label></div>
<div class="compact-grid"><label><span>City *</span><input name="city" required placeholder="Makkah"></label><label><span>City IATA</span><input name="city_iata_code" maxlength="3" placeholder="JED"></label></div>
<div class="compact-grid"><label><span>Country *</span><input name="country_code" required maxlength="2" value="SA"></label><label><span>Phone</span><input name="phone"></label></div>
<div class="form-actions"><button class="btn btn-primary btn-small">Add Hotel</button></div>
</form>
<small>Duplicate Hotel + City combinations are rejected and the existing master should be selected instead.</small>
</details>
@endif
<form method="POST" action="{{ route('operations.bookings.services.details.update',[$booking,$service]) }}">@csrf @method('PUT')
<div class="compact-grid"><label><span>Hotel Master</span><select name="hotel_id"><option value="">Select hotel</option>@foreach($hotels as $h)<option value="{{ $h->id }}" {{ old('hotel_id',$hotelDetail?->hotel_id)==$h->id?'selected':'' }}>{{ $h->city_iata_code ?: $h->city }} · {{ $h->name }}</option>@endforeach</select><small>Reusable Hotel Master; saved transaction keeps a historical snapshot.</small></label><label><span>Confirmation Number</span><input name="confirmation_number" value="{{ old('confirmation_number',$hotelDetail?->confirmation_number) }}"></label></div>
<div class="compact-grid"><label><span>Room Type</span><input name="room_type" value="{{ old('room_type',$hotelDetail?->room_type) }}"></label><label><span>Rooms</span><input type="number" min="1" name="rooms" value="{{ old('rooms',$hotelDetail?->rooms ?? 1) }}" required></label></div>
<div class="compact-grid"><label><span>Check-in</span><input type="date" name="check_in" value="{{ old('check_in',$hotelDetail?->check_in?->format('Y-m-d')) }}"></label><label><span>Check-out</span><input type="date" name="check_out" value="{{ old('check_out',$hotelDetail?->check_out?->format('Y-m-d')) }}"></label></div>
<div class="compact-grid"><label><span>Meal Plan</span><input name="meal_plan" value="{{ old('meal_plan',$hotelDetail?->meal_plan) }}"></label><label><span>Occupancy</span><input name="occupancy" value="{{ old('occupancy',$hotelDetail?->occupancy) }}" placeholder="Quad / Triple / Double"></label></div>
<div class="callout">Linked guests: {{ $service->passengers->map(fn($p)=>$p->fullName())->implode(' · ') ?: 'No guests linked' }} @if($hotelDetail?->nights!==null) · Nights: {{ $hotelDetail->nights }} @endif</div>
<label><span>Special Requests</span><textarea name="special_requests" rows="3">{{ old('special_requests',$hotelDetail?->special_requests) }}</textarea></label><div class="form-actions"><button class="btn btn-primary">Save Hotel Details</button></div></form>
@elseif($category==='VISA')
@php
    $visaOldPassengerId=(int)old('booking_passenger_id',0);
    $visaOperatorOld=old('visa_action')==='operator';
    $selectedSaudiOperatorId=$visaOperatorOld ? (int)old('saudi_visa_company_id',0) : (int)($visaOperator?->saudi_visa_company_id ?? 0);
    $visaFxRate=(float)($visaOperator?->cost_fx_rate ?: ($sarFx['rate']??0));
    $visaFxDate=$visaOperator?->cost_fx_rate_date?->format('Y-m-d') ?: ($sarFx['effective_date']??null);
    $visaPaxCounts=['ADULT'=>0,'CHILD'=>0,'INFANT'=>0];
    foreach($service->passengers->where('is_active',true) as $vp){$t=strtoupper((string)($vp->pax_type?:'ADULT'));if(!isset($visaPaxCounts[$t]))$t='ADULT';$visaPaxCounts[$t]++;}
    $visaForeignTotal=(float)($visaOperator?->total_cost_foreign ?? 0);
    $visaBaseTotal=(float)($visaOperator?->total_cost_base ?? ($visaForeignTotal*$visaFxRate));
@endphp
@if(!$visaOperatorUpgradeInstalled)
<div class="callout" style="background:#fffbeb;border-color:#f59e0b;color:#92400e"><strong>Visa operator linkage upgrade required.</strong> Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache. Existing passenger visa records are preserved.</div>
@else
<div class="record-card" style="margin-top:12px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
        <div><strong style="font-size:15px">Visa Operator</strong><div style="margin-top:3px;color:var(--muted);font-size:12px">Select the Saudi Visa Company once. Its linked Pakistan Visa / IATA Operator is filled automatically and applies to every passenger in this Visa service and voucher.</div></div>
        @if($visaOperator)<span class="badge badge-success">SAVED</span>@endif
    </div>
    <form method="POST" action="{{ route('operations.bookings.services.details.update',[$booking,$service]) }}" style="margin-top:14px" data-visa-operator-form>@csrf @method('PUT')
        <input type="hidden" name="visa_action" value="operator">
        <div class="compact-grid">
            <label><span>Saudi Visa Company *</span>
                <select name="saudi_visa_company_id" required data-saudi-visa-select>
                    <option value="">Select Saudi Visa Company</option>
                    @foreach($saudiVisaCompanies as $company)
                        @php $linkedIata=$company->linkedPakistanIataOperator; @endphp
                        <option value="{{ $company->id }}"
                            data-iata-name="{{ $linkedIata ? (($linkedIata->iata_number ?: $linkedIata->code).' · '.$linkedIata->name) : '' }}"
                            data-linked="{{ $linkedIata?'1':'0' }}"
                            {{ $selectedSaudiOperatorId===(int)$company->id?'selected':'' }}>{{ $company->code }} · {{ $company->name }}{{ !$linkedIata?' · NOT LINKED':'' }}</option>
                    @endforeach
                </select>
                <small>Relationship is managed in Master Data → Travel Masters → Saudi Visa Companies.</small>
            </label>
            <label><span>Pakistan Visa / IATA Operator</span>
                <input type="text" value="{{ $visaOperator?->pakistanIataOperator ? (($visaOperator->pakistanIataOperator->iata_number ?: $visaOperator->pakistanIataOperator->code).' · '.$visaOperator->pakistanIataOperator->name) : 'Select a linked Saudi Visa Company' }}" readonly data-pakistan-iata-display>
                <small>Auto-filled from the Saudi Visa Company master; it cannot be independently changed here.</small>
            </label>
        </div>
        @if($visaPricingUpgradeInstalled && $foreignCostUpgradeInstalled)
        <div style="margin-top:16px"><strong style="font-size:13px">Visa Pricing</strong><small style="display:block;color:var(--muted);margin-top:3px">Sale is in {{ $baseCurrency }}. Saudi vendor cost is entered in SAR and converted using the saved dated FX rate.</small></div>
        <div class="compact-grid" style="margin-top:8px">
            <label><span>Adult Sale Price ({{ $baseCurrency }})</span><input type="number" step="0.01" min="0" name="adult_sale_price" value="{{ $visaOperatorOld?old('adult_sale_price',$visaOperator?->adult_sale_price ?? 0):($visaOperator?->adult_sale_price ?? 0) }}"></label>
            <label><span>Adult Cost Price (SAR)</span><input type="number" step="0.01" min="0" name="adult_cost_price" value="{{ $visaOperatorOld?old('adult_cost_price',$visaOperator?->adult_cost_foreign ?? 0):($visaOperator?->adult_cost_foreign ?? 0) }}" data-visa-cost-sar></label>
        </div>
        <div class="compact-grid">
            <label><span>Child Sale Price ({{ $baseCurrency }})</span><input type="number" step="0.01" min="0" name="child_sale_price" value="{{ $visaOperatorOld?old('child_sale_price',$visaOperator?->child_sale_price ?? 0):($visaOperator?->child_sale_price ?? 0) }}"></label>
            <label><span>Child Cost Price (SAR)</span><input type="number" step="0.01" min="0" name="child_cost_price" value="{{ $visaOperatorOld?old('child_cost_price',$visaOperator?->child_cost_foreign ?? 0):($visaOperator?->child_cost_foreign ?? 0) }}" data-visa-cost-sar></label>
        </div>
        <div class="compact-grid">
            <label><span>Infant Sale Price ({{ $baseCurrency }})</span><input type="number" step="0.01" min="0" name="infant_sale_price" value="{{ $visaOperatorOld?old('infant_sale_price',$visaOperator?->infant_sale_price ?? 0):($visaOperator?->infant_sale_price ?? 0) }}"></label>
            <label><span>Infant Cost Price (SAR)</span><input type="number" step="0.01" min="0" name="infant_cost_price" value="{{ $visaOperatorOld?old('infant_cost_price',$visaOperator?->infant_cost_foreign ?? 0):($visaOperator?->infant_cost_foreign ?? 0) }}" data-visa-cost-sar></label>
        </div>
        <div class="callout" style="margin-top:8px" data-visa-fx-summary data-fx-rate="{{ $visaFxRate }}" data-adults="{{ $visaPaxCounts['ADULT'] }}" data-children="{{ $visaPaxCounts['CHILD'] }}" data-infants="{{ $visaPaxCounts['INFANT'] }}">
            <strong>FX:</strong> 1 SAR = {{ $baseCurrency }} {{ $visaFxRate>0?number_format($visaFxRate,4):'Not configured' }} @if($visaFxDate) · Rate date {{ $visaFxDate }} @endif
            · <strong>Saved Visa Cost:</strong> SAR <span data-visa-cost-foreign>{{ number_format($visaForeignTotal,2) }}</span> → {{ $baseCurrency }} <span data-visa-cost-base>{{ number_format($visaBaseTotal,2) }}</span>
            @if($visaOperator?->cost_fx_rate)<button class="btn btn-ghost btn-small" type="submit" name="refresh_fx_rate" value="1" style="margin-left:8px">Refresh FX Rate</button>@endif
        </div>
        @else
        <div class="callout" style="margin-top:12px;background:#fffbeb;border-color:#f59e0b;color:#92400e"><strong>Visa pricing upgrade required.</strong> Run Safe Database Upgrade, then Clear Application Cache.</div>
        @endif
        <div class="form-actions"><button class="btn btn-primary">Save Visa Operator</button></div>
    </form>
</div>

@if(auth()->user()->hasPermission('travel_masters.manage'))
<details class="record-card" style="margin-top:10px"><summary style="cursor:pointer;font-weight:800">+ Quick Add Visa / IATA Master</summary>
<div class="compact-grid" style="margin-top:12px">
<form method="POST" action="{{ route('master-data.travel-partners.store') }}">@csrf
<input type="hidden" name="partner_type" value="PAKISTANI_IATA_UMRAH_OPERATOR"><input type="hidden" name="is_active" value="1"><input type="hidden" name="country_code" value="PK">
<label><span>Pakistan IATA / Operator *</span><input name="name" required placeholder="Agency / operator name"></label>
<label><span>IATA Number</span><input name="iata_number"></label>
<div class="form-actions"><button class="btn btn-primary btn-small">Add Pakistan IATA / Operator</button></div>
</form>
<form method="POST" action="{{ route('master-data.travel-partners.store') }}">@csrf
<input type="hidden" name="partner_type" value="SAUDI_VISA_COMPANY"><input type="hidden" name="is_active" value="1"><input type="hidden" name="country_code" value="SA">
<label><span>Saudi Visa Company *</span><input name="name" required placeholder="Company name"></label>
<label><span>Linked Pakistan Visa / IATA *</span><select name="linked_pakistan_iata_operator_id" required><option value="">Select operator</option>@foreach($pakistanIataCompanies as $iata)<option value="{{ $iata->id }}">{{ $iata->iata_number ?: $iata->code }} · {{ $iata->name }}</option>@endforeach</select></label>
<label><span>Registration / License</span><input name="registration_no"></label>
<div class="form-actions"><button class="btn btn-primary btn-small">Add Saudi Visa Company</button></div>
</form>
</div>
<small style="display:block;margin-top:8px;color:var(--muted)">If you add a new Pakistan IATA Operator first, reload this page once before adding its linked Saudi Visa Company.</small>
</details>
@endif

<div class="callout" style="margin-top:12px">Visa company/operator is common for this service. Application number, Visa number, status and dates remain passenger-specific.</div>
@foreach($service->passengers as $p)
@php
    $v=$visaDetails->get($p->id);
    $useVisaOld=$visaOldPassengerId===(int)$p->id;
@endphp
<details class="record-card" style="margin-top:12px" {{ ($loop->first || $useVisaOld)?'open':'' }}><summary style="cursor:pointer;font-weight:800">#{{ $p->passenger_no }} · {{ $p->fullName() }} @if($v) · {{ $v->status }} @endif</summary>
<form method="POST" action="{{ route('operations.bookings.services.details.update',[$booking,$service]) }}" style="margin-top:14px">@csrf @method('PUT')
<input type="hidden" name="visa_action" value="passenger"><input type="hidden" name="booking_passenger_id" value="{{ $p->id }}">
<div class="compact-grid"><label><span>Destination Country</span><input maxlength="2" name="destination_country_code" value="{{ $useVisaOld?old('destination_country_code',$v?->destination_country_code):$v?->destination_country_code }}" placeholder="SA"></label><label><span>Visa Type</span><input name="visa_type" value="{{ $useVisaOld?old('visa_type',$v?->visa_type):$v?->visa_type }}"></label></div>
<div class="compact-grid"><label><span>Application No.</span><input name="application_number" value="{{ $useVisaOld?old('application_number',$v?->application_number):$v?->application_number }}"></label><label><span>Visa No.</span><input name="visa_number" value="{{ $useVisaOld?old('visa_number',$v?->visa_number):$v?->visa_number }}"></label></div>
<div class="compact-grid"><label><span>Status</span><select name="status">@foreach(['PENDING','SUBMITTED','APPROVED','ISSUED','REJECTED','CANCELLED'] as $s)<option {{ ($useVisaOld?old('status',$v?->status ?? 'PENDING'):($v?->status ?? 'PENDING'))===$s?'selected':'' }}>{{ $s }}</option>@endforeach</select></label><label><span>Submitted On</span><input type="date" name="submitted_on" value="{{ $useVisaOld?old('submitted_on',$v?->submitted_on?->format('Y-m-d')):$v?->submitted_on?->format('Y-m-d') }}"></label></div>
<div class="compact-grid"><label><span>Issued On</span><input type="date" name="issued_on" value="{{ $useVisaOld?old('issued_on',$v?->issued_on?->format('Y-m-d')):$v?->issued_on?->format('Y-m-d') }}"></label><label><span>Valid Until</span><input type="date" name="valid_until" value="{{ $useVisaOld?old('valid_until',$v?->valid_until?->format('Y-m-d')):$v?->valid_until?->format('Y-m-d') }}"></label></div>
<label><span>Notes</span><textarea name="notes" rows="2">{{ $useVisaOld?old('notes',$v?->notes):$v?->notes }}</textarea></label><div class="form-actions"><button class="btn btn-primary btn-small">Save {{ $p->first_name }} Visa</button></div></form></details>
@endforeach

<script>
(function(){
    const box=document.querySelector('[data-visa-fx-summary]'); if(!box)return;
    const rate=Number(box.dataset.fxRate||0), adults=Number(box.dataset.adults||0), children=Number(box.dataset.children||0), infants=Number(box.dataset.infants||0);
    const inputs=[...document.querySelectorAll('[data-visa-cost-sar]')];
    const foreignEl=box.querySelector('[data-visa-cost-foreign]'), baseEl=box.querySelector('[data-visa-cost-base]');
    const sync=()=>{const vals=inputs.map(x=>Number(x.value||0));const total=(vals[0]||0)*adults+(vals[1]||0)*children+(vals[2]||0)*infants;if(foreignEl)foreignEl.textContent=total.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});if(baseEl)baseEl.textContent=(total*rate).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});};
    inputs.forEach(i=>i.addEventListener('input',sync)); sync();
})();
</script>
<script>
(function(){
    const select=document.querySelector('[data-saudi-visa-select]');
    const display=document.querySelector('[data-pakistan-iata-display]');
    if(!select||!display)return;
    const sync=()=>{
        const option=select.options[select.selectedIndex];
        if(!option||!option.value){display.value='Select a linked Saudi Visa Company';return;}
        display.value=option.dataset.linked==='1' && option.dataset.iataName ? option.dataset.iataName : 'NOT LINKED — update Travel Masters';
    };
    select.addEventListener('change',sync);
    sync();
})();
</script>
@endif
@elseif($category==='TRANSPORT')
@if(!$transportMasterUpgradeInstalled || !$foreignCostUpgradeInstalled)
<div class="callout" style="background:#fffbeb;border-color:#f59e0b;color:#92400e"><strong>Transport master / FX upgrade required.</strong> Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache.</div>
@else
@php
    $transportCostCurrency=strtoupper((string)($transportDetail?->cost_currency_code ?: 'SAR'));
    $transportFxRate=(float)($transportDetail?->cost_fx_rate ?: ($transportFx['rate']??0));
    $transportFxDate=$transportDetail?->cost_fx_rate_date?->format('Y-m-d') ?: ($transportFx['effective_date']??null);
    $transportForeignCost=(float)($transportDetail?->cost_price_foreign ?? 0);
    $transportBaseCost=(float)($transportDetail?->cost_price_base ?? ($transportForeignCost*$transportFxRate));
    $transportSale=(float)($transportDetail?->sale_price ?? 0);
    $transportMargin=$transportSale-$transportBaseCost;
    $transportIsConfirmed=in_array(strtoupper((string)$service->status),['CONFIRMED','COMPLETED'],true)
        || in_array(strtoupper((string)$booking->status),['CONFIRMED','COMPLETED'],true);
    $transportHasFxSnapshot=(float)($transportDetail?->cost_fx_rate ?? 0)>0;
@endphp
<form method="POST" action="{{ route('operations.bookings.services.details.update',[$booking,$service]) }}" data-transport-form data-booking-date="{{ $bookingRateDate }}" data-base-currency="{{ $baseCurrency }}" data-fx-snapshot="{{ $transportHasFxSnapshot?'1':'0' }}" data-commercial-locked="{{ $transportIsConfirmed?'1':'0' }}">@csrf @method('PUT')
<div class="compact-grid"><label><span>Transport Company (Travel Masters)</span><select name="transport_company_id" required data-transport-company><option value="">Select Transport Company</option>@foreach($transportCompanies as $company)<option value="{{ $company->id }}" {{ old('transport_company_id',$transportDetail?->transport_company_id)==$company->id?'selected':'' }}>{{ $company->code }} · {{ $company->name }}{{ $company->phone?' · '.$company->phone:'' }}</option>@endforeach</select><small>Company comes only from Master Data → Travel Masters → Transport Companies.</small></label><label><span>Provider Reference / BRN</span><input name="provider_reference" value="{{ old('provider_reference',$transportDetail?->provider_reference) }}"></label></div>
<div class="compact-grid">
<label><span>Route / Service</span><select name="transport_route_id" required data-transport-route><option value="">Select Route / Service</option>@foreach($transportRoutes as $route)<option value="{{ $route->id }}" data-pickup="{{ $route->pickup_location }}" data-dropoff="{{ $route->dropoff_location }}" {{ (int)old('transport_route_id',$transportDetail?->transport_route_id)===(int)$route->id?'selected':'' }}>{{ $route->name }}</option>@endforeach</select></label>
<label><span>Vehicle Type</span><select name="transport_vehicle_type_id" required data-transport-vehicle><option value="">Select Vehicle Type</option>@foreach($vehicleTypes as $vehicle)<option value="{{ $vehicle->id }}" data-min-pax="{{ $vehicle->min_pax }}" data-max-pax="{{ $vehicle->max_pax }}" {{ (int)old('transport_vehicle_type_id',$transportDetail?->transport_vehicle_type_id)===(int)$vehicle->id?'selected':'' }}>{{ $vehicle->label() }}</option>@endforeach</select></label>
</div>
<div class="compact-grid"><label><span>Pickup Date / Time</span><input type="datetime-local" name="pickup_at" value="{{ old('pickup_at',$transportDetail?->pickup_at?->format('Y-m-d\TH:i')) }}" data-transport-pickup-at></label><label><span>Driver Mobile</span><input name="driver_mobile" value="{{ old('driver_mobile',$transportDetail?->driver_mobile) }}" placeholder="Falls back to Transport Company phone"></label></div>
<div class="compact-grid"><label><span>Pickup Location</span><input name="pickup_location" value="{{ old('pickup_location',$transportDetail?->pickup_location) }}" data-transport-pickup></label><label><span>Drop-off Location</span><input name="dropoff_location" value="{{ old('dropoff_location',$transportDetail?->dropoff_location) }}" data-transport-dropoff></label></div>
<div class="compact-grid"><label><span>Vehicle Number</span><input name="vehicle_number" value="{{ old('vehicle_number',$transportDetail?->vehicle_number) }}"></label><label><span>Driver Name</span><input name="driver_name" value="{{ old('driver_name',$transportDetail?->driver_name) }}"></label></div>
<div class="compact-grid"><label><span>Transport Sale Price ({{ $baseCurrency }})</span><input type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price',$transportDetail?->sale_price ?? 0) }}" data-transport-sale></label><label><span>Vendor Cost (<span data-transport-cost-currency>{{ $transportCostCurrency }}</span>)</span><input type="number" step="0.01" min="0" name="cost_price" value="{{ old('cost_price',$transportForeignCost) }}" data-transport-cost-foreign {{ $transportIsConfirmed && $transportDetail ? 'readonly' : '' }}><small data-transport-rate-source>@if($transportIsConfirmed && $transportDetail)Historical supplier-cost snapshot locked after confirmation.@elseif($transportDetail?->vendor_rate_card_id)Saved from vendor rate card; changing Company / Route / Vehicle / service date can refresh the vendor cost before confirmation.@else Auto-fills from the dated vendor Rate Card when Company + Route + Vehicle match.@endif</small></label></div>
<div class="callout" style="margin-top:8px" data-transport-fx-summary data-fx-rate="{{ $transportFxRate }}" data-fx-date="{{ $transportFxDate }}" data-cost-currency="{{ $transportCostCurrency }}"><strong>FX:</strong> 1 <span data-transport-fx-currency>{{ $transportCostCurrency }}</span> = {{ $baseCurrency }} <span data-transport-fx-rate>{{ $transportFxRate>0?number_format($transportFxRate,4):'Not configured' }}</span> <span data-transport-fx-date>@if($transportFxDate)· Rate date {{ $transportFxDate }}@endif</span> · <strong>Cost {{ $baseCurrency }}:</strong> <span data-transport-cost-base>{{ number_format($transportBaseCost,2) }}</span> · <strong>Margin {{ $baseCurrency }}:</strong> <span data-transport-margin>{{ number_format($transportMargin,2) }}</span>@if($transportHasFxSnapshot && !$transportIsConfirmed)<button class="btn btn-ghost btn-small" type="submit" name="refresh_fx_rate" value="1" style="margin-left:8px">Refresh FX Rate</button>@elseif($transportIsConfirmed && $transportHasFxSnapshot)<span style="margin-left:8px;font-weight:700">FX snapshot locked</span>@endif</div>
<label><span>Notes</span><textarea name="notes" rows="3">{{ old('notes',$transportDetail?->notes) }}</textarea></label><div class="form-actions"><button class="btn btn-primary">Save Transport Details</button></div></form>
@if(auth()->user()->hasPermission('travel_masters.manage'))
<div class="compact-grid" style="margin-top:12px">
<details class="record-card"><summary style="cursor:pointer;font-weight:800">+ Quick Add Vehicle Type</summary><form method="POST" action="{{ route('master-data.transport-vehicle-types.store') }}" style="margin-top:12px">@csrf<div class="compact-grid"><label><span>Vehicle Type *</span><input name="name" required placeholder="e.g. Grand Cabin"></label><label><span>Code</span><input name="code"></label></div><div class="compact-grid"><label><span>Min Pax</span><input type="number" min="1" name="min_pax"></label><label><span>Max Pax</span><input type="number" min="1" name="max_pax"></label></div><div class="form-actions"><button class="btn btn-primary btn-small">Add Vehicle Type</button></div></form></details>
<details class="record-card"><summary style="cursor:pointer;font-weight:800">+ Quick Add Transport Route</summary><form method="POST" action="{{ route('master-data.transport-routes.store') }}" style="margin-top:12px">@csrf<label><span>Route / Service *</span><input name="name" required placeholder="JED Airport → Makkah Hotel"></label><div class="compact-grid"><label><span>Pickup</span><input name="pickup_location"></label><label><span>Drop-off</span><input name="dropoff_location"></label></div><div class="form-actions"><button class="btn btn-primary btn-small">Add Transport Route</button></div></form></details>
</div>
<details class="record-card" style="margin-top:12px"><summary style="cursor:pointer;font-weight:800">+ Quick Add Transport Company</summary><form method="POST" action="{{ route('master-data.travel-partners.store') }}" style="margin-top:12px">@csrf<input type="hidden" name="partner_type" value="TRANSPORT_COMPANY"><input type="hidden" name="is_active" value="1"><div class="compact-grid"><label><span>Company Name *</span><input name="name" required></label><label><span>Phone</span><input name="phone"></label></div><div class="compact-grid"><label><span>City</span><input name="city"></label><label><span>Country</span><input name="country_code" maxlength="2" placeholder="SA"></label></div><div class="form-actions"><button class="btn btn-primary btn-small">Add Transport Company</button></div></form></details>
@endif
<script>
(function(){
    const form=document.querySelector('[data-transport-form]'); if(!form)return;
    const company=form.querySelector('[data-transport-company]'), route=form.querySelector('[data-transport-route]'), vehicle=form.querySelector('[data-transport-vehicle]');
    const pickupAt=form.querySelector('[data-transport-pickup-at]'), pickup=form.querySelector('[data-transport-pickup]'), dropoff=form.querySelector('[data-transport-dropoff]');
    const cost=form.querySelector('[data-transport-cost-foreign]'), sale=form.querySelector('[data-transport-sale]');
    const source=form.querySelector('[data-transport-rate-source]'), base=form.querySelector('[data-transport-cost-base]'), margin=form.querySelector('[data-transport-margin]');
    const fxSummary=form.querySelector('[data-transport-fx-summary]'), fxRateText=form.querySelector('[data-transport-fx-rate]'), fxDateText=form.querySelector('[data-transport-fx-date]');
    const fxCurrencyText=form.querySelector('[data-transport-fx-currency]'), costCurrencyText=form.querySelector('[data-transport-cost-currency]');
    const bookingDate=form.dataset.bookingDate||'';
    const baseCurrency=(form.dataset.baseCurrency||'PKR').toUpperCase();
    const hasSavedFx=form.dataset.fxSnapshot==='1';
    const commercialLocked=form.dataset.commercialLocked==='1';
    const savedFxRate=Number(fxSummary?.dataset.fxRate||0);
    const savedFxDate=fxSummary?.dataset.fxDate||'';
    const savedCostCurrency=(fxSummary?.dataset.costCurrency||'SAR').toUpperCase();
    const rates=@json($transportRateOptions->values());
    const fxRows=@json($transportFxOptions->values());

    const serviceDate=()=>{
        const raw=(pickupAt?.value||'').trim();
        return raw.length>=10?raw.slice(0,10):bookingDate;
    };
    const inRange=(row,date)=>{
        if(!date)return true;
        if(row.effective_from && row.effective_from>date)return false;
        if(row.effective_until && row.effective_until<date)return false;
        return true;
    };
    const findRate=()=>{
        if(!company?.value||!route?.value||!vehicle?.value)return null;
        const date=serviceDate();
        return rates.filter(row=>
            String(row.transport_company_id)===String(company.value)
            && String(row.transport_route_id)===String(route.value)
            && String(row.transport_vehicle_type_id)===String(vehicle.value)
            && inRange(row,date)
        ).sort((a,b)=>String(b.effective_from||'').localeCompare(String(a.effective_from||'')) || Number(b.rate_card_id||0)-Number(a.rate_card_id||0))[0]||null;
    };
    const findFx=(currency,date)=>{
        currency=(currency||'SAR').toUpperCase();
        if(currency===baseCurrency)return {rate:1,effective_date:date};
        return fxRows.filter(row=>
            String(row.from_currency_code||'').toUpperCase()===currency
            && String(row.to_currency_code||'').toUpperCase()===baseCurrency
            && (!date || String(row.effective_date||'')<=date)
        ).sort((a,b)=>String(b.effective_date||'').localeCompare(String(a.effective_date||'')))[0]||null;
    };
    const currentCurrency=()=>{
        const matched=findRate();
        if(commercialLocked||hasSavedFx)return savedCostCurrency;
        return String(matched?.currency_code||savedCostCurrency||'SAR').toUpperCase();
    };
    const currentFx=()=>{
        const currency=currentCurrency();
        if(hasSavedFx)return {rate:savedFxRate,effective_date:savedFxDate,currency};
        const row=findFx(currency,serviceDate());
        return {rate:Number(row?.rate||0),effective_date:row?.effective_date||'',currency};
    };
    const syncAmounts=()=>{
        const fx=currentFx();
        const costBase=Number(cost?.value||0)*Number(fx.rate||0);
        const saleAmount=Number(sale?.value||0);
        if(base)base.textContent=costBase.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
        if(margin)margin.textContent=(saleAmount-costBase).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
        if(fxCurrencyText)fxCurrencyText.textContent=fx.currency;
        if(costCurrencyText)costCurrencyText.textContent=fx.currency;
        if(fxRateText)fxRateText.textContent=fx.rate>0?Number(fx.rate).toLocaleString(undefined,{minimumFractionDigits:4,maximumFractionDigits:8}):'Not configured';
        if(fxDateText)fxDateText.textContent=fx.effective_date?'· Rate date '+fx.effective_date:'';
    };
    const syncRoute=()=>{
        const o=route?.options[route.selectedIndex];if(!o)return;
        if(pickup && !pickup.value)pickup.value=o.dataset.pickup||'';
        if(dropoff && !dropoff.value)dropoff.value=o.dataset.dropoff||'';
    };
    const syncRate=()=>{
        if(!cost)return;
        if(commercialLocked){syncAmounts();return;}
        const row=findRate();
        if(row){
            cost.value=Number(row.cost_amount).toFixed(2);
            if(source)source.textContent=(row.rate_card_name||'Vendor rate card')+' · '+(row.currency_code||'SAR')+' '+Number(row.cost_amount).toFixed(2)+' · service date '+serviceDate();
        }else if(source){
            source.textContent='No matching active vendor Rate Card for this Company + Route + Vehicle + service date. Enter the agreed vendor cost manually.';
        }
        syncAmounts();
    };

    route?.addEventListener('change',()=>{syncRoute();syncRate();});
    company?.addEventListener('change',syncRate);
    vehicle?.addEventListener('change',syncRate);
    pickupAt?.addEventListener('change',syncRate);
    cost?.addEventListener('input',syncAmounts);
    sale?.addEventListener('input',syncAmounts);
    syncAmounts();
})();
</script>
@endif
@else
<div class="empty-state"><strong>No ERP-08 specialized form is required for {{ $service->product?->name }}.</strong><p>Package/component composition will be expanded in the Umrah workflow. The booking service and passenger links remain valid.</p></div>
@endif
</div>
@endsection
