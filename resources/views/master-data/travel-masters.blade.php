@extends('layouts.app')
@section('title',$tab==='products'?'Products & Services':'Travel Masters')
@section('page_title',$tab==='products'?'Products & Services':'Travel Masters')
@section('content')
@php
    $canManage=auth()->user()->hasPermission('travel_masters.manage');
    $canManageProducts=auth()->user()->hasPermission('products.manage');
    $baseCurrencyCode=strtoupper((string)($baseCurrencyCode ?? 'PKR'));
    $tabs=[
        'overview'=>'Overview',
        'hotels'=>'Hotels',
        'transport'=>'Transport Companies',
        'vehicle-types'=>'Vehicle Types',
        'transport-routes'=>'Transport Routes',
        'transport-rates'=>'Transport Vendor Rates',
        'saudi-visa'=>'Saudi Visa Companies',
        'pakistan-iata'=>'Pakistan Visa / IATA',
        'airlines'=>'Airlines',
        'airline-codes'=>'Airline Codes',
        'booking-sources'=>'Booking Sources',
    ];
    if(auth()->user()->hasPermission('products.view')) $tabs['products']='Products & Services';
    $exportable=['hotels','transport','saudi-visa','pakistan-iata','airlines','airline-codes'];
    $partnerTitle=match($tab){
        'transport'=>'Transport Companies',
        'vehicle-types'=>'Vehicle Types',
        'transport-routes'=>'Transport Routes',
        'transport-rates'=>'Transport Vendor Rates',
        'saudi-visa'=>'Saudi Visa Companies',
        'pakistan-iata'=>'Pakistan Visa / IATA Companies',
        default=>'Travel Partners',
    };
    $partnerType=match($tab){
        'transport'=>'TRANSPORT_COMPANY',
        'saudi-visa'=>'SAUDI_VISA_COMPANY',
        'pakistan-iata'=>'PAKISTANI_IATA_UMRAH_OPERATOR',
        default=>null,
    };
@endphp

