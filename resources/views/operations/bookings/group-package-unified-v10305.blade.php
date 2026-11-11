@extends($erpLayout)

@section($erpTitleSection, $bookingId ? 'Edit Group Package Booking' : 'Create Group Package Booking')

@section($erpContentSection)
@php
    $bookingRow = $existing['booking'] ?? [];
    $commercialRow = $existing['commercial'] ?? [];
    $bookingReference = $existing['booking_reference'] ?? null;

    $valueFrom = function (array $row, array $keys, $default = null) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }
        return $default;
    };

    $bookingDate = $valueFrom($bookingRow, ['booking_date', 'date'], now()->toDateString());
    $customerId = $valueFrom($bookingRow, ['customer_id', 'party_id', 'client_id']);
    $branchId = $valueFrom($bookingRow, ['branch_id', 'office_id']);
    $currencyCode = strtoupper((string) $valueFrom($commercialRow, ['currency_code'], $valueFrom($bookingRow, ['currency_code', 'currency'], 'PKR')));
    $packageCode = $valueFrom($commercialRow, ['package_code'], 'Auto-generated on Save');
    $packageName = $valueFrom($commercialRow, ['package_name'], '');
    $vendorId = $valueFrom($commercialRow, ['vendor_id']);
    $vendorPackageCode = $valueFrom($commercialRow, ['vendor_package_code'], '');
    $vendorVoucherNo = $valueFrom($commercialRow, ['vendor_voucher_no'], '');
    $notes = $valueFrom($commercialRow, ['notes'], $valueFrom($bookingRow, ['notes', 'remarks'], ''));
@endphp

<style>
/*
 * ERP-10.30.5 — native ERP shell + compact full-width booking rows.
 * This stylesheet is deliberately scoped to #gp-booking only.
 * It never styles the Easy Ticket sidebar/header/navigation.
 */
#gp-booking{
    --gp-blue:#1769d2;
    --gp-green:#15945b;
    --gp-red:#c83b3b;
    --gp-text:#17243a;
    --gp-muted:#6f7d90;
    --gp-line:#dfe7f0;
    --gp-soft:#eef5ff;
    --gp-success:#eaf8ef;
    width:100%;
    max-width:none;
    color:var(--gp-text);
    font-family:inherit;
}
#gp-booking *,#gp-booking *::before,#gp-booking *::after{box-sizing:border-box}

#gp-booking .gp-top{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:12px;margin:0 0 10px
}
#gp-booking .gp-kicker{
    font-size:9px;font-weight:800;letter-spacing:.06em;color:var(--gp-blue);
    text-transform:uppercase;margin-bottom:3px
}
#gp-booking h1{font-size:20px;line-height:1.15;margin:0 0 3px;color:var(--gp-text)}
#gp-booking .gp-sub{font-size:11px;color:var(--gp-muted)}
#gp-booking .gp-ref{
    display:inline-block;margin-top:5px;border-radius:999px;background:var(--gp-soft);
    color:var(--gp-blue);padding:3px 8px;font-size:9px;font-weight:800
}

#gp-booking .gp-card{
    width:100%;background:#fff;border:1px solid var(--gp-line);
    border-radius:8px;margin-bottom:8px;overflow:hidden;
    box-shadow:0 1px 2px rgba(20,35,55,.02)
}
#gp-booking .gp-card-head{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:8px 10px;border-bottom:1px solid #e8edf3
}
#gp-booking .gp-card-title{font-size:13px;font-weight:800;margin:0}
#gp-booking .gp-card-note{font-size:9px;color:var(--gp-muted);margin-top:2px}
#gp-booking .gp-card-body{padding:8px 10px}

#gp-booking .gp-grid{
    display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:7px
}
#gp-booking .gp-c12{grid-column:span 12}
#gp-booking .gp-c8{grid-column:span 8}
#gp-booking .gp-c6{grid-column:span 6}
#gp-booking .gp-c4{grid-column:span 4}
#gp-booking .gp-c3{grid-column:span 3}
#gp-booking .gp-c2{grid-column:span 2}

#gp-booking .gp-field label{
    display:block;font-size:8px;font-weight:800;color:#4b5a70;margin:0 0 3px;
    text-transform:uppercase;letter-spacing:.02em;white-space:nowrap
}
#gp-booking .gp-input,#gp-booking .gp-select{
    display:block;width:100%;min-width:0;height:30px;
    border:1px solid #d3deea;border-radius:5px;background:#fff;
    padding:4px 6px;font:inherit;font-size:10px;color:var(--gp-text);outline:none
}
#gp-booking .gp-input:focus,#gp-booking .gp-select:focus{
    border-color:#70a8e5;box-shadow:0 0 0 2px rgba(23,105,210,.07)
}
#gp-booking .gp-input[readonly]{background:#f6f8fb;color:#526176}

