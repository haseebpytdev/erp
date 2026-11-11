@php
    $rows=$ticketRows ?? collect();
    $linked=$service->passengers->where('is_active',true)->sortBy('passenger_no');
    $postedInvoice=$booking->salesInvoices->firstWhere('status','POSTED');
    $packageOperationalMode=(bool)($packageOperationalMode ?? false);

    $saleTotal=(float)$rows->sum('selling_total');
    $costTotal=(float)$rows->sum('net_supplier_cost');
    $agentTotal=(float)$rows->sum(fn($t)=>(float)optional($t->commissions->firstWhere('commission_type','AGENT'))->commission_amount);
    $staffTotal=(float)$rows->sum(fn($t)=>(float)optional($t->commissions->firstWhere('commission_type','SALESPERSON'))->commission_amount);
    $gross=$saleTotal-$costTotal;
    $net=$gross-$agentTotal-$staffTotal;

    $paxCounts=$linked->groupBy('pax_type')->map->count();
    $paxTypes=collect(['ADULT','CHILD','INFANT'])->filter(fn($type)=>(int)($paxCounts[$type]??0)>0);
    $defaultTab=$paxTypes->first() ?: 'ADULT';

    // ERP10.22.7 — preserve the complete Air Ticket Batch form after validation errors
    // and restore the latest database-backed working draft when the page is reloaded.
    $draftPayload=[];
    if(\Illuminate\Support\Facades\Schema::hasTable('air_ticket_entry_drafts')){
        $draftRow=\App\Models\AirTicketEntryDraft::query()
            ->where('booking_id',$booking->id)
            ->where('booking_service_id',$service->id)
            ->first();
        $draftPayload=is_array($draftRow?->payload) ? $draftRow->payload : [];
    }

    $hasOldBatchInput=(int)old('_air_ticket_batch_service_id',0)===(int)$service->id;
    $hasDraftBatchInput=!$hasOldBatchInput
        && (int)data_get($draftPayload,'_air_ticket_batch_service_id',0)===(int)$service->id;

    $batchValue=function(string $key,mixed $default=null) use ($hasOldBatchInput,$hasDraftBatchInput,$draftPayload){
        if($hasOldBatchInput)return old($key,$default);
        if($hasDraftBatchInput)return data_get($draftPayload,$key,$default);
        return $default;
    };

    $retainedBatchPassengerIds=collect((array)$batchValue('passenger_ids',$linked->pluck('id')->all()))
        ->map(fn($id)=>(int)$id)->values();
    $retainedTicketNumberLines=preg_split('/\r\n|\r|\n/', (string)$batchValue('ticket_numbers_text','')) ?: [];
    $retainedTicketNumberByPassenger=[];
    $retainedTicketLineIndex=0;
    foreach($linked as $retainedLinkedPassenger){
        if($retainedBatchPassengerIds->contains((int)$retainedLinkedPassenger->id)){
            $retainedTicketNumberByPassenger[(int)$retainedLinkedPassenger->id]=(string)($retainedTicketNumberLines[$retainedTicketLineIndex]??'');
            $retainedTicketLineIndex++;
        }
    }

    $retainedFareTab=strtoupper((string)$batchValue('_active_fare_tab',$defaultTab));
    if($paxTypes->contains($retainedFareTab))$defaultTab=$retainedFareTab;

    // ERP10.22.8 — once passenger tickets are saved, the batch-entry workspace
    // becomes a deliberate "Add More Tickets" action instead of staying open
    // with a second blank copy of Common Flight Details / Ticket Numbers.
    $hasSavedTickets=$rows->isNotEmpty();
    $showBatchEntry=!$hasSavedTickets || $hasOldBatchInput || $hasDraftBatchInput;
@endphp

