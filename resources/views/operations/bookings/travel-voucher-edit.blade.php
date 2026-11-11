@extends('layouts.app')
@section('title','Client Voucher · '.$booking->booking_no)
@section('page_title','Client Voucher')
@section('content')
@php
    $clientVoucherIssueRouteReady=\Illuminate\Support\Facades\Route::has('operations.bookings.travel-voucher.issue');
    $clientVoucherVoidRouteReady=\Illuminate\Support\Facades\Route::has('operations.bookings.travel-voucher.void');
@endphp
<style>
.tv-admin{max-width:1450px;margin:0 auto}.tv-hero{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:12px}.tv-hero h1{margin:0;font-size:25px}.tv-hero p{margin:4px 0 0;color:var(--muted);font-size:10px}.tv-actions{display:flex;gap:8px;flex-wrap:wrap}.tv-actions form{margin:0}.tv-state{display:inline-flex;align-items:center;border-radius:999px;padding:7px 10px;font-size:8px;font-weight:900;background:#eef3f8;color:#496078}.tv-state.issued{background:#e8f8ef;color:#16724c}.tv-state.voided{background:#fdecec;color:#a2242d}
.tv-grid-top{display:grid;grid-template-columns:1.2fr .85fr .75fr;gap:12px}.tv-card{background:#fff;border:1px solid var(--line);border-radius:13px;padding:13px;margin-bottom:12px}.tv-card h3{margin:0 0 3px;font-size:14px}.tv-card .sub{color:var(--muted);font-size:8px;margin-bottom:9px}
.tv-identity{display:grid;grid-template-columns:1fr 1fr;gap:7px}.tv-auto{border:1px solid #dce5ef;background:#f6f9fc;border-radius:8px;padding:8px}.tv-auto small{display:block;color:var(--muted);font-size:7px;text-transform:uppercase;font-weight:900}.tv-auto strong{display:block;margin-top:3px;font-size:10px}
.tv-field span{display:block;font-weight:800;font-size:8px;margin-bottom:3px}.tv-field input,.tv-field select,.tv-field textarea{width:100%;border:1px solid var(--line);border-radius:7px;padding:7px 8px;background:#fff;color:var(--text);font-size:10px}
.tv-wide{grid-column:1/-1}.tv-note{background:#eef9f3;border:1px solid #cde9da;border-radius:8px;padding:8px;color:#246243;font-size:8px}.tv-detected{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.tv-detected>div{border:1px solid var(--line);border-radius:8px;padding:8px}.tv-detected small{display:block;font-size:7px;color:var(--muted);text-transform:uppercase;font-weight:900}.tv-detected strong{display:block;margin-top:3px;font-size:10px}
.logic-box{border:1px solid #dce5ef;background:#f7f9fc;border-radius:8px;padding:9px;font-size:8px;color:#42566f;line-height:1.55}
.itinerary-card{background:#fff;border:1px solid var(--line);border-radius:13px;padding:13px;margin-top:12px}.itinerary-card h3{margin:0 0 3px;font-size:14px}
.zero{background:#eef9f3;border:1px solid #cde9da;border-radius:8px;padding:8px 10px;color:#246243;font-size:8px;margin:8px 0}
.tv-tabs{display:flex;gap:7px;margin:9px 0}.tv-tab{border:1px solid #d9e2ed;border-radius:7px;padding:7px 12px;font-size:8px;font-weight:900;background:#fff;color:#32506f;cursor:pointer}.tv-tab.active{background:#1769d2;color:#fff;border-color:#1769d2}
.tv-section{border:1px solid #dfe6ee;border-radius:10px;padding:11px;margin-top:8px}.tv-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:8px}.tv-section-head h4{margin:0;font-size:12px}.tv-section-head p{margin:2px 0 0;color:var(--muted);font-size:8px}
.flight-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px}.flight-grid .span2{grid-column:span 2}.flight-grid .span4{grid-column:1/-1}
.transport-grid{display:grid;grid-template-columns:1.25fr auto auto 1fr 1fr;gap:7px;align-items:end}.transport-grid.second{grid-template-columns:1fr 1fr 1fr 1fr;align-items:end}.transport-grid .wide{grid-column:1/-1}
.master-actions{display:flex;gap:5px;align-items:end}.master-actions .btn{white-space:nowrap}
.saved-table{width:100%;border-collapse:collapse;margin-top:9px;font-size:8px}.saved-table th,.saved-table td{border:1px solid #e1e7ee;padding:6px;text-align:left}.saved-table th{background:#f4f7fa;text-transform:uppercase;font-size:7px;color:#4c5e74}.saved-table .right{text-align:right}.saved-table form{display:inline}
.inline-master{display:none;margin:8px 0;border:1px dashed #b9c9da;background:#f8fafc;border-radius:8px;padding:9px}.inline-master.show{display:block}.inline-master-grid{display:grid;grid-template-columns:1.3fr 1fr 1.5fr auto;gap:7px;align-items:end}
.simple-grid{display:grid;grid-template-columns:180px 1fr;gap:7px;align-items:start}.simple-grid textarea{min-height:90px}
.bottom-save{display:flex;justify-content:flex-end;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid #e3e8ef}
@media(max-width:1100px){.tv-grid-top{grid-template-columns:1fr}.flight-grid{grid-template-columns:1fr 1fr}.transport-grid,.transport-grid.second{grid-template-columns:1fr 1fr}.inline-master-grid{grid-template-columns:1fr 1fr}.tv-detected{grid-template-columns:repeat(4,1fr)}}
@media(max-width:700px){.flight-grid,.transport-grid,.transport-grid.second,.tv-identity,.simple-grid,.inline-master-grid{grid-template-columns:1fr}.flight-grid .span2,.flight-grid .span4,.transport-grid .wide,.tv-wide{grid-column:auto}.tv-detected{grid-template-columns:1fr 1fr}}
</style>

@php
    $voucherRouteParams=['booking'=>$booking];
    if((int)$requestedHotelService>0){
        $voucherRouteParams['hotel_service_id']=(int)$requestedHotelService;
    }
@endphp

<div class="tv-admin">
    <div class="tv-hero">
        <div>
            <h1>{{ $documentTitle }}</h1>
            <p>{{ $booking->booking_no }} · Dynamic client travel document. Only purchased ERP services and explicit Voucher-Only information are printed.</p>
        </div>
        <div class="tv-actions">
            <a class="btn btn-secondary" href="{{ route('operations.bookings.show',$booking) }}">← Back to Booking</a>
            <a class="btn btn-primary" target="_blank" href="{{ route('operations.bookings.travel-voucher.show',$voucherRouteParams) }}">Preview Voucher</a>
            @if($issueControlsInstalled && $clientVoucherIssueRouteReady && $isFinal && auth()->user()->hasPermission('service_details.manage'))
                <form method="POST" action="{{ route('operations.bookings.travel-voucher.issue',$booking) }}" target="_blank" onsubmit="return confirm('{{ $isIssued ? 'Reissue this approved voucher now? The current QR token will be superseded.' : 'Issue this voucher now?' }}')">
                    @csrf
                    <input type="hidden" name="confirm_reissue" value="1">
                    <input type="hidden" name="expected_revision" value="{{ (int)$issueRevision }}">
                    <input name="issue_reason" required minlength="3" maxlength="1000" placeholder="Mandatory reason" aria-label="Voucher issue reason">
                    <button class="btn btn-success">{{ $isIssued ? 'Reissue Voucher' : ($isVoided ? 'Reissue Voucher' : 'Generate Client Voucher') }}</button>
                </form>
            @endif
            @if($isIssued)
                <button type="button" class="btn btn-secondary" data-copy-public-link data-url="{{ $publicVoucherUrl }}">Copy Public Link</button>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="callout" style="margin-bottom:11px">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="callout warn" style="margin-bottom:11px">{{ $errors->first() }}</div>
    @endif
    @if(!$issueControlsInstalled)
        <div class="callout warn" style="margin-bottom:11px"><strong>Client Voucher issue controls need the ERP10.27.0 database upgrade.</strong> Preview/manage remains available, but Generate / Reissue / Void and secure public links activate after System → Health & Updates → Safe Database Upgrade.</div>
    @endif
    @if($issueControlsInstalled && !$clientVoucherIssueRouteReady)
        <div class="callout warn" style="margin-bottom:11px"><strong>Voucher route cache is stale.</strong> Run System → Health & Updates → Clear Application Cache once. This page stays usable; Generate / Reissue / Void activate after the route cache refresh.</div>
    @endif

    <form method="POST" action="{{ route('operations.bookings.travel-voucher.update',$voucherRouteParams) }}">
        @csrf
        @method('PUT')
        <div class="tv-grid-top">
            <div class="tv-card">
                <h3>Voucher Identity & Parties</h3>
                <div class="sub">Auto number is system controlled. Manual / External Ref is optional.</div>
                <div class="tv-identity">
                    <div class="tv-auto"><small>Document Type</small><strong>{{ $documentTitle }}</strong></div>
                    <div class="tv-auto"><small>Automatic Voucher No.</small><strong>{{ $voucher->voucher_no }}</strong></div>
                    <div class="tv-auto"><small>Issue Status</small><strong>{{ $isVoided ? 'VOIDED' : ($isIssued ? 'ISSUED' : ($isFinal ? 'READY TO ISSUE' : 'DRAFT')) }}@if($issueRevision>1) · REV {{ $issueRevision }}@endif</strong></div>
                    <label class="tv-field"><span>Manual / External Ref No.</span><input name="manual_reference" value="{{ old('manual_reference',$voucher->manual_reference) }}" placeholder="Optional external / supplier / group ref"></label>
                    <div class="tv-auto"><small>Accounting Effect</small><strong>Voucher document only</strong></div>

                    @if($documentType==='UMRAH')
                        @if($resolvedVisaServiceOperator)
                            <div class="tv-auto"><small>Saudi Visa Company</small><strong>{{ $resolvedSaudiVisaCompany?->name ?: '—' }}</strong></div>
                            <div class="tv-auto"><small>Pakistani IATA / Umrah Operator</small><strong>{{ $resolvedPakistanIataOperator?->name ?: '—' }}</strong></div>
                            <div class="tv-wide tv-note">Visa operator pair is controlled by the saved Visa service. Change it from the Visa service screen; all passengers and this voucher stay on one operator pair.</div>
                        @else
                            <label class="tv-field">
                                <span>Saudi Visa Company</span>
                                <select name="saudi_visa_company_id" data-voucher-saudi-select>
                                    <option value="">Select from DB</option>
                                    @foreach($saudiCompanies as $partner)
                                        @php $linkedIata=$partner->linkedPakistanIataOperator; @endphp
                                        <option value="{{ $partner->id }}" data-iata-name="{{ $linkedIata ? (($linkedIata->iata_number ?: $linkedIata->code).' · '.$linkedIata->name) : '' }}" data-linked="{{ $linkedIata?'1':'0' }}" {{ (int)old('saudi_visa_company_id',$resolvedSaudiVisaCompany?->id)===(int)$partner->id?'selected':'' }}>{{ $partner->name }}{{ $partner->registration_no?' · '.$partner->registration_no:'' }}{{ !$linkedIata?' · NOT LINKED':'' }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="tv-field">
                                <span>Pakistani IATA / Umrah Operator</span>
                                <input readonly value="{{ $resolvedPakistanIataOperator ? (($resolvedPakistanIataOperator->iata_number ?: $resolvedPakistanIataOperator->code).' · '.$resolvedPakistanIataOperator->name) : 'Auto from Saudi Visa Company' }}" data-voucher-iata-display>
                            </label>
                            <div class="tv-wide tv-note">Pakistan IATA is linked in Master Data → Travel Masters → Saudi Visa Companies and cannot be selected independently.</div>
                        @endif
                    @else
                        <div class="tv-wide tv-note">Saudi Visa Company and Pakistani IATA / Umrah Operator stay hidden for non-Umrah bookings.</div>
                    @endif

                    <label class="tv-field tv-wide"><span>Special Instructions</span><textarea rows="3" name="special_instructions" placeholder="Client-facing travel instructions only">{{ old('special_instructions',$voucher->special_instructions) }}</textarea></label>
                    <label class="tv-field tv-wide">
                        <span>Voucher-Specific Footer Override (HTML)</span>
                        <textarea rows="3" name="footer_html" placeholder="Example: <strong>Easy Group Of Travels</strong><br>03106220038 | 03106220041 | 03106220046">{{ old('footer_html',$voucher->footer_html) }}</textarea>
                        <small style="display:block;margin-top:4px;color:var(--muted);font-size:7px">Highest priority for this voucher. If blank, ERP uses the selected Pakistan Visa / IATA footer; if that is blank, it uses the Company Profile default footer. Safe tags: &lt;br&gt;, &lt;p&gt;, &lt;div&gt;, &lt;strong&gt;, &lt;b&gt;, &lt;em&gt;, &lt;i&gt;, &lt;u&gt;.</small>
                    </label>

                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="btn btn-success">Save Voucher Settings</button></div>
            </div>

            <div class="tv-card">
                <h3>Detected Purchased Services</h3>
                <div class="sub">Real ERP booking data keeps its normal commercial/accounting workflow.</div>
                <div class="tv-detected">
                    <div><small>Passengers</small><strong>{{ $activePassengers->count() }}</strong></div>
                    <div><small>Hotel Stays</small><strong>{{ $hotelDetails->count() }}</strong></div>
                    <div><small>Flight Sectors</small><strong>{{ $flights->count() }}</strong></div>
                    <div><small>Transport</small><strong>{{ $transport->count() }}</strong></div>
                    <div><small>Visa Records</small><strong>{{ $visas->count() }}</strong></div>
                    <div><small>Umrah Package</small><strong>{{ $umrahServices->count() }}</strong></div>
                    <div><small>Voucher Only</small><strong>{{ $voucherOnly->count() }}</strong></div>
                    <div><small>Booking Status</small><strong>{{ str_replace('_',' ',$booking->status) }}</strong></div>
                </div>
            </div>

            <div class="tv-card">
                <h3>Section Logic</h3>
                <div class="sub">Nothing unrelated is printed.</div>
                <div class="logic-box">
                    <strong>Hotel Only:</strong> Accommodation Voucher + Auto Voucher No. + optional Manual Ref + passengers + accommodation.<br><br>
                    <strong>Full Umrah:</strong> Umrah Travel Voucher + Visa Company + Pakistani IATA / Umrah Operator + accommodation + purchased flight / visa / transport / package sections.<br><br>
                    <strong>Transport / Visa / Air-only:</strong> the same Client Voucher engine prints only the purchased service sections that apply to this booking.<br><br>
                    <strong>External itinerary:</strong> voucher-only data appears on the client document with zero accounting impact.
                </div>
            </div>
        </div>
    </form>

    @if($issueControlsInstalled && ($isIssued || $isVoided) && auth()->user()->hasPermission('service_details.manage'))
    <div class="tv-card">
        <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">
            <div>
                <h3>Issued Client Voucher</h3>
                <div class="sub">Public QR/link is read-only. No invoice, supplier cost, gross margin, ledger or editable ERP data is exposed.</div>
                @if($isIssued)
                    <span class="tv-state issued">ISSUED · REV {{ max(1,$issueRevision) }} · {{ $voucher->issued_at?->format('d M Y H:i') }}</span>
                @else
                    <span class="tv-state voided">VOIDED · {{ $voucher->voided_at?->format('d M Y H:i') }}</span>
                @endif
            </div>
            @if($isIssued && $clientVoucherVoidRouteReady)
            <form method="POST" action="{{ route('operations.bookings.travel-voucher.void',$booking) }}" onsubmit="return confirm('Void this Client Voucher? Its public QR/link will stop showing travel details until reissued.')" style="min-width:min(520px,100%)">
                @csrf
                <label class="tv-field"><span>Void Reason *</span><input name="void_reason" required maxlength="1000" placeholder="Correction required, duplicate voucher, service changed, etc."></label>
                <div style="display:flex;justify-content:flex-end;margin-top:7px"><button class="btn btn-danger">Void Voucher</button></div>
            </form>
            @endif
        </div>
    </div>
    @endif

    @if($issueControlsInstalled && $voucher->revisions()->exists())
    <div class="tv-card">
        <h3>Voucher Revision History</h3>
        <div class="sub">Immutable snapshots. Superseded revisions remain available for authorized review.</div>
        <table class="saved-table">
            <thead><tr><th>Revision</th><th>Issued</th><th>Issued By</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach($voucher->revisions()->with('issuedBy')->orderBy('revision_no')->get() as $revision)
                <tr>
                    <td>REV {{ $revision->revision_no }}</td>
                    <td>{{ optional($revision->issued_at)->format('d M Y H:i:s') }}</td>
                    <td>{{ $revision->issuedBy?->name ?: ('User #'.$revision->issued_by) }}</td>
                    <td>{{ $revision->reason }}</td>
                    <td>{{ (int)$revision->revision_no === (int)$issueRevision ? ($isVoided ? 'VOIDED' : 'CURRENT') : 'SUPERSEDED' }}</td>
                    <td><a class="btn btn-secondary btn-small" target="_blank" href="{{ route('bookings.travel-voucher.revision',[$booking,$revision->revision_no]) }}">View snapshot</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="itinerary-card">
        <h3>Voucher-Only Itinerary Information</h3>
        <div class="zero"><strong>Zero accounting impact.</strong> Use these sections only for client travel information that was not sold as an ERP product.</div>

        <div class="tv-tabs">
            <button type="button" class="tv-tab active" data-jump="flight-entry">Flight Information</button>
            <button type="button" class="tv-tab" data-jump="transport-entry">Transport / Services</button>
            <button type="button" class="tv-tab" data-jump="visa-entry">Visa Information</button>
            <button type="button" class="tv-tab" data-jump="other-entry">Other Information</button>
        </div>

        <section class="tv-section" id="flight-entry">
            <div class="tv-section-head">
                <div><h4>A. Flight Time</h4><p>Add departure / arrival flight timings exactly as required on the Travel Voucher.</p></div>
            </div>

            <form method="POST" action="{{ route('operations.bookings.travel-voucher.items.store',$booking) }}">
                @csrf
                <input type="hidden" name="item_type" value="FLIGHT">
                <div class="flight-grid">
                    <label class="tv-field"><span>Type *</span><select name="flight_leg" required><option value="DEPARTURE">Departure (Pakistan to KSA)</option><option value="ARRIVAL">Arrival (KSA to PAK)</option><option value="OTHER">Other Flight</option></select></label>
                    <label class="tv-field"><span>Airline</span><select name="airline_id" id="voucher-airline-select"><option value="">-- Select Airline --</option>@foreach($airlines as $airline)<option value="{{ $airline->id }}">{{ $airline->iata_code ?: $airline->code }} · {{ $airline->name }}</option>@endforeach</select><button type="button" class="btn btn-secondary" id="show-new-airline" style="margin-top:5px;width:100%">+ Add New Airline</button></label>
                    <label class="tv-field"><span>Flight No. *</span><input name="flight_number" required placeholder="e.g. 9P-586"></label>
                    <label class="tv-field"><span>PNR / Booking Ref.</span><input name="pnr" placeholder="e.g. ABC123"></label>

                    <label class="tv-field"><span>From *</span><input name="from_location" required placeholder="LHE"></label>
                    <label class="tv-field"><span>To *</span><input name="to_location" required placeholder="JED"></label>
                    <label class="tv-field"><span>Class</span><input name="flight_class" placeholder="Economy"></label>
                    <label class="tv-field"><span>Baggage</span><input name="baggage" placeholder="30 KG"></label>

                    <label class="tv-field"><span>Departure Date *</span><input type="date" name="departure_date" required></label>
                    <label class="tv-field"><span>Departure Time *</span><input type="time" name="departure_time" required></label>
                    <label class="tv-field"><span>Arrival Date *</span><input type="date" name="arrival_date" required></label>
                    <label class="tv-field"><span>Arrival Time *</span><input type="time" name="arrival_time" required></label>

                    <div class="inline-master span4" id="new-airline-panel">
                        <div class="inline-master-grid">
                            <label class="tv-field"><span>New Airline Name *</span><input name="new_airline_name" id="new-airline-name" placeholder="e.g. Saudia"></label>
                            <label class="tv-field"><span>IATA</span><input name="new_airline_iata_code" maxlength="2" placeholder="SV"></label>
                            <label class="tv-field"><span>ICAO</span><input name="new_airline_icao_code" maxlength="3" placeholder="SVA"></label>
                            <label class="tv-field"><span>Accounting Prefix</span><input name="new_airline_accounting_prefix" maxlength="3" pattern="[0-9]{3}" placeholder="065"></label>
                            <label class="tv-field"><span>Country</span><input name="new_airline_country_code" maxlength="2" value="PK"></label>
                        </div>
                        <div style="margin-top:6px;font-size:8px;color:var(--muted)">The new airline is saved to <strong>Master Data → Travel Masters → Airlines</strong> and becomes reusable for future entries.</div>
                    </div>

                    <label class="tv-field span4"><span>Flight Details / Notes</span><textarea rows="2" name="details" placeholder="Aircraft, terminal, meal, ticket ref, special note, etc."></textarea></label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="btn btn-primary">+ Add Flight</button></div>
            </form>

            @php
                $savedFlights=$voucherOnly->where('item_type','FLIGHT');
            @endphp
            @if($savedFlights->isNotEmpty())
                <table class="saved-table">
                    <thead><tr><th>Type</th><th>Airline</th><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th><th>PNR</th><th>Class</th><th>Baggage</th><th></th></tr></thead>
                    <tbody>
                    @foreach($savedFlights as $item)
                        @php
                            $meta=(array)($item->meta_json ?? []);
                        @endphp
                        <tr>
                            <td>{{ $meta['leg']??$item->title }}</td>
                            <td>{{ $meta['airline']??'—' }}</td>
                            <td>{{ $meta['flight_number']??'—' }}</td>
                            <td>{{ $item->from_location ?: '—' }} → {{ $item->to_location ?: '—' }}</td>
                            <td>{{ $item->starts_at?->format('d M Y H:i') ?: '—' }}</td>
                            <td>{{ $item->ends_at?->format('d M Y H:i') ?: '—' }}</td>
                            <td>{{ $meta['pnr']??$item->reference??'—' }}</td>
                            <td>{{ $meta['class']??'—' }}</td>
                            <td>{{ $meta['baggage']??'—' }}</td>
                            <td class="right"><form method="POST" action="{{ route('operations.bookings.travel-voucher.items.remove',[$booking,$item]) }}" onsubmit="return confirm('Remove this flight from the voucher?')">@csrf @method('DELETE')<button class="btn btn-danger btn-small">Remove</button></form></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="tv-section" id="transport-entry">
            <div class="tv-section-head">
                <div><h4>B. Transport / Services Information</h4><p>Select an existing transporter, add a new one, or edit the selected master record.</p></div>
            </div>

            <div class="transport-grid">
                <label class="tv-field">
                    <span>Transport Company *</span>
                    <select id="transport-company-select" form="transport-item-form" name="transport_company_id" required>
                        <option value="">-- Select Transport Company --</option>
                        @foreach($transportCompanies as $company)
                            <option value="{{ $company->id }}" data-name="{{ $company->name }}" data-phone="{{ $company->phone }}" data-notes="{{ $company->notes }}">{{ $company->name }}{{ $company->phone?' · '.$company->phone:'' }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" class="btn btn-success" id="show-new-transport">+ Add New</button>
                <button type="button" class="btn btn-primary" id="show-edit-transport">Edit Selected</button>
                <label class="tv-field"><span>Vehicle Type *</span><select form="transport-item-form" name="vehicle_type" required><option value="">-- Select Vehicle Type --</option><option>Economy By Bus</option><option>Luxury Bus</option><option>Coaster</option><option>Hiace</option><option>Van</option><option>Car</option><option>SUV</option><option>GMC</option><option>Private Car</option><option>Other</option></select></label>
                <label class="tv-field"><span>Contact Number</span><input id="transport-contact" form="transport-item-form" name="contact_number" placeholder="+966 5X XXX XXXX"></label>
            </div>

            <div class="inline-master" id="new-transport-panel">
                <form method="POST" action="{{ route('operations.bookings.travel-voucher.transport-companies.store',$booking) }}">
                    @csrf
                    <div class="inline-master-grid">
                        <label class="tv-field"><span>New Transport Company *</span><input name="transport_company_name" required></label>
                        <label class="tv-field"><span>Contact Number</span><input name="transport_company_phone"></label>
                        <label class="tv-field"><span>Notes</span><input name="transport_company_notes" placeholder="Optional master note"></label>
                        <button class="btn btn-success">Save Company</button>
                    </div>
                </form>
            </div>

            <div class="inline-master" id="edit-transport-panel">
                <form method="POST" id="edit-transport-form" data-action-template="{{ route('operations.bookings.travel-voucher.transport-companies.update',[$booking,'__PARTNER__']) }}">
                    @csrf
                    @method('PUT')
                    <div class="inline-master-grid">
                        <label class="tv-field"><span>Company Name *</span><input id="edit-transport-name" name="transport_company_name" required></label>
                        <label class="tv-field"><span>Contact Number</span><input id="edit-transport-phone" name="transport_company_phone"></label>
                        <label class="tv-field"><span>Notes</span><input id="edit-transport-notes" name="transport_company_notes"></label>
                        <button class="btn btn-primary">Update Company</button>
                    </div>
                </form>
            </div>

            <form method="POST" id="transport-item-form" action="{{ route('operations.bookings.travel-voucher.items.store',$booking) }}">
                @csrf
                <input type="hidden" name="item_type" value="TRANSPORT">
                <div class="transport-grid second" style="margin-top:8px">
                    <label class="tv-field"><span>Service Type</span><select name="service_type"><option value="">-- Select Service Type --</option><option>Transfer</option><option>Airport Transfer</option><option>Ziyarat</option><option>Intercity Transfer</option><option>Full Umrah Transport</option><option>Other</option></select></label>
                    <label class="tv-field"><span>Service Date</span><input type="date" name="service_date"></label>
                    <label class="tv-field"><span>Pickup Time</span><input type="time" name="pickup_time"></label>
                    <label class="tv-field"><span>BRN / Ref. No.</span><input name="brn" placeholder="BRN12345"></label>
                    <label class="tv-field"><span>From / Pickup</span><input name="from_location" placeholder="e.g. Jeddah Airport"></label>
                    <label class="tv-field"><span>To / Drop-off</span><input name="to_location" placeholder="e.g. Makkah Hotel"></label>
                    <label class="tv-field"><span>Driver Name</span><input name="driver_name" placeholder="Optional"></label>
                    <label class="tv-field"><span>Vehicle No.</span><input name="vehicle_number" placeholder="Optional"></label>
                    <label class="tv-field wide"><span>Description / Instructions</span><textarea rows="2" name="details" placeholder="Meeting point, terminal, hotel, driver instructions, etc."></textarea></label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="btn btn-primary">+ Add Transport / Service</button></div>
            </form>

            @php
                $savedTransport=$voucherOnly->where('item_type','TRANSPORT');
            @endphp
            @if($savedTransport->isNotEmpty())
                <table class="saved-table">
                    <thead><tr><th>Transporter</th><th>Vehicle Type</th><th>Service Type</th><th>Date / Time</th><th>From</th><th>To</th><th>Driver / Vehicle</th><th>Contact No.</th><th>BRN / Ref.</th><th>Description</th><th></th></tr></thead>
                    <tbody>
                    @foreach($savedTransport as $item)
                        @php
                            $meta=(array)($item->meta_json ?? []);
                        @endphp
                        <tr>
                            <td>{{ $meta['transport_company']??$item->title }}</td>
                            <td>{{ $meta['vehicle_type']??'—' }}</td>
                            <td>{{ $meta['service_type']??'—' }}</td>
                            <td>{{ $item->starts_at ? $item->starts_at->format(($meta['pickup_time_provided']??false)?'d M Y H:i':'d M Y') : '—' }}</td>
                            <td>{{ $item->from_location ?: '—' }}</td>
                            <td>{{ $item->to_location ?: '—' }}</td>
                            <td>{{ trim(implode(' · ',array_filter([$meta['driver_name']??null,$meta['vehicle_number']??null]))) ?: '—' }}</td>
                            <td>{{ $meta['contact_number']??'—' }}</td>
                            <td>{{ $item->reference ?: '—' }}</td>
                            <td>{{ $item->details ?: '—' }}</td>
                            <td class="right"><form method="POST" action="{{ route('operations.bookings.travel-voucher.items.remove',[$booking,$item]) }}" onsubmit="return confirm('Remove this transport line from the voucher?')">@csrf @method('DELETE')<button class="btn btn-danger btn-small">Remove</button></form></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="tv-section" id="visa-entry">
            <div class="tv-section-head"><div><h4>C. Visa Information (Voucher-Only)</h4><p>Visa notes are simple client-facing text. No accounting entry is created.</p></div></div>
            <form method="POST" action="{{ route('operations.bookings.travel-voucher.items.store',$booking) }}">
                @csrf
                <input type="hidden" name="item_type" value="VISA">
                <div class="simple-grid">
                    <label class="tv-field"><span>Reference / Visa Ref.</span><input name="reference" placeholder="Optional"></label>
                    <label class="tv-field"><span>Visa Details / Remarks *</span><textarea name="details" required placeholder="Saudi visa processing completed. Visa validity / special remarks, etc."></textarea></label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="btn btn-primary">+ Add Visa Note</button></div>
            </form>
        </section>

        <section class="tv-section" id="other-entry">
            <div class="tv-section-head"><div><h4>D. Other Information (Voucher-Only)</h4><p>Any other voucher remark or client travel information.</p></div></div>
            <form method="POST" action="{{ route('operations.bookings.travel-voucher.items.store',$booking) }}">
                @csrf
                <input type="hidden" name="item_type" value="OTHER">
                <div class="simple-grid">
                    <label class="tv-field"><span>Reference</span><input name="reference" placeholder="Optional"></label>
                    <label class="tv-field"><span>Remarks / Details *</span><textarea name="details" required placeholder="Special travel note, service note, or other voucher information."></textarea></label>
                </div>
                <div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="btn btn-primary">+ Add Note</button></div>
            </form>
        </section>

        @php
            $textNotes=$voucherOnly->whereIn('item_type',['VISA','OTHER']);
        @endphp
        @if($textNotes->isNotEmpty())
            <table class="saved-table">
                <thead><tr><th>Type</th><th>Reference</th><th>Details</th><th></th></tr></thead>
                <tbody>
                @foreach($textNotes as $item)
                    <tr>
                        <td>{{ $item->item_type }}</td>
                        <td>{{ $item->reference ?: '—' }}</td>
                        <td>{{ $item->details }}</td>
                        <td class="right"><form method="POST" action="{{ route('operations.bookings.travel-voucher.items.remove',[$booking,$item]) }}" onsubmit="return confirm('Remove this note from the voucher?')">@csrf @method('DELETE')<button class="btn btn-danger btn-small">Remove</button></form></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <div class="bottom-save">
            <a class="btn btn-primary" target="_blank" href="{{ route('operations.bookings.travel-voucher.show',$voucherRouteParams) }}">Preview Voucher</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('[data-copy-public-link]').forEach(function(btn){
        btn.addEventListener('click',function(){
            const url=btn.getAttribute('data-url')||'';
            if(!url)return;
            navigator.clipboard.writeText(url).then(function(){
                const old=btn.textContent;btn.textContent='Link Copied';
                setTimeout(function(){btn.textContent=old},1400);
            });
        });
    });
    const airlineNewBtn=document.getElementById('show-new-airline');
    const airlineNewPanel=document.getElementById('new-airline-panel');
    const airlineSelect=document.getElementById('voucher-airline-select');
    const airlineNewName=document.getElementById('new-airline-name');
    if(airlineNewBtn && airlineNewPanel){
        airlineNewBtn.addEventListener('click',function(){
            airlineNewPanel.classList.toggle('show');
            if(airlineNewPanel.classList.contains('show')){
                if(airlineSelect) airlineSelect.value='';
                if(airlineNewName) airlineNewName.focus();
            }
        });
    }
    if(airlineSelect){
        airlineSelect.addEventListener('change',function(){
            if(airlineSelect.value && airlineNewPanel) airlineNewPanel.classList.remove('show');
        });
    }

    const select=document.getElementById('transport-company-select');
    const contact=document.getElementById('transport-contact');
    const newBtn=document.getElementById('show-new-transport');
    const editBtn=document.getElementById('show-edit-transport');
    const newPanel=document.getElementById('new-transport-panel');
    const editPanel=document.getElementById('edit-transport-panel');
    const editForm=document.getElementById('edit-transport-form');
    const editName=document.getElementById('edit-transport-name');
    const editPhone=document.getElementById('edit-transport-phone');
    const editNotes=document.getElementById('edit-transport-notes');

    function selectedOption(){
        return select && select.selectedIndex>=0 ? select.options[select.selectedIndex] : null;
    }

    if(select){
        select.addEventListener('change',function(){
            const option=selectedOption();
            if(option && option.value && contact && !contact.value){
                contact.value=option.dataset.phone || '';
            }
        });
    }

    if(newBtn){
        newBtn.addEventListener('click',function(){
            newPanel.classList.toggle('show');
            editPanel.classList.remove('show');
        });
    }

    if(editBtn){
        editBtn.addEventListener('click',function(){
            const option=selectedOption();
            if(!option || !option.value){
                alert('Select a Transport Company first.');
                return;
            }
            editName.value=option.dataset.name || option.textContent.trim();
            editPhone.value=option.dataset.phone || '';
            editNotes.value=option.dataset.notes || '';
            editForm.action=editForm.dataset.actionTemplate.replace('__PARTNER__',option.value);
            editPanel.classList.add('show');
            newPanel.classList.remove('show');
        });
    }

    document.querySelectorAll('.tv-tab').forEach(function(btn){
        btn.addEventListener('click',function(){
            document.querySelectorAll('.tv-tab').forEach(function(x){x.classList.remove('active')});
            btn.classList.add('active');
            const target=document.getElementById(btn.dataset.jump);
            if(target){target.scrollIntoView({behavior:'smooth',block:'start'})}
        });
    });
});
</script>

@if($documentType==='UMRAH' && !$resolvedVisaServiceOperator)
<script>
(function(){
    const select=document.querySelector('[data-voucher-saudi-select]');
    const display=document.querySelector('[data-voucher-iata-display]');
    if(!select||!display)return;
    const sync=()=>{
        const option=select.options[select.selectedIndex];
        if(!option||!option.value){display.value='Auto from Saudi Visa Company';return;}
        display.value=option.dataset.linked==='1' && option.dataset.iataName ? option.dataset.iataName : 'NOT LINKED — update Travel Masters';
    };
    select.addEventListener('change',sync);
    sync();
})();
</script>
@endif
@endsection