#gp-booking .gp-commercial{
    display:grid;grid-template-columns:repeat(8,minmax(82px,1fr));gap:7px
}
#gp-booking .gp-commercial .gp-value{font-weight:800;color:var(--gp-green)}

#gp-booking .gp-actions{display:flex;align-items:center;flex-wrap:wrap;gap:5px}
#gp-booking .gp-btn{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:29px;padding:5px 9px;border:1px solid transparent;border-radius:5px;
    background:#eef2f7;color:#2b3a50;font:inherit;font-size:9px;font-weight:800;
    cursor:pointer;text-decoration:none;white-space:nowrap
}
#gp-booking .gp-btn-blue{background:var(--gp-blue);color:#fff}
#gp-booking .gp-btn-green{background:var(--gp-green);color:#fff}
#gp-booking .gp-btn-red{background:#fff1f1;color:var(--gp-red);border-color:#f2cece}
#gp-booking .gp-btn-outline{background:#fff;color:var(--gp-blue);border-color:#96bae6}

#gp-booking .gp-badge{
    display:inline-flex;align-items:center;border-radius:999px;padding:3px 6px;
    font-size:8px;font-weight:800;background:#e9f7ef;color:#147849
}
#gp-booking .gp-info{
    padding:7px 9px;border-radius:6px;background:var(--gp-soft);
    color:#2b5f9d;font-size:9px
}
#gp-booking .gp-package-note{
    padding:7px 9px;border-radius:6px;background:var(--gp-success);
    color:#217248;font-size:9px;margin-top:7px
}
#gp-booking .gp-empty{
    padding:9px;border:1px dashed #d9e2ec;border-radius:6px;color:#7b8797;
    font-size:9px;text-align:center;background:#fbfcfe
}

/* Keep every data-entry item as ONE horizontal row.
   Narrow screens scroll the row horizontally instead of turning it into
   a tall card/stacked form. */
#gp-booking .gp-table-scroll{
    width:100%;overflow-x:auto;overflow-y:hidden;padding-bottom:2px;
    scrollbar-width:thin
}
#gp-booking .gp-row-head{
    display:grid;align-items:center;gap:5px;padding:0 0 4px;
    border-bottom:1px solid #e6ecf2;color:#536176;
    font-size:7.5px;font-weight:800;text-transform:uppercase;
    letter-spacing:.02em
}
#gp-booking .gp-data-row{
    display:grid;align-items:center;gap:5px;padding:6px 0;
    border-bottom:1px solid #edf1f5
}
#gp-booking .gp-data-row:last-child{border-bottom:0}
#gp-booking .gp-data-row .gp-input,#gp-booking .gp-data-row .gp-select{
    height:29px;font-size:9px;padding:4px 5px
}
#gp-booking .gp-data-row .gp-btn{height:29px;padding:4px 6px;width:100%}

#gp-booking .gp-passenger-grid{
    grid-template-columns:62px 105px 105px 108px 108px 98px 78px 115px 58px;
    min-width:900px
}
#gp-booking .gp-flight-grid{
    grid-template-columns:74px 70px 70px 100px 68px 104px 68px 104px 68px 82px 88px 82px 58px;
    min-width:1110px
}
#gp-booking .gp-hotel-grid{
    grid-template-columns:82px 145px 104px 104px 90px 90px 58px 110px 135px 58px;
    min-width:1040px
}
#gp-booking .gp-transport-grid{
    grid-template-columns:135px 135px 88px 104px 70px 90px 115px 140px 58px;
    min-width:990px
}
#gp-booking .gp-service-grid{
    grid-template-columns:150px 190px 58px 92px 175px 58px;
    min-width:810px
}
#gp-booking .gp-type{
    font-size:8px;font-weight:800;text-transform:capitalize;border-radius:999px;
    text-align:center;padding:4px 5px;background:#e9f2ff;color:#1769d2
}
#gp-booking .gp-type.inbound{background:#e8f7ee;color:#137a49}
#gp-booking .gp-type.connection{background:#fff3da;color:#8c6100}

#gp-booking .gp-search-panel{
    display:none;margin:0 0 7px;padding:8px;border:1px solid #dfe7f0;
    border-radius:7px;background:#fafcff
}
#gp-booking .gp-search-results{
    display:grid;grid-template-columns:repeat(2,minmax(0,1fr));
    gap:5px;margin-top:6px;max-height:190px;overflow:auto
}
#gp-booking .gp-search-item{
    display:flex;align-items:center;justify-content:space-between;gap:6px;
    padding:6px 7px;border:1px solid #e1e8f0;border-radius:6px;background:#fff;cursor:pointer
}
#gp-booking .gp-search-item:hover{border-color:#91b7e4;background:#f6faff}
#gp-booking .gp-search-name{font-size:9px;font-weight:800}
#gp-booking .gp-search-meta{font-size:8px;color:#748196;margin-top:2px}