<style>
.tm-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.tm-head h1{margin:4px 0 5px;font-size:26px}.tm-head p{margin:0;max-width:850px;color:#667085}.tm-eyebrow{font-size:11px;letter-spacing:.08em;text-transform:uppercase;font-weight:800;color:#2563eb}.tm-version{white-space:nowrap;font-size:12px;color:#667085;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:7px 10px}.tm-alert{padding:11px 13px;border:1px solid #f59e0b;background:#fffbeb;border-radius:10px;margin:0 0 14px;color:#92400e}.tm-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.tm-metric{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}.tm-metric span{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#667085;font-weight:700}.tm-metric strong{display:block;font-size:24px;margin-top:3px}.tm-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}.tm-tab{display:inline-flex;text-decoration:none;color:#344054;background:#fff;border:1px solid #d0d5dd;border-radius:8px;padding:7px 10px;font-size:12px;font-weight:700}.tm-tab.active{background:#111827;color:#fff;border-color:#111827}.tm-toolbar{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px;margin-bottom:14px;display:flex;gap:8px;align-items:end;flex-wrap:wrap}.tm-toolbar label{display:grid;gap:4px;font-size:11px;font-weight:700;color:#475467}.tm-toolbar input,.tm-toolbar select{min-width:190px;border:1px solid #d0d5dd;border-radius:8px;padding:8px 9px;background:#fff}.tm-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;margin-bottom:14px;overflow:hidden}.tm-card-head{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:13px 15px;border-bottom:1px solid #eaecf0;background:#fcfcfd}.tm-card-head h3{margin:0;font-size:16px}.tm-card-head p{margin:3px 0 0;font-size:12px;color:#667085}.tm-body{padding:14px 15px}.tm-form{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}.tm-field{display:grid;gap:4px}.tm-field>span{font-size:11px;font-weight:700;color:#475467}.tm-field input,.tm-field select,.tm-field textarea{width:100%;box-sizing:border-box;border:1px solid #d0d5dd;border-radius:8px;padding:8px 9px;background:#fff;font:inherit}.tm-field textarea{min-height:64px;resize:vertical}.tm-span2{grid-column:span 2}.tm-span4{grid-column:1/-1}.tm-check{display:flex;gap:7px;align-items:center;font-size:12px;font-weight:700;min-height:38px}.tm-check input{width:auto}.tm-table{width:100%;border-collapse:collapse}.tm-table th,.tm-table td{padding:9px 10px;border-bottom:1px solid #eaecf0;text-align:left;vertical-align:top;font-size:12px}.tm-table th{font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#667085;background:#f9fafb}.tm-table small{display:block;color:#667085;margin-top:2px}.tm-table tbody tr:last-child td{border-bottom:0}.tm-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700}.tm-badge{display:inline-block;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:800}.tm-badge.on{background:#ecfdf3;color:#027a48}.tm-badge.off{background:#f2f4f7;color:#667085}.tm-usage{font-weight:800}.tm-actions details{min-width:78px}.tm-actions summary{cursor:pointer;color:#175cd3;font-weight:700;list-style:none}.tm-actions details[open]{position:fixed;inset:0;z-index:20000;display:flex;align-items:center;justify-content:center;padding:28px;background:rgba(15,23,42,.58);backdrop-filter:blur(2px)}.tm-actions details[open]>summary{position:fixed;top:20px;right:22px;z-index:20002;background:#fff;color:#101828;border:1px solid #d0d5dd;border-radius:9px;padding:9px 12px;box-shadow:0 6px 20px rgba(16,24,40,.18);font-size:0}.tm-actions details[open]>summary::after{content:'✕ Close';font-size:12px;font-weight:800}.tm-edit{position:relative;z-index:20001;margin:0;width:min(980px,calc(100vw - 56px));max-height:calc(100vh - 90px);overflow:auto;border:1px solid #d0d5dd;border-radius:14px;background:#fff;padding:20px;min-width:0;box-sizing:border-box;box-shadow:0 24px 70px rgba(16,24,40,.28)}.tm-actions details[open] .tm-form{grid-template-columns:repeat(4,minmax(0,1fr))}.tm-pager{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;border-top:1px solid #eaecf0;background:#fcfcfd;font-size:12px;color:#475467}.tm-pager a,.tm-pager span.tm-page-btn{display:inline-flex;padding:7px 10px;border:1px solid #d0d5dd;border-radius:8px;text-decoration:none;color:#344054;background:#fff;font-weight:700}.tm-pager span.tm-page-btn{opacity:.45}.tm-ref-note{margin:0;padding:10px 14px;border-bottom:1px solid #eaecf0;background:#eff6ff;color:#1e3a8a;font-size:12px}.tm-empty{padding:26px;text-align:center;color:#667085}.tm-overview-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.tm-overview-link{text-decoration:none;color:inherit;border:1px solid #e5e7eb;border-radius:12px;padding:14px;background:#fff}.tm-overview-link:hover{border-color:#98a2b3}.tm-overview-link strong{display:block;font-size:15px}.tm-overview-link span{display:block;margin-top:4px;color:#667085;font-size:12px}.tm-note{font-size:12px;color:#667085;padding:10px 12px;border-left:3px solid #98a2b3;background:#f8fafc;margin-bottom:12px}.tm-right{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.tm-btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:8px;padding:8px 11px;font-weight:700;font-size:12px;cursor:pointer}.tm-btn.primary{background:#175cd3;color:#fff}.tm-btn.secondary{background:#fff;color:#344054;border:1px solid #d0d5dd}.tm-btn.success{background:#067647;color:#fff}.tm-badge.status-active{background:#ecfdf3;color:#027a48}.tm-badge.status-upcoming{background:#eff8ff;color:#175cd3}.tm-badge.status-draft{background:#fff7ed;color:#c2410c}.tm-badge.status-expired{background:#f2f4f7;color:#667085}.tm-badge.status-inactive{background:#f2f4f7;color:#667085}.tm-rate-wrap{overflow:auto;border-top:1px solid #eaecf0;border-bottom:1px solid #eaecf0}.tm-rate-matrix{border-collapse:separate;border-spacing:0;width:100%;min-width:980px}.tm-rate-matrix th,.tm-rate-matrix td{border-right:1px solid #eaecf0;border-bottom:1px solid #eaecf0;padding:7px 8px;font-size:11px;background:#fff;text-align:center;vertical-align:middle}.tm-rate-matrix th{position:sticky;top:0;z-index:3;background:#f8fafc;color:#475467;font-size:10px;text-transform:uppercase}.tm-rate-matrix th.route,.tm-rate-matrix td.route{position:sticky;left:0;z-index:2;text-align:left;min-width:260px;max-width:320px;background:#fff}.tm-rate-matrix th.route{z-index:4;background:#f8fafc}.tm-rate-matrix small{display:block;color:#98a2b3;margin-top:2px;text-transform:none;font-weight:500}.tm-rate-matrix input{width:92px;box-sizing:border-box;border:1px solid #d0d5dd;border-radius:7px;padding:7px;text-align:right}.tm-rate-actions{display:flex;justify-content:flex-end;padding:12px 15px;background:#fcfcfd}.tm-rate-footer-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.tm-currency-help{font-size:10px;color:#667085;margin-top:3px}
@media(max-width:1050px){.tm-metrics{grid-template-columns:repeat(2,1fr)}.tm-form{grid-template-columns:repeat(2,1fr)}.tm-overview-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:720px){.tm-head{display:block}.tm-version{display:inline-block;margin-top:8px}.tm-metrics,.tm-form,.tm-overview-grid{grid-template-columns:1fr}.tm-span2,.tm-span4{grid-column:auto}.tm-card{overflow:auto}.tm-actions details[open]{padding:12px}.tm-actions details[open]>summary{top:10px;right:10px}.tm-edit{width:calc(100vw - 24px);max-height:calc(100vh - 54px);padding:15px}.tm-actions details[open] .tm-form{grid-template-columns:1fr}.tm-toolbar input,.tm-toolbar select{min-width:150px}}
</style>

<div class="tm-head">
    <div>
        <span class="tm-eyebrow">ERP-10.25.5 · Travel Masters Fail-Safe</span>
        <h1>{{ $tab==='products' ? 'Products & Services' : 'Travel Masters' }}</h1>
        <p>{{ $tab==='products' ? 'Reusable product/service definitions and accounting defaults.' : 'Manage every reusable hotel, transporter, visa company, IATA company and airline created during booking, ticketing or voucher work.' }}</p>
    </div>
    <div class="tm-version">v{{ config('erp.version') }}</div>
</div>

@if(!empty($loadError))
<div class="tm-alert"><strong>Some optional master data could not be loaded.</strong> The page remains available; run Safe Database Upgrade and Clear Application Cache, then retry the affected tab.</div>
@endif

@if(!$registryUpgradeInstalled)
<div class="tm-alert"><strong>Database upgrade required:</strong> Run <strong>System → Health & Updates → Safe Database Upgrade</strong> once. Existing Transport/Visa/IATA records are preserved; the upgrade only adds management fields and stable codes.</div>
@endif

@if(!$voucherFooterUpgradeInstalled)
<div class="tm-alert"><strong>Voucher footer upgrade required:</strong> Run <strong>System → Health & Updates → Safe Database Upgrade</strong> once to enable IATA-specific and Company Profile default voucher footers.</div>
@endif
@if(in_array($tab,['saudi-visa','pakistan-iata'],true) && !$visaOperatorLinkUpgradeInstalled)
<div class="tm-alert"><strong>Visa operator linkage upgrade required:</strong> Run <strong>System → Health & Updates → Safe Database Upgrade</strong> once. Saudi Visa Companies can then be permanently linked to their Pakistan Visa / IATA Operator.</div>
@endif
@if(in_array($tab,['vehicle-types','transport-routes','transport-rates'],true) && !$transportRateMasterUpgradeInstalled)
<div class="tm-alert"><strong>Transport master/rate upgrade required:</strong> Run <strong>System → Health & Updates → Safe Database Upgrade</strong> once to enable reusable Vehicle Types, Routes and vendor rate cards.</div>
@endif

@if($tab!=='products')
<div class="tm-metrics">
    <div class="tm-metric"><span>Hotels</span><strong>{{ number_format($summary['hotels']) }}</strong></div>
    <div class="tm-metric"><span>Transport</span><strong>{{ number_format($summary['transport']) }}</strong></div>
    <div class="tm-metric"><span>Saudi Visa</span><strong>{{ number_format($summary['saudiVisa']) }}</strong></div>
    <div class="tm-metric"><span>Pakistan Visa / IATA</span><strong>{{ number_format($summary['pakistanIata']) }}</strong></div>
    <div class="tm-metric"><span>Airlines</span><strong>{{ number_format($summary['airlines']) }}</strong></div>
</div>
@endif

<div class="tm-tabs">
@foreach($tabs as $key=>$label)
    <a class="tm-tab {{ $tab===$key?'active':'' }}" href="{{ route('master-data.travel.index',['tab'=>$key]) }}">{{ $label }}</a>
@endforeach
</div>

@if($tab!=='overview' && $tab!=='transport-rates')
<form class="tm-toolbar" method="GET" action="{{ route('master-data.travel.index') }}">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <label>Search<input name="q" value="{{ $q }}" placeholder="Name, code, city, IATA…"></label>
    <label>Status<select name="status"><option value="all" {{ $status==='all'?'selected':'' }}>All</option><option value="active" {{ $status==='active'?'selected':'' }}>Active</option><option value="inactive" {{ $status==='inactive'?'selected':'' }}>Inactive</option></select></label>
    <button class="tm-btn primary" type="submit">Apply Filter</button>
    <a class="tm-btn secondary" href="{{ route('master-data.travel.index',['tab'=>$tab]) }}">Reset</a>
    @if(in_array($tab,$exportable,true))
        <a class="tm-btn secondary" href="{{ route('master-data.travel.export',['section'=>$tab]) }}">Export CSV</a>
    @endif
</form>
@endif

@if($tab==='overview')
<div class="tm-note"><strong>One master, many transactions:</strong> changes here affect future selections. Existing booking/voucher/ticket snapshots remain historical. A Hotel/Airline/Partner can optionally link to a Vendor Party, but the operational master is never treated as the accounting supplier automatically.</div>
<div class="tm-overview-grid">
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'hotels']) }}"><strong>Hotels · {{ $summary['hotels'] }}</strong><span>Accommodation identities created from Hotel Booking or directly here.</span></a>
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'transport']) }}"><strong>Transport Companies · {{ $summary['transport'] }}</strong><span>Transporters created from vouchers or directly from Master Data.</span></a>
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'saudi-visa']) }}"><strong>Saudi Visa Companies · {{ $summary['saudiVisa'] }}</strong><span>Saudi-side visa/service companies reusable on Umrah vouchers.</span></a>
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'pakistan-iata']) }}"><strong>Pakistan Visa / IATA · {{ $summary['pakistanIata'] }}</strong><span>Pakistan-side visa operators, IATA agencies and Umrah operators.</span></a>
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'airlines']) }}"><strong>Airlines · {{ $summary['airlines'] }}</strong><span>Carrier identity, IATA/ICAO/accounting prefix and optional supplier mapping.</span></a>
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'airline-codes']) }}"><strong>Airline Codes</strong><span>Focused IATA, ICAO and 3-digit accounting-prefix management.</span></a>
    <a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'booking-sources']) }}"><strong>Booking Sources</strong><span>GDS, NDC, API, aggregator and manual booking-source identities.</span></a>
    @if(auth()->user()->hasPermission('products.view'))<a class="tm-overview-link" href="{{ route('master-data.travel.index',['tab'=>'products']) }}"><strong>Products & Services</strong><span>Commercial service catalogue and accounting mapping defaults.</span></a>@endif
