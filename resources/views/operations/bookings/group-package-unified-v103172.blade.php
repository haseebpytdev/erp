@extends($erpLayout)

@section($erpTitleSection, $bookingId ? 'Edit Group Umrah Booking' : 'Create Group Umrah Booking')

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
    $customerIdentity = $existing['customer_identity'] ?? ['id' => null, 'name' => '', 'resolved' => false];
    $customerId = data_get($customerIdentity, 'id')
        ?: $valueFrom($bookingRow, ['customer_id', 'party_id', 'client_id', 'customer_party_id', 'party_master_id']);
    $branchId = $valueFrom($bookingRow, ['branch_id', 'office_id']);
    $currencyCode = strtoupper((string) $valueFrom($commercialRow, ['currency_code'], $valueFrom($bookingRow, ['currency_code', 'currency'], 'PKR')));
    $packageCode = $valueFrom($commercialRow, ['package_code'], 'Auto-generated on Save');
    $packageName = $valueFrom($commercialRow, ['package_name'], '');
    $vendorId = $valueFrom($commercialRow, ['vendor_id'], $valueFrom($bookingRow, ['vendor_id', 'supplier_id', 'service_partner_id']));
    $selectedCustomer = collect($customers)->first(fn ($item) => (string)($item['id'] ?? '') === (string)$customerId);
    $customerName = trim((string) data_get($customerIdentity, 'name', ''))
        ?: trim((string) data_get($selectedCustomer, 'name', $customerId ? 'Customer #'.$customerId : ''));
    $bookedPax = max(1, (int) $valueFrom($commercialRow, ['booked_pax'], max(1, count($existing['passengers'] ?? []))));
    $vendorPackageCode = $valueFrom($commercialRow, ['vendor_package_code'], '');
    $vendorVoucherNo = $valueFrom($commercialRow, ['vendor_voucher_no'], '');
    $notes = $valueFrom($commercialRow, ['notes'], $valueFrom($bookingRow, ['notes', 'remarks'], ''));
@endphp

<style>
html,body{
    overflow-x:hidden !important;
    max-width:100% !important;
}
/*
 * ERP-10.31.72 — native ERP shell + compact full-width booking rows.
 * Booking-form rules remain scoped to #gp-booking.
 * Small focus-shell rules intentionally hide native ERP chrome only
 * while this dedicated Group Umrah workspace is active.
 */
#gp-booking{
    position:relative;
    overflow:visible;
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
    gap:14px;margin:0 0 12px
}
#gp-booking .gp-kicker{
    font-size:11px;font-weight:800;letter-spacing:.06em;color:var(--gp-blue);
    text-transform:uppercase;margin-bottom:3px
}
#gp-booking h1{font-size:27px;line-height:1.15;margin:0 0 3px;color:var(--gp-text)}
#gp-booking .gp-sub{font-size:13px;color:var(--gp-muted)}
#gp-booking .gp-ref{
    display:inline-block;margin-top:5px;border-radius:999px;background:var(--gp-soft);
    color:var(--gp-blue);padding:4px 9px;font-size:10px;font-weight:800
}

#gp-booking .gp-card{
    width:100%;background:#fff;border:1px solid var(--gp-line);
    border-radius:10px;margin-bottom:12px;overflow:hidden;
    box-shadow:0 1px 2px rgba(20,35,55,.02)
}
#gp-booking .gp-card-head{
    display:flex;align-items:center;justify-content:space-between;gap:8px;
    padding:11px 14px;border-bottom:1px solid #e8edf3
}
#gp-booking .gp-card-title{font-size:16px;font-weight:800;margin:0}
#gp-booking .gp-card-note{font-size:11.5px;color:var(--gp-muted);margin-top:2px}
#gp-booking .gp-card-body{padding:11px 14px}

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
    display:block;font-size:10px;font-weight:800;color:#4b5a70;margin:0 0 3px;
    text-transform:uppercase;letter-spacing:.02em;white-space:nowrap
}
#gp-booking .gp-input,#gp-booking .gp-select{
    display:block;width:100%;min-width:0;height:36px;
    border:1px solid #d3deea;border-radius:5px;background:#fff;
    padding:7px 9px;font:inherit;font-size:13px;color:var(--gp-text);outline:none
}
#gp-booking .gp-input:focus,#gp-booking .gp-select:focus{
    border-color:#70a8e5;box-shadow:0 0 0 2px rgba(23,105,210,.07)
}
#gp-booking .gp-input[readonly]{background:#f6f8fb;color:#526176}

#gp-booking .gp-commercial{
    display:grid;grid-template-columns:repeat(6,minmax(100px,1fr));gap:7px
}
#gp-booking .gp-commercial .gp-value{font-weight:800;color:var(--gp-green)}
#gp-booking .gp-fare-matrix{
    display:grid;grid-template-columns:150px repeat(3,minmax(120px,1fr));gap:7px;
    align-items:end;margin-bottom:8px
}
#gp-booking .gp-fare-label{
    height:38px;display:flex;align-items:center;padding:0 10px;border-radius:6px;
    background:#f3f6fa;border:1px solid #e0e7ef;font-size:11px;font-weight:850;color:#32445d
}
#gp-booking .gp-commercial-totals{
    display:grid;
    grid-template-columns:
        1.08fr 1.06fr .92fr .90fr 1.04fr 1.02fr 1.14fr .96fr;
    gap:6px;align-items:end
}
#gp-booking .gp-commercial-totals .gp-field{
    min-width:0;display:flex;flex-direction:column;justify-content:flex-end
}
#gp-booking .gp-commercial-totals .gp-field label{
    white-space:normal;line-height:1.05;min-height:21px;
    font-size:9px;margin-bottom:3px
}
#gp-booking .gp-commercial-totals .gp-input,
#gp-booking .gp-commercial-totals .gp-select{
    height:34px;padding:6px 8px;font-size:12.5px
}

/* Package Details stays one compact desktop row. */
#gp-booking .gp-package-grid{
    display:grid;
    grid-template-columns:
        2.35fr 1.48fr 1.60fr .74fr .70fr .70fr .86fr 1.20fr 1.18fr;
    gap:7px;align-items:end
}
#gp-booking .gp-package-grid > .gp-field{
    grid-column:auto !important;min-width:0
}
#gp-booking .gp-package-grid .gp-field label{
    white-space:normal;line-height:1.05;min-height:21px;
    font-size:9.2px;margin-bottom:3px
}
#gp-booking .gp-package-grid .gp-input,
#gp-booking .gp-package-grid .gp-select{
    height:34px;padding:6px 8px;font-size:12.5px
}

/* Accounting action buttons stay together on desktop. */
#gp-booking #gp-accounting-card .gp-actions{
    flex-wrap:nowrap;gap:4px;justify-content:flex-end;min-width:0
}
#gp-booking #gp-accounting-card .gp-actions .gp-btn{
    min-height:32px;padding:5px 8px;font-size:10px;border-radius:5px
}
#gp-booking #gp-accounting-card .gp-actions #gp-accounting-invoice-link{
    max-width:210px;overflow:hidden;text-overflow:ellipsis
}
#gp-booking #gp-accounting-card .gp-actions [data-native-invoice-workflow="1"]{
    padding-left:10px;padding-right:10px
}
#gp-booking input[readonly][id^="gp-booked-"]{
    background:#f2f5f9;color:#52627a;cursor:not-allowed
}
#gp-booking .gp-amend-fare-matrix{
    display:grid;grid-template-columns:170px repeat(3,minmax(115px,1fr));
    gap:7px;align-items:end
}
#gp-booking .gp-amend-fare-label{
    height:36px;display:flex;align-items:center;padding:0 10px;
    border:1px solid #dce5ef;border-radius:6px;background:#f5f8fb;
    font-weight:800;font-size:10px;color:#35465e
}
#gp-booking .gp-amend-summary{
    display:grid;grid-template-columns:repeat(7,minmax(110px,1fr));
    gap:7px;margin-top:8px;align-items:end
}
#gp-booking .gp-amend-summary .gp-field label{
    white-space:normal;line-height:1.05;min-height:20px
}
#gp-booking .gp-invoice-workflow-bar{
    display:flex;align-items:center;gap:6px;
    margin-top:6px;padding:5px 8px;border:1px solid #d8e5f5;
    border-radius:6px;background:#f6faff;min-height:30px
}
#gp-booking .gp-invoice-workflow-copy{
    display:flex;align-items:center;gap:6px;flex-wrap:nowrap;
    min-width:0;width:100%;white-space:nowrap;overflow:hidden
}
#gp-booking .gp-invoice-workflow-copy .gp-card-note{
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap
}

#gp-booking .gp-actions{display:flex;align-items:center;flex-wrap:wrap;gap:5px}
#gp-booking .gp-btn{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:36px;padding:7px 12px;border:1px solid transparent;border-radius:5px;
    background:#eef2f7;color:#2b3a50;font:inherit;font-size:11.5px;font-weight:800;
    cursor:pointer;text-decoration:none;white-space:nowrap
}
#gp-booking .gp-btn-blue{background:var(--gp-blue);color:#fff}
#gp-booking .gp-btn-green{background:var(--gp-green);color:#fff}
#gp-booking .gp-btn-red{background:#fff1f1;color:var(--gp-red);border-color:#f2cece}
#gp-booking .gp-btn-outline{background:#fff;color:var(--gp-blue);border-color:#96bae6}

#gp-booking .gp-badge{
    display:inline-flex;align-items:center;border-radius:999px;padding:3px 6px;
    font-size:9px;font-weight:800;background:#e9f7ef;color:#147849
}
#gp-booking .gp-info{
    padding:7px 9px;border-radius:6px;background:var(--gp-soft);
    color:#2b5f9d;font-size:10.5px
}
#gp-booking .gp-package-note{
    padding:7px 9px;border-radius:6px;background:var(--gp-success);
    color:#217248;font-size:10.5px;margin-top:8px
}
#gp-booking .gp-empty{
    padding:9px;border:1px dashed #d9e2ec;border-radius:6px;color:#7b8797;
    font-size:11px;text-align:center;background:#fbfcfe
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
    font-size:9px;font-weight:800;text-transform:uppercase;
    letter-spacing:.02em
}
#gp-booking .gp-data-row{
    display:grid;align-items:center;gap:5px;padding:6px 0;
    border-bottom:1px solid #edf1f5
}
#gp-booking .gp-data-row:last-child{border-bottom:0}
#gp-booking .gp-data-row .gp-input,#gp-booking .gp-data-row .gp-select{
    height:36px;font-size:11.5px;padding:6px 8px
}
#gp-booking .gp-data-row .gp-btn{height:34px;padding:5px 7px;width:100%}

#gp-booking .gp-passenger-grid{
    grid-template-columns:62px 105px 105px 108px 108px 98px 78px 115px 58px;
    min-width:900px
}
#gp-booking .gp-flight-grid{
    grid-template-columns:74px 70px 70px 135px 70px 104px 68px 104px 68px 82px 58px;
    min-width:965px
}
#gp-booking .gp-hotel-grid{
    grid-template-columns:82px 145px 104px 104px 58px 90px 90px 58px 110px 135px 58px;
    min-width:1110px
}
#gp-booking .gp-transport-grid{
    grid-template-columns:
        minmax(145px,1.55fr)
        minmax(78px,.72fr)
        minmax(105px,1fr)
        minmax(88px,.82fr)
        minmax(78px,.72fr)
        minmax(92px,.85fr)
        minmax(105px,1.08fr)
        54px;
    min-width:0
}
#gp-booking .gp-transport-grid > *{min-width:0}
#gp-booking #gp-transport-card .gp-table-scroll{
    overflow-x:hidden;
    padding-bottom:0
}
#gp-booking .gp-service-grid{
    grid-template-columns:150px 190px 58px 92px 175px 58px;
    min-width:810px
}
#gp-booking .gp-type{
    font-size:9px;font-weight:800;text-transform:capitalize;border-radius:999px;
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
#gp-booking .gp-search-name{font-size:10.5px;font-weight:800}
#gp-booking .gp-search-meta{font-size:9px;color:#748196;margin-top:2px}