#gp-booking .gp-review{
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px
}
#gp-booking .gp-review-box{
    border:1px solid #e0e7ef;border-radius:6px;padding:7px;background:#fbfcfe
}
#gp-booking .gp-review-label{font-size:8px;color:#6b798c}
#gp-booking .gp-review-value{font-size:15px;font-weight:800;margin-top:1px}
#gp-booking .gp-savebar{
    display:flex;align-items:center;justify-content:flex-end;gap:6px;margin-top:8px
}
#gp-booking .gp-savebar .gp-btn{min-width:120px;height:33px}

#gp-booking .gp-alert{
    display:none;margin-bottom:8px;padding:8px 10px;border-radius:6px;font-size:10px
}
#gp-booking .gp-alert.ok{display:block;background:#e9f8ef;color:#176b43}
#gp-booking .gp-alert.error{display:block;background:#fff0f0;color:#9f2929}
#gp-booking .gp-alert.warn{display:block;background:#fff7e5;color:#815c0c}
#gp-booking .gp-saving{opacity:.65;pointer-events:none}

@media(max-width:1050px){
    #gp-booking .gp-commercial{
        grid-template-columns:repeat(4,minmax(95px,1fr))
    }
}
@media(max-width:760px){
    #gp-booking .gp-top{display:block}
    #gp-booking .gp-top>a{margin-top:6px}
    #gp-booking .gp-commercial{grid-template-columns:repeat(2,minmax(100px,1fr))}
    #gp-booking .gp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    #gp-booking .gp-c12,#gp-booking .gp-c8,#gp-booking .gp-c6,
    #gp-booking .gp-c4,#gp-booking .gp-c3,#gp-booking .gp-c2{grid-column:span 1}
    #gp-booking .gp-search-results,#gp-booking .gp-review{grid-template-columns:1fr}
}
</style>