</div>
@endif

@if($tab==='hotels')
<div class="tm-card">
    <div class="tm-card-head"><div><h3>Hotels</h3><p>Hotel masters are shared with Hotel Booking. Duplicate hotel/city combinations are blocked.</p></div></div>
    @if($canManage)<div class="tm-body"><form method="POST" action="{{ route('master-data.hotels.store') }}" class="tm-form">@csrf
        <label class="tm-field"><span>Code *</span><input name="code" required placeholder="HTL-0001"></label>
        <label class="tm-field"><span>Hotel Name *</span><input name="name" required></label>
        <label class="tm-field"><span>City *</span><input name="city" required placeholder="Makkah"></label>
        <label class="tm-field"><span>City IATA</span><input name="city_iata_code" maxlength="3" placeholder="JED" {{ !$hotelCityIataInstalled?'disabled':'' }}></label>
        <label class="tm-field"><span>Country *</span><input name="country_code" maxlength="2" value="SA" required></label>
        <label class="tm-field"><span>Star Rating</span><input type="number" step="0.5" min="0" max="5" name="star_rating"></label>
        <label class="tm-field"><span>Phone</span><input name="phone"></label>
        <label class="tm-field"><span>Default Supplier / Vendor</span><select name="default_vendor_party_id"><option value="">No default supplier</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label>
        <label class="tm-field tm-span2"><span>Address</span><input name="address"></label>
        <label class="tm-field"><span>Notes</span><input name="notes"></label>
        <label class="tm-check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
        <div><button class="tm-btn success">+ Add Hotel</button></div>
    </form></div>@endif
    <div style="overflow:auto"><table class="tm-table"><thead><tr><th>Code / Hotel</th><th>Location</th><th>Contact</th><th>Supplier</th><th>Used</th><th>Status</th><th>Manage</th></tr></thead><tbody>
    @forelse($hotels as $hotel)<tr>
        <td><span class="tm-code">{{ $hotel->code }}</span><strong style="display:block">{{ $hotel->name }}</strong><small>{{ $hotel->star_rating!==null?$hotel->star_rating.' ★':'Unrated' }}</small></td>
        <td>{{ $hotel->city }}{{ $hotel->city_iata_code?' · '.$hotel->city_iata_code:'' }}<small>{{ $hotel->country_code }}{{ $hotel->address?' · '.$hotel->address:'' }}</small></td>
        <td>{{ $hotel->phone ?: '—' }}</td><td>{{ $hotel->defaultVendor?->display_name ?: 'No default' }}</td><td class="tm-usage">{{ $usage['hotels'][(int)$hotel->id]??0 }}</td>
        <td><span class="tm-badge {{ $hotel->is_active?'on':'off' }}">{{ $hotel->is_active?'ACTIVE':'INACTIVE' }}</span></td>
        <td class="tm-actions">@if($canManage)<details><summary>Edit</summary><div class="tm-edit"><form method="POST" action="{{ route('master-data.hotels.update',$hotel) }}" class="tm-form">@csrf @method('PUT')
            <label class="tm-field"><span>Code *</span><input name="code" value="{{ $hotel->code }}" required></label><label class="tm-field"><span>Name *</span><input name="name" value="{{ $hotel->name }}" required></label><label class="tm-field"><span>City *</span><input name="city" value="{{ $hotel->city }}" required></label><label class="tm-field"><span>City IATA</span><input name="city_iata_code" maxlength="3" value="{{ $hotel->city_iata_code }}" {{ !$hotelCityIataInstalled?'disabled':'' }}></label>
            <label class="tm-field"><span>Country *</span><input name="country_code" maxlength="2" value="{{ $hotel->country_code }}" required></label><label class="tm-field"><span>Stars</span><input type="number" step="0.5" min="0" max="5" name="star_rating" value="{{ $hotel->star_rating }}"></label><label class="tm-field"><span>Phone</span><input name="phone" value="{{ $hotel->phone }}"></label><label class="tm-field"><span>Default Vendor</span><select name="default_vendor_party_id"><option value="">No default</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" {{ (int)$hotel->default_vendor_party_id===(int)$vendor->id?'selected':'' }}>{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label>
            <label class="tm-field tm-span2"><span>Address</span><input name="address" value="{{ $hotel->address }}"></label><label class="tm-field"><span>Notes</span><input name="notes" value="{{ $hotel->notes }}"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" {{ $hotel->is_active?'checked':'' }}> Active</label><div><button class="tm-btn primary">Save Changes</button></div>
        </form></div></details>@endif</td>
    </tr>@empty<tr><td colspan="7" class="tm-empty">No hotel records found.</td></tr>@endforelse
    </tbody></table></div>