#gp-booking .gp-review{
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px
}
#gp-booking .gp-review-box{
    border:1px solid #e0e7ef;border-radius:6px;padding:7px;background:#fbfcfe
}
#gp-booking .gp-review-label{font-size:10px;color:#6b798c}
#gp-booking .gp-review-value{font-size:16px;font-weight:800;margin-top:1px}
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

#gp-booking .gp-workflow-statusline{
    display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px
}
#gp-booking .gp-workflow-statusline .gp-review-box{padding:7px 9px}
#gp-booking .gp-workflow-number{
    display:block;margin-top:2px;font-size:8px;font-weight:700;color:#607087;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
#gp-booking .gp-workflow-toolbar{
    display:flex;align-items:center;gap:5px;overflow-x:auto;overflow-y:hidden;
    white-space:nowrap;padding:8px 0 3px;scrollbar-width:thin
}
#gp-booking .gp-workflow-toolbar .gp-btn{flex:0 0 auto}
#gp-booking .gp-workflow-note{
    margin-top:6px;font-size:10px;color:#6b788b
}
#gp-booking.gp-editing-locked section.gp-card:not(#gp-workflow-card){
    background:#fbfcfe
}
#gp-booking.gp-editing-locked [data-save],
#gp-booking.gp-editing-locked [data-remove],
#gp-booking.gp-editing-locked [data-add-flight],
#gp-booking.gp-editing-locked #gp-add-passenger,
#gp-booking.gp-editing-locked #gp-reuse-passenger,
#gp-booking.gp-editing-locked #gp-add-hotel,
#gp-booking.gp-editing-locked #gp-add-transport,
#gp-booking.gp-editing-locked #gp-add-service{
    display:none !important
}
#gp-booking.gp-editing-locked .gp-input:disabled,
#gp-booking.gp-editing-locked .gp-select:disabled{
    background:#f7f9fc;color:#26364d;border-color:#d8e1eb;opacity:1;cursor:not-allowed
}
#gp-booking.gp-editing-locked input:disabled::placeholder,
#gp-booking.gp-editing-locked textarea:disabled::placeholder{
    color:#7b8797;opacity:1
}
#gp-booking.gp-phase-locked #gp-passenger-card,
#gp-booking.gp-phase-locked #gp-flights-card,
#gp-booking.gp-phase-locked #gp-hotels-card,
#gp-booking.gp-phase-locked #gp-transport-card,
#gp-booking.gp-phase-locked #gp-services-card{opacity:1}
#gp-booking .gp-accounting-strip{
    display:grid;
    grid-template-columns:.95fr .76fr 1.08fr .76fr auto;
    gap:6px;align-items:center
}
#gp-booking .gp-accounting-item{
    border:1px solid #e0e7ef;border-radius:6px;
    padding:6px 8px;background:#fbfcfe;min-height:48px;min-width:0
}
#gp-booking .gp-accounting-label{
    font-size:9.2px;color:#6b798c;line-height:1.05;white-space:nowrap
}
#gp-booking .gp-accounting-value{
    font-size:12.4px;font-weight:800;margin-top:3px;color:#17243a;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis
}
#gp-booking .gp-top-actions{
    display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap
}
#gp-booking .gp-title-meta{
    display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:5px
}
#gp-booking .gp-lock-pill{
    display:none;align-items:center;padding:4px 8px;border-radius:999px;
    border:1px solid #edcf83;background:#fff8e6;color:#775513;
    font-size:9.5px;font-weight:850;line-height:1.1
}
#gp-booking.gp-editing-locked .gp-lock-pill{display:inline-flex}
.gp-focus-overlay{
    display:none;position:fixed;inset:0;background:rgba(10,22,40,.35);z-index:9997
}
.gp-focus-overlay.open{display:block}
[data-gp-focus-sidebar]{transition:transform .18s ease,box-shadow .18s ease}
[data-gp-focus-sidebar].gp-focus-sidebar-open{
    display:block !important;position:fixed !important;left:0 !important;top:0 !important;bottom:0 !important;
    z-index:9998 !important;overflow:auto !important;box-shadow:0 10px 36px rgba(0,0,0,.22) !important;
    transform:none !important
}
[data-gp-focus-native-header]{display:none !important}


@media(max-width:1250px){
    #gp-booking .gp-commercial-totals{
        grid-template-columns:repeat(4,minmax(120px,1fr))
    }
    #gp-booking .gp-package-grid{
        grid-template-columns:repeat(5,minmax(120px,1fr))
    }
}
@media(max-width:1120px){
    #gp-booking .gp-accounting-strip{
        grid-template-columns:repeat(4,minmax(135px,1fr))
    }
    #gp-booking #gp-accounting-card .gp-actions{
        grid-column:1 / -1;justify-content:flex-start;flex-wrap:wrap
    }
}
@media(max-width:1050px){
    #gp-booking .gp-commercial{grid-template-columns:repeat(4,minmax(95px,1fr))}
}
@media(max-width:900px){
    #gp-booking .gp-fare-matrix{grid-template-columns:130px repeat(3,minmax(105px,1fr));overflow-x:auto}
    #gp-booking .gp-package-grid{
        grid-template-columns:repeat(3,minmax(120px,1fr))
    }
    #gp-booking .gp-amend-fare-matrix{
        grid-template-columns:140px repeat(3,120px);overflow-x:auto
    }
    #gp-booking .gp-amend-summary{
        grid-template-columns:repeat(3,minmax(120px,1fr))
    }
}
@media(max-width:820px){
    #gp-booking #gp-transport-card .gp-table-scroll{
        overflow-x:auto;
        padding-bottom:2px
    }
    #gp-booking .gp-transport-grid{
        grid-template-columns:155px 86px 115px 96px 84px 100px 115px 54px;
        min-width:840px
    }
}
@media(max-width:760px){
    #gp-booking .gp-top{display:block}
    #gp-booking .gp-top-actions{justify-content:flex-start;margin-top:8px}
    #gp-booking .gp-top>a{margin-top:6px}
    #gp-booking .gp-commercial{grid-template-columns:repeat(2,minmax(100px,1fr))}
    #gp-booking .gp-commercial-totals{grid-template-columns:repeat(2,minmax(100px,1fr))}
    #gp-booking .gp-fare-matrix{grid-template-columns:120px repeat(3,120px);overflow-x:auto}
    #gp-booking .gp-package-grid{grid-template-columns:repeat(2,minmax(120px,1fr))}
    #gp-booking .gp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    #gp-booking .gp-c12,#gp-booking .gp-c8,#gp-booking .gp-c6,
    #gp-booking .gp-c4,#gp-booking .gp-c3,#gp-booking .gp-c2{grid-column:span 1}
    #gp-booking .gp-search-results,#gp-booking .gp-review{grid-template-columns:1fr}
}

@media(min-width:1200px){
    #gp-booking .gp-grid{gap:9px}
    #gp-booking .gp-commercial{gap:9px}
    #gp-booking .gp-accounting-strip{gap:9px}
}


#gp-booking .gp-amendment-panel{
    display:none;margin-top:9px;padding:10px;border:1px solid #dce5ef;border-radius:8px;background:#fbfcfe
}
#gp-booking .gp-amendment-panel.open{display:block}
#gp-booking .gp-amendment-grid{
    display:grid;grid-template-columns:.7fr 1fr 1fr .8fr .8fr 1fr 1fr;gap:7px
}
#gp-booking .gp-amendment-history{margin-top:9px}
#gp-booking .gp-amendment-row{
    display:grid;grid-template-columns:70px 90px 120px 120px minmax(170px,1fr) 130px;
    gap:7px;align-items:center;padding:6px 0;border-top:1px solid #e8edf3;font-size:10px
}
#gp-booking .gp-accounting-lock-note{
    display:none;margin-top:6px;font-size:10px;color:#6b788b
}
#gp-booking.gp-commercial-locked .gp-accounting-lock-note{display:block}
#gp-booking.gp-commercial-locked [data-commercial-control]{
    pointer-events:none;background:#f7f9fc;color:#26364d
}
@media(max-width:1050px){
    #gp-booking .gp-amendment-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
    #gp-booking .gp-amendment-row{grid-template-columns:repeat(3,minmax(0,1fr))}
}


[data-gp-focus-sidebar]:not(.gp-focus-sidebar-open){display:none!important}
</style>

