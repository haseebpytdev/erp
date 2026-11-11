@extends($erpLayout)
@section($erpTitleSection, $bookingId ? 'Edit Group Package Booking' : 'Create Group Package Booking')

@section($erpContentSection)

<style>
/*
 * ERP-10.29.4
 * PAGE-CONTENT LAYOUT ONLY.
 * The Easy Ticket native `layout` continues to own the sidebar, header,
 * typography, navigation and application shell. These rules are scoped
 * strictly to this unified Group Package form.
 */
#et-gp-unified{
    width:100%;
    max-width:none;
    box-sizing:border-box;
}
#et-gp-unified *,
#et-gp-unified *::before,
#et-gp-unified *::after{box-sizing:border-box}

#et-gp-unified .row{
    display:grid;
    grid-template-columns:repeat(12,minmax(0,1fr));
}
#et-gp-unified .g-2{gap:8px}
#et-gp-unified .gap-2{gap:8px}
#et-gp-unified .gap-3{gap:12px}

#et-gp-unified .col-12{grid-column:span 12}
#et-gp-unified .col-10{grid-column:span 10}
#et-gp-unified .col-8{grid-column:span 8}
#et-gp-unified .col-6{grid-column:span 6}
#et-gp-unified .col-4{grid-column:span 4}
#et-gp-unified .col-2{grid-column:span 2}

#et-gp-unified .et-commercial-grid{
    grid-template-columns:repeat(8,minmax(0,1fr));
}
#et-gp-unified .et-commercial-grid > *{
    grid-column:auto !important;
    min-width:0;
}

#et-gp-unified .container-fluid{width:100%;max-width:none;margin:0;padding-left:0;padding-right:0}
#et-gp-unified .card{
    display:block;
    width:100%;
    background:#fff;
    border:1px solid #dfe7f0;
    border-radius:10px;
    overflow:hidden;
    box-shadow:0 1px 2px rgba(15,34,58,.025);
}
#et-gp-unified .card-header{
    padding:10px 12px;
    border-bottom:1px solid #e8edf3;
    background:#fff;
}
#et-gp-unified .card-body{padding:12px}
#et-gp-unified .card-title{font-size:15px;font-weight:700;color:#16233a}
#et-gp-unified .border{
    border:1px solid #dfe7f0 !important;
}
#et-gp-unified .rounded{border-radius:8px}
#et-gp-unified .p-2{padding:8px}
#et-gp-unified .py-2{padding-top:8px;padding-bottom:8px}
#et-gp-unified .py-3{padding-top:12px;padding-bottom:12px}
#et-gp-unified .mb-0{margin-bottom:0}
#et-gp-unified .mb-1{margin-bottom:4px}
#et-gp-unified .mb-2{margin-bottom:8px}
#et-gp-unified .mb-3{margin-bottom:12px}
#et-gp-unified .mt-1{margin-top:4px}
#et-gp-unified .mt-2{margin-top:8px}
#et-gp-unified .ms-1{margin-left:4px}

#et-gp-unified .d-flex{display:flex}
#et-gp-unified .d-none{display:none !important}
#et-gp-unified .align-items-start{align-items:flex-start}
#et-gp-unified .align-items-center{align-items:center}
#et-gp-unified .align-items-end{align-items:end}
#et-gp-unified .justify-content-between{justify-content:space-between}
#et-gp-unified .flex-wrap{flex-wrap:wrap}
#et-gp-unified .w-100{width:100%}
#et-gp-unified .fw-bold{font-weight:700}
#et-gp-unified .small{font-size:11px}
#et-gp-unified .text-muted{color:#68778e !important}
#et-gp-unified .text-danger{color:#c83a3a !important}

#et-gp-unified .form-label{
    display:block;
    margin:0 0 4px;
    color:#43526a;
    font-size:10px;
    line-height:1.2;
    font-weight:700;
}
#et-gp-unified .form-control,
#et-gp-unified .form-select{
    display:block;
    width:100%;
    min-width:0;
    height:34px;
    margin:0;
    padding:6px 8px;
    border:1px solid #d3deea;
    border-radius:6px;
    background:#fff;
    color:#17243a;
    font:inherit;
    font-size:11px;
    line-height:1.2;
}
#et-gp-unified .form-control:focus,
#et-gp-unified .form-select:focus{
    outline:none;
    border-color:#6fa4df;
    box-shadow:0 0 0 2px rgba(31,111,205,.08);
}
#et-gp-unified input[readonly]{
    background:#f6f8fb;
}
#et-gp-unified .form-hint{
    margin-top:3px;
    color:#7c8899;
    font-size:9px;
}
#et-gp-unified .input-group{
    display:flex;
    width:100%;
}
#et-gp-unified .input-group > .form-control{
    flex:1 1 50%;
    min-width:0;
    border-radius:0;
}
#et-gp-unified .input-group > .form-control:first-child{
    border-radius:6px 0 0 6px;
}
#et-gp-unified .input-group > .form-control:last-child{
    border-radius:0 6px 6px 0;
    border-left:0;
}

#et-gp-unified .btn-list{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:6px;
}
#et-gp-unified .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:32px;
    padding:6px 10px;
    border:1px solid transparent;
    border-radius:6px;
    font:inherit;
    font-size:10px;
    font-weight:700;
    text-decoration:none;
    cursor:pointer;
    white-space:nowrap;
}
#et-gp-unified .btn-sm{min-height:30px;padding:5px 9px}
#et-gp-unified .btn-primary{background:#1769d2;color:#fff;border-color:#1769d2}
#et-gp-unified .btn-success{background:#15935a;color:#fff;border-color:#15935a}
#et-gp-unified .btn-outline-primary{background:#fff;color:#1769d2;border-color:#8ab3e8}
#et-gp-unified .btn-outline-secondary{background:#fff;color:#34445b;border-color:#cfd9e5}
#et-gp-unified .btn-outline-danger{background:#fff6f6;color:#bd3030;border-color:#f1c4c4}