<div id="gp-booking">
    <div class="gp-top">
        <div>
            <div class="gp-kicker">Group Package Booking · ERP-10.30.5</div>
            <h1>{{ $bookingId ? 'Edit Group Package Booking' : 'Create Group Package Booking' }}</h1>
            <div class="gp-sub">One simple page. Add all details, then save the complete booking together.</div>
            <span class="gp-ref" id="gp-booking-ref">{{ $bookingReference ?: 'New Booking · Not Saved' }}</span>
        </div>
        <a class="gp-btn gp-btn-outline" href="{{ url('/operations/bookings/create') }}">← Back to Create Booking</a>
    </div>

    <div id="gp-alert" class="gp-alert"></div>

    <form id="gp-form" action="{{ route('operations.bookings.group-package-unified.store') }}" data-update-url="{{ $bookingId ? route('operations.bookings.group-package-unified.update', $bookingId) : '' }}">
        @csrf
        <input type="hidden" name="save_mode" value="complete" id="gp-save-mode">
        <input type="hidden" name="booking_type" value="UMRAH">

        <section class="gp-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">Commercial Summary</div><div class="gp-card-note">Package-level pricing only. Child Flight, Hotel, Transport and Service rows are operational/voucher data.</div></div>
            </div>
            <div class="gp-card-body">
                <div class="gp-commercial">
                    <div class="gp-field"><label>Sale Price</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="package_sale_price" value="{{ $commercialRow['package_sale_price'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Supplier Cost</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="supplier_cost" value="{{ $commercialRow['supplier_cost'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Discount Type</label><select class="gp-select gp-commercial-input" name="discount_type"><option value="none" @selected(($commercialRow['discount_type'] ?? 'none') === 'none')>None</option><option value="fixed" @selected(($commercialRow['discount_type'] ?? '') === 'fixed')>Fixed</option><option value="percent" @selected(($commercialRow['discount_type'] ?? '') === 'percent')>Percent</option></select></div>
                    <div class="gp-field"><label>Discount Value</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="discount_value" value="{{ $commercialRow['discount_value'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Agent Commission</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="agent_commission" value="{{ $commercialRow['agent_commission'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Salesperson Commission</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="salesperson_commission" value="{{ $commercialRow['salesperson_commission'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Final Sale</label><input class="gp-input gp-value" id="gp-final-sale" readonly value="{{ number_format((float)($commercialRow['final_sale_price'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><label>Net Margin</label><input class="gp-input gp-value" id="gp-net-margin" readonly value="{{ number_format((float)($commercialRow['net_margin'] ?? 0), 2, '.', '') }}"></div>
                </div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head"><div><div class="gp-card-title">1. Booking Information</div></div></div>
            <div class="gp-card-body">
                <div class="gp-grid">
                    <div class="gp-field gp-c2"><label>Booking Date *</label><input class="gp-input" type="date" name="booking_date" value="{{ $bookingDate }}" required></div>
                    <div class="gp-field gp-c2"><label>Branch</label><select class="gp-select" name="branch_id"><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch['id'] }}" @selected((string)$branchId === (string)$branch['id'])>{{ $branch['name'] }}</option>@endforeach</select></div>
                    <div class="gp-field gp-c3"><label>Customer / Party *</label><select class="gp-select" name="customer_id" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer['id'] }}" @selected((string)$customerId === (string)$customer['id'])>{{ $customer['name'] }}</option>@endforeach</select></div>
                    <div class="gp-field gp-c2"><label>Currency *</label><select class="gp-select" name="currency_code">@foreach($currencies as $currency)<option value="{{ $currency['code'] }}" @selected($currencyCode === strtoupper($currency['code']))>{{ $currency['code'] }} - {{ $currency['name'] }}</option>@endforeach</select></div>
                    <div class="gp-field gp-c3"><label>Notes</label><input class="gp-input" type="text" name="notes" value="{{ $notes }}" placeholder="Optional booking note"></div>
                </div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head"><div><div class="gp-card-title">2. Package Details</div><div class="gp-card-note">Package is created inside this booking. No pre-created package is required.</div></div></div>
            <div class="gp-card-body">
                <div class="gp-grid">
                    <div class="gp-field gp-c3"><label>Package Name *</label><input class="gp-input" type="text" name="package_name" value="{{ $packageName }}" placeholder="e.g. 15 Days Umrah Package"></div>
                    <div class="gp-field gp-c2"><label>Our Package Code</label><input class="gp-input" id="gp-package-code" value="{{ $packageCode }}" readonly></div>
                    <div class="gp-field gp-c3"><label>Vendor / Supplier *</label><select class="gp-select" name="vendor_id"><option value="">Select vendor / supplier</option>@foreach($vendors as $vendor)<option value="{{ $vendor['id'] }}" @selected((string)$vendorId === (string)$vendor['id'])>{{ $vendor['name'] }}</option>@endforeach</select></div>
                    <div class="gp-field gp-c2"><label>Vendor Package Code</label><input class="gp-input" type="text" name="vendor_package_code" value="{{ $vendorPackageCode }}" placeholder="Manual"></div>
                    <div class="gp-field gp-c2"><label>Vendor Voucher / Booking No.</label><input class="gp-input" type="text" name="vendor_voucher_no" value="{{ $vendorVoucherNo }}" placeholder="Can be added later"></div>
                </div>
                <div class="gp-package-note">✓ All package commercial values are controlled above. No separate Hotel, Transport or Other Service pricing is used on this page.</div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">3. Passengers</div><div class="gp-card-note">Reuse saved passengers from the ERP database or add a new passenger. Ticket number belongs to this booking only.</div></div>
                <div class="gp-actions"><button type="button" class="gp-btn gp-btn-outline" id="gp-reuse-passenger">Reuse Existing Passenger</button><button type="button" class="gp-btn gp-btn-blue" id="gp-add-passenger">+ New Passenger</button></div>
            </div>
            <div class="gp-card-body">
                <div class="gp-search-panel" id="gp-passenger-search-panel">
                    <div class="gp-grid"><div class="gp-field gp-c6"><label>Find Saved Passenger</label><input class="gp-input" type="search" id="gp-passenger-search" placeholder="Search by name, passport or date of birth"></div></div>
                    <div class="gp-search-results" id="gp-passenger-results"></div>
                </div>
                <div class="gp-table-scroll"><div class="gp-row-head gp-passenger-grid"><div>Title</div><div>First Name</div><div>Last Name</div><div>DOB</div><div>Passport</div><div>Nationality</div><div>Fare</div><div>Ticket No.</div><div></div></div>
                <div id="gp-passenger-rows"></div></div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">4. Flights</div><div class="gp-card-note">Enter outbound, inbound and any connection sectors. Inbound automatically reverses the latest outbound route.</div></div>
                <div class="gp-actions"><button type="button" class="gp-btn gp-btn-blue" data-add-flight="outbound">+ Outbound</button><button type="button" class="gp-btn gp-btn-green" data-add-flight="inbound">+ Inbound</button><button type="button" class="gp-btn" data-add-flight="connection">+ Connection</button></div>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-flight-grid"><div>Type</div><div>From</div><div>To</div><div>Airline</div><div>Flight</div><div>Depart Date</div><div>Time</div><div>Arrival Date</div><div>Time</div><div>PNR</div><div>Cabin</div><div>Baggage</div><div></div></div>
                <div id="gp-flight-rows"></div></div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">5. Hotel Stays <span class="gp-badge">Included in Package</span></div><div class="gp-card-note">Add as many hotel rows as required. Accommodation details only; no commercial fields.</div></div>
                <button type="button" class="gp-btn gp-btn-blue" id="gp-add-hotel">+ Add Hotel Stay</button>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-hotel-grid"><div>City</div><div>Hotel</div><div>Check In</div><div>Check Out</div><div>Room Type</div><div>Meal Plan</div><div>Rooms</div><div>Confirmation</div><div>Notes</div><div></div></div>
                <div id="gp-hotel-rows"></div></div><div class="gp-empty" id="gp-hotel-empty">No hotel stay added yet.</div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">6. Transport <span class="gp-badge">Included in Package</span></div><div class="gp-card-note">Add movement rows only. No separate transport accounting for Group Package bookings.</div></div>
                <button type="button" class="gp-btn gp-btn-blue" id="gp-add-transport">+ Add Transport</button>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-transport-grid"><div>From</div><div>To</div><div>Vehicle</div><div>Pickup Date</div><div>Time</div><div>Status</div><div>Vendor Ref / BRN</div><div>Notes</div><div></div></div>
                <div id="gp-transport-rows"></div></div><div class="gp-empty" id="gp-transport-empty">No transport movement added yet.</div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">7. Other Services / Products <span class="gp-badge">Included in Package</span></div><div class="gp-card-note">Optional operational/voucher items such as Ziyarat, SIM, meals or other package inclusions.</div></div>
                <button type="button" class="gp-btn gp-btn-blue" id="gp-add-service">+ Add Service / Product</button>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-service-grid"><div>Service / Product</div><div>Details</div><div>Qty</div><div>Status</div><div>Notes</div><div></div></div>
                <div id="gp-service-rows"></div></div><div class="gp-empty" id="gp-service-empty">No other service added yet.</div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head"><div><div class="gp-card-title">8. Review & Save</div><div class="gp-card-note">Add/Remove changes this page only. Nothing is written to the database until Save Draft or Save All Booking is pressed.</div></div></div>
            <div class="gp-card-body">
                <div class="gp-review">
                    <div class="gp-review-box"><div class="gp-review-label">Passengers</div><div class="gp-review-value" id="gp-count-passengers">0</div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Flights</div><div class="gp-review-value" id="gp-count-flights">0</div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Hotel Stays</div><div class="gp-review-value" id="gp-count-hotels">0</div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Transport</div><div class="gp-review-value" id="gp-count-transports">0</div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Other Services</div><div class="gp-review-value" id="gp-count-services">0</div></div>
                </div>
                <div class="gp-info" style="margin-top:9px">All rows above are saved together in one database transaction.</div>
                <div class="gp-savebar"><a class="gp-btn" href="{{ url('/operations/bookings/create') }}">Cancel</a><button type="button" class="gp-btn gp-btn-outline" data-save="draft">Save Draft</button><button type="button" class="gp-btn gp-btn-green" data-save="complete">Save All Booking</button></div>
            </div>
        </section>
    </form>

    <datalist id="gp-airline-list">@foreach($airlineOptions as $airline)<option value="{{ $airline['name'] }}">{{ $airline['code'] }}</option>@endforeach</datalist>
    <datalist id="gp-hotel-list">@foreach($hotelOptions as $hotel)<option value="{{ $hotel['name'] }}">{{ $hotel['city'] }}</option>@endforeach</datalist>
</div>

<script>
(function(){
    const root = document.getElementById('gp-booking');
    const form = document.getElementById('gp-form');
    if (!root || !form) return;

    const existingPassengers = @json(array_values($existing['passengers'] ?? []));
    const existingFlights = @json(array_values($existing['flights'] ?? []));
    const existingHotels = @json(array_values($existing['hotels'] ?? []));
    const existingTransports = @json(array_values($existing['transports'] ?? []));
    const existingServices = @json(array_values($existing['services'] ?? []));
    const savedPassengerOptions = @json($passengerOptions->values());
    const hotelOptions = @json($hotelOptions->values());

    let passengerIndex = 0, flightIndex = 0, hotelIndex = 0, transportIndex = 0, serviceIndex = 0;

    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const selectOptions = (values, selected) => values.map(v => `<option value="${esc(v)}" ${String(v).toLowerCase()===String(selected??'').toLowerCase()?'selected':''}>${esc(v)}</option>`).join('');
    const rowCount = (id) => document.querySelectorAll(`#${id} .gp-data-row`).length;

    function updateCounts(){
        document.getElementById('gp-count-passengers').textContent = rowCount('gp-passenger-rows');
        document.getElementById('gp-count-flights').textContent = rowCount('gp-flight-rows');
        document.getElementById('gp-count-hotels').textContent = rowCount('gp-hotel-rows');
        document.getElementById('gp-count-transports').textContent = rowCount('gp-transport-rows');
        document.getElementById('gp-count-services').textContent = rowCount('gp-service-rows');
        document.getElementById('gp-hotel-empty').style.display = rowCount('gp-hotel-rows') ? 'none' : '';
        document.getElementById('gp-transport-empty').style.display = rowCount('gp-transport-rows') ? 'none' : '';
        document.getElementById('gp-service-empty').style.display = rowCount('gp-service-rows') ? 'none' : '';
    }

    function bindRemove(row){
        row.querySelector('[data-remove]')?.addEventListener('click', () => { row.remove(); updateCounts(); });
    }

    function addPassenger(data={}){
        const i = passengerIndex++;
        const row = document.createElement('div');
        row.className = 'gp-data-row gp-passenger-grid';
        row.innerHTML = `
            <input type="hidden" name="passengers[${i}][passenger_id]" value="${esc(data.passenger_id ?? data.id ?? '')}">
            <input type="hidden" name="passengers[${i}][passenger_source]" value="${esc(data.passenger_source ?? data.source_table ?? '')}">
            <select class="gp-select" name="passengers[${i}][title]">${selectOptions(['Mr','Mrs','Ms','Miss','Master'], data.title || 'Mr')}</select>
            <input class="gp-input" name="passengers[${i}][first_name]" value="${esc(data.first_name)}" placeholder="First name">
            <input class="gp-input" name="passengers[${i}][last_name]" value="${esc(data.last_name)}" placeholder="Last name">
            <input class="gp-input" type="date" name="passengers[${i}][date_of_birth]" value="${esc(data.date_of_birth)}">
            <input class="gp-input" name="passengers[${i}][passport_no]" value="${esc(data.passport_no)}" placeholder="Passport no.">
            <input class="gp-input" name="passengers[${i}][nationality]" value="${esc(data.nationality)}" placeholder="Nationality">
            <select class="gp-select" name="passengers[${i}][fare_as]">${selectOptions(['ADULT','CHILD','INFANT'], String(data.fare_as || 'ADULT').toUpperCase())}</select>
            <input class="gp-input" name="passengers[${i}][ticket_number]" value="${esc(data.ticket_number)}" placeholder="Ticket no.">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>`;
        document.getElementById('gp-passenger-rows').appendChild(row);
        bindRemove(row); updateCounts();
    }

    function latestOutbound(){
        const rows = Array.from(document.querySelectorAll('#gp-flight-rows .gp-data-row'));
        const outbound = rows.filter(r => r.dataset.type === 'outbound').pop() || rows[0];
        if (!outbound) return null;
        const value = key => outbound.querySelector(`[data-key="${key}"]`)?.value || '';
        return {from_code:value('from_code'),to_code:value('to_code'),airline_name:value('airline_name'),pnr:value('pnr'),cabin_class:value('cabin_class'),baggage:value('baggage')};
    }

    function addFlight(type='outbound', data={}){
        const i = flightIndex++;
        if (type === 'inbound' && !Object.keys(data).length) {
            const o = latestOutbound();
            if (o) data = {...o, from_code:o.to_code, to_code:o.from_code};
        }
        const row = document.createElement('div');
        row.className = 'gp-data-row gp-flight-grid'; row.dataset.type = type;
        const typeLabel = type === 'inbound' ? 'Inbound' : type.charAt(0).toUpperCase()+type.slice(1);
        row.innerHTML = `
            <input type="hidden" name="flights[${i}][segment_type]" value="${esc(type)}">
            <div class="gp-type ${esc(type)}">${esc(typeLabel)}</div>
            <input class="gp-input" data-key="from_code" name="flights[${i}][from_code]" value="${esc(data.from_code)}" placeholder="LHE">
            <input class="gp-input" data-key="to_code" name="flights[${i}][to_code]" value="${esc(data.to_code)}" placeholder="JED">
            <input class="gp-input" list="gp-airline-list" data-key="airline_name" name="flights[${i}][airline_name]" value="${esc(data.airline_name)}" placeholder="Airline">
            <input class="gp-input" name="flights[${i}][flight_number]" value="${esc(data.flight_number)}" placeholder="SV739">
            <input class="gp-input" type="date" name="flights[${i}][departure_date]" value="${esc(data.departure_date)}">
            <input class="gp-input" type="time" name="flights[${i}][departure_time]" value="${esc((data.departure_time || '').slice(0,5))}">
            <input class="gp-input" type="date" name="flights[${i}][arrival_date]" value="${esc(data.arrival_date)}">
            <input class="gp-input" type="time" name="flights[${i}][arrival_time]" value="${esc((data.arrival_time || '').slice(0,5))}">
            <input class="gp-input" data-key="pnr" name="flights[${i}][pnr]" value="${esc(data.pnr)}" placeholder="PNR">
            <select class="gp-select" data-key="cabin_class" name="flights[${i}][cabin_class]">${selectOptions(['Economy','Premium Economy','Business','First'], data.cabin_class || 'Economy')}</select>
            <input class="gp-input" data-key="baggage" name="flights[${i}][baggage]" value="${esc(data.baggage || '30 KG')}" placeholder="30 KG">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>
            <input type="hidden" name="flights[${i}][status]" value="${esc(data.status || 'booked')}">`;
        document.getElementById('gp-flight-rows').appendChild(row);
        bindRemove(row); updateCounts();
    }

    function addHotel(data={}){
        const i = hotelIndex++;
        const row = document.createElement('div'); row.className='gp-data-row gp-hotel-grid';
        row.innerHTML = `
            <input class="gp-input" name="hotels[${i}][city]" value="${esc(data.city)}" placeholder="Makkah">
            <input class="gp-input" list="gp-hotel-list" name="hotels[${i}][hotel_name]" value="${esc(data.hotel_name)}" placeholder="Hotel name" data-hotel-name>
            <input class="gp-input" type="date" name="hotels[${i}][check_in]" value="${esc(data.check_in)}">
            <input class="gp-input" type="date" name="hotels[${i}][check_out]" value="${esc(data.check_out)}">
            <select class="gp-select" name="hotels[${i}][room_type]">${selectOptions(['Single','Double','Triple','Quad','Quint','Sharing'], data.room_type || 'Double')}</select>
            <select class="gp-select" name="hotels[${i}][meal_plan]">${selectOptions(['Room Only','Breakfast','Half Board','Full Board'], data.meal_plan || 'Room Only')}</select>
            <input class="gp-input" type="number" min="1" name="hotels[${i}][rooms]" value="${esc(data.rooms || 1)}">
            <input class="gp-input" name="hotels[${i}][confirmation_no]" value="${esc(data.confirmation_no)}" placeholder="Confirmation">
            <input class="gp-input" name="hotels[${i}][notes]" value="${esc(data.notes)}" placeholder="Optional note">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>
            <input type="hidden" name="hotels[${i}][status]" value="${esc(data.status || 'requested')}">`;
        document.getElementById('gp-hotel-rows').appendChild(row);
        row.querySelector('[data-hotel-name]')?.addEventListener('change', e => {
            const match = hotelOptions.find(h => String(h.name).toLowerCase() === String(e.target.value).toLowerCase());
            if (match && !row.querySelector(`[name="hotels[${i}][city]"]`).value) row.querySelector(`[name="hotels[${i}][city]"]`).value = match.city || '';
        });
        bindRemove(row); updateCounts();
    }

    function addTransport(data={}){
        const i = transportIndex++;
        const row = document.createElement('div'); row.className='gp-data-row gp-transport-grid';
        row.innerHTML = `
            <input class="gp-input" name="transports[${i}][from_location]" value="${esc(data.from_location)}" placeholder="JED Airport">
            <input class="gp-input" name="transports[${i}][to_location]" value="${esc(data.to_location)}" placeholder="Makkah Hotel">
            <input class="gp-input" name="transports[${i}][vehicle_type]" value="${esc(data.vehicle_type)}" placeholder="Bus / Coaster">
            <input class="gp-input" type="date" name="transports[${i}][pickup_date]" value="${esc(data.pickup_date)}">
            <input class="gp-input" type="time" name="transports[${i}][pickup_time]" value="${esc((data.pickup_time || '').slice(0,5))}">
            <select class="gp-select" name="transports[${i}][status]">${selectOptions(['requested','booked','confirmed','completed'], data.status || 'requested')}</select>
            <input class="gp-input" name="transports[${i}][provider_reference]" value="${esc(data.provider_reference)}" placeholder="Vendor Ref / BRN">
            <input class="gp-input" name="transports[${i}][notes]" value="${esc(data.notes)}" placeholder="Optional note">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>`;
        document.getElementById('gp-transport-rows').appendChild(row); bindRemove(row); updateCounts();
    }

    function addService(data={}){
        const i = serviceIndex++;
        const row = document.createElement('div'); row.className='gp-data-row gp-service-grid';
        row.innerHTML = `
            <input class="gp-input" name="services[${i}][service_name]" value="${esc(data.service_name)}" placeholder="Ziyarat / SIM / Other">
            <input class="gp-input" name="services[${i}][details]" value="${esc(data.details)}" placeholder="Service details">
            <input class="gp-input" type="number" min="1" name="services[${i}][quantity]" value="${esc(data.quantity || 1)}">
            <select class="gp-select" name="services[${i}][status]">${selectOptions(['included','requested','booked','confirmed'], data.status || 'included')}</select>
            <input class="gp-input" name="services[${i}][notes]" value="${esc(data.notes)}" placeholder="Optional note">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>`;
        document.getElementById('gp-service-rows').appendChild(row); bindRemove(row); updateCounts();
    }

    function calculateCommercial(){
        const num = name => Number(form.querySelector(`[name="${name}"]`)?.value || 0);
        const sale = num('package_sale_price'), discountValue = num('discount_value');
        const type = form.querySelector('[name="discount_type"]')?.value || 'none';
        const discount = type === 'percent' ? sale * Math.min(discountValue,100)/100 : type === 'fixed' ? Math.min(discountValue,sale) : 0;
        const finalSale = Math.max(0,sale-discount);
        const margin = finalSale-num('supplier_cost')-num('agent_commission')-num('salesperson_commission');
        document.getElementById('gp-final-sale').value = finalSale.toFixed(2);
        document.getElementById('gp-net-margin').value = margin.toFixed(2);
    }

    document.querySelectorAll('.gp-commercial-input').forEach(el => el.addEventListener('input', calculateCommercial));
    document.querySelectorAll('.gp-commercial-input').forEach(el => el.addEventListener('change', calculateCommercial));

    document.getElementById('gp-add-passenger').addEventListener('click', () => addPassenger());
    document.querySelectorAll('[data-add-flight]').forEach(btn => btn.addEventListener('click', () => addFlight(btn.dataset.addFlight)));
    document.getElementById('gp-add-hotel').addEventListener('click', () => addHotel());
    document.getElementById('gp-add-transport').addEventListener('click', () => addTransport());
    document.getElementById('gp-add-service').addEventListener('click', () => addService());

    const searchPanel = document.getElementById('gp-passenger-search-panel');
    const searchInput = document.getElementById('gp-passenger-search');
    const results = document.getElementById('gp-passenger-results');
    document.getElementById('gp-reuse-passenger').addEventListener('click', () => { searchPanel.style.display = searchPanel.style.display === 'block' ? 'none' : 'block'; if (searchPanel.style.display === 'block') { searchInput.focus(); renderPassengerSearch(''); } });
    searchInput.addEventListener('input', () => renderPassengerSearch(searchInput.value));

    function renderPassengerSearch(query){
        const q = String(query || '').trim().toLowerCase();
        const matches = savedPassengerOptions.filter(p => !q || String(p.search_label || `${p.name} ${p.passport_no} ${p.date_of_birth}`).toLowerCase().includes(q)).slice(0,20);
        results.innerHTML = matches.length ? '' : '<div class="gp-empty">No saved passenger found.</div>';
        matches.forEach(p => {
            const item = document.createElement('div'); item.className='gp-search-item';
            item.innerHTML = `<div><div class="gp-search-name">${esc(p.name)}</div><div class="gp-search-meta">${esc(p.passport_no || 'No passport')} ${p.date_of_birth ? ' · '+esc(p.date_of_birth) : ''}</div></div><span class="gp-badge">Use</span>`;
            item.addEventListener('click', () => { addPassenger(p); searchPanel.style.display='none'; searchInput.value=''; });
            results.appendChild(item);
        });
    }

    function showAlert(type, message){ const el=document.getElementById('gp-alert'); el.className=`gp-alert ${type}`; el.innerHTML=message; el.scrollIntoView({behavior:'smooth',block:'center'}); }

    async function save(mode){
        document.getElementById('gp-save-mode').value = mode;
        const updateUrl = form.dataset.updateUrl || '';
        const url = updateUrl || form.action;
        const fd = new FormData(form);
        if (updateUrl) fd.append('_method','PUT');
        root.classList.add('gp-saving');
        try {
            const response = await fetch(url,{method:'POST',body:fd,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 422 && data.errors) {
                    const messages = Object.values(data.errors).flat().slice(0,12).map(esc).join('<br>');
                    showAlert('error', `<strong>Please check the form:</strong><br>${messages}`);
                } else showAlert('error', esc(data.message || 'Booking could not be saved.'));
                return;
            }
            form.dataset.updateUrl = data.update_url || form.dataset.updateUrl;
            document.getElementById('gp-booking-ref').textContent = data.booking_reference || 'Saved';
            if (data.package_code) document.getElementById('gp-package-code').value = data.package_code;
            if (data.edit_url && window.history?.replaceState) window.history.replaceState({},'',data.edit_url);
            let message = `<strong>${esc(data.message || 'Booking saved.')}</strong>`;
            if (data.warnings?.length) message += '<br>'+data.warnings.map(esc).join('<br>');
            showAlert(data.warnings?.length ? 'warn' : 'ok', message);
        } catch (e) { showAlert('error','The server could not be reached while saving. Please try again.'); }
        finally { root.classList.remove('gp-saving'); }
    }

    document.querySelectorAll('[data-save]').forEach(btn => btn.addEventListener('click', () => save(btn.dataset.save)));

    // Initial page data. Nothing below saves to the server until a save button is pressed.
    if (existingPassengers.length) existingPassengers.forEach(addPassenger); else addPassenger();
    if (existingFlights.length) existingFlights.forEach(f => addFlight(f.segment_type || 'outbound', f)); else { addFlight('outbound'); addFlight('inbound'); }
    existingHotels.forEach(addHotel); existingTransports.forEach(addTransport); existingServices.forEach(addService);
    calculateCommercial(); updateCounts();
})();
</script>
@endsection