</div>
@endif

@if(in_array($tab,['transport','saudi-visa','pakistan-iata'],true))
<div class="tm-card">
    <div class="tm-card-head"><div><h3>{{ $partnerTitle }}</h3><p>@if($tab==='saudi-visa')Each Saudi Visa Company must be linked to one Pakistan Visa / IATA Operator. Booking and voucher screens then auto-resolve the operator pair.@else Records created inside vouchers appear here automatically and can be completed, linked to a Vendor Party, deactivated or exported.@endif</p></div></div>
    @if($canManage && $registryUpgradeInstalled)<div class="tm-body"><form method="POST" action="{{ route('master-data.travel-partners.store') }}" class="tm-form">@csrf
        <input type="hidden" name="partner_type" value="{{ $partnerType }}">
        <label class="tm-field"><span>Code</span><input name="code" placeholder="Auto if blank"></label><label class="tm-field"><span>Company / Operator Name *</span><input name="name" required></label>
        <label class="tm-field"><span>{{ $tab==='pakistan-iata'?'Registration / License':'Registration No.' }}</span><input name="registration_no"></label><label class="tm-field"><span>IATA Number</span><input name="iata_number" placeholder="{{ $tab==='pakistan-iata'?'e.g. 273xxxxx':'Optional' }}"></label>
        <label class="tm-field"><span>Phone</span><input name="phone"></label><label class="tm-field"><span>Email</span><input type="email" name="email"></label><label class="tm-field"><span>Website</span><input type="url" name="website" placeholder="https://"></label><label class="tm-field"><span>City</span><input name="city"></label>
        <label class="tm-field"><span>Country</span><input name="country_code" maxlength="2" value="{{ $tab==='saudi-visa'?'SA':'PK' }}"></label><label class="tm-field"><span>Default Supplier / Vendor</span><select name="default_vendor_party_id"><option value="">No default supplier</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label>
        @if($tab==='saudi-visa')<label class="tm-field tm-span2"><span>Linked Pakistan Visa / IATA Operator *</span><select name="linked_pakistan_iata_operator_id" required {{ !$visaOperatorLinkUpgradeInstalled?'disabled':'' }}><option value="">Select linked operator</option>@foreach($pakistanIataOptions as $iata)<option value="{{ $iata->id }}">{{ $iata->iata_number ?: $iata->code }} · {{ $iata->name }}</option>@endforeach</select><small>Selected automatically in Visa bookings and Umrah vouchers.</small></label>@endif
        @if($tab==='pakistan-iata')<label class="tm-field tm-span4"><span>Voucher Footer / KSA Contact Footer</span><textarea name="voucher_footer_html" rows="3" {{ !$voucherFooterUpgradeInstalled?'disabled':'' }} placeholder="KSA staff, Makkah/Madinah contacts, emergency numbers, etc."></textarea></label>@endif
        <label class="tm-field"><span>Notes</span><input name="notes"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" checked> Active</label><div><button class="tm-btn success">+ Add Record</button></div>
    </form></div>@endif
    <div style="overflow:auto"><table class="tm-table"><thead><tr><th>Code / Name</th><th>Registration / IATA</th><th>Contact</th><th>Location</th><th>Supplier</th><th>Used</th><th>Status</th><th>Manage</th></tr></thead><tbody>
    @forelse($partners as $partner)<tr>
        <td><span class="tm-code">{{ $partner->code ?: '—' }}</span><strong style="display:block">{{ $partner->name }}</strong><small>Source: {{ $partner->source_context ?: 'Voucher / legacy' }}</small></td>
        <td>{{ $partner->registration_no ?: '—' }}<small>@if($partner->partner_type==='SAUDI_VISA_COMPANY')Linked IATA: {{ $partner->linkedPakistanIataOperator?->name ?: 'NOT LINKED' }}@else IATA: {{ $partner->iata_number ?: '—' }}@endif</small></td><td>{{ $partner->phone ?: '—' }}<small>{{ $partner->email ?: '' }}</small></td><td>{{ $partner->city ?: '—' }}<small>{{ $partner->country_code ?: '—' }}</small></td><td>{{ $partner->defaultVendor?->display_name ?: 'No default' }}</td><td class="tm-usage">{{ $usage['partners'][(int)$partner->id]??0 }}</td><td><span class="tm-badge {{ $partner->is_active?'on':'off' }}">{{ $partner->is_active?'ACTIVE':'INACTIVE' }}</span></td>
        <td class="tm-actions">@if($canManage && $registryUpgradeInstalled)<details><summary>Edit</summary><div class="tm-edit"><form method="POST" action="{{ route('master-data.travel-partners.update',$partner) }}" class="tm-form">@csrf @method('PUT')<input type="hidden" name="partner_type" value="{{ $partner->partner_type }}">
            <label class="tm-field"><span>Code</span><input name="code" value="{{ $partner->code }}"></label><label class="tm-field"><span>Name *</span><input name="name" value="{{ $partner->name }}" required></label><label class="tm-field"><span>Registration / License</span><input name="registration_no" value="{{ $partner->registration_no }}"></label><label class="tm-field"><span>IATA Number</span><input name="iata_number" value="{{ $partner->iata_number }}"></label>
            <label class="tm-field"><span>Phone</span><input name="phone" value="{{ $partner->phone }}"></label><label class="tm-field"><span>Email</span><input type="email" name="email" value="{{ $partner->email }}"></label><label class="tm-field"><span>Website</span><input type="url" name="website" value="{{ $partner->website }}"></label><label class="tm-field"><span>City</span><input name="city" value="{{ $partner->city }}"></label>
            <label class="tm-field"><span>Country</span><input name="country_code" maxlength="2" value="{{ $partner->country_code }}"></label><label class="tm-field"><span>Default Vendor</span><select name="default_vendor_party_id"><option value="">No default</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" {{ (int)$partner->default_vendor_party_id===(int)$vendor->id?'selected':'' }}>{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label>
            @if($partner->partner_type==='SAUDI_VISA_COMPANY')<label class="tm-field tm-span2"><span>Linked Pakistan Visa / IATA Operator *</span><select name="linked_pakistan_iata_operator_id" required {{ !$visaOperatorLinkUpgradeInstalled?'disabled':'' }}><option value="">Select linked operator</option>@foreach($pakistanIataOptions as $iata)<option value="{{ $iata->id }}" {{ (int)$partner->linked_pakistan_iata_operator_id===(int)$iata->id?'selected':'' }}>{{ $iata->iata_number ?: $iata->code }} · {{ $iata->name }}</option>@endforeach</select><small>Changing this affects future saves only; historical snapshots remain unchanged.</small></label>@endif
            @if($partner->partner_type==='PAKISTANI_IATA_UMRAH_OPERATOR')<label class="tm-field tm-span4"><span>Voucher Footer / KSA Contact Footer</span><textarea name="voucher_footer_html" rows="3" {{ !$voucherFooterUpgradeInstalled?'disabled':'' }} placeholder="KSA staff, Makkah/Madinah contacts, emergency numbers, etc.">{{ $partner->voucher_footer_html }}</textarea></label>@endif
            <label class="tm-field"><span>Notes</span><input name="notes" value="{{ $partner->notes }}"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" {{ $partner->is_active?'checked':'' }}> Active</label><div><button class="tm-btn primary">Save Changes</button></div>
        </form></div></details>@endif</td>
    </tr>@empty<tr><td colspan="8" class="tm-empty">No {{ strtolower($partnerTitle) }} found.</td></tr>@endforelse
    </tbody></table></div>