#et-gp-unified .badge{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:3px 7px;
    font-size:9px;
    line-height:1;
    font-weight:700;
}
#et-gp-unified .bg-blue-lt{background:#eaf2ff;color:#1769d2}
#et-gp-unified .bg-green-lt{background:#e8f7ee;color:#16814e}
#et-gp-unified .bg-secondary-lt{background:#eef2f6;color:#526176}

#et-gp-unified .alert{
    width:100%;
    padding:10px 12px;
    margin-bottom:12px;
    border:1px solid transparent;
    border-radius:8px;
    font-size:11px;
}
#et-gp-unified .alert-success{background:#e9f8ef;color:#166b43;border-color:#ccebd8}
#et-gp-unified .alert-warning{background:#fff7e5;color:#805a0b;border-color:#f4e1af}
#et-gp-unified .alert-danger{background:#fff0f0;color:#9d2727;border-color:#f0cccc}

#et-gp-unified .et-passenger-row,
#et-gp-unified .et-flight-row,
#et-gp-unified .et-hotel-row,
#et-gp-unified .et-transport-row,
#et-gp-unified .et-service-row{
    width:100%;
    background:#fff;
}

@media (min-width:768px){
    #et-gp-unified .col-md-1{grid-column:span 1}
    #et-gp-unified .col-md-2{grid-column:span 2}
    #et-gp-unified .col-md-3{grid-column:span 3}
    #et-gp-unified .col-md-4{grid-column:span 4}
    #et-gp-unified .col-md-5{grid-column:span 5}
    #et-gp-unified .col-md-6{grid-column:span 6}
    #et-gp-unified .col-md-8{grid-column:span 8}
}
@media (min-width:1200px){
    #et-gp-unified .col-xl{grid-column:auto}
    #et-gp-unified .col-xl-1{grid-column:span 1}
    #et-gp-unified .col-xl-2{grid-column:span 2}
    #et-gp-unified .col-xl-3{grid-column:span 3}
    #et-gp-unified .col-xl-4{grid-column:span 4}
}
@media (max-width:1199px){
    #et-gp-unified .et-commercial-grid{
        grid-template-columns:repeat(4,minmax(0,1fr));
    }
}
@media (max-width:767px){
    #et-gp-unified{padding:8px}
    #et-gp-unified .et-commercial-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    #et-gp-unified .d-flex.justify-content-between{
        align-items:stretch;
    }
}
</style>

@php
    $bookingRow = $existing['booking'] ?? [];
    $commercialRow = $existing['commercial'] ?? [];
    $initialPassengers = $existing['passengers'] ?? [];
    $initialFlights = $existing['flights'] ?? [];
    $initialHotels = $existing['hotels'] ?? [];
    $initialTransports = $existing['transports'] ?? [];
    $initialServices = $existing['services'] ?? [];
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
    $customerId = $valueFrom($bookingRow, ['customer_id', 'party_id', 'client_id'], null);
    $branchId = $valueFrom($bookingRow, ['branch_id', 'office_id'], null);
    $currencyCode = strtoupper((string) $valueFrom($commercialRow, ['currency_code'], $valueFrom($bookingRow, ['currency_code', 'currency'], 'PKR')));
    $packageCode = $valueFrom($commercialRow, ['package_code'], 'Auto-generated on Save');
@endphp