<div id="gp-booking">
    <div class="gp-top">
        <div>
            <div class="gp-kicker">Group Umrah Booking · ERP-10.31.72</div>
            <h1>{{ $bookingId ? 'Edit Group Umrah Booking' : 'Create Group Umrah Booking' }}</h1>
            <div class="gp-sub">Commercial package first. Passenger names and operational details may be completed later.</div>
            <div class="gp-title-meta">
                <span class="gp-ref" id="gp-booking-ref">{{ $bookingReference ?: 'New Booking · Not Saved' }}</span>
                <span class="gp-lock-pill" id="gp-edit-lock-pill">{{ data_get($workflow, 'editing_lock_label', 'Editing Locked') }}</span>
            </div>
        </div>
        <div class="gp-top-actions">
            <button type="button" class="gp-btn gp-btn-outline" id="gp-focus-menu">☰ Menu</button>
            <a class="gp-btn gp-btn-outline" href="{{ url('/operations/bookings') }}">Booking Register</a>
            @if($bookingId && ($canViewProfitability ?? false))
                <a
                    class="gp-btn gp-btn-outline"
                    href="{{ route('reports.group-umrah-profitability.show', ['booking'=>$bookingId]) }}"
                >Profitability</a>
            @endif
            <a class="gp-btn gp-btn-outline" id="gp-dashboard-link" href="#" style="display:none">Dashboard</a>
        </div>
    </div>

    @if(session('error'))
        <div id="gp-alert" class="gp-alert error">{{ session('error') }}</div>
    @elseif(session('success'))
        <div id="gp-alert" class="gp-alert ok">{{ session('success') }}</div>
    @elseif(session('info'))
        <div id="gp-alert" class="gp-alert warn">{{ session('info') }}</div>
    @elseif($errors->any())
        <div id="gp-alert" class="gp-alert error">{{ implode(' | ', $errors->all()) }}</div>
    @else
        <div id="gp-alert" class="gp-alert"></div>
    @endif

    <form id="gp-form" action="{{ route('operations.bookings.group-package-unified.store') }}" data-update-url="{{ $bookingId ? route('operations.bookings.group-package-unified.update', $bookingId) : '' }}">
        @csrf
        <input type="hidden" name="save_mode" value="complete" id="gp-save-mode">
        <input type="hidden" name="save_scope" value="operational" id="gp-save-scope">
        <input type="hidden" name="booking_type" value="UMRAH">

        <section class="gp-card">
            <div class="gp-card-head">
                <div>
                    <div class="gp-card-title">Commercial Summary</div>
                    <div class="gp-card-note">Customer and Vendor prices are per pax by Adult / Child / Infant. Package accounting remains separate from operational Flight, Hotel, Transport and Other Service rows.</div>
                </div>
            </div>
            <div class="gp-card-body">
                <div class="gp-fare-matrix">
                    <div></div>
                    <div class="gp-field"><label>Adult / Pax</label></div>
                    <div class="gp-field"><label>Child / Pax</label></div>
                    <div class="gp-field"><label>Infant / Pax</label></div>

                    <div class="gp-fare-label">Customer Sale Price</div>
                    <div class="gp-field"><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="adult_sale_price" data-commercial-control="1" value="{{ number_format((float)($commercialRow['adult_sale_price'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="child_sale_price" data-commercial-control="1" value="{{ number_format((float)($commercialRow['child_sale_price'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="infant_sale_price" data-commercial-control="1" value="{{ number_format((float)($commercialRow['infant_sale_price'] ?? 0), 2, '.', '') }}"></div>

                    <div class="gp-fare-label">Vendor / Supplier Cost</div>
                    <div class="gp-field"><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="adult_supplier_cost" data-commercial-control="1" value="{{ number_format((float)($commercialRow['adult_supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="child_supplier_cost" data-commercial-control="1" value="{{ number_format((float)($commercialRow['child_supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="infant_supplier_cost" data-commercial-control="1" value="{{ number_format((float)($commercialRow['infant_supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                </div>

                <div class="gp-commercial-totals">
                    <div class="gp-field"><label>Gross Customer Total</label><input class="gp-input gp-value" id="gp-gross-sale-total" readonly value="{{ number_format((float)($commercialRow['gross_sale_total'] ?? $commercialRow['package_sale_price'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><label>Vendor Cost Total</label><input class="gp-input gp-value" id="gp-supplier-cost-total" readonly value="{{ number_format((float)($commercialRow['supplier_cost_total'] ?? $commercialRow['supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><label>Discount Type</label><select class="gp-select gp-commercial-input" name="discount_type" data-commercial-control="1"><option value="none" @selected(($commercialRow['discount_type'] ?? 'none') === 'none')>None</option><option value="fixed" @selected(($commercialRow['discount_type'] ?? '') === 'fixed')>Fixed Total</option><option value="percent" @selected(($commercialRow['discount_type'] ?? '') === 'percent')>Percent</option></select></div>
                    <div class="gp-field"><label>Discount Value</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="discount_value" data-commercial-control="1" value="{{ $commercialRow['discount_value'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Final Sale Total</label><input class="gp-input gp-value" id="gp-final-sale" readonly value="{{ number_format((float)($commercialRow['final_sale_total'] ?? $commercialRow['final_sale_price'] ?? 0), 2, '.', '') }}"></div>
                    <div class="gp-field"><label>Agent Commission (Total)</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="agent_commission" data-commercial-control="1" value="{{ $commercialRow['agent_commission'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Salesperson Commission (Total)</label><input class="gp-input gp-commercial-input" type="number" min="0" step="0.01" name="salesperson_commission" data-commercial-control="1" value="{{ $commercialRow['salesperson_commission'] ?? 0 }}"></div>
                    <div class="gp-field"><label>Net Margin Total</label><input class="gp-input gp-value" id="gp-net-margin" readonly value="{{ number_format((float)($commercialRow['net_margin_total'] ?? $commercialRow['net_margin'] ?? 0), 2, '.', '') }}"></div>
                </div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head"><div><div class="gp-card-title">1. Booking Information</div></div></div>
            <div class="gp-card-body">
                <div class="gp-grid">
                    <div class="gp-field gp-c2"><label>Booking Date *</label><input class="gp-input" type="date" name="booking_date" value="{{ $bookingDate }}" required></div>
                    <div class="gp-field gp-c2"><label>Branch</label><select class="gp-select" name="branch_id"><option value="">Select branch</option>@foreach($branches as $branch)<option value="{{ $branch['id'] }}" @selected((string)$branchId === (string)$branch['id'])>{{ $branch['name'] }}</option>@endforeach</select></div>
                    <div class="gp-field gp-c3">
                        <label>Customer / Party *</label>
                        @if($bookingId)
                            <input type="hidden" name="customer_id" value="{{ $customerId ?: '' }}">
                            <input class="gp-input"
                                   value="{{ $customerName ?: 'Customer linked on original booking' }}"
                                   readonly
                                   aria-label="Customer carried from booking">
                        @else
                            <select class="gp-select" name="customer_id" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer['id'] }}" @selected((string)$customerId === (string)$customer['id'])>{{ $customer['name'] }}</option>@endforeach</select>
                        @endif
                    </div>
                    <div class="gp-field gp-c2"><label>Currency *</label><select class="gp-select" name="currency_code">@foreach($currencies as $currency)<option value="{{ $currency['code'] }}" @selected($currencyCode === strtoupper($currency['code']))>{{ $currency['code'] }} - {{ $currency['name'] }}</option>@endforeach</select></div>
                    <div class="gp-field gp-c3"><label>Notes</label><input class="gp-input" type="text" name="notes" value="{{ $notes }}" placeholder="Optional booking note"></div>
                </div>
            </div>
        </section>

        <section class="gp-card">
            <div class="gp-card-head"><div><div class="gp-card-title">2. Package Details</div><div class="gp-card-note">Package is created inside this booking. No pre-created package is required.</div></div></div>
            <div class="gp-card-body">
                <div class="gp-package-grid">
                    <div class="gp-field"><label>Package Name *</label><input class="gp-input" type="text" name="package_name" value="{{ $packageName }}" placeholder="e.g. 15 Days Umrah Package"></div>
                    <div class="gp-field"><label>Our Package Code</label><input class="gp-input" id="gp-package-code" value="{{ $packageCode }}" readonly></div>
                    <div class="gp-field">
                        <label>Vendor / Supplier *</label>
                        <select class="gp-select" name="vendor_id" required>
                            <option value="">Select vendor / supplier</option>
                            @foreach($vendors as $vendor)
                                <option value="{{ $vendor['id'] }}" @selected((string)$vendorId === (string)$vendor['id'])>{{ $vendor['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="gp-field"><label>Adult Pax *</label><input class="gp-input gp-commercial-input" type="number" min="0" max="9999" name="booked_adult_pax" data-commercial-control="1" id="gp-booked-adult-pax" value="{{ (int)($commercialRow['booked_adult_pax'] ?? $bookedPax) }}" @if(data_get($workflow, 'invoice_exists')) readonly aria-readonly="true" @endif></div>
                    <div class="gp-field"><label>Child Pax</label><input class="gp-input gp-commercial-input" type="number" min="0" max="9999" name="booked_child_pax" data-commercial-control="1" id="gp-booked-child-pax" value="{{ (int)($commercialRow['booked_child_pax'] ?? 0) }}" @if(data_get($workflow, 'invoice_exists')) readonly aria-readonly="true" @endif></div>
                    <div class="gp-field"><label>Infant Pax</label><input class="gp-input gp-commercial-input" type="number" min="0" max="9999" name="booked_infant_pax" data-commercial-control="1" id="gp-booked-infant-pax" value="{{ (int)($commercialRow['booked_infant_pax'] ?? 0) }}" @if(data_get($workflow, 'invoice_exists')) readonly aria-readonly="true" @endif></div>
                    <div class="gp-field"><label>Total Booked Pax</label><input class="gp-input gp-value" type="number" min="1" max="9999" name="booked_pax" id="gp-booked-pax" value="{{ $bookedPax }}" readonly></div>
                    <div class="gp-field"><label>Package Code</label><input class="gp-input" type="text" name="vendor_package_code" value="{{ $vendorPackageCode }}" placeholder="Manual"></div>
                    <div class="gp-field"><label>Booking No.</label><input class="gp-input" type="text" name="vendor_voucher_no" value="{{ $vendorVoucherNo }}" placeholder="Can be added later"></div>
                </div>
                <div class="gp-package-note">✓ All package commercial values are controlled above. No separate Hotel, Transport or Other Service pricing is used on this page.@if(data_get($workflow, 'invoice_exists')) <strong> Adult / Child / Infant Booked Pax are locked after invoicing; use + Add More Pax.</strong>@endif</div>
            </div>
        </section>

        <section class="gp-card" id="gp-accounting-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">3. Accounting · Invoice First</div><div class="gp-card-note">Commercial accounting starts before passenger names and travel operations.</div></div>
            </div>
            <div class="gp-card-body">
                <div class="gp-accounting-strip">
                    <div class="gp-accounting-item"><div class="gp-accounting-label">Package Commercial</div><div class="gp-accounting-value" id="gp-commercial-flow-status">{{ !empty($commercialRow) ? 'Saved' : 'Not Saved' }}</div></div>
                    <div class="gp-accounting-item"><div class="gp-accounting-label">Booked Pax</div><div class="gp-accounting-value"><span id="gp-accounting-booked-pax">{{ $bookedPax }}</span> Pax</div></div>
                    <div class="gp-accounting-item"><div class="gp-accounting-label">Customer Sales Invoice</div><div class="gp-accounting-value" id="gp-accounting-invoice-status">{{ data_get($workflow, 'sales_invoice_number', '') ?: 'Not Created' }}</div></div>
                    <div class="gp-accounting-item"><div class="gp-accounting-label">Payment</div><div class="gp-accounting-value" id="gp-accounting-payment-status">{{ data_get($workflow, 'payment_label', 'Pending') }}</div></div>
                    <div class="gp-actions">
                        @if($bookingId && data_get($workflow, 'invoice_exists'))
                            <button type="button" class="gp-btn gp-btn-blue" id="gp-open-amendment">+ Add More Pax</button>
                        @endif
                        @if(!data_get($workflow, 'commercial_pricing_locked'))
                            <button
                                type="button"
                                class="gp-btn gp-btn-blue"
                                data-save-mode="complete"
                                data-save-scope="commercial"
                                id="gp-save-commercial"
                            >
                                {{ data_get($workflow, 'invoice_exists') ? 'Update Commercial' : 'Save Commercial' }}
                            </button>
                        @endif
                        <button type="button" class="gp-btn gp-btn-blue" data-workflow-action="confirm" id="gp-accounting-confirm-booking">Confirm Booking</button>
                        <a class="gp-btn gp-btn-green" id="gp-accounting-invoice-link" data-commercial-saved="{{ !empty($commercialRow) ? '1' : '0' }}" href="{{ !empty($commercialRow) ? data_get($workflowActions, 'sales_invoice_url', '#') : '#' }}">{{ !empty($commercialRow) ? data_get($workflowActions, 'sales_invoice_label', 'Create Sales Invoice') : 'Save Commercial First' }}</a>
                        @if(data_get($workflowActions, 'invoice_workflow_action.url'))
                            <button type="button" class="gp-btn gp-btn-green" data-native-invoice-workflow="1" data-workflow-url="{{ data_get($workflowActions, 'invoice_workflow_action.url') }}" data-workflow-method="{{ data_get($workflowActions, 'invoice_workflow_action.method', 'POST') }}">{{ data_get($workflowActions, 'invoice_workflow_action.label') }}</button>
                        @endif
                    </div>
                </div>
                <div class="gp-accounting-lock-note">
                    @if(data_get($workflow, 'invoice_exists') && !data_get($workflow, 'commercial_pricing_locked'))
                        <strong>Update Commercial</strong> is available only because this Sales Invoice is still Draft. Use it for corrections to the current commercial values, then Sync / Open the Draft invoice.
                    @elseif(data_get($workflow, 'commercial_pricing_locked'))
                        Commercial values are accounting-controlled because the Sales Invoice has left Draft. Additional capacity must use <strong>+ Add More Pax</strong>.
                    @else
                        Save Commercial first, then Confirm Booking and create the Customer Sales Invoice.
                    @endif
                </div>

                @if($bookingId && data_get($workflow, 'invoice_exists'))
                <div class="gp-invoice-workflow-bar">
                    <div class="gp-invoice-workflow-copy">
                        <strong>Invoice Workflow</strong>
                        <span class="gp-badge">{{ data_get($workflowActions, 'invoice_workflow_status_label', data_get($workflow, 'sales_invoice_status', 'Draft')) }}</span>
                        @if(data_get($workflowActions, 'invoice_workflow_action.label'))
                            <span class="gp-card-note">Next: {{ data_get($workflowActions, 'invoice_workflow_action.label') }}</span>
                        @elseif(in_array(data_get($workflowActions, 'invoice_workflow_status'), ['posted','posted_to_gl','final','finalized'], true))
                            <span class="gp-card-note">Posted to GL. Customer receipt/payment remains separate.</span>
                        @else
                            <span class="gp-card-note">Open invoice for any additional action available to your role.</span>
                        @endif
                    </div>
                </div>
                @endif

                @if($bookingId && data_get($workflow, 'invoice_exists'))
                <div style="display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:8px">
                    <span class="gp-badge">{{ count($commercialAmendments ?? []) }} Amendment{{ count($commercialAmendments ?? []) === 1 ? '' : 's' }}</span>
                    @if(data_get($commercialAmendmentState, 'pending_count', 0))
                        <span class="gp-badge" style="background:#fff5df;color:#8b5a00">{{ data_get($commercialAmendmentState, 'pending_count', 0) }} Accounting Pending</span>
                    @endif
                </div>

                <div class="gp-amendment-panel" id="gp-amendment-panel">
                    <div class="gp-card-note" style="margin-bottom:7px">
                        Increase booked capacity after invoicing. Enter the additional Adult / Child / Infant pax and the NEW Customer/Vendor per-pax rates for this amendment. Current package rates are prefilled only as a starting point.
                    </div>

                    <div class="gp-amend-fare-matrix">
                        <div></div>
                        <div class="gp-field"><label>Adult</label></div>
                        <div class="gp-field"><label>Child</label></div>
                        <div class="gp-field"><label>Infant</label></div>

                        <div class="gp-amend-fare-label">Additional Pax</div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" id="gp-amend-adult-pax" value="0"></div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" id="gp-amend-child-pax" value="0"></div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" id="gp-amend-infant-pax" value="0"></div>

                        <div class="gp-amend-fare-label">Customer Price / Pax</div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-adult-sale" value="{{ number_format((float)($commercialRow['adult_sale_price'] ?? 0), 2, '.', '') }}"></div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-child-sale" value="{{ number_format((float)($commercialRow['child_sale_price'] ?? 0), 2, '.', '') }}"></div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-infant-sale" value="{{ number_format((float)($commercialRow['infant_sale_price'] ?? 0), 2, '.', '') }}"></div>

                        <div class="gp-amend-fare-label">Vendor Cost / Pax</div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-adult-cost" value="{{ number_format((float)($commercialRow['adult_supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-child-cost" value="{{ number_format((float)($commercialRow['child_supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                        <div class="gp-field"><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-infant-cost" value="{{ number_format((float)($commercialRow['infant_supplier_cost'] ?? 0), 2, '.', '') }}"></div>
                    </div>

                    <div class="gp-amend-summary">
                        <div class="gp-field"><label>Total Additional Pax</label><input class="gp-input gp-value" id="gp-amend-total-pax" readonly value="0"></div>
                        <div class="gp-field"><label>Gross Customer Total</label><input class="gp-input gp-value" id="gp-amend-gross" readonly value="0.00"></div>
                        <div class="gp-field"><label>Vendor Cost Total</label><input class="gp-input gp-value" id="gp-amend-vendor-total" readonly value="0.00"></div>
                        <div class="gp-field"><label>Discount Type</label><select class="gp-select gp-amend-calc" id="gp-amend-discount-type"><option value="none">None</option><option value="fixed">Fixed Total</option><option value="percent">Percent</option></select></div>
                        <div class="gp-field"><label>Discount Value</label><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-discount" value="0"></div>
                        <div class="gp-field"><label>Final Sale Total</label><input class="gp-input gp-value" id="gp-amend-final" readonly value="0.00"></div>
                        <div class="gp-field"><label>Net Margin</label><input class="gp-input gp-value" id="gp-amend-margin" readonly value="0.00"></div>

                        <div class="gp-field"><label>Agent Commission</label><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-agent" value="0"></div>
                        <div class="gp-field"><label>Salesperson Commission</label><input class="gp-input gp-amend-calc" type="number" min="0" step="0.01" id="gp-amend-salesperson" value="0"></div>
                    </div>

                    <div class="gp-grid" style="margin-top:7px">
                        <div class="gp-field gp-c2"><label>Vendor Additional Reference</label><input class="gp-input" id="gp-amend-vendor-ref" placeholder="Optional supplementary vendor reference"></div>
                        <div class="gp-field gp-c4"><label>Reason / Notes</label><input class="gp-input" id="gp-amend-notes" placeholder="Reason for additional pax"></div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:8px">
                        <button type="button" class="gp-btn" id="gp-cancel-amendment">Cancel</button>
                        <button type="button" class="gp-btn gp-btn-green" id="gp-submit-amendment">Apply Pax Amendment</button>
                    </div>
                </div>

                @if(count($commercialAmendments ?? []))
                <div class="gp-amendment-history">
                    <div class="gp-card-note"><strong>Commercial Amendment History</strong></div>
                    @foreach($commercialAmendments as $amendment)
                    <div class="gp-amendment-row">
                        <div><strong>#{{ $amendment['amendment_no'] }}</strong></div>
                        <div>+{{ (int)($amendment['additional_adult_pax'] ?? $amendment['additional_pax'] ?? 0) }}A / {{ (int)($amendment['additional_child_pax'] ?? 0) }}C / {{ (int)($amendment['additional_infant_pax'] ?? 0) }}I</div>
                        <div>Sale +{{ number_format((float)$amendment['additional_final_sale'], 2) }}</div>
                        <div>Cost +{{ number_format((float)$amendment['additional_supplier_cost'], 2) }}</div>
                        <div>{{ ($amendment['accounting_action'] ?? '') === 'supplementary_invoice' ? 'Supplementary Invoice' : 'Revise Existing Invoice' }}</div>
                        <div>{{ !empty($amendment['resolved']) ? 'Accounted' : 'Accounting Pending' }}</div>
                    </div>
                    @endforeach
                </div>
                @endif
                @endif

                <div class="gp-info" id="gp-accounting-guidance" style="margin-top:7px">Save the package commercial booking, then create the Customer Sales Invoice. Passenger names are not required for either step.</div>
            </div>
        </section>

        <section class="gp-card" id="gp-passenger-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">4. Passenger Manifest <span class="gp-badge">Booked: <span id="gp-manifest-booked">{{ $bookedPax }}</span></span> <span class="gp-badge">Names: <span id="gp-manifest-received">{{ count($existing['passengers'] ?? []) }}</span></span> <span class="gp-badge">Pending: <span id="gp-manifest-pending">{{ max(0, $bookedPax - count($existing['passengers'] ?? [])) }}</span></span></div><div class="gp-card-note">Passenger names are optional at booking time. Add them as received; assigned rows cannot exceed Booked Pax.</div></div>
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

        <section class="gp-card" id="gp-flights-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">5. Flights</div><div class="gp-card-note">Enter outbound, inbound and any connection sectors. Inbound automatically reverses the latest outbound route.</div></div>
                <div class="gp-actions"><button type="button" class="gp-btn gp-btn-blue" data-add-flight="outbound">+ Outbound</button><button type="button" class="gp-btn gp-btn-green" data-add-flight="inbound">+ Inbound</button><button type="button" class="gp-btn" data-add-flight="connection">+ Connection</button></div>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-flight-grid"><div>Type</div><div>From</div><div>To</div><div>Airline</div><div>Flight</div><div>Depart Date</div><div>Time</div><div>Arrival Date</div><div>Time</div><div>PNR</div><div></div></div>
                <div id="gp-flight-rows"></div></div>
            </div>
        </section>

        <section class="gp-card" id="gp-hotels-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">6. Hotel Stays <span class="gp-badge">Included in Package</span> <span class="gp-badge">Total Nights: <span id="gp-total-hotel-nights">0</span></span></div><div class="gp-card-note">Add as many hotel rows as required. Accommodation details only; no commercial fields.</div></div>
                <button type="button" class="gp-btn gp-btn-blue" id="gp-add-hotel">+ Add Hotel Stay</button>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-hotel-grid"><div>City</div><div>Hotel</div><div>Check In</div><div>Check Out</div><div>Nights</div><div>Room Type</div><div>Meal Plan</div><div>Rooms</div><div>Confirmation</div><div>Notes</div><div></div></div>
                <div id="gp-hotel-rows"></div></div><div class="gp-empty" id="gp-hotel-empty">No hotel stay added yet.</div>
            </div>
        </section>

        <section class="gp-card" id="gp-transport-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">7. Transport <span class="gp-badge">Included in Package</span></div><div class="gp-card-note">Select saved Route and Vehicle from Transport Master / Rate Cards. Master rates are reference only; no separate package transport accounting is created.</div></div>
                <button type="button" class="gp-btn gp-btn-blue" id="gp-add-transport">+ Add Transport</button>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-transport-grid"><div>Route</div><div>Vehicle</div><div>Company Name</div><div>Contact No.</div><div>BRN No.</div><div>Vendor Ref</div><div>Notes</div><div></div></div>
                <div id="gp-transport-rows"></div></div><div class="gp-empty" id="gp-transport-empty">No transport movement added yet.</div>
            </div>
        </section>

        <section class="gp-card" id="gp-services-card">
            <div class="gp-card-head">
                <div><div class="gp-card-title">8. Other Services / Products <span class="gp-badge">Included in Package</span></div><div class="gp-card-note">Optional operational/voucher items such as Ziyarat, SIM, meals or other package inclusions.</div></div>
                <button type="button" class="gp-btn gp-btn-blue" id="gp-add-service">+ Add Service / Product</button>
            </div>
            <div class="gp-card-body">
                <div class="gp-table-scroll"><div class="gp-row-head gp-service-grid"><div>Service / Product</div><div>Details</div><div>Qty</div><div>Status</div><div>Notes</div><div></div></div>
                <div id="gp-service-rows"></div></div><div class="gp-empty" id="gp-service-empty">No other service added yet.</div>
            </div>
        </section>

        <section class="gp-card" id="gp-readiness-card">
            <div class="gp-card-head"><div><div class="gp-card-title">9. Travel Readiness & Save</div><div class="gp-card-note">Accounting is independent. Voucher readiness depends on passenger names and operational travel details.</div></div></div>
            <div class="gp-card-body">
                <div class="gp-review">
                    <div class="gp-review-box"><div class="gp-review-label">Passenger Names / Booked</div><div class="gp-review-value"><span id="gp-count-passengers">0</span> / <span id="gp-review-booked-pax">{{ $bookedPax }}</span></div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Flights</div><div class="gp-review-value" id="gp-count-flights">0</div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Hotel Stays / Nights</div><div class="gp-review-value"><span id="gp-count-hotels">0</span> / <span id="gp-review-hotel-nights">0</span></div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Transport</div><div class="gp-review-value" id="gp-count-transports">0</div></div>
                    <div class="gp-review-box"><div class="gp-review-label">Other Services</div><div class="gp-review-value" id="gp-count-services">0</div></div>
                </div>
                <div class="gp-info" style="margin-top:9px">Passenger, Flight, Hotel, Transport and Other Service rows save together even when incomplete. Travel Ready is calculated separately; no second operational Draft button is required.</div>
                <div class="gp-savebar">
                    <a class="gp-btn" href="{{ url('/operations/bookings') }}">Back to Booking Register</a>
                    <button type="button" class="gp-btn gp-btn-green" data-save-mode="complete" data-save-scope="operational">Save Operational Changes</button>
                </div>
            </div>
        </section>

        <section class="gp-card" id="gp-workflow-card" style="{{ $bookingId ? '' : 'display:none' }}">
            <div class="gp-card-head">
                <div>
                    <div class="gp-card-title">10. Voucher & Post-Booking Actions</div>
                    <div class="gp-card-note">Commercial/accounting starts first; voucher approval starts only after Travel Ready.</div>
                </div>
                <span class="gp-badge" id="gp-workflow-booking-ref">{{ $existing['booking_reference'] ?? 'Saved Booking' }}</span>
            </div>
            <div class="gp-card-body">
                <div class="gp-workflow-statusline">
                    <div class="gp-review-box">
                        <div class="gp-review-label">Commercial</div>
                        <div class="gp-review-value" id="gp-workflow-booking-status" style="font-size:11px">{{ data_get($workflow, 'booking_label', 'Draft') }}</div>
                    </div>
                    <div class="gp-review-box">
                        <div class="gp-review-label">Accounting</div>
                        <div class="gp-review-value" id="gp-workflow-accounting-status" style="font-size:11px">{{ data_get($workflow, 'accounting_label', 'Invoice Pending') }}</div>
                        <span class="gp-workflow-number" id="gp-workflow-invoice-number">{{ data_get($workflow, 'sales_invoice_number', '') ?: 'No invoice created' }}</span>
                    </div>
                    <div class="gp-review-box">
                        <div class="gp-review-label">Manifest</div>
                        <div class="gp-review-value" id="gp-workflow-manifest-status" style="font-size:11px">{{ data_get($workflow, 'passenger_names_received', 0) }}/{{ data_get($workflow, 'booked_pax', $bookedPax) }} Names</div>
                    </div>
                    <div class="gp-review-box">
                        <div class="gp-review-label">Operations</div>
                        <div class="gp-review-value" id="gp-workflow-operations-status" style="font-size:11px">{{ data_get($workflow, 'operations_label', 'Pending') }}</div>
                    </div>
                    <div class="gp-review-box">
                        <div class="gp-review-label">Voucher</div>
                        <div class="gp-review-value" id="gp-workflow-voucher-status" style="font-size:11px">{{ data_get($workflow, 'voucher_label', 'Not Prepared') }}</div>
                        <span class="gp-workflow-number" id="gp-workflow-voucher-number">{{ data_get($workflow, 'voucher_number', data_get($workflowActions, 'voucher_number', '')) }}</span>
                    </div>
                </div>

                <div class="gp-workflow-toolbar">
                    <a class="gp-btn gp-btn-outline" id="gp-booking-workspace" href="{{ data_get($workflowActions, 'booking_workspace_url', '#') }}">Open Group Umrah</a>
                    <button type="button" class="gp-btn gp-btn-red" data-workflow-action="reopen-editing" id="gp-reopen-editing">Reopen for Editing</button>

                    <a class="gp-btn gp-btn-outline" target="_blank" id="gp-voucher-preview" href="{{ data_get($workflowActions, 'voucher_preview_url', '#') }}">Preview Voucher</a>
                    <button type="button" class="gp-btn gp-btn-outline" data-workflow-action="voucher-submit" id="gp-voucher-submit">Submit Voucher</button>
                    <button type="button" class="gp-btn gp-btn-green" data-workflow-action="voucher-approve" id="gp-voucher-approve">Approve Voucher</button>
                    <button type="button" class="gp-btn gp-btn-blue" data-workflow-action="voucher-issue" id="gp-voucher-issue">Mark Issued</button>

                    <a class="gp-btn gp-btn-outline" id="gp-sales-invoice-link" href="{{ data_get($workflowActions, 'sales_invoice_url', '#') }}">{{ data_get($workflowActions, 'sales_invoice_label', 'Create Sales Invoice') }}</a>
                    <a class="gp-btn gp-btn-outline" id="gp-supplier-payable-link" href="{{ data_get($workflowActions, 'supplier_payable_url', '#') }}">Supplier Payable</a>
                    <a class="gp-btn gp-btn-outline" id="gp-receipt-link" href="{{ data_get($workflowActions, 'receipt_url', '#') }}">Receipt Voucher</a>
                    <a class="gp-btn gp-btn-outline" id="gp-payment-link" href="{{ data_get($workflowActions, 'payment_url', '#') }}">Payment Voucher</a>
                </div>

                <div class="gp-workflow-note">
                    Voucher preview now reads the unified Group Umrah booking directly. Sales Invoice opens the native invoice if it exists; otherwise it opens the native Create Sales Invoice flow for this booking.
                </div>
            </div>
        </section>
    </form>

    <datalist id="gp-hotel-list">@foreach($hotelOptions as $hotel)<option value="{{ $hotel['name'] }}">{{ $hotel['city'] }}</option>@endforeach</datalist>
</div>

<script>
(function(){
    const root = document.getElementById('gp-booking');
    const form = document.getElementById('gp-form');
    let workflowState = @json($workflow ?? null);
    let workflowActions = @json($workflowActions ?? []);
    let workflowUrl = @json($workflowUrl ?? null);
    const commercialAmendmentUrl = @json($commercialAmendmentUrl ?? null);
    if (!root || !form) return;

    const existingPassengers = @json(array_values($existing['passengers'] ?? []));
    const existingFlights = @json(array_values($existing['flights'] ?? []));
    const existingHotels = @json(array_values($existing['hotels'] ?? []));
    const existingTransports = @json(array_values($existing['transports'] ?? []));
    const existingServices = @json(array_values($existing['services'] ?? []));
    const savedPassengerOptions = @json($passengerOptions->values());
    const hotelOptions = @json($hotelOptions->values());
    const airlineOptions = @json($airlineOptions->values());
    const transportRouteOptions = @json($transportRouteOptions->values());
    const transportVehicleOptions = @json($transportVehicleOptions->values());
    const initialBookedPax = Number(@json($bookedPax));

    let passengerIndex = 0, flightIndex = 0, hotelIndex = 0, transportIndex = 0, serviceIndex = 0;

    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
    const selectOptions = (values, selected) => values.map(v => `<option value="${esc(v)}" ${String(v).toLowerCase()===String(selected??'').toLowerCase()?'selected':''}>${esc(v)}</option>`).join('');
    const rowCount = (id) => document.querySelectorAll(`#${id} .gp-data-row`).length;

    function updateCounts(){
        const assigned = rowCount('gp-passenger-rows');
        const booked = Math.max(1, Number(document.getElementById('gp-booked-pax')?.value || initialBookedPax || 1));
        const pending = Math.max(0, booked - assigned);
        document.getElementById('gp-count-passengers').textContent = assigned;
        document.getElementById('gp-review-booked-pax').textContent = booked;
        document.getElementById('gp-manifest-booked').textContent = booked;
        document.getElementById('gp-manifest-received').textContent = assigned;
        document.getElementById('gp-manifest-pending').textContent = pending;
        document.getElementById('gp-accounting-booked-pax').textContent = booked;
        document.getElementById('gp-count-flights').textContent = rowCount('gp-flight-rows');
        document.getElementById('gp-count-hotels').textContent = rowCount('gp-hotel-rows');
        document.getElementById('gp-count-transports').textContent = rowCount('gp-transport-rows');
        document.getElementById('gp-count-services').textContent = rowCount('gp-service-rows');
        updateTotalHotelNights();
        document.getElementById('gp-hotel-empty').style.display = rowCount('gp-hotel-rows') ? 'none' : '';
        document.getElementById('gp-transport-empty').style.display = rowCount('gp-transport-rows') ? 'none' : '';
        document.getElementById('gp-service-empty').style.display = rowCount('gp-service-rows') ? 'none' : '';
    }

    function bindRemove(row){
        row.querySelector('[data-remove]')?.addEventListener('click', () => { row.remove(); updateCounts(); });
    }

    function canAddPassengerSlot(){
        const booked = Math.max(1, Number(document.getElementById('gp-booked-pax')?.value || initialBookedPax || 1));
        const assigned = rowCount('gp-passenger-rows');

        if (assigned >= booked) {
            showAlert('error', `All ${booked} booked passenger slots are already assigned. Increase Booked Pax first if more passengers are required.`);
            return false;
        }

        return true;
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
        return {
            from_code: value('from_code'),
            to_code: value('to_code'),
            airline_name: value('airline_name'),
            pnr: value('pnr')
        };
    }

    function airlineSelectOptions(selected=''){
        const selectedText = String(selected || '');
        let found = false;
        let html = '<option value="">Select airline</option>';

        airlineOptions.forEach(airline => {
            const name = String(airline.name || '').trim();
            if (!name) return;
            const code = String(airline.code || '').trim();
            const isSelected = name.toLowerCase() === selectedText.toLowerCase();
            if (isSelected) found = true;
            const label = code ? `${code} - ${name}` : name;
            html += `<option value="${esc(name)}" ${isSelected ? 'selected' : ''}>${esc(label)}</option>`;
        });

        if (selectedText && !found) {
            html += `<option value="${esc(selectedText)}" selected>${esc(selectedText)} (saved)</option>`;
        }

        if (!airlineOptions.length && !selectedText) {
            html += '<option value="" disabled>No airlines found in Travel Masters</option>';
        }

        return html;
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
            <select class="gp-select" data-key="airline_name" name="flights[${i}][airline_name]">${airlineSelectOptions(data.airline_name)}</select>
            <input class="gp-input" name="flights[${i}][flight_number]" value="${esc(data.flight_number)}" placeholder="SV739">
            <input class="gp-input" type="date" name="flights[${i}][departure_date]" value="${esc(data.departure_date)}">
            <input class="gp-input" type="time" name="flights[${i}][departure_time]" value="${esc((data.departure_time || '').slice(0,5))}">
            <input class="gp-input" type="date" name="flights[${i}][arrival_date]" value="${esc(data.arrival_date)}">
            <input class="gp-input" type="time" name="flights[${i}][arrival_time]" value="${esc((data.arrival_time || '').slice(0,5))}">
            <input class="gp-input" data-key="pnr" name="flights[${i}][pnr]" value="${esc(data.pnr)}" placeholder="PNR">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>
            <input type="hidden" name="flights[${i}][status]" value="${esc(data.status || 'booked')}">`;
        document.getElementById('gp-flight-rows').appendChild(row);
        bindRemove(row); updateCounts();
    }

    function dateDiffNights(checkIn, checkOut){ if(!checkIn||!checkOut)return 0; const s=new Date(checkIn+'T00:00:00'), e=new Date(checkOut+'T00:00:00'); if(Number.isNaN(s.getTime())||Number.isNaN(e.getTime())||e<s)return 0; return Math.max(0,Math.round((e-s)/86400000)); }
    function updateTotalHotelNights(){ const total=Array.from(document.querySelectorAll('#gp-hotel-rows [data-hotel-nights]')).reduce((sum,x)=>sum+Number(x.value||0),0); const a=document.getElementById('gp-total-hotel-nights'),b=document.getElementById('gp-review-hotel-nights'); if(a)a.textContent=String(total); if(b)b.textContent=String(total); }
    function updateHotelRowNights(row){ const ci=row.querySelector('[data-hotel-check-in]')?.value||'',co=row.querySelector('[data-hotel-check-out]')?.value||''; const n=dateDiffNights(ci,co); const input=row.querySelector('[data-hotel-nights]'); if(input)input.value=String(n); updateTotalHotelNights(); }
    function latestHotelCheckout(){ const rows=Array.from(document.querySelectorAll('#gp-hotel-rows .gp-data-row')); const last=rows.length?rows[rows.length-1]:null; return last?.querySelector('[data-hotel-check-out]')?.value||''; }
    function addHotel(data={}){
        const i=hotelIndex++; if(!data.check_in){ const prev=latestHotelCheckout(); if(prev)data={...data,check_in:prev}; }
        const row=document.createElement('div'); row.className='gp-data-row gp-hotel-grid';
        row.innerHTML=`
            <input class="gp-input" name="hotels[${i}][city]" value="${esc(data.city)}" placeholder="Makkah">
            <input class="gp-input" list="gp-hotel-list" name="hotels[${i}][hotel_name]" value="${esc(data.hotel_name)}" placeholder="Hotel name" data-hotel-name>
            <input class="gp-input" type="date" name="hotels[${i}][check_in]" value="${esc(data.check_in)}" data-hotel-check-in>
            <input class="gp-input" type="date" name="hotels[${i}][check_out]" value="${esc(data.check_out)}" data-hotel-check-out>
            <input class="gp-input" type="number" min="0" name="hotels[${i}][nights]" value="${esc(data.nights||0)}" readonly data-hotel-nights>
            <select class="gp-select" name="hotels[${i}][room_type]">${selectOptions(['Single','Double','Triple','Quad','Quint','Sharing'],data.room_type||'Double')}</select>
            <select class="gp-select" name="hotels[${i}][meal_plan]">${selectOptions(['Room Only','Breakfast','Half Board','Full Board'],data.meal_plan||'Room Only')}</select>
            <input class="gp-input" type="number" min="1" name="hotels[${i}][rooms]" value="${esc(data.rooms||1)}">
            <input class="gp-input" name="hotels[${i}][confirmation_no]" value="${esc(data.confirmation_no)}" placeholder="Confirmation">
            <input class="gp-input" name="hotels[${i}][notes]" value="${esc(data.notes)}" placeholder="Optional note">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>
            <input type="hidden" name="hotels[${i}][status]" value="${esc(data.status||'requested')}">`;
        document.getElementById('gp-hotel-rows').appendChild(row);
        row.querySelector('[data-hotel-name]')?.addEventListener('change',ev=>{ const m=hotelOptions.find(h=>String(h.name).toLowerCase()===String(ev.target.value).toLowerCase()); if(m&&!row.querySelector(`[name="hotels[${i}][city]"]`).value)row.querySelector(`[name="hotels[${i}][city]"]`).value=m.city||''; });
        row.querySelector('[data-hotel-check-in]')?.addEventListener('change',()=>updateHotelRowNights(row)); row.querySelector('[data-hotel-check-out]')?.addEventListener('change',()=>updateHotelRowNights(row)); bindRemove(row); updateHotelRowNights(row); updateCounts();
    }

    function routeOptionKey(route){
        return `${route.source_table || ''}:${route.id || 0}`;
    }

    function routeSelectOptions(data={}){
        const savedSource = String(data.route_source_table || '');
        const savedId = Number(data.route_master_id || 0);
        const savedName = String(data.route_name || '');
        let found = false;
        let html = '<option value="">Select route</option>';

        transportRouteOptions.forEach(route => {
            const key = routeOptionKey(route);
            const matchesId = savedId > 0 && Number(route.id || 0) === savedId && String(route.source_table || '') === savedSource;
            const matchesName = !savedId && savedName && String(route.name || '').toLowerCase() === savedName.toLowerCase();
            const selected = matchesId || matchesName;
            if (selected) found = true;
            html += `<option value="${esc(key)}" ${selected ? 'selected' : ''}>${esc(route.display || route.name || key)}</option>`;
        });

        if (savedName && !found) {
            html += `<option value="legacy:${esc(savedName)}" selected>${esc(savedName)} (saved)</option>`;
        }

        if (!transportRouteOptions.length && !savedName) {
            html += '<option value="" disabled>No routes found in Transport Master / Rate Cards</option>';
        }

        return html;
    }

    function vehicleSelectOptions(data={}){
        const savedName = String(data.vehicle_type || '');
        let found = false;
        let html = '<option value="">Select vehicle</option>';
        transportVehicleOptions.forEach(vehicle => {
            const name = String(vehicle.name || '').trim();
            if (!name) return;
            const selected = savedName && name.toLowerCase() === savedName.toLowerCase();
            if (selected) found = true;
            html += `<option value="${esc(name)}" data-source="${esc(vehicle.source_table || '')}" data-id="${esc(vehicle.id || 0)}" ${selected ? 'selected' : ''}>${esc(name)}</option>`;
        });
        if (savedName && !found) {
            html += `<option value="${esc(savedName)}" selected>${esc(savedName)} (saved)</option>`;
        }
        if (!transportVehicleOptions.length && !savedName) {
            html += '<option value="" disabled>No vehicles found in Transport Master</option>';
        }
        return html;
    }

    function addTransport(data={}){
        const i = transportIndex++;
        const row = document.createElement('div'); row.className='gp-data-row gp-transport-grid';

        row.innerHTML = `
            <select class="gp-select" data-transport-route>${routeSelectOptions(data)}</select>
            <select class="gp-select" name="transports[${i}][vehicle_type]" data-transport-vehicle>${vehicleSelectOptions(data)}</select>
            <input class="gp-input" name="transports[${i}][company_name]" value="${esc(data.company_name)}" placeholder="Transport company" data-transport-company>
            <input class="gp-input" name="transports[${i}][contact_number]" value="${esc(data.contact_number)}" placeholder="Contact number" data-transport-contact>
            <input class="gp-input" name="transports[${i}][brn_number]" value="${esc(data.brn_number || data.provider_reference)}" placeholder="BRN number" data-transport-brn>
            <input class="gp-input" name="transports[${i}][provider_reference]" value="${esc(data.provider_reference)}" placeholder="Vendor reference">
            <input class="gp-input" name="transports[${i}][notes]" value="${esc(data.notes)}" placeholder="Optional note">
            <button type="button" class="gp-btn gp-btn-red" data-remove>Remove</button>

            <input type="hidden" name="transports[${i}][route_name]" value="${esc(data.route_name)}" data-route-name>
            <input type="hidden" name="transports[${i}][route_master_id]" value="${esc(data.route_master_id || '')}" data-route-master-id>
            <input type="hidden" name="transports[${i}][route_source_table]" value="${esc(data.route_source_table || '')}" data-route-source>
            <input type="hidden" name="transports[${i}][vehicle_master_id]" value="${esc(data.vehicle_master_id || '')}" data-vehicle-master-id>
            <input type="hidden" name="transports[${i}][vehicle_source_table]" value="${esc(data.vehicle_source_table || '')}" data-vehicle-source>
            <input type="hidden" name="transports[${i}][from_location]" value="${esc(data.from_location || '')}" data-route-from>
            <input type="hidden" name="transports[${i}][to_location]" value="${esc(data.to_location || '')}" data-route-to>
            <input type="hidden" name="transports[${i}][status]" value="requested">`;

        document.getElementById('gp-transport-rows').appendChild(row);

        const routeSelect = row.querySelector('[data-transport-route]');
        const vehicleSelect = row.querySelector('[data-transport-vehicle]');
        const routeName = row.querySelector('[data-route-name]');
        const routeMasterId = row.querySelector('[data-route-master-id]');
        const routeSource = row.querySelector('[data-route-source]');
        const routeFrom = row.querySelector('[data-route-from]');
        const routeTo = row.querySelector('[data-route-to]');
        const vehicleMasterId = row.querySelector('[data-vehicle-master-id]');
        const vehicleSource = row.querySelector('[data-vehicle-source]');
        const company = row.querySelector('[data-transport-company]');
        const contact = row.querySelector('[data-transport-contact]');
        const brn = row.querySelector('[data-transport-brn]');

        const applyRoute = () => {
            const selectedKey = String(routeSelect?.value || '');
            const route = transportRouteOptions.find(item => routeOptionKey(item) === selectedKey);
            if (!route) return;

            routeName.value = route.name || '';
            routeMasterId.value = route.id || '';
            routeSource.value = route.source_table || '';
            routeFrom.value = route.from_location || '';
            routeTo.value = route.to_location || '';

            if (!company.value && route.company_name) company.value = route.company_name;
            if (!contact.value && route.contact_number) contact.value = route.contact_number;
            if (!brn.value && route.brn_number) brn.value = route.brn_number;

            if (!vehicleSelect.value && route.vehicle_type) {
                let option = Array.from(vehicleSelect.options).find(opt => String(opt.value).toLowerCase() === String(route.vehicle_type).toLowerCase());
                if (!option) {
                    option = new Option(route.vehicle_type, route.vehicle_type, true, true);
                    option.dataset.source = route.source_table || '';
                    option.dataset.id = route.id || 0;
                    vehicleSelect.add(option);
                }
                vehicleSelect.value = route.vehicle_type;
                vehicleSelect.dispatchEvent(new Event('change'));
            }
        };

        const applyVehicle = () => {
            const option = vehicleSelect?.selectedOptions?.[0];
            vehicleMasterId.value = option?.dataset?.id || '';
            vehicleSource.value = option?.dataset?.source || '';
        };

        routeSelect?.addEventListener('change', applyRoute);
        vehicleSelect?.addEventListener('change', applyVehicle);
        applyVehicle();

        bindRemove(row); updateCounts();
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
        if (workflowState?.commercial_pricing_locked) return;

        const num = name => Number(form.querySelector(`[name="${name}"]`)?.value || 0);
        const adultPax = Math.max(0, Math.trunc(num('booked_adult_pax')));
        const childPax = Math.max(0, Math.trunc(num('booked_child_pax')));
        const infantPax = Math.max(0, Math.trunc(num('booked_infant_pax')));
        const booked = adultPax + childPax + infantPax;

        const bookedInput = document.getElementById('gp-booked-pax');
        if (bookedInput) bookedInput.value = booked;

        const gross =
            adultPax * num('adult_sale_price')
            + childPax * num('child_sale_price')
            + infantPax * num('infant_sale_price');

        const supplier =
            adultPax * num('adult_supplier_cost')
            + childPax * num('child_supplier_cost')
            + infantPax * num('infant_supplier_cost');

        const discountValue = num('discount_value');
        const type = form.querySelector('[name="discount_type"]')?.value || 'none';
        const discount = type === 'percent'
            ? gross * Math.min(discountValue,100)/100
            : type === 'fixed'
                ? Math.min(discountValue,gross)
                : 0;

        const finalSale = Math.max(0,gross-discount);
        const margin = finalSale
            - supplier
            - num('agent_commission')
            - num('salesperson_commission');

        document.getElementById('gp-gross-sale-total').value = gross.toFixed(2);
        document.getElementById('gp-supplier-cost-total').value = supplier.toFixed(2);
        document.getElementById('gp-final-sale').value = finalSale.toFixed(2);
        document.getElementById('gp-net-margin').value = margin.toFixed(2);

        updateCounts();
    }

    document.querySelectorAll('.gp-commercial-input').forEach(el => el.addEventListener('input', calculateCommercial));
    document.querySelectorAll('.gp-commercial-input').forEach(el => el.addEventListener('change', calculateCommercial));

    document.getElementById('gp-add-passenger').addEventListener('click', () => {
        if (canAddPassengerSlot()) addPassenger();
    });
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
            item.addEventListener('click', () => {
                if (!canAddPassengerSlot()) return;
                addPassenger(p);
                searchPanel.style.display='none';
                searchInput.value='';
            });
            results.appendChild(item);
        });
    }

    function showAlert(type, message){ const el=document.getElementById('gp-alert'); el.className=`gp-alert ${type}`; el.innerHTML=message; el.scrollIntoView({behavior:'smooth',block:'center'}); }

    function setWorkflowLink(id, url){
        const el = document.getElementById(id);
        if (!el) return;
        if (url) {
            el.href = url;
            el.style.display = '';
            el.removeAttribute('aria-disabled');
        } else {
            el.href = '#';
            el.style.display = 'none';
            el.setAttribute('aria-disabled','true');
        }
    }

    function setWorkflowButton(id, enabled){
        const el = document.getElementById(id);
        if (!el) return;
        el.disabled = !enabled;
        el.style.display = enabled ? '' : 'none';
        el.style.cursor = enabled ? '' : 'not-allowed';
    }

    function applyCommercialPhase(workflow){
        const unlocked = Boolean(workflow?.operations_unlocked);
        const invoiceExists = Boolean(workflow?.invoice_exists);
        const commercialLocked = Boolean(workflow?.commercial_pricing_locked);
        root.classList.toggle('gp-phase-locked', !unlocked);
        root.classList.toggle('gp-commercial-locked', commercialLocked);

        ['gp-passenger-card','gp-flights-card','gp-hotels-card','gp-transport-card','gp-services-card'].forEach(id => {
            const section = document.getElementById(id);
            if (!section) return;
            section.querySelectorAll('input:not([type="hidden"]),select,textarea,button').forEach(el => {
                if (!unlocked) {
                    if (!el.hasAttribute('data-gp-phase-disabled')) {
                        el.setAttribute('data-gp-phase-disabled', el.disabled ? '1' : '0');
                    }
                    el.disabled = true;
                } else {
                    const previous = el.getAttribute('data-gp-phase-disabled');
                    if (previous !== null) {
                        el.disabled = previous === '1';
                        el.removeAttribute('data-gp-phase-disabled');
                    }
                }
            });
        });

        const invoiceStatus = document.getElementById('gp-accounting-invoice-status');
        if (invoiceStatus) invoiceStatus.textContent = workflow?.sales_invoice_number || (workflow?.sales_invoice_status ? workflow.sales_invoice_status : 'Not Created');

        const accountingLink = document.getElementById('gp-accounting-invoice-link');
        if (accountingLink && accountingLink.dataset.commercialSaved === '1') {
            accountingLink.textContent = workflowActions.sales_invoice_label || (workflow?.sales_invoice_number ? `Open ${workflow.sales_invoice_number}` : 'Create Sales Invoice');
        }

        const paymentStatus = document.getElementById('gp-accounting-payment-status');
        if (paymentStatus) paymentStatus.textContent = workflow?.payment_label || 'Pending';

        const commercialButton = document.getElementById('gp-save-commercial');
        if (commercialButton) {
            commercialButton.textContent = workflow?.invoice_exists
                ? 'Update Commercial'
                : 'Save Commercial';

            commercialButton.style.display = workflow?.commercial_pricing_locked
                ? 'none'
                : '';
        }

        const guidance = document.getElementById('gp-accounting-guidance');
        if (guidance) {
            if (workflow?.invoice_exists) {
                const invoiceNo = workflow?.sales_invoice_number || 'Sales Invoice';
                const invoiceStatus = String(workflowActions?.invoice_workflow_status_label || workflow?.sales_invoice_status || 'Draft');
                guidance.textContent = `${invoiceNo} is ${invoiceStatus}. `
                    + (workflowActions?.invoice_workflow_action?.label ? `Next accounting action: ${workflowActions.invoice_workflow_action.label}. ` : '')
                    + 'Travel operations may continue in parallel; customer payment/receipt is separate from invoice posting.';
            } else {
                guidance.textContent = workflow?.legacy_accounting_gap
                    ? 'Accounting gap: this existing voucher was already processed before the invoice-first workflow. Create the Customer Sales Invoice now; the existing voucher history is preserved.'
                    : (workflow?.package_saved
                        ? (workflow?.booking_status === 'confirmed'
                            ? 'Booking is confirmed. Create the Customer Sales Invoice; travel operations may continue in parallel.'
                            : 'Commercial is saved. Confirm Booking, then create the Customer Sales Invoice. Travel operations may continue in parallel.')
                        : 'Save Commercial first. Passenger, flight, hotel, transport and service entry unlock immediately after the commercial package is saved.');
            }
        }
    }

    function applyEditingLock(workflow){
        const locked = Boolean(workflow?.editing_locked);
        root.classList.toggle('gp-editing-locked', locked);

        const lockPill = document.getElementById('gp-edit-lock-pill');
        if (lockPill) lockPill.textContent = workflow?.editing_lock_label || (locked ? 'Editing Locked' : 'Open for Editing');

        const editableSections = form.querySelectorAll(
            'section.gp-card:not(#gp-workflow-card) input:not([type="hidden"]), ' +
            'section.gp-card:not(#gp-workflow-card) select, ' +
            'section.gp-card:not(#gp-workflow-card) textarea, ' +
            'section.gp-card:not(#gp-workflow-card) button'
        );

        editableSections.forEach(el => {
            if (locked) {
                if (!el.hasAttribute('data-gp-prelock-disabled')) {
                    el.setAttribute('data-gp-prelock-disabled', el.disabled ? '1' : '0');
                }
                el.disabled = true;
            } else {
                const before = el.getAttribute('data-gp-prelock-disabled');
                if (before !== null) {
                    el.disabled = before === '1';
                    el.removeAttribute('data-gp-prelock-disabled');
                }
            }
        });

        const saveButtons = form.querySelectorAll('[data-save-scope]');
        saveButtons.forEach(btn => btn.disabled = locked);
    }

    function renderWorkflow(workflow, actions){
        if (!workflow) return;
        workflowState = workflow;
        workflowActions = actions || workflowActions || {};

        const card = document.getElementById('gp-workflow-card');
        if (card) card.style.display = '';
        const text = (id, value) => { const el=document.getElementById(id); if(el) el.textContent=value || 'Pending'; };
        text('gp-workflow-booking-status', workflow.booking_label);
        text('gp-workflow-voucher-status', workflow.voucher_label);
        text('gp-workflow-accounting-status', workflow.accounting_label);
        text('gp-workflow-manifest-status', `${workflow.passenger_names_received || 0}/${workflow.booked_pax || 0} Names`);
        text('gp-workflow-operations-status', workflow.operations_label || 'Pending');
        text('gp-workflow-voucher-number', workflow.voucher_number || workflowActions.voucher_number || '');
        text('gp-workflow-invoice-number', workflow.sales_invoice_number || 'No invoice created');

        const invoiceLink = document.getElementById('gp-sales-invoice-link');
        if (invoiceLink) invoiceLink.textContent = workflowActions.sales_invoice_label || (workflow.sales_invoice_number ? `Open ${workflow.sales_invoice_number}` : 'Create Sales Invoice');

        applyCommercialPhase(workflow);
        applyEditingLock(workflow);
        setWorkflowButton('gp-reopen-editing', Boolean(workflow.editing_locked && workflow.can_reopen));
        setWorkflowButton('gp-accounting-confirm-booking', Boolean(workflow.can_confirm));
        setWorkflowButton('gp-voucher-submit', Boolean(workflow.can_submit_voucher));
        setWorkflowButton('gp-voucher-approve', Boolean(workflow.can_approve_voucher));
        setWorkflowButton('gp-voucher-issue', Boolean(workflow.can_issue_voucher));

        setWorkflowLink('gp-booking-workspace', workflowActions.booking_workspace_url);
        setWorkflowLink('gp-voucher-preview', workflowActions.voucher_preview_url);
        setWorkflowLink('gp-sales-invoice-link', workflowActions.sales_invoice_url);
        setWorkflowLink('gp-accounting-invoice-link', workflowActions.sales_invoice_url);
        setWorkflowLink('gp-supplier-payable-link', workflowActions.supplier_payable_url);
        setWorkflowLink('gp-receipt-link', workflowActions.receipt_url);
        setWorkflowLink('gp-payment-link', workflowActions.payment_url);
    }

    async function runWorkflowAction(action){
        if (!workflowUrl) {
            showAlert('error','Save the booking first before using workflow actions.');
            return;
        }

        const url = workflowUrl.replace('__ACTION__', action);
        root.classList.add('gp-saving');
        try {
            const fd = new FormData();
            fd.append('_token', form.querySelector('[name="_token"]')?.value || '');
            const response = await fetch(url,{method:'POST',body:fd,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                if (response.status === 422 && data.errors) {
                    showAlert('error', Object.values(data.errors).flat().map(esc).join('<br>'));
                } else {
                    showAlert('error', esc(data.message || 'Workflow action could not be completed.'));
                }
                return;
            }
            renderWorkflow(data.workflow, data.actions);
            showAlert('ok', `<strong>${esc(data.message || 'Workflow updated.')}</strong>`);
        } catch (e) {
            showAlert('error','The server could not be reached while updating the workflow.');
        } finally {
            root.classList.remove('gp-saving');
        }
    }

    document.querySelectorAll('[data-workflow-action]').forEach(btn => {
        btn.addEventListener('click', () => runWorkflowAction(btn.dataset.workflowAction));
    });

    document.getElementById('gp-accounting-invoice-link')?.addEventListener('click', event => {
        const link = event.currentTarget;

        if (link.dataset.commercialSaved !== '1') {
            event.preventDefault();
            showAlert('warn', 'Save the Group Umrah commercial package first. Passenger names are not required.');
            return;
        }

        if (
            !workflowState?.invoice_exists
            && workflowState?.booking_status !== 'confirmed'
        ) {
            event.preventDefault();
            showAlert('warn', 'Confirm Booking first, then create the Customer Sales Invoice.');
        }
    });


    document.getElementById('gp-sales-invoice-link')?.addEventListener('click', event => {
        if (
            !workflowState?.invoice_exists
            && workflowState?.booking_status !== 'confirmed'
        ) {
            event.preventDefault();
            showAlert('warn', 'Confirm Booking first, then create the Customer Sales Invoice.');
        }
    });

    const amendmentPanel = document.getElementById('gp-amendment-panel');

    function calculateAmendment(){
        const value = id => Number(document.getElementById(id)?.value || 0);
        const adultPax = Math.max(0, Math.trunc(value('gp-amend-adult-pax')));
        const childPax = Math.max(0, Math.trunc(value('gp-amend-child-pax')));
        const infantPax = Math.max(0, Math.trunc(value('gp-amend-infant-pax')));
        const totalPax = adultPax + childPax + infantPax;

        const gross =
            adultPax * value('gp-amend-adult-sale')
            + childPax * value('gp-amend-child-sale')
            + infantPax * value('gp-amend-infant-sale');

        const vendor =
            adultPax * value('gp-amend-adult-cost')
            + childPax * value('gp-amend-child-cost')
            + infantPax * value('gp-amend-infant-cost');

        const discountType = document.getElementById('gp-amend-discount-type')?.value || 'none';
        const discountValue = Math.max(0, value('gp-amend-discount'));

        const discount = discountType === 'percent'
            ? gross * Math.min(discountValue,100) / 100
            : discountType === 'fixed'
                ? Math.min(discountValue,gross)
                : 0;

        const finalSale = Math.max(0,gross-discount);
        const margin = finalSale
            - vendor
            - Math.max(0,value('gp-amend-agent'))
            - Math.max(0,value('gp-amend-salesperson'));

        const set = (id, number, decimals = 2) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = decimals === 0
                ? String(Math.trunc(number))
                : Number(number).toFixed(decimals);
        };

        set('gp-amend-total-pax', totalPax, 0);
        set('gp-amend-gross', gross);
        set('gp-amend-vendor-total', vendor);
        set('gp-amend-final', finalSale);
        set('gp-amend-margin', margin);
    }

    document.querySelectorAll('.gp-amend-calc').forEach(el => {
        el.addEventListener('input', calculateAmendment);
        el.addEventListener('change', calculateAmendment);
    });

    document.querySelectorAll('[data-native-invoice-workflow="1"]').forEach(button => {
        button.addEventListener('click', () => {
            const url = button.dataset.workflowUrl || '';
            const method = (button.dataset.workflowMethod || 'POST').toUpperCase();
            if (!url) return;
            const actionForm = document.createElement('form');
            actionForm.method = 'POST'; actionForm.action = url; actionForm.style.display = 'none';
            const csrf = document.createElement('input'); csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || ''; actionForm.appendChild(csrf);
            if (method !== 'POST') { const override = document.createElement('input'); override.type = 'hidden'; override.name = '_method'; override.value = method; actionForm.appendChild(override); }
            document.body.appendChild(actionForm); actionForm.submit();
        });
    });

    document.getElementById('gp-open-amendment')?.addEventListener('click', () => {
        if (workflowState?.editing_locked) {
            showAlert('error', 'This booking is locked. Admin must Reopen for Editing before adding more pax.');
            return;
        }
        amendmentPanel?.classList.toggle('open');
        calculateAmendment();
    });

    document.getElementById('gp-cancel-amendment')?.addEventListener('click', () => {
        amendmentPanel?.classList.remove('open');
    });

    document.getElementById('gp-submit-amendment')?.addEventListener('click', async function(){
        if (!commercialAmendmentUrl) return;
        const button = this;
        button.disabled = true;

        try {
            const response = await fetch(commercialAmendmentUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content
                        || form.querySelector('input[name="_token"]')?.value
                        || ''
                },
                body: JSON.stringify({
                    additional_adult_pax: Number(document.getElementById('gp-amend-adult-pax')?.value || 0),
                    additional_child_pax: Number(document.getElementById('gp-amend-child-pax')?.value || 0),
                    additional_infant_pax: Number(document.getElementById('gp-amend-infant-pax')?.value || 0),
                    additional_adult_sale_price: Number(document.getElementById('gp-amend-adult-sale')?.value || 0),
                    additional_child_sale_price: Number(document.getElementById('gp-amend-child-sale')?.value || 0),
                    additional_infant_sale_price: Number(document.getElementById('gp-amend-infant-sale')?.value || 0),
                    additional_adult_supplier_cost: Number(document.getElementById('gp-amend-adult-cost')?.value || 0),
                    additional_child_supplier_cost: Number(document.getElementById('gp-amend-child-cost')?.value || 0),
                    additional_infant_supplier_cost: Number(document.getElementById('gp-amend-infant-cost')?.value || 0),
                    discount_type: document.getElementById('gp-amend-discount-type')?.value || 'none',
                    discount_value: Number(document.getElementById('gp-amend-discount')?.value || 0),
                    additional_agent_commission: Number(document.getElementById('gp-amend-agent')?.value || 0),
                    additional_salesperson_commission: Number(document.getElementById('gp-amend-salesperson')?.value || 0),
                    vendor_reference: document.getElementById('gp-amend-vendor-ref')?.value || '',
                    notes: document.getElementById('gp-amend-notes')?.value || ''
                })
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.ok) {
                const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
                throw new Error(firstError || data.message || 'Commercial amendment could not be saved.');
            }

            showAlert('success', data.message || 'Pax amendment saved.');
            window.setTimeout(() => window.location.reload(), 450);
        } catch (error) {
            showAlert('error', error.message || 'Commercial amendment could not be saved.');
        } finally {
            button.disabled = false;
        }
    });

    async function save(mode, scope){
        if (workflowState?.editing_locked) {
            showAlert('error', 'Editing is locked. An Admin or Super Admin must use Reopen for Editing first.');
            return;
        }

        if (scope === 'operational' && !workflowState?.package_saved) {
            showAlert('warn', 'Save Commercial first. Operations unlock immediately after the commercial package is saved.');
            return;
        }

        document.getElementById('gp-save-mode').value = mode;
        document.getElementById('gp-save-scope').value = scope;
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
                } else { const stage=data.save_stage?` (${esc(data.save_stage)})`:''; showAlert('error',`${esc(data.message||'Booking could not be saved.')}${stage}`); }
                return;
            }
            form.dataset.updateUrl = data.update_url || form.dataset.updateUrl;
            document.getElementById('gp-booking-ref').textContent = data.booking_reference || 'Saved';
            const focusRef = document.getElementById('gp-focus-booking-ref'); if (focusRef) focusRef.textContent = data.booking_reference || 'Saved';
            const commercialFlow = document.getElementById('gp-commercial-flow-status');
            const accountingLink = document.getElementById('gp-accounting-invoice-link');

            if (scope === 'commercial') {
                if (commercialFlow) commercialFlow.textContent = 'Saved';

                if (accountingLink) {
                    accountingLink.dataset.commercialSaved = '1';
                    accountingLink.textContent = (data.actions && data.actions.sales_invoice_label)
                        ? data.actions.sales_invoice_label
                        : 'Create Sales Invoice';
                }
            }
            if (data.package_code) document.getElementById('gp-package-code').value = data.package_code;
            if (data.edit_url && window.history?.replaceState) window.history.replaceState({},'',data.edit_url);
            if (data.workflow_url) workflowUrl = data.workflow_url;
            renderWorkflow(data.workflow, data.actions);
            const workflowRef = document.getElementById('gp-workflow-booking-ref'); if (workflowRef && data.booking_reference) workflowRef.textContent = data.booking_reference;
            let message = `<strong>${esc(data.message || 'Booking saved.')}</strong>`;
            if (data.warnings?.length) message += '<br>'+data.warnings.map(esc).join('<br>');
            showAlert(data.warnings?.length ? 'warn' : 'ok', message);
        } catch (e) { showAlert('error','The server could not be reached while saving. Please try again.'); }
        finally { root.classList.remove('gp-saving'); }
    }

    document.querySelectorAll('[data-save-scope]').forEach(btn => btn.addEventListener('click', () => save(btn.dataset.saveMode, btn.dataset.saveScope)));

    function enableFocusedWorkspace(){
        document.body.classList.add('gp-focus-mode');

        // Prevent the browser-level horizontal scrollbar. Individual passenger,
        // flight, hotel and transport rows keep their own local horizontal
        // scrollers where needed.
        document.documentElement.style.overflowX = 'hidden';
        document.body.style.overflowX = 'hidden';
        document.documentElement.style.maxWidth = '100%';
        document.body.style.maxWidth = '100%';

        const visibleRect = el => {
            try {
                const style = window.getComputedStyle(el);
                const rect = el.getBoundingClientRect();
                return style.display !== 'none'
                    && style.visibility !== 'hidden'
                    && rect.width > 0
                    && rect.height > 0;
            } catch (_) {
                return false;
            }
        };

        // Hide the native permanent sidebar, but keep it available through
        // the compact title-level Menu button as an overlay drawer.
        const sidebarCandidates = Array.from(document.querySelectorAll(
            'aside, nav, [class*="sidebar"], [class*="navbar-vertical"], [class*="side-nav"]'
        )).filter(el => !root.contains(el));

        const sidebar = sidebarCandidates.find(el => {
            const text = String(el.textContent || '').replace(/\s+/g,' ').toLowerCase();
            return text.includes('dashboard') && text.includes('easy ticket');
        });

        const menuButton = document.getElementById('gp-focus-menu');
        const dashboardButton = document.getElementById('gp-dashboard-link');

        // Resolve Dashboard from the REAL native ERP sidebar instead of assuming
        // /dashboard. This preserves the installation's actual route.
        if (sidebar && dashboardButton) {
            const dashboardAnchor = Array.from(sidebar.querySelectorAll('a')).find(anchor => {
                const label = String(anchor.textContent || '').replace(/\s+/g,' ').trim().toLowerCase();
                return label === 'dashboard' || label.startsWith('dashboard ');
            });

            if (dashboardAnchor?.href && !dashboardAnchor.href.endsWith('#')) {
                dashboardButton.href = dashboardAnchor.href;
                dashboardButton.style.display = '';
            } else {
                dashboardButton.style.display = 'none';
            }
        } else if (dashboardButton) {
            dashboardButton.style.display = 'none';
        }

        let overlay = null;

        if (sidebar) {
            sidebar.setAttribute('data-gp-focus-sidebar','1');
            sidebar.dataset.gpFocusWidth = `${Math.max(200, Math.round(sidebar.getBoundingClientRect().width || 240))}px`;
            sidebar.style.display = 'none';

            overlay = document.createElement('div');
            overlay.className = 'gp-focus-overlay';
            document.body.appendChild(overlay);

            const closeMenu = () => {
                sidebar.classList.remove('gp-focus-sidebar-open');
                sidebar.style.display = 'none';
                overlay.classList.remove('open');
            };

            const openMenu = () => {
                sidebar.style.width = sidebar.dataset.gpFocusWidth || '240px';
                sidebar.style.display = 'block';
                sidebar.classList.add('gp-focus-sidebar-open');
                overlay.classList.add('open');
            };

            menuButton?.addEventListener('click', openMenu);
            overlay.addEventListener('click', closeMenu);
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape') closeMenu();
            });
        } else if (menuButton) {
            menuButton.style.display = 'none';
        }

        // Hide the native ERP top header on this dedicated workspace.
        const nativeHeaderCandidates = Array.from(document.querySelectorAll(
            'header, [class*="topbar"], [class*="top-bar"], [class*="page-header"], [class*="navbar"]'
        )).filter(el => !root.contains(el) && visibleRect(el));

        const nativeHeader = nativeHeaderCandidates
            .filter(el => {
                const text = String(el.textContent || '').replace(/\s+/g,' ').toLowerCase();
                const rect = el.getBoundingClientRect();
                return rect.height <= 150
                    && (text.includes('sign out') || text.includes('logout'))
                    && (
                        text.includes('dashboard')
                        || text.includes('easy group')
                        || text.includes('easy ticket')
                    );
            })
            .sort((a,b) => a.getBoundingClientRect().height - b.getBoundingClientRect().height)[0];

        if (nativeHeader) {
            nativeHeader.setAttribute('data-gp-focus-native-header','1');
            nativeHeader.style.display = 'none';
        }

        const fitWorkspace = () => {
            const gutter = window.innerWidth <= 760 ? 8 : 16;
            const viewportWidth = document.documentElement.clientWidth || window.innerWidth;

            root.style.boxSizing = 'border-box';
            root.style.maxWidth = `${Math.max(320, viewportWidth - (gutter * 2))}px`;
            root.style.width = `${Math.max(320, viewportWidth - (gutter * 2))}px`;

            // Reset, measure the native content offset, then visually align the
            // Group Umrah root to the viewport gutter without increasing its width.
            root.style.marginLeft = '0';
            const rect = root.getBoundingClientRect();
            root.style.marginLeft = `${gutter - rect.left}px`;

            root.style.overflowX = 'visible';
        };

        requestAnimationFrame(() => {
            fitWorkspace();
            requestAnimationFrame(() => {
                fitWorkspace();
                document.documentElement.scrollLeft = 0;
                document.body.scrollLeft = 0;
            });
        });

        window.addEventListener('resize', fitWorkspace, {passive:true});
    }

    enableFocusedWorkspace();

    // Initial page data. Nothing below saves to the server until a save button is pressed.
    existingPassengers.forEach(addPassenger);
    existingFlights.forEach(f => addFlight(f.segment_type || 'outbound', f));
    existingHotels.forEach(addHotel); existingTransports.forEach(addTransport); existingServices.forEach(addService);
    calculateCommercial(); updateCounts();
    renderWorkflow(workflowState, workflowActions);
})();
</script>
@endsection