</div>
@endif

@if(in_array($tab,['airlines','airline-codes'],true))
<div class="tm-card">
    <div class="tm-card-head"><div><h3>{{ $tab==='airline-codes'?'Airline IATA / ICAO / Accounting Codes':'Airlines' }}</h3><p>{{ $tab==='airline-codes'?'One airline record owns all standard codes; no duplicate code table is created.':'Airline identity remains separate from the supplier/vendor that sold the ticket.' }}</p></div></div>
    <div class="tm-ref-note"><strong>Airline reference preloaded:</strong> global IATA + ICAO airline identities are loaded by the database upgrade and merged with your existing records. Three-digit accounting/ticketing prefixes are filled only where explicitly mapped; unknown values stay blank rather than being guessed.</div>
    @if($canManage)<div class="tm-body"><form method="POST" action="{{ route('master-data.airlines.store') }}" class="tm-form">@csrf
        <label class="tm-field"><span>Internal Code *</span><input name="code" required placeholder="SV"></label><label class="tm-field"><span>Airline Name *</span><input name="name" required placeholder="Saudia"></label><label class="tm-field"><span>IATA</span><input name="iata_code" maxlength="2" placeholder="SV"></label><label class="tm-field"><span>ICAO</span><input name="icao_code" maxlength="3" placeholder="SVA"></label>
        <label class="tm-field"><span>Accounting Prefix</span><input name="accounting_prefix" maxlength="3" pattern="[0-9]{3}" placeholder="065" {{ !$airlineAccountingPrefixInstalled?'disabled':'' }}></label><label class="tm-field"><span>Country</span><input name="country_code" maxlength="2" placeholder="SA"></label><label class="tm-field"><span>Default Supplier / Vendor</span><select name="default_vendor_party_id"><option value="">No default supplier</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label><label class="tm-field"><span>Notes</span><input name="notes"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" checked> Active</label><div><button class="tm-btn success">+ Add Airline</button></div>
    </form></div>@endif
    <div style="overflow:auto"><table class="tm-table"><thead><tr><th>Airline</th><th>IATA</th><th>ICAO</th><th>Accounting</th><th>Country</th><th>Supplier</th><th>Tickets Used</th><th>Status</th><th>Manage</th></tr></thead><tbody>
    @forelse($airlines as $airline)<tr>
        <td><span class="tm-code">{{ $airline->code }}</span><strong style="display:block">{{ $airline->name }}</strong></td><td class="tm-code">{{ $airline->iata_code ?: '—' }}</td><td class="tm-code">{{ $airline->icao_code ?: '—' }}</td><td class="tm-code">{{ $airline->accounting_prefix ?: '—' }}</td><td>{{ $airline->country_code ?: '—' }}</td><td>{{ $airline->defaultVendor?->display_name ?: 'No default' }}</td><td class="tm-usage">{{ $usage['airlines'][(int)$airline->id]??0 }}</td><td><span class="tm-badge {{ $airline->is_active?'on':'off' }}">{{ $airline->is_active?'ACTIVE':'INACTIVE' }}</span></td>
        <td class="tm-actions">@if($canManage)<details><summary>Edit</summary><div class="tm-edit"><form method="POST" action="{{ route('master-data.airlines.update',$airline) }}" class="tm-form">@csrf @method('PUT')
            <label class="tm-field"><span>Internal Code *</span><input name="code" value="{{ $airline->code }}" required></label><label class="tm-field"><span>Name *</span><input name="name" value="{{ $airline->name }}" required></label><label class="tm-field"><span>IATA</span><input name="iata_code" maxlength="2" value="{{ $airline->iata_code }}"></label><label class="tm-field"><span>ICAO</span><input name="icao_code" maxlength="3" value="{{ $airline->icao_code }}"></label><label class="tm-field"><span>Accounting Prefix</span><input name="accounting_prefix" maxlength="3" pattern="[0-9]{3}" value="{{ $airline->accounting_prefix }}" {{ !$airlineAccountingPrefixInstalled?'disabled':'' }}></label><label class="tm-field"><span>Country</span><input name="country_code" maxlength="2" value="{{ $airline->country_code }}"></label><label class="tm-field"><span>Default Vendor</span><select name="default_vendor_party_id"><option value="">No default</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" {{ (int)$airline->default_vendor_party_id===(int)$vendor->id?'selected':'' }}>{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label><label class="tm-field"><span>Notes</span><input name="notes" value="{{ $airline->notes }}"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" {{ $airline->is_active?'checked':'' }}> Active</label><div><button class="tm-btn primary">Save Changes</button></div>
        </form></div></details>@endif</td>
    </tr>@empty<tr><td colspan="9" class="tm-empty">No airline records found.</td></tr>@endforelse
    </tbody></table></div>
    @if(method_exists($airlines,'hasPages') && $airlines->hasPages())
    <div class="tm-pager">
        <div>@if($airlines->onFirstPage())<span class="tm-page-btn">← Previous</span>@else<a href="{{ $airlines->previousPageUrl() }}">← Previous</a>@endif</div>
        <strong>Page {{ $airlines->currentPage() }} of {{ $airlines->lastPage() }} · {{ number_format($airlines->total()) }} airlines</strong>
        <div>@if($airlines->hasMorePages())<a href="{{ $airlines->nextPageUrl() }}">Next →</a>@else<span class="tm-page-btn">Next →</span>@endif</div>
    </div>
    @endif