<style>
.ticket-batch{border-top:1px solid var(--line);padding-top:10px}
.ticket-section{border:1px solid var(--line);border-radius:11px;background:#fff;margin-top:9px;overflow:hidden}
.ticket-section-title{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 11px;background:#f5f8fc;border-bottom:1px solid var(--line);font-size:12px;font-weight:900;color:#153865}
.ticket-section-body{padding:11px}
.ticket-checks{display:flex;gap:8px;flex-wrap:wrap}
.ticket-check{display:flex;align-items:center;gap:7px;border:1px solid var(--line);background:#fff;border-radius:8px;padding:7px 9px;font-size:11px}
.ticket-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.ticket-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.ticket-grid.two{grid-template-columns:repeat(2,minmax(0,1fr))}
.common-flight-grid .date-time-wide{grid-column:span 2}
.common-flight-grid .fare-basis-wide{grid-column:span 2}
.common-flight-grid input[type="datetime-local"]{width:100%;min-width:0;font-size:12px}

.ticket-primary-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(320px,.75fr);gap:12px;align-items:stretch}
.ticket-secondary-grid{display:grid;grid-template-columns:1fr;gap:12px;align-items:start}
.ticket-primary-grid .ticket-section,.ticket-secondary-grid .ticket-section{margin-top:9px;height:calc(100% - 9px)}
.ticket-number-grid .select-col{width:42px;text-align:center}
.ticket-number-grid .pax-col{width:80px}
.ticket-number-grid tr.is-muted{opacity:.55;background:#fafbfc}
.commission-compact{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.commission-compact .wide{grid-column:1/-1}
.ticket-number-grid{width:100%;border-collapse:collapse;font-size:11px}
.ticket-number-grid th{background:#eef3f9;padding:7px;text-align:left;color:#43536b}
.ticket-number-grid td{padding:7px;border-top:1px solid var(--line)}
.ticket-number-grid input{min-width:0}
.fare-tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);padding:0 10px;background:#fbfcfe}
.fare-tab{border:0;background:transparent;padding:9px 14px;font-weight:800;font-size:11px;color:#68778d;cursor:pointer;border-bottom:2px solid transparent}
.fare-tab.active{color:var(--primary);border-bottom-color:var(--primary)}
.fare-panel{display:none;padding:10px}.fare-panel.active{display:block}
.fare-core-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;padding:9px;border:1px solid var(--line);border-radius:9px;background:#fff;margin-bottom:8px}
.auto-tax-field span em{font-style:normal;color:var(--primary);font-size:9px;font-weight:900;text-transform:uppercase;margin-left:4px}
.auto-tax-field input[readonly]{background:#f7f9fc;color:#34465f}
.fare-pricing-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.fare-pricing-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;padding:9px}
.pricing-auto-amount{margin:0 9px 8px;border:1px solid var(--line);border-radius:7px;background:#f8fafc;padding:7px 8px}
.pricing-auto-amount span{display:block;color:#5b6b80;font-size:8px;font-weight:900;text-transform:uppercase;letter-spacing:.02em}
.pricing-auto-amount strong{display:block;margin-top:4px;color:var(--primary);font-size:11px}
.matrix-live-summary{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-top:8px}
.matrix-live-summary div{border:1px solid var(--line);border-radius:8px;background:#fff;padding:7px}
.matrix-live-summary small{display:block;color:var(--muted);font-size:8px;text-transform:uppercase;font-weight:900}
.matrix-live-summary strong{display:block;margin-top:4px;font-size:10px;color:#087a4b}
.matrix-live-summary div:nth-child(4) strong,.matrix-live-summary div:nth-child(5) strong{color:#b42318}
.commercial-grid{display:grid;grid-template-columns:1.05fr 1fr 1fr;gap:8px}
.commercial-card{border:1px solid var(--line);border-radius:9px;background:#fff;overflow:hidden}
.commercial-card h5{margin:0;padding:7px 9px;background:#f6f9fd;color:#183c6b;font-size:9px;text-transform:uppercase}
.commercial-card.agent-card{background:#f8fbff}.commercial-card.salesperson-card{background:#fffaf2}
.commercial-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;padding:8px}
.fare-columns{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fare-card{border:1px solid var(--line);border-radius:9px;overflow:hidden}
.fare-card h5{margin:0;padding:8px 10px;background:#f6f9fd;font-size:11px;color:#183c6b}
.fare-card.purchase h5{background:#fff8ec;color:#755014}
.fare-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;padding:9px}
.fare-total{display:flex;justify-content:space-between;gap:10px;padding:8px 10px;background:#eef9f3;font-size:11px}
.fare-total strong{color:#087a4b}
.commission-row{display:grid;grid-template-columns:1.1fr .8fr .8fr 1.1fr .8fr .8fr;gap:8px}
.batch-summary{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:7px;margin-top:10px}
.batch-summary div{border:1px solid var(--line);border-radius:9px;background:#fff;padding:8px}
.batch-summary small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase;font-weight:800}
.batch-summary strong{display:block;margin-top:4px;font-size:12px}
.ticket-existing{margin-top:12px;border:1px solid var(--line);border-radius:11px;overflow:hidden}
.ticket-existing table{width:100%;border-collapse:collapse;font-size:11px}
.ticket-existing th{background:#eef3f9;padding:7px;text-align:left;color:#43536b}
.ticket-existing td{padding:7px;border-top:1px solid var(--line);vertical-align:middle}
.ticket-existing .money{text-align:right;white-space:nowrap}
.ticket-existing details summary{cursor:pointer;color:var(--primary);font-weight:800}
.ticket-edit{padding:10px;background:#fafcff;border-top:1px dashed var(--line)}
.ticket-edit .ticket-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
.ticket-help{font-size:10px;color:var(--muted);line-height:1.45}
.field-error{display:block;margin-top:4px;color:#b42318;font-size:10px;font-weight:700;line-height:1.35}
.ticket-validation-retained{border-left:4px solid #d97706}
.ticket-entry-workspace[hidden]{display:none!important}
.saved-ticket-head-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ticket-edit .ticket-primary-grid{margin-top:9px}
.ticket-edit .ticket-section{height:auto}
.ticket-edit .ticket-secondary-grid{margin-top:2px}
.ticket-edit .ticket-number-grid input{width:100%}
.ticket-edit .matrix-live-summary{margin:8px 0 0}
@media(max-width:1180px){.ticket-primary-grid,.ticket-secondary-grid{grid-template-columns:1fr}.ticket-grid,.ticket-edit .ticket-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.commission-row{grid-template-columns:repeat(3,minmax(0,1fr))}.commission-compact{grid-template-columns:repeat(2,minmax(0,1fr))}.fare-columns,.fare-pricing-grid{grid-template-columns:1fr}.commercial-grid{grid-template-columns:1fr}.matrix-live-summary,.batch-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:680px){.ticket-grid,.ticket-grid.two,.ticket-grid.three,.ticket-edit .ticket-grid,.commission-row,.fare-fields,.commission-compact,.fare-core-fields,.fare-pricing-fields,.commercial-fields{grid-template-columns:1fr}.matrix-live-summary,.batch-summary{grid-template-columns:1fr}}
.package-operational-mode .ticket-secondary-grid,.package-operational-mode .batch-summary{display:none!important}
.package-operational-mode .ticket-existing th:nth-child(7),.package-operational-mode .ticket-existing th:nth-child(8),.package-operational-mode .ticket-existing th:nth-child(9),.package-operational-mode .ticket-existing td:nth-child(7),.package-operational-mode .ticket-existing td:nth-child(8),.package-operational-mode .ticket-existing td:nth-child(9){display:none!important}
.package-operational-banner{margin:0 0 10px;padding:8px 10px;border:1px solid #d3eadc;border-radius:9px;background:#eef8f3;color:#236747;font-size:10px;font-weight:700}
</style>

<div class="ticket-batch {{ $packageOperationalMode ? 'package-operational-mode' : '' }}">
    @if($packageOperationalMode)<div class="package-operational-banner">Group Package flight component: enter PNR, flight and ticket details only. Ticket sale, supplier cost, markup and commission are controlled by the package commercial and are not entered here.</div>@endif
    @if($postedInvoice)
    <div class="callout warn" style="margin-bottom:10px"><strong>Posted invoice protection:</strong> customer selling amounts are locked. Ticket status/number, supplier cost and forecast commissions can still be maintained.</div>
    @endif

    @if($booking->isEditable() && !$postedInvoice && auth()->user()->hasPermission('ticket_commercial.manage'))
    <div class="ticket-entry-workspace" data-ticket-entry-workspace {{ $showBatchEntry ? '' : 'hidden' }}>
    <form method="POST" action="{{ route('operations.bookings.air-tickets.batch',[$booking,$service]) }}" data-ticket-batch-form data-ticket-draft-url="{{ route('operations.bookings.air-tickets.draft.store',[$booking,$service]) }}">@csrf
        <input type="hidden" name="_air_ticket_batch_form" value="1">
        <input type="hidden" name="_air_ticket_batch_service_id" value="{{ $service->id }}">
        <input type="hidden" name="_active_fare_tab" value="{{ $defaultTab }}" data-active-fare-tab>

        @if($hasOldBatchInput && $errors->any())
        <div class="callout warn ticket-validation-retained" style="margin:0 0 10px">
            <strong>Ticket data retained.</strong> Nothing from this batch was saved because one or more fields need correction. Your entered values are still below; correct the highlighted field(s) and click <strong>Save Passenger Tickets</strong> again.
        </div>
        @endif

        <div class="ticket-primary-grid">
            <div class="ticket-section">
                <div class="ticket-section-title"><span>1. Common PNR & Flight Details</span><span class="muted-copy">{{ $packageOperationalMode ? 'Operational details only' : 'Enter once for this passenger batch' }}</span></div>
                <div class="ticket-section-body">
                    <div class="ticket-grid common-flight-grid">
                        <label><span>PNR</span><input name="pnr" maxlength="20" required placeholder="ABC123" value="{{ $batchValue('pnr','') }}"></label>
                        <label><span>Status</span><select name="ticket_status"><option value="BOOKED" {{ $batchValue('ticket_status','BOOKED')==='BOOKED' ? 'selected' : '' }}>BOOKED</option><option value="ISSUED" {{ $batchValue('ticket_status','BOOKED')==='ISSUED' ? 'selected' : '' }}>ISSUED</option></select></label>
                        <label><span>Airline</span><select name="airline_id" required><option value="">Select airline</option>@foreach($airlines as $a)<option value="{{ $a->id }}" {{ (int)$batchValue('airline_id',0)===(int)$a->id ? 'selected' : '' }}>{{ $a->name }}@if($a->iata_code) · {{ $a->iata_code }}@endif</option>@endforeach</select>@error('airline_id')<small class="field-error">{{ $message }}</small>@enderror</label>
                        <label><span>Booking Source / GDS</span><select name="booking_source_id" required><option value="">Select source</option>@foreach($sources as $src)<option value="{{ $src->id }}" {{ (int)$batchValue('booking_source_id',0)===(int)$src->id ? 'selected' : '' }}>{{ $src->name }}</option>@endforeach</select>@error('booking_source_id')<small class="field-error">{{ $message }}</small>@enderror</label>
                        <label><span>From</span><input name="origin" maxlength="10" required placeholder="LHE" value="{{ $batchValue('origin','') }}"></label>
                        <label><span>To</span><input name="destination" maxlength="10" required placeholder="JED" value="{{ $batchValue('destination','') }}"></label>
                        <label><span>Sector / Routing</span><input name="sector" placeholder="LHE-JED" value="{{ $batchValue('sector','') }}"></label>
                        <label><span>Flight Number</span><input name="flight_number" placeholder="SV739" value="{{ $batchValue('flight_number','') }}"></label>
                        <label class="date-time-wide"><span>Departure Date & Time</span><input type="datetime-local" name="departure_at" value="{{ $batchValue('departure_at','') }}" required>@error('departure_at')<small class="field-error">{{ $message }}</small>@enderror</label>
                        <label class="date-time-wide"><span>Arrival Date & Time</span><input type="datetime-local" name="arrival_at" value="{{ $batchValue('arrival_at','') }}">@error('arrival_at')<small class="field-error">{{ $message }}</small>@enderror</label>
                        <label><span>Cabin</span><input name="cabin_class" placeholder="Economy" value="{{ $batchValue('cabin_class','') }}"></label>
                        <label><span>Baggage</span><input name="baggage" placeholder="30 KG" value="{{ $batchValue('baggage','') }}"></label>
                        <label class="fare-basis-wide"><span>Fare Basis</span><input name="fare_basis" placeholder="e.g. HLX1M2" value="{{ $batchValue('fare_basis','') }}"></label>
                    </div>
                </div>
            </div>

            <div class="ticket-section">
                <div class="ticket-section-title"><span>2. Ticket Numbers</span><span class="muted-copy"></span></div>
                <div class="ticket-section-body">
                    <table class="ticket-number-grid">
                        <thead><tr><th class="select-col">Use</th><th>Passenger</th><th class="pax-col">Pax</th><th>Ticket Number</th></tr></thead>
                        <tbody data-ticket-number-rows>
                            @foreach($linked as $p)
                            <tr data-ticket-passenger="{{ $p->id }}">
                                <td class="select-col"><input type="checkbox" name="passenger_ids[]" value="{{ $p->id }}" data-pax-type="{{ $p->pax_type }}" data-passenger-name="{{ $p->fullName() }}" {{ $retainedBatchPassengerIds->contains((int)$p->id) ? 'checked' : '' }}></td>
                                <td><strong>{{ $p->fullName() }}</strong></td>
                                <td>{{ $p->pax_type }}</td>
                                <td><input data-ticket-number-input data-passenger-id="{{ $p->id }}" placeholder="065-1234567890" value="{{ $retainedTicketNumberByPassenger[(int)$p->id] ?? '' }}"></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <textarea name="ticket_numbers_text" data-ticket-numbers-hidden style="display:none">{{ $batchValue('ticket_numbers_text','') }}</textarea>
                    <div class="ticket-help" style="margin-top:8px">BOOKED may remain without ticket numbers. ISSUED requires one unique ticket number per selected passenger. Uncheck a passenger if this flight/leg does not apply to them.</div>
                </div>
            </div>
        </div>

        <div class="ticket-secondary-grid">
            <div class="ticket-section ticket-fare-entry-section">
                <div class="ticket-section-title"><span>3. Passenger-Type Fare Entry</span><span class="muted-copy">Fare Total + Base Fare are entered once; Taxes calculate automatically and all other pricing formulas remain unchanged</span></div>
                <div class="fare-tabs" role="tablist">
                    @foreach($paxTypes as $type)<button type="button" class="fare-tab {{ $type===$defaultTab?'active':'' }}" data-fare-tab="{{ $type }}">{{ $type }} <span style="opacity:.7">({{ (int)($paxCounts[$type]??0) }})</span></button>@endforeach
                </div>
                @foreach($paxTypes as $type)
                @php
                    $retainedBase=(float)$batchValue('fare.'.$type.'.base_fare',0);
                    $retainedTaxes=(float)$batchValue('fare.'.$type.'.airline_taxes',0);
                    $retainedFareTotal=(float)$batchValue('fare_total.'.$type,$retainedBase+$retainedTaxes);
                    $retainedServiceMarkup=(float)$batchValue('fare.'.$type.'.customer_service_fee',
                        $batchValue('fare.'.$type.'.service_markup',0))
                        +(float)$batchValue('fare.'.$type.'.markup',0);
                    $retainedCustomerDiscount=(float)$batchValue('fare.'.$type.'.discount',0);
                    $customerDiscountType=$batchValue('ui_customer_discount_type.'.$type,
                        $batchValue('fare.'.$type.'.discount_type','FIXED'));
                    $customerDiscountValue=$batchValue('ui_customer_discount_value.'.$type,
                        $batchValue('fare.'.$type.'.discount_value',$retainedCustomerDiscount));
                    $retainedSupplierCharges=(float)$batchValue('purchase.'.$type.'.supplier_charges',0)
                        +(float)$batchValue('purchase.'.$type.'.supplier_other_charges',0);
                    $retainedSupplierDiscount=(float)$batchValue('purchase.'.$type.'.supplier_discount',0)
                        +(float)$batchValue('purchase.'.$type.'.supplier_commission',0);
                    $supplierDiscountType=$batchValue('ui_supplier_discount_type.'.$type,
                        $batchValue('purchase.'.$type.'.discount_type','FIXED'));
                    $supplierDiscountValue=$batchValue('ui_supplier_discount_value.'.$type,
                        $batchValue('purchase.'.$type.'.discount_value',$retainedSupplierDiscount));
                @endphp
                <div class="fare-panel {{ $type===$defaultTab?'active':'' }}" data-fare-panel="{{ $type }}" data-fare-row="{{ $type }}">
                    <div class="fare-core-fields">
                        <label><span>Fare Total *</span><input type="number" step="0.01" min="0" name="fare_total[{{ $type }}]" value="{{ $retainedFareTotal }}" required data-fare-field="fare_total"></label>
                        <label><span>Base Fare *</span><input type="number" step="0.01" min="0" name="fare[{{ $type }}][base_fare]" value="{{ $retainedBase }}" required data-fare-field="base_fare"></label>
                        <label class="auto-tax-field"><span>Taxes * <em>Auto</em></span><input type="number" step="0.01" min="0" name="fare[{{ $type }}][airline_taxes]" value="{{ $retainedTaxes }}" required readonly data-fare-field="airline_taxes" title="Auto-calculated as Fare Total minus Base Fare"></label>
                    </div>

                    <div class="fare-pricing-grid">
                        <div class="fare-card customer-pricing-card">
                            <h5>Customer Pricing — per {{ $type }} ticket</h5>
                            <div class="fare-pricing-fields customer-pricing-fields">
                                <label><span>Service / Markup</span><input type="number" step="0.01" min="0" name="fare[{{ $type }}][customer_service_fee]" value="{{ $retainedServiceMarkup }}" data-fare-field="customer_service_fee" data-optional-pricing-number></label>
                                <input type="hidden" name="fare[{{ $type }}][markup]" value="0" data-fare-field="markup">
                                <label><span>Discount Type</span><select name="ui_customer_discount_type[{{ $type }}]" data-customer-discount-type data-pricing-ui="customer_discount_type"><option value="FIXED" {{ $customerDiscountType==='FIXED'?'selected':'' }}>Fixed Amount</option><option value="PERCENT" {{ $customerDiscountType==='PERCENT'?'selected':'' }}>Percentage %</option></select></label>
                                <label><span>Discount Value</span><input type="number" step="0.01" min="0" name="ui_customer_discount_value[{{ $type }}]" value="{{ $customerDiscountValue }}" data-customer-discount-value data-pricing-ui="customer_discount_value" data-optional-pricing-number></label>
                                <input type="hidden" name="fare[{{ $type }}][discount]" value="{{ $retainedCustomerDiscount }}" data-fare-field="discount">
                            </div>
                            <div class="pricing-auto-amount"><span>CUSTOMER DISCOUNT AMOUNT · AUTO · BASE FARE ONLY</span><strong data-customer-discount-auto>{{ $booking->currency_code }} 0.00</strong></div>
                            <div class="fare-total"><span>Receivable / Sell per Ticket</span><strong data-pax-sell>{{ $booking->currency_code }} 0.00</strong></div>
                        </div>

                        <div class="fare-card purchase supplier-pricing-card">
                            <h5>Supplier Pricing — per {{ $type }} ticket</h5>
                            <div class="fare-pricing-fields supplier-pricing-fields">
                                <input type="hidden" name="purchase[{{ $type }}][supplier_base_fare]" value="{{ $retainedBase }}" data-purchase-field="supplier_base_fare">
                                <input type="hidden" name="purchase[{{ $type }}][supplier_taxes]" value="{{ $retainedTaxes }}" data-purchase-field="supplier_taxes">
                                <label><span>Supplier Charges</span><input type="number" step="0.01" min="0" name="purchase[{{ $type }}][supplier_charges]" value="{{ $retainedSupplierCharges }}" data-purchase-field="supplier_charges" data-optional-pricing-number></label>
                                <input type="hidden" name="purchase[{{ $type }}][supplier_other_charges]" value="0" data-purchase-field="supplier_other_charges">
                                <input type="hidden" name="purchase[{{ $type }}][supplier_commission]" value="0" data-purchase-field="supplier_commission">
                                <label><span>Supplier Discount Type</span><select name="ui_supplier_discount_type[{{ $type }}]" data-supplier-discount-type data-pricing-ui="supplier_discount_type"><option value="FIXED" {{ $supplierDiscountType==='FIXED'?'selected':'' }}>Fixed Amount</option><option value="PERCENT" {{ $supplierDiscountType==='PERCENT'?'selected':'' }}>Percentage %</option></select></label>
                                <label><span>Supplier Discount Value</span><input type="number" step="0.01" min="0" name="ui_supplier_discount_value[{{ $type }}]" value="{{ $supplierDiscountValue }}" data-supplier-discount-value data-pricing-ui="supplier_discount_value" data-optional-pricing-number></label>
                                <input type="hidden" name="purchase[{{ $type }}][supplier_incentive]" value="0" data-purchase-field="supplier_incentive">
                                <input type="hidden" name="purchase[{{ $type }}][supplier_discount]" value="{{ $retainedSupplierDiscount }}" data-purchase-field="supplier_discount">
                            </div>
                            <div class="pricing-auto-amount"><span>SUPPLIER DISCOUNT AMOUNT · AUTO · BASE FARE ONLY</span><strong data-supplier-discount-auto>{{ $booking->currency_code }} 0.00</strong></div>
                            <div class="fare-total"><span>Net Cost / Ticket</span><strong data-pax-cost>{{ $booking->currency_code }} 0.00</strong></div>
                        </div>
                    </div>

                    <div class="matrix-live-summary">
                        <div><small>Customer Sell</small><strong data-matrix-customer-sale>{{ $booking->currency_code }} 0.00</strong></div>
                        <div><small>Supplier Cost</small><strong data-matrix-supplier-cost>{{ $booking->currency_code }} 0.00</strong></div>
                        <div><small>Gross Margin</small><strong data-matrix-gross-margin>{{ $booking->currency_code }} 0.00</strong></div>
                        <div><small>Agent Commission</small><strong data-matrix-agent-commission>{{ $booking->currency_code }} 0.00</strong></div>
                        <div><small>Sales Commission</small><strong data-matrix-sales-commission>{{ $booking->currency_code }} 0.00</strong></div>
                        <div><small>Net Contribution</small><strong data-matrix-net-contribution>{{ $booking->currency_code }} 0.00</strong></div>
                        <div><small>Net Margin %</small><strong data-matrix-net-margin-pct>0.00%</strong></div>
                    </div>

                    @if($type!=='ADULT' && $paxTypes->contains('ADULT'))
                    <div style="margin-top:8px"><button type="button" class="btn btn-ghost btn-small" data-copy-adult="{{ $type }}">Copy Adult Fare to {{ $type }}</button></div>
                    @endif
                </div>
                @endforeach
            </div>

            <div class="ticket-section commercial-section">
                <div class="ticket-section-title"><span>4. Commercial Parties & Commissions</span><span class="muted-copy">Vendor identifies supplier cost owner; Agent and Salesperson commissions are separate from supplier cost</span></div>
                <div class="ticket-section-body">
                    <div class="commercial-grid">
                        <div class="commercial-card vendor-card">
                            <h5>Vendor / Supplier</h5>
                            <div class="commercial-fields">
                                <label><span>Vendor</span><select name="supplier_vendor_party_id"><option value="">No supplier</option>@foreach($vendors as $v)<option value="{{ $v->id }}" {{ (int)$batchValue('supplier_vendor_party_id',0)===(int)$v->id ? 'selected' : '' }}>{{ $v->display_name }}</option>@endforeach</select></label>
                                <label><span>Cost Status</span><select name="supplier_cost_status"><option value="ESTIMATED" {{ $batchValue('supplier_cost_status','ESTIMATED')==='ESTIMATED' ? 'selected' : '' }}>ESTIMATED</option><option value="CONFIRMED" {{ $batchValue('supplier_cost_status','ESTIMATED')==='CONFIRMED' ? 'selected' : '' }}>CONFIRMED</option></select></label>
                                <label><span>GDS PNR</span><input name="supplier_reference" placeholder="GDS PNR" value="{{ $batchValue('supplier_reference','') }}"></label>
                            </div>
                        </div>
                        <div class="commercial-card agent-card">
                            <h5>Agent Commission</h5>
                            <div class="commercial-fields">
                                <label><span>Agent</span><select name="agent_party_id"><option value="">Direct / None</option>@foreach($agents as $a)<option value="{{ $a->id }}" {{ (int)$batchValue('agent_party_id',0)===(int)$a->id ? 'selected' : '' }}>{{ $a->display_name }}</option>@endforeach</select></label>
                                <label><span>Basis</span><select name="agent_commission_basis">@foreach(['FIXED'=>'Fixed Amount','PER_TICKET'=>'Per Ticket','PERCENT_OF_SALE'=>'% of Sale','PERCENT_OF_BASE_FARE'=>'% of Base Fare','PERCENT_OF_GROSS_MARGIN'=>'% of Gross Margin','MANUAL'=>'Manual'] as $b=>$label)<option value="{{ $b }}" {{ $batchValue('agent_commission_basis','FIXED')===$b ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></label>
                                <label><span>Rate / Amount</span><input type="number" step="0.0001" min="0" name="agent_commission_rate" value="{{ $batchValue('agent_commission_rate',0) }}"></label>
                            </div>
                        </div>
                        <div class="commercial-card salesperson-card">
                            <h5>Salesperson Commission</h5>
                            <div class="commercial-fields">
                                <label><span>Salesperson</span><select name="salesperson_staff_id"><option value="">None / Booking default</option>@foreach($staff as $st)<option value="{{ $st->id }}" {{ (int)$batchValue('salesperson_staff_id',0)===(int)$st->id ? 'selected' : '' }}>{{ $st->employee_code }} · {{ $st->name }}</option>@endforeach</select></label>
                                <label><span>Basis</span><select name="salesperson_commission_basis">@foreach(['FIXED'=>'Fixed Amount','PER_TICKET'=>'Per Ticket','PERCENT_OF_SALE'=>'% of Sale','PERCENT_OF_BASE_FARE'=>'% of Base Fare','PERCENT_OF_GROSS_MARGIN'=>'% of Gross Margin','MANUAL'=>'Manual'] as $b=>$label)<option value="{{ $b }}" {{ $batchValue('salesperson_commission_basis','FIXED')===$b ? 'selected' : '' }}>{{ $label }}</option>@endforeach</select></label>
                                <label><span>Rate / Amount</span><input type="number" step="0.0001" min="0" name="salesperson_commission_rate" value="{{ $batchValue('salesperson_commission_rate',0) }}"></label>
                            </div>
                        </div>
                    </div>
                    <label style="margin-top:8px"><span>Notes <small class="muted-copy">(optional)</small></span><textarea name="notes" rows="2" placeholder="Supplier, commission or internal remarks">{{ $batchValue('notes','') }}</textarea></label>
                </div>
            </div>
        </div>

        <div class="batch-summary">
            <div><small>Selected Passengers</small><strong data-selected-count>0</strong></div>
            <div><small>Batch Customer Sale</small><strong data-batch-sale>{{ $booking->currency_code }} 0.00</strong></div>
            <div><small>Batch Supplier Cost</small><strong data-batch-cost>{{ $booking->currency_code }} 0.00</strong></div>
            <div><small>Gross Margin</small><strong data-batch-margin>{{ $booking->currency_code }} 0.00</strong></div>
            <div><small>Commissions</small><strong data-batch-commission>{{ $booking->currency_code }} 0.00</strong></div>
            <div><small>Net Contribution</small><strong data-batch-net>{{ $booking->currency_code }} 0.00</strong></div>
            <div><small>Net Margin %</small><strong data-batch-net-margin-pct>0.00%</strong></div>
        </div>

        <div class="form-actions"><button class="btn btn-primary">Save Passenger Tickets</button></div>
    </form>
    </div>
    @endif

    <div class="ticket-existing" id="saved-passenger-tickets-{{ $service->id }}">
        <div class="ticket-section-title">
            <span>Saved Passenger Tickets</span>
            <span class="saved-ticket-head-actions">
                <span>{{ $rows->count() }} ticket(s)</span>
                @if($rows->isNotEmpty() && $booking->isEditable() && !$postedInvoice && auth()->user()->hasPermission('ticket_commercial.manage'))
                <button type="button" class="btn btn-primary btn-small" data-add-more-tickets>+ Add More Tickets</button>
                @endif
            </span>
        </div>
        @if($rows->isNotEmpty())
        <form method="POST" action="{{ route('operations.bookings.air-tickets.bulk-numbers',[$booking,$service]) }}">@csrf @method('PUT')
            <table>
                <thead><tr><th>#</th><th>Passenger</th><th>PNR</th><th>Ticket No.</th><th>Route</th><th>Status</th><th class="money">Sell</th><th class="money">Cost</th><th class="money">Margin</th><th>Action</th></tr></thead>
                <tbody>
                @foreach($rows as $ticket)
                @php
                    $rowGross=(float)$ticket->selling_total-(float)$ticket->net_supplier_cost;
                    $rowPct=(float)$ticket->selling_total>0?($rowGross/(float)$ticket->selling_total*100):0;
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><strong>{{ $ticket->passenger?->fullName() }}</strong><div class="muted-copy">{{ $ticket->passenger?->pax_type }}</div></td>
                    <td>{{ $ticket->pnr }}</td>
                    <td><input name="ticket_number[{{ $ticket->id }}]" value="{{ $ticket->ticket_number }}" style="min-width:130px"></td>
                    <td>{{ $ticket->sector ?: ($ticket->origin.'-'.$ticket->destination) }}</td>
                    <td><select name="ticket_status[{{ $ticket->id }}]">@foreach(['BOOKED','ISSUED','VOID','REFUNDED','REISSUED'] as $st)<option {{ $ticket->ticket_status===$st?'selected':'' }}>{{ $st }}</option>@endforeach</select></td>
                    <td class="money">{{ number_format((float)$ticket->selling_total,2) }}</td>
                    <td class="money">{{ number_format((float)$ticket->net_supplier_cost,2) }}</td>
                    <td class="money">{{ number_format($rowGross,2) }} <span class="muted-copy">({{ number_format($rowPct,1) }}%)</span></td>
                    <td>@if($booking->isEditable() && auth()->user()->hasPermission('ticket_commercial.manage'))<a class="btn btn-ghost btn-small" href="{{ route('operations.bookings.show',$booking) }}?edit_ticket={{ $ticket->id }}#ticket-edit-panel-{{ $service->id }}">Edit</a>@else<span class="muted-copy">Operational only</span>@endif</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            <div class="form-actions"><button class="btn btn-ghost btn-small">Save Ticket Updates</button></div>
        </form>

        <div class="batch-summary" style="margin:10px">
            <div><small>Customer Sale</small><strong>{{ $booking->currency_code }} {{ number_format($saleTotal,2) }}</strong></div>
            <div><small>Supplier Cost</small><strong>{{ $booking->currency_code }} {{ number_format($costTotal,2) }}</strong></div>
            <div><small>Gross Margin</small><strong>{{ $booking->currency_code }} {{ number_format($gross,2) }}</strong></div>
            <div><small>Commissions</small><strong>{{ $booking->currency_code }} {{ number_format($agentTotal+$staffTotal,2) }}</strong></div>
            <div><small>Net Contribution</small><strong>{{ $booking->currency_code }} {{ number_format($net,2) }}</strong></div>
        </div>

        @php
            $requestedEditTicketId=(int)(old('_air_ticket_edit_id') ?: request()->query('edit_ticket',0));
            $ticket=$requestedEditTicketId>0 ? $rows->firstWhere('id',$requestedEditTicketId) : null;
        @endphp
        @if($ticket)
        @php
            $ac=$ticket->commissions->firstWhere('commission_type','AGENT');
            $sc=$ticket->commissions->firstWhere('commission_type','SALESPERSON');
            $editFareTotal=(float)$ticket->base_fare+(float)$ticket->airline_taxes;
            $editServiceMarkup=(float)$ticket->customer_service_fee+(float)$ticket->markup;
            $editCustomerDiscount=(float)$ticket->discount;
            $editSupplierCharges=(float)$ticket->supplier_charges+(float)$ticket->supplier_other_charges;
            $editSupplierDiscount=(float)$ticket->supplier_discount+(float)$ticket->supplier_commission;
            $isFailedTicketEdit=(int)old('_air_ticket_edit_id',0)===(int)$ticket->id;
            $ticketOld=fn(string $key,mixed $default=null)=>$isFailedTicketEdit?old($key,$default):$default;
        @endphp
        <div id="ticket-edit-panel-{{ $service->id }}" class="ticket-edit" style="margin:8px 10px">
            <div class="ticket-section-title">
                <span>Edit {{ $ticket->passenger?->fullName() }} · {{ $ticket->ticket_number ?: $ticket->pnr }}</span>
                <a class="btn btn-ghost btn-small" href="{{ route('operations.bookings.show',$booking) }}#saved-passenger-tickets-{{ $service->id }}">Close</a>
            </div>
            <form method="POST" action="{{ route('operations.bookings.air-tickets.update',[$booking,$service,$ticket]) }}" style="margin-top:10px" data-ticket-edit-fare-form {{ $isFailedTicketEdit?'data-failed-ticket-edit=true':'' }}>@csrf @method('PUT')<input type="hidden" name="_air_ticket_edit_id" value="{{ $ticket->id }}">
                <div class="ticket-primary-grid">
                    <div class="ticket-section">
                        <div class="ticket-section-title"><span>1. Common PNR & Flight Details</span><span class="muted-copy">Edit saved ticket details</span></div>
                        <div class="ticket-section-body">
                            <div class="ticket-grid common-flight-grid">
                                <label><span>PNR</span><input name="pnr" value="{{ $ticketOld('pnr',$ticket->pnr) }}" required></label>
                                <label><span>Status</span><select name="ticket_status">@foreach(['BOOKED','ISSUED','VOID','REFUNDED','REISSUED'] as $st)<option {{ $ticketOld('ticket_status',$ticket->ticket_status)===$st?'selected':'' }}>{{ $st }}</option>@endforeach</select></label>
                                <label><span>Airline</span><select name="airline_id" required><option value="">Select airline</option>@foreach($airlines as $a)<option value="{{ $a->id }}" {{ (int)$ticketOld('airline_id',$ticket->airline_id)===(int)$a->id?'selected':'' }}>{{ $a->name }}@if($a->iata_code) · {{ $a->iata_code }}@endif</option>@endforeach</select></label>
                                <label><span>Booking Source / GDS</span><select name="booking_source_id" required><option value="">Select source</option>@foreach($sources as $src)<option value="{{ $src->id }}" {{ (int)$ticketOld('booking_source_id',$ticket->booking_source_id)===(int)$src->id?'selected':'' }}>{{ $src->name }}</option>@endforeach</select></label>
                                <label><span>From</span><input name="origin" value="{{ $ticketOld('origin',$ticket->origin) }}" required></label>
                                <label><span>To</span><input name="destination" value="{{ $ticketOld('destination',$ticket->destination) }}" required></label>
                                <label><span>Sector / Routing</span><input name="sector" value="{{ $ticketOld('sector',$ticket->sector) }}"></label>
                                <label><span>Flight Number</span><input name="flight_number" value="{{ $ticketOld('flight_number',$ticket->flight_number) }}"></label>
                                <label class="date-time-wide"><span>Departure Date & Time</span><input type="datetime-local" name="departure_at" value="{{ $ticketOld('departure_at',$ticket->departure_at?->format('Y-m-d\TH:i')) }}" required></label>
                                <label class="date-time-wide"><span>Arrival Date & Time</span><input type="datetime-local" name="arrival_at" value="{{ $ticketOld('arrival_at',$ticket->arrival_at?->format('Y-m-d\TH:i')) }}"></label>
                                <label><span>Cabin</span><input name="cabin_class" value="{{ $ticketOld('cabin_class',$ticket->cabin_class) }}"></label>
                                <label><span>Baggage</span><input name="baggage" value="{{ $ticketOld('baggage',$ticket->baggage) }}"></label>
                                <label class="fare-basis-wide"><span>Fare Basis</span><input name="fare_basis" value="{{ $ticketOld('fare_basis',$ticket->fare_basis) }}"></label>
                            </div>
                        </div>
                    </div>

                    <div class="ticket-section">
                        <div class="ticket-section-title"><span>2. Ticket Numbers</span><span class="muted-copy"></span></div>
                        <div class="ticket-section-body">
                            <table class="ticket-number-grid">
                                <thead><tr><th>Passenger</th><th class="pax-col">Pax</th><th>Ticket Number</th></tr></thead>
                                <tbody><tr><td><strong>{{ $ticket->passenger?->fullName() }}</strong></td><td>{{ $ticket->passenger?->pax_type }}</td><td><input name="ticket_number" value="{{ $ticketOld('ticket_number',$ticket->ticket_number) }}" placeholder="065-1234567890"></td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="ticket-secondary-grid">
                    <div class="ticket-section">
                        <div class="ticket-section-title"><span>3. Passenger-Type Fare Entry</span><span class="muted-copy">Fare Total + Base Fare are entered; Taxes calculate automatically</span></div>
                        <div class="fare-panel active" style="display:block" data-edit-fare-row>
                            <div class="fare-core-fields">
                                <label><span>Fare Total *</span><input type="number" step="0.01" min="0" name="_edit_fare_total" value="{{ $ticketOld('_edit_fare_total',number_format($editFareTotal,2,'.','')) }}" required data-edit-fare-total></label>
                                <label><span>Base Fare *</span><input type="number" step="0.01" min="0" name="base_fare" value="{{ $ticketOld('base_fare',$ticket->base_fare) }}" required data-edit-base-fare></label>
                                <label class="auto-tax-field"><span>Taxes * <em>Auto</em></span><input type="number" step="0.01" min="0" name="airline_taxes" value="{{ $ticketOld('airline_taxes',$ticket->airline_taxes) }}" required readonly data-edit-airline-taxes></label>
                            </div>

                            <div class="fare-pricing-grid">
                                <div class="fare-card customer-pricing-card">
                                    <h5>Customer Pricing — per {{ $ticket->passenger?->pax_type }} ticket</h5>
                                    <div class="fare-pricing-fields customer-pricing-fields">
                                        <label><span>Service / Markup</span><input type="number" step="0.01" min="0" name="customer_service_fee" value="{{ $ticketOld('customer_service_fee',number_format($editServiceMarkup,2,'.','')) }}" data-edit-service-markup></label>
                                        <input type="hidden" name="markup" value="0">
                                        <label><span>Discount Type</span><select name="_edit_customer_discount_type" data-edit-customer-discount-type><option value="FIXED" {{ $ticketOld('_edit_customer_discount_type','FIXED')==='FIXED'?'selected':'' }}>Fixed Amount</option><option value="PERCENT" {{ $ticketOld('_edit_customer_discount_type','FIXED')==='PERCENT'?'selected':'' }}>Percentage %</option></select></label>
                                        <label><span>Discount Value</span><input type="number" step="0.01" min="0" name="_edit_customer_discount_value" value="{{ $ticketOld('_edit_customer_discount_value',number_format($editCustomerDiscount,2,'.','')) }}" data-edit-customer-discount-value></label>
                                        <input type="hidden" name="discount" value="{{ $ticketOld('discount',number_format($editCustomerDiscount,2,'.','')) }}" data-edit-customer-discount-hidden>
                                    </div>
                                    <div class="pricing-auto-amount"><span>CUSTOMER DISCOUNT AMOUNT · AUTO · BASE FARE ONLY</span><strong data-edit-customer-discount-auto>{{ $booking->currency_code }} {{ number_format($editCustomerDiscount,2) }}</strong></div>
                                    <div class="fare-total"><span>Receivable / Sell per Ticket</span><strong data-edit-sell>{{ $booking->currency_code }} {{ number_format((float)$ticket->selling_total,2) }}</strong></div>
                                </div>

                                <div class="fare-card purchase supplier-pricing-card">
                                    <h5>Supplier Pricing — per {{ $ticket->passenger?->pax_type }} ticket</h5>
                                    <div class="fare-pricing-fields supplier-pricing-fields">
                                        <input type="hidden" name="supplier_base_fare" value="{{ $ticket->base_fare }}" data-edit-supplier-base>
                                        <input type="hidden" name="supplier_taxes" value="{{ $ticket->airline_taxes }}" data-edit-supplier-taxes>
                                        <label><span>Supplier Charges</span><input type="number" step="0.01" min="0" name="supplier_charges" value="{{ $ticketOld('supplier_charges',number_format($editSupplierCharges,2,'.','')) }}" data-edit-supplier-charges></label>
                                        <input type="hidden" name="supplier_other_charges" value="0">
                                        <input type="hidden" name="supplier_commission" value="0">
                                        <label><span>Supplier Discount Type</span><select name="_edit_supplier_discount_type" data-edit-supplier-discount-type><option value="FIXED" {{ $ticketOld('_edit_supplier_discount_type','FIXED')==='FIXED'?'selected':'' }}>Fixed Amount</option><option value="PERCENT" {{ $ticketOld('_edit_supplier_discount_type','FIXED')==='PERCENT'?'selected':'' }}>Percentage %</option></select></label>
                                        <label><span>Supplier Discount Value</span><input type="number" step="0.01" min="0" name="_edit_supplier_discount_value" value="{{ $ticketOld('_edit_supplier_discount_value',number_format($editSupplierDiscount,2,'.','')) }}" data-edit-supplier-discount-value></label>
                                        <input type="hidden" name="supplier_discount" value="{{ $ticketOld('supplier_discount',number_format($editSupplierDiscount,2,'.','')) }}" data-edit-supplier-discount-hidden>
                                        <input type="hidden" name="supplier_incentive" value="0">
                                    </div>
                                    <div class="pricing-auto-amount"><span>SUPPLIER DISCOUNT AMOUNT · AUTO · BASE FARE ONLY</span><strong data-edit-supplier-discount-auto>{{ $booking->currency_code }} {{ number_format($editSupplierDiscount,2) }}</strong></div>
                                    <div class="fare-total"><span>Net Cost / Ticket</span><strong data-edit-cost>{{ $booking->currency_code }} {{ number_format((float)$ticket->net_supplier_cost,2) }}</strong></div>
                                </div>
                            </div>

                            <div class="matrix-live-summary">
                                <div><small>Customer Sell</small><strong data-edit-summary-sell>{{ $booking->currency_code }} {{ number_format((float)$ticket->selling_total,2) }}</strong></div>
                                <div><small>Supplier Cost</small><strong data-edit-summary-cost>{{ $booking->currency_code }} {{ number_format((float)$ticket->net_supplier_cost,2) }}</strong></div>
                                <div><small>Gross Margin</small><strong data-edit-summary-margin>{{ $booking->currency_code }} {{ number_format((float)$ticket->selling_total-(float)$ticket->net_supplier_cost,2) }}</strong></div>
                                <div><small>Agent Commission</small><strong data-edit-summary-agent>{{ $booking->currency_code }} {{ number_format((float)optional($ac)->commission_amount,2) }}</strong></div>
                                <div><small>Sales Commission</small><strong data-edit-summary-sales>{{ $booking->currency_code }} {{ number_format((float)optional($sc)->commission_amount,2) }}</strong></div>
                                <div><small>Net Contribution</small><strong data-edit-summary-net>{{ $booking->currency_code }} {{ number_format(((float)$ticket->selling_total-(float)$ticket->net_supplier_cost)-((float)optional($ac)->commission_amount+(float)optional($sc)->commission_amount),2) }}</strong></div>
                                <div><small>Net Margin %</small><strong data-edit-summary-pct>0.00%</strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="ticket-section commercial-section">
                        <div class="ticket-section-title"><span>4. Commercial Parties & Commissions</span><span class="muted-copy">Vendor identifies supplier cost owner; Agent and Salesperson commissions remain separate</span></div>
                        <div class="ticket-section-body">
                            <div class="commercial-grid">
                                <div class="commercial-card vendor-card">
                                    <h5>Vendor / Supplier</h5>
                                    <div class="commercial-fields">
                                        <label><span>Vendor</span><select name="supplier_vendor_party_id"><option value="">No supplier</option>@foreach($vendors as $v)<option value="{{ $v->id }}" {{ (int)$ticketOld('supplier_vendor_party_id',$ticket->supplier_vendor_party_id)===(int)$v->id?'selected':'' }}>{{ $v->display_name }}</option>@endforeach</select></label>
                                        <label><span>Cost Status</span><select name="supplier_cost_status"><option {{ $ticketOld('supplier_cost_status',$ticket->supplier_cost_status)==='ESTIMATED'?'selected':'' }}>ESTIMATED</option><option {{ $ticketOld('supplier_cost_status',$ticket->supplier_cost_status)==='CONFIRMED'?'selected':'' }}>CONFIRMED</option></select></label>
                                        <label><span>GDS PNR</span><input name="supplier_reference" value="{{ $ticketOld('supplier_reference',$ticket->supplier_reference) }}"></label>
                                    </div>
                                </div>
                                <div class="commercial-card agent-card">
                                    <h5>Agent Commission</h5>
                                    <div class="commercial-fields">
                                        <label><span>Agent</span><select name="agent_party_id"><option value="">Direct / None</option>@foreach($agents as $a)<option value="{{ $a->id }}" {{ (int)$ticketOld('agent_party_id',optional($ac)->agent_party_id)===(int)$a->id?'selected':'' }}>{{ $a->display_name }}</option>@endforeach</select></label>
                                        <label><span>Basis</span><select name="agent_commission_basis">@foreach(['FIXED'=>'Fixed Amount','PER_TICKET'=>'Per Ticket','PERCENT_OF_SALE'=>'% of Sale','PERCENT_OF_BASE_FARE'=>'% of Base Fare','PERCENT_OF_GROSS_MARGIN'=>'% of Gross Margin','MANUAL'=>'Manual'] as $b=>$label)<option value="{{ $b }}" {{ $ticketOld('agent_commission_basis',optional($ac)->basis)===$b?'selected':'' }}>{{ $label }}</option>@endforeach</select></label>
                                        <label><span>Rate / Amount</span><input type="number" step="0.0001" min="0" name="agent_commission_rate" value="{{ $ticketOld('agent_commission_rate',optional($ac)->rate ?? 0) }}"></label>
                                    </div>
                                </div>
                                <div class="commercial-card salesperson-card">
                                    <h5>Salesperson Commission</h5>
                                    <div class="commercial-fields">
                                        <label><span>Salesperson</span><select name="salesperson_staff_id"><option value="">None / Booking default</option>@foreach($staff as $st)<option value="{{ $st->id }}" {{ (int)$ticketOld('salesperson_staff_id',optional($sc)->salesperson_staff_id)===(int)$st->id?'selected':'' }}>{{ $st->employee_code }} · {{ $st->name }}</option>@endforeach</select></label>
                                        <label><span>Basis</span><select name="salesperson_commission_basis">@foreach(['FIXED'=>'Fixed Amount','PER_TICKET'=>'Per Ticket','PERCENT_OF_SALE'=>'% of Sale','PERCENT_OF_BASE_FARE'=>'% of Base Fare','PERCENT_OF_GROSS_MARGIN'=>'% of Gross Margin','MANUAL'=>'Manual'] as $b=>$label)<option value="{{ $b }}" {{ $ticketOld('salesperson_commission_basis',optional($sc)->basis)===$b?'selected':'' }}>{{ $label }}</option>@endforeach</select></label>
                                        <label><span>Rate / Amount</span><input type="number" step="0.0001" min="0" name="salesperson_commission_rate" value="{{ $ticketOld('salesperson_commission_rate',optional($sc)->rate ?? 0) }}"></label>
                                    </div>
                                </div>
                            </div>
                            <label style="margin-top:8px"><span>Notes <small class="muted-copy">(optional)</small></span><textarea name="notes" rows="2">{{ $ticketOld('notes',$ticket->notes) }}</textarea></label>
                        </div>
                    </div>
                </div>

                <div class="form-actions"><button class="btn btn-primary btn-small">Save Ticket</button></div>
            </form>
        </div>
        @endif
        @else
        <div class="empty-state"><strong>No passenger tickets saved yet.</strong><p>Select passengers, enter common flight and ticket details, then Save Passenger Tickets.</p></div>
        @endif
    </div>
</div>

<script>
(function(){
    const currency='{{ $booking->currency_code }}';
    const failedEdit=document.querySelector('[data-failed-ticket-edit=true]');
    if(failedEdit){requestAnimationFrame(()=>failedEdit.closest('.ticket-edit')?.scrollIntoView({behavior:'smooth',block:'center'}));}
    function fmt(v){return currency+' '+new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(v||0)}
    document.querySelectorAll('[data-ticket-batch-form]').forEach(form=>{
        if(form.querySelector('input[name="_air_ticket_batch_service_id"]') && {{ $hasOldBatchInput ? 'true' : 'false' }}){
            requestAnimationFrame(()=>form.scrollIntoView({behavior:'smooth',block:'start'}));
        }
        const tabs=[...form.querySelectorAll('[data-fare-tab]')];
        const activeFareTab=form.querySelector('[data-active-fare-tab]');
        tabs.forEach(btn=>btn.addEventListener('click',()=>{
            tabs.forEach(x=>x.classList.toggle('active',x===btn));
            form.querySelectorAll('[data-fare-panel]').forEach(p=>p.classList.toggle('active',p.dataset.farePanel===btn.dataset.fareTab));
            if(activeFareTab)activeFareTab.value=btn.dataset.fareTab;
        }));

        const departureInput=form.querySelector('input[name="departure_at"]');
        const arrivalInput=form.querySelector('input[name="arrival_at"]');
        const draftUrl=form.dataset.ticketDraftUrl||'';
        let draftTimer=null;
        let draftPromise=null;
        let submitAfterDraft=false;
        function validateFlightTimes(){
            if(!departureInput||!arrivalInput)return true;
            arrivalInput.setCustomValidity('');
            if(departureInput.value&&arrivalInput.value&&arrivalInput.value<departureInput.value){
                arrivalInput.setCustomValidity('Arrival Date & Time must be after or equal to Departure Date & Time.');
                return false;
            }
            return true;
        }
        departureInput?.addEventListener('input',validateFlightTimes);
        arrivalInput?.addEventListener('input',validateFlightTimes);

        function incrementTicketNumber(value){
            const match=String(value||'').trim().match(/^(.*?)(\d+)(\D*)$/);
            if(!match)return '';
            try{
                const next=(BigInt(match[2])+1n).toString().padStart(match[2].length,'0');
                return match[1]+next+match[3];
            }catch(e){
                const numeric=Number(match[2]);
                if(!Number.isFinite(numeric))return '';
                return match[1]+String(numeric+1).padStart(match[2].length,'0')+match[3];
            }
        }

        function fillFollowingTicketNumbers(sourceInput){
            const inputs=[...form.querySelectorAll('[data-ticket-number-input]')];
            const start=inputs.indexOf(sourceInput);
            if(start<0)return;
            let previous=String(sourceInput.value||'').trim();
            if(!previous)return;

            for(let i=start+1;i<inputs.length;i++){
                const input=inputs[i];
                const row=input.closest('[data-ticket-passenger]');
                const checkbox=row?.querySelector('input[name="passenger_ids[]"]');
                if(!checkbox?.checked)continue;

                const existing=String(input.value||'').trim();
                if(existing){
                    previous=existing;
                    continue;
                }

                const next=incrementTicketNumber(previous);
                if(!next)break;
                input.value=next;
                previous=next;
            }
        }

        form.querySelectorAll('[data-ticket-number-input]').forEach(input=>{
            input.addEventListener('change',()=>{
                fillFollowingTicketNumbers(input);
                refresh();
                scheduleDraftSave();
            });
        });

        function val(row,selector){return Number(row.querySelector(selector)?.value||0)}
        function discountAmount(type,value,base){return type==='PERCENT' ? Math.max(0,base*value/100) : Math.max(0,value)}
        function syncAutoTax(row){
            const totalInput=row.querySelector('[data-fare-field="fare_total"]');
            const baseInput=row.querySelector('[data-fare-field="base_fare"]');
            const taxInput=row.querySelector('[data-fare-field="airline_taxes"]');
            if(!totalInput||!baseInput||!taxInput)return true;
            const total=Number(totalInput.value||0);
            const base=Number(baseInput.value||0);
            totalInput.setCustomValidity('');
            if(total<base){
                taxInput.value='0.00';
                totalInput.setCustomValidity('Fare Total must be greater than or equal to Base Fare.');
                return false;
            }
            const taxes=total-base;
            taxInput.value=taxes.toFixed(2);
            const supplierBase=row.querySelector('[data-purchase-field="supplier_base_fare"]');
            const supplierTaxes=row.querySelector('[data-purchase-field="supplier_taxes"]');
            if(supplierBase)supplierBase.value=base.toFixed(2);
            if(supplierTaxes)supplierTaxes.value=taxes.toFixed(2);
            return true;
        }
        function calcRow(row){
            syncAutoTax(row);
            const total=val(row,'[data-fare-field="fare_total"]');
            const base=val(row,'[data-fare-field="base_fare"]');
            const customerDiscountType=row.querySelector('[data-customer-discount-type]')?.value||'FIXED';
            const customerDiscountValue=val(row,'[data-customer-discount-value]');
            const customerDiscount=discountAmount(customerDiscountType,customerDiscountValue,base);
            const customerDiscountHidden=row.querySelector('[data-fare-field="discount"]');
            if(customerDiscountHidden)customerDiscountHidden.value=customerDiscount.toFixed(2);
            const customerDiscountAuto=row.querySelector('[data-customer-discount-auto]');
            if(customerDiscountAuto)customerDiscountAuto.textContent=fmt(customerDiscount);

            const supplierDiscountType=row.querySelector('[data-supplier-discount-type]')?.value||'FIXED';
            const supplierDiscountValue=val(row,'[data-supplier-discount-value]');
            const supplierDiscount=discountAmount(supplierDiscountType,supplierDiscountValue,base);
            const supplierDiscountHidden=row.querySelector('[data-purchase-field="supplier_discount"]');
            if(supplierDiscountHidden)supplierDiscountHidden.value=supplierDiscount.toFixed(2);
            const supplierDiscountAuto=row.querySelector('[data-supplier-discount-auto]');
            if(supplierDiscountAuto)supplierDiscountAuto.textContent=fmt(supplierDiscount);

            const sell=total+val(row,'[data-fare-field="customer_service_fee"]')+val(row,'[data-fare-field="markup"]')-customerDiscount;
            const cost=total+val(row,'[data-purchase-field="supplier_charges"]')+val(row,'[data-purchase-field="supplier_other_charges"]')-val(row,'[data-purchase-field="supplier_commission"]')-supplierDiscount;
            row.querySelector('[data-pax-sell]').textContent=fmt(Math.max(0,sell));
            row.querySelector('[data-pax-cost]').textContent=fmt(Math.max(0,cost));
            return {sell:Math.max(0,sell),cost:Math.max(0,cost),base};
        }
        function commissionAmount(prefix,selectedCount,sale,baseTotal,gross){
            const basis=form.querySelector('[name="'+prefix+'_commission_basis"]')?.value||'FIXED';
            const rate=Number(form.querySelector('[name="'+prefix+'_commission_rate"]')?.value||0);
            if(rate<=0)return 0;
            if(basis==='PERCENT_OF_SALE')return Math.max(0,sale)*rate/100;
            if(basis==='PERCENT_OF_BASE_FARE')return Math.max(0,baseTotal)*rate/100;
            if(basis==='PERCENT_OF_GROSS_MARGIN')return Math.max(0,gross)*rate/100;
            if(basis==='PER_TICKET')return Math.max(0,selectedCount)*rate;
            return rate;
        }
        function setAll(selector,text){form.querySelectorAll(selector).forEach(el=>el.textContent=text)}
        function refresh(){
            const fares={};
            form.querySelectorAll('[data-fare-row]').forEach(r=>fares[r.dataset.fareRow]=calcRow(r));

            const selected=[...form.querySelectorAll('input[name="passenger_ids[]"]:checked')];
            let sale=0,cost=0,baseTotal=0;
            selected.forEach(cb=>{
                const r=fares[cb.dataset.paxType];
                if(r){sale+=r.sell;cost+=r.cost;baseTotal+=r.base}
            });
            const gross=sale-cost;
            const agentCommission=commissionAmount('agent',selected.length,sale,baseTotal,gross);
            const salesCommission=commissionAmount('salesperson',selected.length,sale,baseTotal,gross);
            const commissions=agentCommission+salesCommission;
            const net=gross-commissions;
            const netMarginPct=sale>0?(net/sale*100):0;

            form.querySelector('[data-selected-count]').textContent=selected.length;
            form.querySelector('[data-batch-sale]').textContent=fmt(sale);
            form.querySelector('[data-batch-cost]').textContent=fmt(cost);
            form.querySelector('[data-batch-margin]').textContent=fmt(gross);
            form.querySelector('[data-batch-commission]').textContent=fmt(commissions);
            form.querySelector('[data-batch-net]').textContent=fmt(net);
            form.querySelector('[data-batch-net-margin-pct]').textContent=netMarginPct.toFixed(2)+'%';

            setAll('[data-matrix-customer-sale]',fmt(sale));
            setAll('[data-matrix-supplier-cost]',fmt(cost));
            setAll('[data-matrix-gross-margin]',fmt(gross));
            setAll('[data-matrix-agent-commission]',fmt(agentCommission));
            setAll('[data-matrix-sales-commission]',fmt(salesCommission));
            setAll('[data-matrix-net-contribution]',fmt(net));
            setAll('[data-matrix-net-margin-pct]',netMarginPct.toFixed(2)+'%');

            const selectedIds=new Set(selected.map(x=>x.value));
            form.querySelectorAll('[data-ticket-passenger]').forEach(tr=>tr.classList.toggle('is-muted',!selectedIds.has(tr.dataset.ticketPassenger)));

            const numbers=[];
            selected.forEach(cb=>{
                const input=form.querySelector('[data-ticket-number-input][data-passenger-id="'+cb.value+'"]');
                numbers.push((input?.value||'').trim());
            });
            form.querySelector('[data-ticket-numbers-hidden]').value=numbers.join('\n');
        }

        function saveDraft(){
            if(!draftUrl)return Promise.resolve();
            if(draftPromise)return draftPromise;
            refresh();
            const data=new FormData(form);
            data.set('_autosave','1');
            draftPromise=fetch(draftUrl,{
                method:'POST',
                body:data,
                credentials:'same-origin',
                headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
                keepalive:true
            }).catch(()=>{
                // Draft save is protective only; do not interrupt ticket entry.
            }).finally(()=>{
                draftPromise=null;
            });
            return draftPromise;
        }

        function scheduleDraftSave(){
            if(!draftUrl)return;
            clearTimeout(draftTimer);
            draftTimer=setTimeout(()=>saveDraft(),700);
        }

        form.addEventListener('input',()=>{
            refresh();
            scheduleDraftSave();
        });
        form.addEventListener('change',()=>{
            refresh();
            scheduleDraftSave();
        });
        function normalizeOptionalPricing(){
            form.querySelectorAll('[data-optional-pricing-number]').forEach(input=>{
                if(String(input.value??'').trim()==='')input.value='0';
            });
            form.querySelectorAll('[data-customer-discount-type],[data-supplier-discount-type]').forEach(select=>{
                if(!select.value)select.value='FIXED';
            });
        }
        form.addEventListener('submit',async event=>{
            if(submitAfterDraft)return;

            normalizeOptionalPricing();
            refresh();

            const invalidFareRow=[...form.querySelectorAll('[data-fare-row]')].find(row=>!syncAutoTax(row));
            if(invalidFareRow){
                event.preventDefault();
                const totalInput=invalidFareRow.querySelector('[data-fare-field="fare_total"]');
                const tab=form.querySelector('[data-fare-tab="'+invalidFareRow.dataset.fareRow+'"]');
                tab?.click();
                totalInput?.reportValidity();
                totalInput?.focus();
                scheduleDraftSave();
                return;
            }

            if(!validateFlightTimes()){
                event.preventDefault();
                arrivalInput?.reportValidity();
                arrivalInput?.focus();
                scheduleDraftSave();
                return;
            }

            // Persist the exact working form to DB before the final save request.
            // If server validation rejects anything, this draft is restored on reload.
            if(draftUrl){
                event.preventDefault();
                clearTimeout(draftTimer);
                await saveDraft();
                submitAfterDraft=true;
                HTMLFormElement.prototype.submit.call(form);
            }
        });

        form.querySelectorAll('[data-copy-adult]').forEach(btn=>btn.addEventListener('click',()=>{
            const target=form.querySelector('[data-fare-row="'+btn.dataset.copyAdult+'"]');
            const adult=form.querySelector('[data-fare-row="ADULT"]');
            if(!target||!adult)return;
            adult.querySelectorAll('[data-fare-field]').forEach(src=>{const dst=target.querySelector('[data-fare-field="'+src.dataset.fareField+'"]');if(dst)dst.value=src.value});
            adult.querySelectorAll('[data-purchase-field]').forEach(src=>{const dst=target.querySelector('[data-purchase-field="'+src.dataset.purchaseField+'"]');if(dst)dst.value=src.value});
            adult.querySelectorAll('[data-pricing-ui]').forEach(src=>{const dst=target.querySelector('[data-pricing-ui="'+src.dataset.pricingUi+'"]');if(dst)dst.value=src.value});
            refresh();
        }));

        refresh();
    });

    document.querySelectorAll('[data-add-more-tickets]').forEach(button=>button.addEventListener('click',()=>{
        const workspace=document.querySelector('[data-ticket-entry-workspace]');
        if(!workspace)return;
        workspace.hidden=false;
        requestAnimationFrame(()=>workspace.scrollIntoView({behavior:'smooth',block:'start'}));
    }));

    document.querySelectorAll('[data-ticket-edit-fare-form]').forEach(form=>{
        const totalInput=form.querySelector('[data-edit-fare-total]');
        const baseInput=form.querySelector('[data-edit-base-fare]');
        const taxInput=form.querySelector('[data-edit-airline-taxes]');
        const supplierBase=form.querySelector('[data-edit-supplier-base]');
        const supplierTaxes=form.querySelector('[data-edit-supplier-taxes]');
        const departure=form.querySelector('input[name="departure_at"]');
        const arrival=form.querySelector('input[name="arrival_at"]');
        const customerType=form.querySelector('[data-edit-customer-discount-type]');
        const customerValue=form.querySelector('[data-edit-customer-discount-value]');
        const customerHidden=form.querySelector('[data-edit-customer-discount-hidden]');
        const supplierType=form.querySelector('[data-edit-supplier-discount-type]');
        const supplierValue=form.querySelector('[data-edit-supplier-discount-value]');
        const supplierHidden=form.querySelector('[data-edit-supplier-discount-hidden]');
        const serviceMarkup=form.querySelector('[data-edit-service-markup]');
        const supplierCharges=form.querySelector('[data-edit-supplier-charges]');

        function editDiscount(type,value,base){
            return type==='PERCENT' ? Math.max(0,base*value/100) : Math.max(0,value);
        }
        function syncEdit(){
            if(!totalInput||!baseInput||!taxInput)return true;
            const total=Number(totalInput.value||0);
            const base=Number(baseInput.value||0);
            totalInput.setCustomValidity('');
            if(total<base){
                taxInput.value='0.00';
                totalInput.setCustomValidity('Fare Total must be greater than or equal to Base Fare.');
                return false;
            }
            const taxes=total-base;
            taxInput.value=taxes.toFixed(2);
            if(supplierBase)supplierBase.value=base.toFixed(2);
            if(supplierTaxes)supplierTaxes.value=taxes.toFixed(2);

            const customerDiscount=editDiscount(customerType?.value||'FIXED',Number(customerValue?.value||0),base);
            const supplierDiscount=editDiscount(supplierType?.value||'FIXED',Number(supplierValue?.value||0),base);
            if(customerHidden)customerHidden.value=customerDiscount.toFixed(2);
            if(supplierHidden)supplierHidden.value=supplierDiscount.toFixed(2);
            const customerAuto=form.querySelector('[data-edit-customer-discount-auto]');
            const supplierAuto=form.querySelector('[data-edit-supplier-discount-auto]');
            if(customerAuto)customerAuto.textContent=fmt(customerDiscount);
            if(supplierAuto)supplierAuto.textContent=fmt(supplierDiscount);

            const sell=Math.max(0,total+Number(serviceMarkup?.value||0)-customerDiscount);
            const cost=Math.max(0,total+Number(supplierCharges?.value||0)-supplierDiscount);
            const margin=sell-cost;
            function editCommission(prefix){
                const basis=form.querySelector('[name="'+prefix+'_commission_basis"]')?.value||'FIXED';
                const rate=Number(form.querySelector('[name="'+prefix+'_commission_rate"]')?.value||0);
                if(rate<=0)return 0;
                if(basis==='PERCENT_OF_SALE')return Math.max(0,sell)*rate/100;
                if(basis==='PERCENT_OF_BASE_FARE')return Math.max(0,base)*rate/100;
                if(basis==='PERCENT_OF_GROSS_MARGIN')return Math.max(0,margin)*rate/100;
                if(basis==='PER_TICKET')return rate;
                return rate;
            }
            const agentAmount=editCommission('agent');
            const saleAmount=editCommission('salesperson');
            const net=margin-agentAmount-saleAmount;
            const pct=sell>0?net/sell*100:0;
            form.querySelector('[data-edit-sell]')?.replaceChildren(document.createTextNode(fmt(sell)));
            form.querySelector('[data-edit-cost]')?.replaceChildren(document.createTextNode(fmt(cost)));
            form.querySelector('[data-edit-summary-sell]')?.replaceChildren(document.createTextNode(fmt(sell)));
            form.querySelector('[data-edit-summary-cost]')?.replaceChildren(document.createTextNode(fmt(cost)));
            form.querySelector('[data-edit-summary-margin]')?.replaceChildren(document.createTextNode(fmt(margin)));
            form.querySelector('[data-edit-summary-agent]')?.replaceChildren(document.createTextNode(fmt(agentAmount)));
            form.querySelector('[data-edit-summary-sales]')?.replaceChildren(document.createTextNode(fmt(saleAmount)));
            form.querySelector('[data-edit-summary-net]')?.replaceChildren(document.createTextNode(fmt(net)));
            form.querySelector('[data-edit-summary-pct]')?.replaceChildren(document.createTextNode(pct.toFixed(2)+'%'));
            return true;
        }
        function validateEditTimes(){
            if(!departure||!arrival)return true;
            arrival.setCustomValidity('');
            if(departure.value&&arrival.value&&arrival.value<departure.value){
                arrival.setCustomValidity('Arrival Date & Time must be after or equal to Departure Date & Time.');
                return false;
            }
            return true;
        }
        form.addEventListener('input',()=>syncEdit());
        form.addEventListener('change',()=>syncEdit());
        departure?.addEventListener('input',validateEditTimes);
        arrival?.addEventListener('input',validateEditTimes);
        form.addEventListener('submit',event=>{
            if(!syncEdit()){
                event.preventDefault();
                totalInput?.reportValidity();
                totalInput?.focus();
                return;
            }
            if(!validateEditTimes()){
                event.preventDefault();
                arrival?.reportValidity();
                arrival?.focus();
            }
        });
        syncEdit();
    });
})();
</script>