<div class="container-fluid py-3 et-gp-unified" id="et-gp-unified">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
        <div>
            <div class="text-muted small mb-1">GROUP PACKAGE BOOKING · ERP-10.29.4</div>
            <h2 class="mb-1">{{ $bookingId ? 'Edit Group Package Booking' : 'Create Group Package Booking' }}</h2>
            <div class="text-muted">All information is entered on this page and saved together as one booking.</div>
            @if($bookingReference)
                <div class="mt-1"><span class="badge bg-blue-lt" id="et-booking-reference">{{ $bookingReference }}</span></div>
            @else
                <div class="mt-1"><span class="badge bg-secondary-lt" id="et-booking-reference">New Booking · Not Saved</span></div>
            @endif
        </div>
        <a href="{{ url('/operations/bookings') }}" class="btn btn-outline-secondary btn-sm">← Back to Bookings</a>
    </div>

    <div id="et-save-alert" class="alert d-none" role="alert"></div>

    <form id="et-group-package-form"
          action="{{ route('operations.bookings.group-package-unified.store') }}"
          data-update-url="{{ $bookingId ? route('operations.bookings.group-package-unified.update', $bookingId) : '' }}"
          novalidate>
        @csrf
        <input type="hidden" name="save_mode" value="complete" id="et-save-mode">
        <input type="hidden" name="booking_type" value="UMRAH">

        {{-- Commercial Summary: package-level commercial boundary only. --}}
        <div class="card mb-3">
            <div class="card-header py-2">
                <div>
                    <h3 class="card-title mb-0">Commercial Summary</h3>
                    <div class="text-muted small">Group Package pricing only. Flights, hotels, transport and other services below are operational/voucher data.</div>
                </div>
            </div>
            <div class="card-body py-3">
                <div class="row g-2 et-commercial-grid">
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Sale Price (PKR)</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm et-commercial" name="package_sale_price" value="{{ $commercialRow['package_sale_price'] ?? 0 }}" required>
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Supplier Cost</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm et-commercial" name="supplier_cost" value="{{ $commercialRow['supplier_cost'] ?? 0 }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Discount Type</label>
                        <select class="form-select form-select-sm et-commercial" name="discount_type">
                            @foreach(['none' => 'None', 'fixed' => 'Fixed', 'percent' => 'Percent'] as $key => $label)
                                <option value="{{ $key }}" @selected(($commercialRow['discount_type'] ?? 'none') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Discount Value</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm et-commercial" name="discount_value" value="{{ $commercialRow['discount_value'] ?? 0 }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Agent Commission</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm et-commercial" name="agent_commission" value="{{ $commercialRow['agent_commission'] ?? 0 }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Salesperson Commission</label>
                        <input type="number" step="0.01" min="0" class="form-control form-control-sm et-commercial" name="salesperson_commission" value="{{ $commercialRow['salesperson_commission'] ?? 0 }}">
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Final Sale</label>
                        <input type="text" class="form-control form-control-sm fw-bold" id="et-final-sale" value="{{ number_format((float) ($commercialRow['final_sale_price'] ?? 0), 2, '.', '') }}" readonly>
                    </div>
                    <div class="col-6 col-md-3 col-xl">
                        <label class="form-label">Net Margin</label>
                        <input type="text" class="form-control form-control-sm fw-bold" id="et-net-margin" value="{{ number_format((float) ($commercialRow['net_margin'] ?? 0), 2, '.', '') }}" readonly>
                    </div>
                </div>
            </div>
        </div>

        {{-- 1. Booking Information --}}
        <div class="card mb-3">
            <div class="card-header py-2"><h3 class="card-title mb-0">1. Booking Information</h3></div>
            <div class="card-body py-3">
                <div class="row g-2">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Booking Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control form-control-sm" name="booking_date" value="{{ $bookingDate }}" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Branch</label>
                        <select class="form-select form-select-sm" name="branch_id">
                            <option value="">Select branch</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch['id'] }}" @selected((string) $branchId === (string) $branch['id'])>{{ $branch['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Customer / Party <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="customer_id" required>
                            <option value="">Select customer</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer['id'] }}" @selected((string) $customerId === (string) $customer['id'])>{{ $customer['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Currency <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="currency_code" required>
                            @foreach($currencies as $currency)
                                <option value="{{ $currency['code'] }}" @selected($currencyCode === strtoupper($currency['code']))>{{ $currency['code'] }} - {{ $currency['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control form-control-sm" name="notes" value="{{ $commercialRow['notes'] ?? $valueFrom($bookingRow, ['notes', 'remarks'], '') }}" placeholder="Optional booking notes">
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Package Details: package is created inside this booking. --}}
        <div class="card mb-3">
            <div class="card-header py-2">
                <div>
                    <h3 class="card-title mb-0">2. Package Details</h3>
                    <div class="text-muted small">The package is created with this booking; it is not selected from a pre-created package list.</div>
                </div>
            </div>
            <div class="card-body py-3">
                <div class="row g-2">
                    <div class="col-12 col-lg-3">
                        <label class="form-label">Package Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="package_name" value="{{ $commercialRow['package_name'] ?? '' }}" placeholder="e.g. 15 Days Umrah Package" required>
                    </div>
                    <div class="col-12 col-lg-2">
                        <label class="form-label">Our Package Code</label>
                        <input type="text" class="form-control form-control-sm" id="et-package-code" value="{{ $packageCode }}" readonly>
                        <div class="form-hint">Internal / auto-generated</div>
                    </div>
                    <div class="col-12 col-lg-3">
                        <label class="form-label">Vendor / Supplier <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="vendor_id" required>
                            <option value="">Select vendor / supplier</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor['id'] }}" @selected((string) ($commercialRow['vendor_id'] ?? '') === (string) $vendor['id'])>{{ $vendor['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-lg-2">
                        <label class="form-label">Vendor Package Code</label>
                        <input type="text" class="form-control form-control-sm" name="vendor_package_code" value="{{ $commercialRow['vendor_package_code'] ?? '' }}" placeholder="Supplier package ref">
                    </div>
                    <div class="col-12 col-lg-2">
                        <label class="form-label">Vendor Voucher / Booking No.</label>
                        <input type="text" class="form-control form-control-sm" name="vendor_voucher_no" value="{{ $commercialRow['vendor_voucher_no'] ?? '' }}" placeholder="Can be added later">
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. Passenger reuse / new passenger --}}
        <div class="card mb-3">
            <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h3 class="card-title mb-0">3. Passengers</h3>
                    <div class="text-muted small">Reuse a passenger already saved in the ERP, or add a new passenger. Saved passenger master details are not overwritten by this booking.</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="et-add-new-passenger">+ New Passenger</button>
            </div>
            <div class="card-body py-3">
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-12 col-lg-8">
                        <label class="form-label">Find / Reuse Existing Passenger</label>
                        <input type="text" class="form-control form-control-sm" id="et-passenger-search" list="et-passenger-master-list" placeholder="Search by name, passport or saved passenger number">
                        <datalist id="et-passenger-master-list">
                            @foreach($passengerOptions as $passenger)
                                <option value="{{ $passenger['search_label'] }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-12 col-lg-4">
                        <button type="button" class="btn btn-outline-primary btn-sm w-100" id="et-add-saved-passenger">+ Add Saved Passenger</button>
                    </div>
                </div>

                <div id="et-passenger-rows"></div>
                <div class="text-muted small" id="et-no-passengers">No passenger added yet.</div>
            </div>
        </div>

        {{-- 4. Flights --}}
        <div class="card mb-3">
            <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h3 class="card-title mb-0">4. Flights</h3>
                    <div class="text-muted small">Shared package itinerary. Passenger ticket numbers remain in the passenger rows above.</div>
                </div>
                <div class="btn-list">
                    <button type="button" class="btn btn-primary btn-sm" data-add-flight="outbound">+ Outbound</button>
                    <button type="button" class="btn btn-success btn-sm" data-add-flight="inbound">+ Inbound</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-add-flight="connection">+ Connection</button>
                </div>
            </div>
            <div class="card-body py-3">
                <div id="et-flight-rows"></div>
            </div>
        </div>

        {{-- 5. Hotels --}}
        <div class="card mb-3">
            <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h3 class="card-title mb-0">5. Hotel Stays <span class="badge bg-green-lt ms-1">Included in Package</span></h3>
                    <div class="text-muted small">Accommodation and voucher details only. No separate hotel sale, supplier cost, commission or margin.</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="et-add-hotel">+ Add Hotel Stay</button>
            </div>
            <div class="card-body py-3">
                <datalist id="et-hotel-master-list">
                    @foreach($hotelOptions as $hotel)
                        <option value="{{ $hotel['name'] }}" label="{{ $hotel['city'] }}"></option>
                    @endforeach
                </datalist>
                <div id="et-hotel-rows"></div>
            </div>
        </div>

        {{-- 6. Transport --}}
        <div class="card mb-3">
            <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h3 class="card-title mb-0">6. Transport <span class="badge bg-green-lt ms-1">Included in Package</span></h3>
                    <div class="text-muted small">Movement and voucher/reference details only. No separate transport pricing.</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="et-add-transport">+ Add Transport</button>
            </div>
            <div class="card-body py-3">
                <div id="et-transport-rows"></div>
            </div>
        </div>

        {{-- 7. Other services --}}
        <div class="card mb-3">
            <div class="card-header py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <h3 class="card-title mb-0">7. Other Services / Products <span class="badge bg-green-lt ms-1">Included in Package</span></h3>
                    <div class="text-muted small">Optional operational/voucher items such as Ziyarat, SIM, meals or assistance.</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="et-add-service">+ Add Service / Product</button>
            </div>
            <div class="card-body py-3">
                <div id="et-service-rows"></div>
                <div class="text-muted small" id="et-no-services">No additional service added.</div>
            </div>
        </div>

        {{-- 8. Review and one-time save --}}
        <div class="card mb-3">
            <div class="card-header py-2"><h3 class="card-title mb-0">8. Review &amp; Save</h3></div>
            <div class="card-body py-3">
                <div class="alert alert-info py-2 mb-3">
                    Add / Remove only changes this page. Nothing is saved one-by-one. Use <strong>Save Draft</strong> or <strong>Save All Booking</strong> to save every section together.
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="text-muted small">Passengers</div><strong id="et-count-passengers">0</strong></div></div>
                    <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="text-muted small">Flights</div><strong id="et-count-flights">0</strong></div></div>
                    <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="text-muted small">Hotel Stays</div><strong id="et-count-hotels">0</strong></div></div>
                    <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="text-muted small">Transport</div><strong id="et-count-transports">0</strong></div></div>
                    <div class="col-6 col-md"><div class="border rounded p-2 text-center"><div class="text-muted small">Other Services</div><strong id="et-count-services">0</strong></div></div>
                </div>
                <div class="d-flex justify-content-end gap-2 flex-wrap">
                    <a href="{{ url('/operations/bookings') }}" class="btn btn-outline-secondary">Cancel</a>
                    <button type="button" class="btn btn-outline-primary" id="et-save-draft">Save Draft</button>
                    <button type="button" class="btn btn-success" id="et-save-all">Save All Booking</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('et-group-package-form');
    if (!form) return;

    const passengerMaster = @json($passengerOptions->values());
    const hotelMaster = @json($hotelOptions->values());
    const airlines = @json($airlineOptions->values());
    const initialPassengers = @json($initialPassengers);
    const initialFlights = @json($initialFlights);
    const initialHotels = @json($initialHotels);
    const initialTransports = @json($initialTransports);
    const initialServices = @json($initialServices);

    let passengerIndex = 0;
    let flightIndex = 0;
    let hotelIndex = 0;
    let transportIndex = 0;
    let serviceIndex = 0;

    const esc = (value) => String(value ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

    const selected = (a, b) => String(a ?? '').toLowerCase() === String(b ?? '').toLowerCase() ? 'selected' : '';
    const num = (name) => Number(form.elements[name]?.value || 0);

    function airlineOptions(value) {
        let html = '<option value="">Select airline</option>';
        airlines.forEach(a => {
            const label = (a.code ? a.code + ' - ' : '') + a.name;
            html += `<option value="${esc(a.name)}" ${selected(value, a.name)}>${esc(label)}</option>`;
        });
        if (value && !airlines.some(a => String(a.name).toLowerCase() === String(value).toLowerCase())) {
            html += `<option value="${esc(value)}" selected>${esc(value)}</option>`;
        }
        return html;
    }

    function addPassenger(row = {}, saved = false) {
        const i = passengerIndex++;
        const isSaved = saved || !!row.passenger_id;
        const wrap = document.createElement('div');
        wrap.className = 'border rounded p-2 mb-2 et-passenger-row';
        wrap.innerHTML = `
            <input type="hidden" name="passengers[${i}][passenger_id]" value="${esc(row.passenger_id || '')}">
            <input type="hidden" name="passengers[${i}][passenger_source]" value="${esc(row.passenger_source || row.source_table || '')}">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="small fw-bold">Passenger ${i + 1} ${isSaved ? '<span class="badge bg-blue-lt ms-1">Saved Passenger</span>' : '<span class="badge bg-secondary-lt ms-1">New</span>'}</div>
                <button type="button" class="btn btn-outline-danger btn-sm et-remove-row">Remove</button>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-4 col-md-2 col-xl-1"><label class="form-label">Title</label><select class="form-select form-select-sm" name="passengers[${i}][title]" ${isSaved ? 'disabled' : ''}><option value="">—</option><option ${selected(row.title,'MR')}>MR</option><option ${selected(row.title,'MRS')}>MRS</option><option ${selected(row.title,'MS')}>MS</option><option ${selected(row.title,'MSTR')}>MSTR</option><option ${selected(row.title,'MISS')}>MISS</option></select>${isSaved ? `<input type="hidden" name="passengers[${i}][title]" value="${esc(row.title || '')}">` : ''}</div>
                <div class="col-8 col-md-5 col-xl-2"><label class="form-label">First Name <span class="text-danger">*</span></label><input class="form-control form-control-sm" name="passengers[${i}][first_name]" value="${esc(row.first_name || '')}" required ${isSaved ? 'readonly' : ''}></div>
                <div class="col-8 col-md-5 col-xl-2"><label class="form-label">Last Name</label><input class="form-control form-control-sm" name="passengers[${i}][last_name]" value="${esc(row.last_name || '')}" ${isSaved ? 'readonly' : ''}></div>
                <div class="col-6 col-md-4 col-xl-1"><label class="form-label">DOB</label><input type="date" class="form-control form-control-sm" name="passengers[${i}][date_of_birth]" value="${esc(row.date_of_birth || '')}" ${isSaved ? 'readonly' : ''}></div>
                <div class="col-6 col-md-4 col-xl-2"><label class="form-label">Passport No.</label><input class="form-control form-control-sm" name="passengers[${i}][passport_no]" value="${esc(row.passport_no || '')}" ${isSaved ? 'readonly' : ''}></div>
                <input type="hidden" name="passengers[${i}][passport_expiry]" value="${esc(row.passport_expiry || '')}">
                <div class="col-6 col-md-4 col-xl-1"><label class="form-label">Nationality</label><input class="form-control form-control-sm" name="passengers[${i}][nationality]" value="${esc(row.nationality || '')}" ${isSaved ? 'readonly' : ''}></div>
                <div class="col-6 col-md-4 col-xl-1"><label class="form-label">Fare</label><select class="form-select form-select-sm" name="passengers[${i}][fare_as]"><option value="ADULT" ${selected(row.fare_as,'ADULT')}>Adult</option><option value="CHILD" ${selected(row.fare_as,'CHILD')}>Child</option><option value="INFANT" ${selected(row.fare_as,'INFANT')}>Infant</option></select></div>
                <div class="col-12 col-md-8 col-xl-2"><label class="form-label">Ticket No.</label><input class="form-control form-control-sm" name="passengers[${i}][ticket_number]" value="${esc(row.ticket_number || '')}" placeholder="Optional / may add later"></div>
            </div>`;
        wrap.querySelector('.et-remove-row').addEventListener('click', () => { wrap.remove(); refreshCounts(); });
        document.getElementById('et-passenger-rows').appendChild(wrap);
        refreshCounts();
    }

    function addFlight(type = 'outbound', row = {}) {
        const i = flightIndex++;
        let source = row;
        if (type === 'inbound' && !row.from_code && !row.to_code) {
            const outbound = readLastFlight('outbound');
            if (outbound) {
                source = {
                    ...row,
                    from_code: outbound.to_code,
                    to_code: outbound.from_code,
                    airline_name: outbound.airline_name,
                    pnr: outbound.pnr,
                    cabin_class: outbound.cabin_class,
                    baggage: outbound.baggage,
                };
            }
        }
        const wrap = document.createElement('div');
        wrap.className = 'border rounded p-2 mb-2 et-flight-row';
        wrap.dataset.type = type;
        wrap.innerHTML = `
            <input type="hidden" name="flights[${i}][segment_type]" value="${esc(type)}">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div><span class="badge ${type === 'outbound' ? 'bg-blue-lt' : type === 'inbound' ? 'bg-green-lt' : 'bg-secondary-lt'}">${esc(type.charAt(0).toUpperCase() + type.slice(1))}</span></div>
                <button type="button" class="btn btn-outline-danger btn-sm et-remove-row">Remove</button>
            </div>
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">From <span class="text-danger">*</span></label><input class="form-control form-control-sm et-flight-from" name="flights[${i}][from_code]" value="${esc(source.from_code || '')}" required></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">To <span class="text-danger">*</span></label><input class="form-control form-control-sm et-flight-to" name="flights[${i}][to_code]" value="${esc(source.to_code || '')}" required></div>
                <div class="col-12 col-md-6 col-xl-2"><label class="form-label">Airline</label><select class="form-select form-select-sm et-flight-airline" name="flights[${i}][airline_name]">${airlineOptions(source.airline_name)}</select></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Flight No.</label><input class="form-control form-control-sm" name="flights[${i}][flight_number]" value="${esc(source.flight_number || '')}"></div>
                <div class="col-12 col-md-5 col-xl-2"><label class="form-label">Departure <span class="text-danger">*</span></label><div class="input-group input-group-sm"><input type="date" class="form-control" name="flights[${i}][departure_date]" value="${esc(source.departure_date || '')}" required><input type="time" class="form-control" name="flights[${i}][departure_time]" value="${esc((source.departure_time || '').substring(0,5))}"></div></div>
                <div class="col-12 col-md-5 col-xl-2"><label class="form-label">Arrival</label><div class="input-group input-group-sm"><input type="date" class="form-control" name="flights[${i}][arrival_date]" value="${esc(source.arrival_date || '')}"><input type="time" class="form-control" name="flights[${i}][arrival_time]" value="${esc((source.arrival_time || '').substring(0,5))}"></div></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">PNR</label><input class="form-control form-control-sm et-flight-pnr" name="flights[${i}][pnr]" value="${esc(source.pnr || '')}"></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Cabin</label><select class="form-select form-select-sm et-flight-cabin" name="flights[${i}][cabin_class]"><option ${selected(source.cabin_class,'Economy')}>Economy</option><option ${selected(source.cabin_class,'Premium Economy')}>Premium Economy</option><option ${selected(source.cabin_class,'Business')}>Business</option><option ${selected(source.cabin_class,'First')}>First</option></select></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Baggage</label><input class="form-control form-control-sm et-flight-baggage" name="flights[${i}][baggage]" value="${esc(source.baggage || '30 KG')}"></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Status</label><select class="form-select form-select-sm" name="flights[${i}][status]"><option value="requested" ${selected(source.status,'requested')}>Requested</option><option value="booked" ${selected(source.status || 'booked','booked')}>Booked</option><option value="confirmed" ${selected(source.status,'confirmed')}>Confirmed</option><option value="issued" ${selected(source.status,'issued')}>Issued</option></select></div>
            </div>`;
        wrap.querySelector('.et-remove-row').addEventListener('click', () => { wrap.remove(); refreshCounts(); });
        document.getElementById('et-flight-rows').appendChild(wrap);
        refreshCounts();
    }

    function readLastFlight(type) {
        const rows = Array.from(document.querySelectorAll('.et-flight-row')).filter(r => r.dataset.type === type);
        const row = rows.at(-1);
        if (!row) return null;
        return {
            from_code: row.querySelector('.et-flight-from')?.value || '',
            to_code: row.querySelector('.et-flight-to')?.value || '',
            airline_name: row.querySelector('.et-flight-airline')?.value || '',
            pnr: row.querySelector('.et-flight-pnr')?.value || '',
            cabin_class: row.querySelector('.et-flight-cabin')?.value || '',
            baggage: row.querySelector('.et-flight-baggage')?.value || '',
        };
    }

    function addHotel(row = {}) {
        const i = hotelIndex++;
        const wrap = document.createElement('div');
        wrap.className = 'border rounded p-2 mb-2 et-hotel-row';
        wrap.innerHTML = `
            <input type="hidden" name="hotels[${i}][hotel_id]" value="${esc(row.hotel_id || '')}">
            <input type="hidden" name="hotels[${i}][status]" value="${esc(row.status || 'requested')}">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">City</label><input class="form-control form-control-sm et-hotel-city" name="hotels[${i}][city]" value="${esc(row.city || '')}"></div>
                <div class="col-12 col-md-5 col-xl-2"><label class="form-label">Hotel <span class="text-danger">*</span></label><input list="et-hotel-master-list" class="form-control form-control-sm et-hotel-name" name="hotels[${i}][hotel_name]" value="${esc(row.hotel_name || '')}" required></div>
                <div class="col-6 col-md-2 col-xl-1"><label class="form-label">Check-in</label><input type="date" class="form-control form-control-sm" name="hotels[${i}][check_in]" value="${esc(row.check_in || '')}"></div>
                <div class="col-6 col-md-2 col-xl-1"><label class="form-label">Check-out</label><input type="date" class="form-control form-control-sm" name="hotels[${i}][check_out]" value="${esc(row.check_out || '')}"></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Room Type</label><select class="form-select form-select-sm" name="hotels[${i}][room_type]"><option ${selected(row.room_type,'Double')}>Double</option><option ${selected(row.room_type,'Triple')}>Triple</option><option ${selected(row.room_type,'Quad')}>Quad</option><option ${selected(row.room_type,'Sharing')}>Sharing</option><option ${selected(row.room_type,'Single')}>Single</option></select></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Meal Plan</label><select class="form-select form-select-sm" name="hotels[${i}][meal_plan]"><option ${selected(row.meal_plan,'Room Only')}>Room Only</option><option ${selected(row.meal_plan,'Breakfast')}>Breakfast</option><option ${selected(row.meal_plan,'Half Board')}>Half Board</option><option ${selected(row.meal_plan,'Full Board')}>Full Board</option></select></div>
                <div class="col-4 col-md-2 col-xl-1"><label class="form-label">Rooms</label><input type="number" min="1" class="form-control form-control-sm" name="hotels[${i}][rooms]" value="${esc(row.rooms || 1)}"></div>
                <div class="col-8 col-md-4 col-xl-2"><label class="form-label">Confirmation / Voucher No.</label><input class="form-control form-control-sm" name="hotels[${i}][confirmation_no]" value="${esc(row.confirmation_no || '')}"></div>
                <div class="col-10 col-md-5 col-xl-1"><label class="form-label">Notes</label><input class="form-control form-control-sm" name="hotels[${i}][notes]" value="${esc(row.notes || '')}"></div>
                <div class="col-2 col-md-1 col-xl-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 et-remove-row">×</button></div>
            </div>`;
        wrap.querySelector('.et-remove-row').addEventListener('click', () => { wrap.remove(); refreshCounts(); });
        wrap.querySelector('.et-hotel-name').addEventListener('change', (e) => {
            const found = hotelMaster.find(h => String(h.name).toLowerCase() === String(e.target.value).toLowerCase());
            if (found) {
                wrap.querySelector('input[name$="[hotel_id]"]').value = found.id || '';
                if (!wrap.querySelector('.et-hotel-city').value) wrap.querySelector('.et-hotel-city').value = found.city || '';
            }
        });
        document.getElementById('et-hotel-rows').appendChild(wrap);
        refreshCounts();
    }

    function addTransport(row = {}) {
        const i = transportIndex++;
        const wrap = document.createElement('div');
        wrap.className = 'border rounded p-2 mb-2 et-transport-row';
        wrap.innerHTML = `
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4 col-xl-2"><label class="form-label">From <span class="text-danger">*</span></label><input class="form-control form-control-sm" name="transports[${i}][from_location]" value="${esc(row.from_location || '')}" required></div>
                <div class="col-12 col-md-4 col-xl-2"><label class="form-label">To <span class="text-danger">*</span></label><input class="form-control form-control-sm" name="transports[${i}][to_location]" value="${esc(row.to_location || '')}" required></div>
                <div class="col-6 col-md-4 col-xl-1"><label class="form-label">Vehicle</label><input class="form-control form-control-sm" name="transports[${i}][vehicle_type]" value="${esc(row.vehicle_type || '')}" placeholder="Bus / Coaster / Car"></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Pickup Date</label><input type="date" class="form-control form-control-sm" name="transports[${i}][pickup_date]" value="${esc(row.pickup_date || '')}"></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Pickup Time</label><input type="time" class="form-control form-control-sm" name="transports[${i}][pickup_time]" value="${esc((row.pickup_time || '').substring(0,5))}"></div>
                <div class="col-6 col-md-3 col-xl-1"><label class="form-label">Status</label><select class="form-select form-select-sm" name="transports[${i}][status]"><option value="requested" ${selected(row.status || 'requested','requested')}>Requested</option><option value="booked" ${selected(row.status,'booked')}>Booked</option><option value="confirmed" ${selected(row.status,'confirmed')}>Confirmed</option><option value="completed" ${selected(row.status,'completed')}>Completed</option></select></div>
                <div class="col-12 col-md-5 col-xl-2"><label class="form-label">Vendor Ref / BRN</label><input class="form-control form-control-sm" name="transports[${i}][provider_reference]" value="${esc(row.provider_reference || '')}"></div>
                <div class="col-10 col-md-6 col-xl-1"><label class="form-label">Notes</label><input class="form-control form-control-sm" name="transports[${i}][notes]" value="${esc(row.notes || '')}"></div>
                <div class="col-2 col-md-1 col-xl-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 et-remove-row">×</button></div>
            </div>`;
        wrap.querySelector('.et-remove-row').addEventListener('click', () => { wrap.remove(); refreshCounts(); });
        document.getElementById('et-transport-rows').appendChild(wrap);
        refreshCounts();
    }

    function addService(row = {}) {
        const i = serviceIndex++;
        const wrap = document.createElement('div');
        wrap.className = 'border rounded p-2 mb-2 et-service-row';
        wrap.innerHTML = `
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4 col-xl-3"><label class="form-label">Service / Product <span class="text-danger">*</span></label><input class="form-control form-control-sm" name="services[${i}][service_name]" value="${esc(row.service_name || '')}" required></div>
                <div class="col-12 col-md-5 col-xl-3"><label class="form-label">Details</label><input class="form-control form-control-sm" name="services[${i}][details]" value="${esc(row.details || '')}"></div>
                <div class="col-4 col-md-2 col-xl-1"><label class="form-label">Qty</label><input type="number" min="1" class="form-control form-control-sm" name="services[${i}][quantity]" value="${esc(row.quantity || 1)}"></div>
                <div class="col-6 col-md-3 col-xl-2"><label class="form-label">Status</label><select class="form-select form-select-sm" name="services[${i}][status]"><option value="included" ${selected(row.status || 'included','included')}>Included</option><option value="requested" ${selected(row.status,'requested')}>Requested</option><option value="booked" ${selected(row.status,'booked')}>Booked</option><option value="confirmed" ${selected(row.status,'confirmed')}>Confirmed</option></select></div>
                <div class="col-10 col-md-6 col-xl-2"><label class="form-label">Notes</label><input class="form-control form-control-sm" name="services[${i}][notes]" value="${esc(row.notes || '')}"></div>
                <div class="col-2 col-md-1 col-xl-1"><button type="button" class="btn btn-outline-danger btn-sm w-100 et-remove-row">×</button></div>
            </div>`;
        wrap.querySelector('.et-remove-row').addEventListener('click', () => { wrap.remove(); refreshCounts(); });
        document.getElementById('et-service-rows').appendChild(wrap);
        refreshCounts();
    }

    function refreshCounts() {
        const counts = {
            passengers: document.querySelectorAll('.et-passenger-row').length,
            flights: document.querySelectorAll('.et-flight-row').length,
            hotels: document.querySelectorAll('.et-hotel-row').length,
            transports: document.querySelectorAll('.et-transport-row').length,
            services: document.querySelectorAll('.et-service-row').length,
        };
        Object.entries(counts).forEach(([key, value]) => {
            const el = document.getElementById('et-count-' + key);
            if (el) el.textContent = value;
        });
        document.getElementById('et-no-passengers')?.classList.toggle('d-none', counts.passengers > 0);
        document.getElementById('et-no-services')?.classList.toggle('d-none', counts.services > 0);
    }

    function calculateCommercial() {
        const sale = num('package_sale_price');
        const supplier = num('supplier_cost');
        const agent = num('agent_commission');
        const salesperson = num('salesperson_commission');
        const discountValue = num('discount_value');
        const discountType = form.elements['discount_type']?.value || 'none';
        let discount = 0;
        if (discountType === 'fixed') discount = Math.min(discountValue, sale);
        if (discountType === 'percent') discount = sale * Math.min(discountValue, 100) / 100;
        const finalSale = Math.max(0, sale - discount);
        const margin = finalSale - supplier - agent - salesperson;
        document.getElementById('et-final-sale').value = finalSale.toFixed(2);
        document.getElementById('et-net-margin').value = margin.toFixed(2);
    }

    function showAlert(type, html) {
        const box = document.getElementById('et-save-alert');
        box.className = `alert alert-${type}`;
        box.innerHTML = html;
        box.classList.remove('d-none');
        box.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    async function saveBooking(mode) {
        document.getElementById('et-save-mode').value = mode;
        calculateCommercial();

        if (!form.reportValidity()) return;

        const saveButtons = [document.getElementById('et-save-draft'), document.getElementById('et-save-all')];
        saveButtons.forEach(b => b.disabled = true);

        const data = new FormData(form);
        const updateUrl = form.dataset.updateUrl;
        const url = updateUrl || form.action;
        if (updateUrl) data.set('_method', 'PUT');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: data,
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                if (response.status === 422 && payload.errors) {
                    const list = Object.values(payload.errors).flat().map(m => `<li>${esc(m)}</li>`).join('');
                    showAlert('danger', `<strong>Please correct the following:</strong><ul class="mb-0">${list}</ul>`);
                } else {
                    showAlert('danger', `<strong>Save failed.</strong> ${esc(payload.message || 'Please try again.')}`);
                }
                return;
            }

            if (payload.update_url) form.dataset.updateUrl = payload.update_url;
            if (payload.package_code) document.getElementById('et-package-code').value = payload.package_code;
            if (payload.booking_reference) document.getElementById('et-booking-reference').textContent = payload.booking_reference;

            let message = `<strong>${esc(payload.message || 'Saved successfully.')}</strong>`;
            if (Array.isArray(payload.warnings) && payload.warnings.length) {
                message += `<div class="mt-2 small">${payload.warnings.map(w => esc(w)).join('<br>')}</div>`;
            }
            showAlert(payload.warnings?.length ? 'warning' : 'success', message);
        } catch (error) {
            showAlert('danger', '<strong>Save failed.</strong> The server could not be reached or returned an invalid response.');
        } finally {
            saveButtons.forEach(b => b.disabled = false);
        }
    }

    document.querySelectorAll('.et-commercial').forEach(el => el.addEventListener('input', calculateCommercial));
    document.querySelectorAll('.et-commercial').forEach(el => el.addEventListener('change', calculateCommercial));

    document.getElementById('et-add-new-passenger').addEventListener('click', () => addPassenger({}, false));
    document.getElementById('et-add-saved-passenger').addEventListener('click', () => {
        const input = document.getElementById('et-passenger-search');
        const term = String(input.value || '').trim().toLowerCase();
        if (!term) return showAlert('warning', 'Choose a saved passenger first.');
        let matches = passengerMaster.filter(p => String(p.search_label || '').toLowerCase() === term);
        if (!matches.length) {
            matches = passengerMaster.filter(p => String(p.passport_no || '').toLowerCase() === term || String(p.name || '').toLowerCase() === term);
        }
        if (matches.length !== 1) return showAlert('warning', matches.length ? 'More than one saved passenger matches. Please choose the exact item from the list.' : 'Saved passenger not found. Choose the exact item from the list or use + New Passenger.');
        const p = matches[0];
        addPassenger({ ...p, passenger_id: p.id, passenger_source: p.source_table }, true);
        input.value = '';
    });

    document.querySelectorAll('[data-add-flight]').forEach(btn => btn.addEventListener('click', () => addFlight(btn.dataset.addFlight, {})));
    document.getElementById('et-add-hotel').addEventListener('click', () => addHotel({}));
    document.getElementById('et-add-transport').addEventListener('click', () => addTransport({}));
    document.getElementById('et-add-service').addEventListener('click', () => addService({}));
    document.getElementById('et-save-draft').addEventListener('click', () => saveBooking('draft'));
    document.getElementById('et-save-all').addEventListener('click', () => saveBooking('complete'));

    initialPassengers.forEach(row => addPassenger(row, !!row.passenger_id));
    initialFlights.forEach(row => addFlight(row.segment_type || 'outbound', row));
    initialHotels.forEach(addHotel);
    initialTransports.forEach(addTransport);
    initialServices.forEach(addService);

    if (!initialFlights.length) {
        addFlight('outbound', {status: 'booked', cabin_class: 'Economy', baggage: '30 KG'});
        addFlight('inbound', {status: 'booked', cabin_class: 'Economy', baggage: '30 KG'});
    }
    if (!initialHotels.length) addHotel({rooms: 1, room_type: 'Double', meal_plan: 'Room Only'});
    if (!initialTransports.length) addTransport({status: 'requested'});

    // While the initial inbound route is still blank, keep it automatically
    // reversed from the outbound route. Staff can then edit it freely.
    document.getElementById('et-flight-rows').addEventListener('input', (event) => {
        const outboundRow = event.target.closest('.et-flight-row[data-type="outbound"]');
        if (!outboundRow) return;
        const inboundRows = Array.from(document.querySelectorAll('.et-flight-row[data-type="inbound"]'));
        const inbound = inboundRows[0];
        if (!inbound) return;
        const inFrom = inbound.querySelector('.et-flight-from');
        const inTo = inbound.querySelector('.et-flight-to');
        if (!inFrom.dataset.manual && !inTo.dataset.manual) {
            inFrom.value = outboundRow.querySelector('.et-flight-to')?.value || '';
            inTo.value = outboundRow.querySelector('.et-flight-from')?.value || '';
            if (!inbound.querySelector('.et-flight-airline').value) inbound.querySelector('.et-flight-airline').value = outboundRow.querySelector('.et-flight-airline')?.value || '';
            if (!inbound.querySelector('.et-flight-pnr').value) inbound.querySelector('.et-flight-pnr').value = outboundRow.querySelector('.et-flight-pnr')?.value || '';
        }
    });
    document.getElementById('et-flight-rows').addEventListener('change', (event) => {
        const inboundRow = event.target.closest('.et-flight-row[data-type="inbound"]');
        if (inboundRow && (event.target.classList.contains('et-flight-from') || event.target.classList.contains('et-flight-to'))) {
            inboundRow.querySelector('.et-flight-from').dataset.manual = '1';
            inboundRow.querySelector('.et-flight-to').dataset.manual = '1';
        }
    });

    calculateCommercial();
    refreshCounts();
})();
</script>
@endsection