</div>
@endif

@if($tab==='booking-sources')
<div class="tm-card"><div class="tm-card-head"><div><h3>Booking Sources / Backends</h3><p>Sabre, Amadeus, Galileo, NDC, airline APIs, aggregators or manual entry. Source identity never determines the accounting vendor automatically.</p></div></div>
@if($canManage)<div class="tm-body"><form method="POST" action="{{ route('master-data.booking-sources.store') }}" class="tm-form">@csrf<label class="tm-field"><span>Code *</span><input name="code" required placeholder="SABRE"></label><label class="tm-field"><span>Name *</span><input name="name" required placeholder="Sabre"></label><label class="tm-field"><span>Type *</span><select name="source_type">@foreach(['GDS','NDC','AIRLINE_API','AGGREGATOR','MANUAL','OTHER'] as $v)<option>{{ $v }}</option>@endforeach</select></label><label class="tm-field"><span>Default Vendor</span><select name="default_vendor_party_id"><option value="">No default supplier</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label><label class="tm-field tm-span2"><span>Notes</span><input name="notes"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" checked> Active</label><div><button class="tm-btn success">+ Add Booking Source</button></div></form></div>@endif
<div style="overflow:auto"><table class="tm-table"><thead><tr><th>Code / Source</th><th>Type</th><th>Default Vendor</th><th>Status</th><th>Manage</th></tr></thead><tbody>@forelse($bookingSources as $source)<tr><td><span class="tm-code">{{ $source->code }}</span><strong style="display:block">{{ $source->name }}</strong></td><td>{{ $source->source_type }}</td><td>{{ $source->defaultVendor?->display_name ?: 'No default' }}</td><td><span class="tm-badge {{ $source->is_active?'on':'off' }}">{{ $source->is_active?'ACTIVE':'INACTIVE' }}</span></td><td class="tm-actions">@if($canManage)<details><summary>Edit</summary><div class="tm-edit"><form method="POST" action="{{ route('master-data.booking-sources.update',$source) }}" class="tm-form">@csrf @method('PUT')<label class="tm-field"><span>Code *</span><input name="code" value="{{ $source->code }}" required></label><label class="tm-field"><span>Name *</span><input name="name" value="{{ $source->name }}" required></label><label class="tm-field"><span>Type *</span><select name="source_type">@foreach(['GDS','NDC','AIRLINE_API','AGGREGATOR','MANUAL','OTHER'] as $v)<option {{ $source->source_type===$v?'selected':'' }}>{{ $v }}</option>@endforeach</select></label><label class="tm-field"><span>Default Vendor</span><select name="default_vendor_party_id"><option value="">No default</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" {{ (int)$source->default_vendor_party_id===(int)$vendor->id?'selected':'' }}>{{ $vendor->code }} · {{ $vendor->display_name }}</option>@endforeach</select></label><label class="tm-field tm-span2"><span>Notes</span><input name="notes" value="{{ $source->notes }}"></label><label class="tm-check"><input type="checkbox" name="is_active" value="1" {{ $source->is_active?'checked':'' }}> Active</label><div><button class="tm-btn primary">Save Changes</button></div></form></div></details>@endif</td></tr>@empty<tr><td colspan="5" class="tm-empty">No booking-source records found.</td></tr>@endforelse</tbody></table></div></div>
@endif


@if($tab==='vehicle-types')
<div class="tm-card"><div class="tm-card-head"><div><h3>Vehicle Types</h3><p>Reusable transport vehicle master. The initial Umrah set is seeded automatically; add more types when required.</p></div></div>
@if($canManage && $transportRateMasterUpgradeInstalled)<div class="tm-body"><form method="POST" action="{{ route('master-data.transport-vehicle-types.store') }}" class="tm-form">@csrf
<label class="tm-field"><span>Code</span><input name="code" placeholder="HIACE"></label><label class="tm-field"><span>Vehicle Type *</span><input name="name" required placeholder="Hiace"></label><label class="tm-field"><span>Min Pax</span><input type="number" min="1" name="min_pax"></label><label class="tm-field"><span>Max Pax</span><input type="number" min="1" name="max_pax"></label><label class="tm-field tm-span2"><span>Notes</span><input name="notes"></label><div><button class="tm-btn success">+ Add Vehicle Type</button></div>
</form></div>@endif
<div style="overflow:auto"><table class="tm-table"><thead><tr><th>Code</th><th>Vehicle Type</th><th>Capacity</th><th>Status</th></tr></thead><tbody>@forelse($vehicleTypes as $v)<tr><td><span class="tm-code">{{ $v->code }}</span></td><td><strong>{{ $v->name }}</strong></td><td>{{ $v->min_pax && $v->max_pax ? $v->min_pax.'–'.$v->max_pax.' Pax' : ($v->max_pax ? 'Up to '.$v->max_pax.' Pax' : '—') }}</td><td><span class="tm-badge {{ $v->is_active?'on':'off' }}">{{ $v->is_active?'ACTIVE':'INACTIVE' }}</span></td></tr>@empty<tr><td colspan="4" class="tm-empty">No Vehicle Types found.</td></tr>@endforelse</tbody></table></div></div>
@endif

@if($tab==='transport-routes')
<div class="tm-card"><div class="tm-card-head"><div><h3>Transport Routes / Services</h3><p>Reusable Saudi transport sectors and Ziyarat services used by vendor rate cards and bookings.</p></div></div>
@if($canManage && $transportRateMasterUpgradeInstalled)<div class="tm-body"><form method="POST" action="{{ route('master-data.transport-routes.store') }}" class="tm-form">@csrf
<label class="tm-field"><span>Code</span><input name="code" placeholder="JED-MAK-HOTEL"></label><label class="tm-field"><span>Route / Service *</span><input name="name" required placeholder="JED Airport → Makkah Hotel"></label><label class="tm-field"><span>Pickup</span><input name="pickup_location"></label><label class="tm-field"><span>Drop-off</span><input name="dropoff_location"></label><label class="tm-field tm-span2"><span>Notes</span><input name="notes"></label><div><button class="tm-btn success">+ Add Route</button></div>
</form></div>@endif
<div style="overflow:auto"><table class="tm-table"><thead><tr><th>Code</th><th>Route / Service</th><th>Pickup</th><th>Drop-off</th><th>Status</th></tr></thead><tbody>@forelse($transportRoutes as $r)<tr><td><span class="tm-code">{{ $r->code }}</span></td><td><strong>{{ $r->name }}</strong></td><td>{{ $r->pickup_location ?: '—' }}</td><td>{{ $r->dropoff_location ?: '—' }}</td><td><span class="tm-badge {{ $r->is_active?'on':'off' }}">{{ $r->is_active?'ACTIVE':'INACTIVE' }}</span></td></tr>@empty<tr><td colspan="5" class="tm-empty">No Transport Routes found.</td></tr>@endforelse</tbody></table></div></div>
@endif

@if($tab==='transport-rates')
    @include('master-data.partials.transport-rate-cards')
@endif

@if($tab==='products')
    @include('master-data.partials.products-services')
@endif
<script>
document.addEventListener('keydown',function(e){if(e.key==='Escape'){document.querySelectorAll('.tm-actions details[open]').forEach(function(d){d.removeAttribute('open');});}});
document.addEventListener('click',function(e){var d=e.target.closest('.tm-actions details[open]');if(d && e.target===d){d.removeAttribute('open');}});
document.querySelectorAll('[data-product-category]').forEach(function(category){
    var form=category.closest('form'); if(!form)return;
    var costCurrency=form.querySelector('[data-cost-currency]'); if(!costCurrency)return;
    function syncCostCurrency(){var v=(category.value||'').toUpperCase();if(['VISA','HOTEL','TRANSPORT'].includes(v)){if(!costCurrency.value)costCurrency.value='SAR';}else if(costCurrency.value==='SAR' && !costCurrency.dataset.userChanged){costCurrency.value='';}}
    costCurrency.addEventListener('change',function(){costCurrency.dataset.userChanged='1';});
    category.addEventListener('change',syncCostCurrency); syncCostCurrency();
});
</script>
@endsection
