@extends('layouts.app')
@section('title',$booking->booking_no)
@section('page_title',$booking->booking_no)
@section('content')
@php
    $overviewOnly=(bool)($overviewOnly ?? false);
    $activePassengers=$booking->passengers->where('is_active',true);
    $activeServices=$booking->services->where('status','!=','CANCELLED');
    $latestInvoice=$booking->salesInvoices->first();

    // Central operational-document bridge. Client Voucher is deliberately separate
    // from Sales Invoice / GL posting and is available to every booking type.
    $clientVoucherTable=\Illuminate\Support\Facades\Schema::hasTable('travel_vouchers');
    $clientVoucherIssueControlsInstalled=$clientVoucherTable
        && \Illuminate\Support\Facades\Schema::hasColumn('travel_vouchers','public_token')
        && \Illuminate\Support\Facades\Schema::hasColumn('travel_vouchers','issued_at')
        && \Illuminate\Support\Facades\Schema::hasColumn('travel_vouchers','issue_revision')
        && \Illuminate\Support\Facades\Schema::hasColumn('travel_vouchers','voided_at');
    $clientVoucher=$clientVoucherTable
        ? \App\Models\TravelVoucher::query()->where('booking_id',$booking->id)->first()
        : null;
    $clientVoucherVoided=$clientVoucherIssueControlsInstalled && (bool)$clientVoucher?->voided_at;
    $clientVoucherIssued=$clientVoucherIssueControlsInstalled && (bool)$clientVoucher?->issued_at && !$clientVoucherVoided;
    $clientVoucherRevision=$clientVoucherIssueControlsInstalled ? max(0,(int)($clientVoucher?->issue_revision ?? 0)) : 0;
    $clientVoucherCanGenerate=in_array(strtoupper((string)$booking->status),['CONFIRMED','COMPLETED'],true)
        && $activePassengers->isNotEmpty();
    // Route-cache safe: after an overlay deployment the old Laravel route cache can
    // temporarily remain active. Never let a missing newly-added route take down the
    // entire Booking Workspace; show a cache-refresh notice instead.
    $clientVoucherIssueRouteReady=\Illuminate\Support\Facades\Route::has('operations.bookings.travel-voucher.issue');
    $clientVoucherVoidRouteReady=\Illuminate\Support\Facades\Route::has('operations.bookings.travel-voucher.void');

    $serviceIssues=collect();
    $ticketCount=0;
    $airTicketSale=0;
    $nonAirSale=0;
    $hotelIssueNo=0;

    foreach($activeServices as $svc){
        $linked=$svc->passengers->where('is_active',true);
        $category=strtoupper((string)$svc->product?->category);
        if(!$overviewOnly && in_array($svc->passenger_link_mode_snapshot,['REQUIRED','MULTIPLE'],true) && $linked->isEmpty()){
            $serviceIssues->push(($svc->product?->name ?: $svc->description).': passengers are not linked.');
        }
        if($category==='AIR_TICKET'){
            $rows=$airTicketRowsByService->get($svc->id,collect());
            $ticketCount += $rows->count();
            $airTicketSale += (float)$rows->sum('selling_total');
            $ticketPassengerIds=$rows->pluck('booking_passenger_id')->unique();
            foreach($overviewOnly ? [] : $linked as $passenger){
                if(!$ticketPassengerIds->contains($passenger->id)){
                    $serviceIssues->push(($svc->product?->name ?: 'Air Ticket').': ticket row missing for '.$passenger->fullName().'.');
                }
            }
        }elseif($category==='HOTEL'){
            $hotelIssueNo++;
            $hotelIssueLabel='Hotel Stay '.str_pad((string)$hotelIssueNo,2,'0',STR_PAD_LEFT);

            $hotelDetail=$hotelDetailsByService->get($svc->id);
            if(!$overviewOnly && !$hotelDetail){
                $serviceIssues->push($hotelIssueLabel.': complete or remove this Hotel Stay.');
            }elseif(!$overviewOnly && $hotelDetail->roomsList->isEmpty()){
                $serviceIssues->push($hotelIssueLabel.': complete the Room Plan or remove this Hotel Stay.');
            }

            $nonAirSale += (float)$svc->line_total;
        }else{
            if(!$overviewOnly && $svc->pricing_basis_snapshot==='PER_PERSON' && ($linked->isEmpty() || abs((float)$svc->quantity-(float)$linked->count())>0.0001)){
                $serviceIssues->push(($svc->product?->name ?: $svc->description).': PER_PERSON quantity must match linked passengers.');
            }
            $nonAirSale += (float)$svc->line_total;
        }
    }
    $operationalValue=$airTicketSale+$nonAirSale;
    $foreignCostUpgradeInstalled=\Illuminate\Support\Facades\Schema::hasTable('exchange_rates') && \Illuminate\Support\Facades\Schema::hasColumn('hotel_booking_details','supplier_cost_foreign');
    $bookingRateDate=$booking->booking_date?->format('Y-m-d') ?? now()->format('Y-m-d');
    $bookingBaseCurrency=strtoupper((string)($booking->currency_code ?: 'PKR'));
    $currentSarFx=$foreignCostUpgradeInstalled ? app(\App\Services\ExchangeRateService::class)->resolve((int)$booking->company_id,'SAR',$bookingBaseCurrency,$bookingRateDate) : null;
    $hotelFxMeta=$foreignCostUpgradeInstalled ? $hotelDetailsByService->mapWithKeys(function($detail){return [(string)$detail->booking_service_id=>[
        'foreign'=>$detail->supplier_cost_foreign!==null?(float)$detail->supplier_cost_foreign:null,
        'fx'=>(float)($detail->supplier_fx_rate ?? 0),
        'fx_date'=>$detail->supplier_fx_rate_date?->format('Y-m-d'),
        'base'=>(float)($detail->supplier_cost_base ?? $detail->net_supplier_cost ?? 0),
    ]];}) : collect();
    $airServices=$activeServices->filter(fn($s)=>strtoupper((string)$s->product?->category)==='AIR_TICKET');
    $hotelServices=$activeServices->filter(fn($s)=>strtoupper((string)$s->product?->category)==='HOTEL');
    $otherServices=$activeServices->reject(fn($s)=>in_array(strtoupper((string)$s->product?->category),['AIR_TICKET','HOTEL'],true));

    // Generic service status shown in the Booking Workspace is intentionally derived from
    // operational completeness. The persisted booking_services.status stays DRAFT until the
    // established Confirm Booking workflow formally confirms the booking/service.
    $visaServiceIds=$otherServices
        ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='VISA')
        ->pluck('id')->values();
    $transportServiceIds=$otherServices
        ->filter(fn($s)=>strtoupper((string)$s->product?->category)==='TRANSPORT')
        ->pluck('id')->values();

    $readyVisaOperatorIds=collect();
    $visaDetailsByService=collect();
    $savedTransportServiceIds=collect();

    if($visaServiceIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('visa_service_operators')){
        $readyVisaOperatorIds=\App\Models\VisaServiceOperator::query()
            ->whereIn('booking_service_id',$visaServiceIds)
            ->whereNotNull('saudi_visa_company_id')
            ->whereNotNull('pakistan_iata_operator_id')
            ->pluck('booking_service_id');
    }
    if($visaServiceIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('visa_booking_details')){
        $visaDetailsByService=\App\Models\VisaBookingDetail::query()
            ->whereIn('booking_service_id',$visaServiceIds)
            ->get(['booking_service_id','booking_passenger_id'])
            ->groupBy('booking_service_id');
    }
    if($transportServiceIds->isNotEmpty() && \Illuminate\Support\Facades\Schema::hasTable('transport_booking_details')){
        $transportReadyQuery=\App\Models\TransportBookingDetail::query()
            ->whereIn('booking_service_id',$transportServiceIds);
        if(\Illuminate\Support\Facades\Schema::hasColumn('transport_booking_details','transport_company_id')){
            $transportReadyQuery->whereNotNull('transport_company_id');
        }
        $savedTransportServiceIds=$transportReadyQuery->pluck('booking_service_id');
    }

    $genericServiceDisplayStatus=function($service) use($readyVisaOperatorIds,$visaDetailsByService,$savedTransportServiceIds){
        $storedStatus=strtoupper((string)$service->status);
        if($storedStatus!=='DRAFT') return $storedStatus;

        $linked=$service->passengers->where('is_active',true);
        $linkedCount=$linked->count();
        $baseReady=true;
        if(in_array($service->passenger_link_mode_snapshot,['REQUIRED','MULTIPLE'],true) && $linkedCount===0){
            $baseReady=false;
        }
        if($service->pricing_basis_snapshot==='PER_PERSON' && ($linkedCount===0 || abs((float)$service->quantity-(float)$linkedCount)>0.0001)){
            $baseReady=false;
        }
        if(!$baseReady) return 'DRAFT';

        $category=strtoupper((string)$service->product?->category);
        if($category==='VISA'){
            $savedPassengerCount=collect($visaDetailsByService->get($service->id,collect()))
                ->pluck('booking_passenger_id')->unique()->count();
            return $linkedCount>0
                && $readyVisaOperatorIds->contains($service->id)
                && $savedPassengerCount >= $linkedCount
                    ? 'READY'
                    : 'DRAFT';
        }
        if($category==='TRANSPORT'){
            return $savedTransportServiceIds->contains($service->id) ? 'READY' : 'DRAFT';
        }

        // Generic services are fully described when the service row itself has passed the
        // required passenger/quantity rules above. They remain formally DRAFT in storage
        // until booking confirmation, but display READY to avoid implying data was not saved.
        return 'READY';
    };

    $airProduct=$products->first(fn($p)=>strtoupper((string)$p->category)==='AIR_TICKET');
    $hotelProduct=$products->first(fn($p)=>strtoupper((string)$p->category)==='HOTEL');

    $bookingType=strtoupper((string)$booking->booking_type);
    $groupPackageEligible=in_array($bookingType,['GROUP','UMRAH'],true);
    $groupPackageInstalled=\Illuminate\Support\Facades\Schema::hasTable('group_travel_packages')
        && \Illuminate\Support\Facades\Schema::hasTable('group_travel_package_passenger_prices');
    $groupPackageRouteReady=\Illuminate\Support\Facades\Route::has('operations.bookings.group-package.edit');
    $groupPackage=$groupPackageEligible && $groupPackageInstalled
        ? \Illuminate\Support\Facades\DB::table('group_travel_packages')->where('booking_id',$booking->id)->first()
        : null;
    $groupPackageVendor=null;
    if($groupPackage?->vendor_party_id && \Illuminate\Support\Facades\Schema::hasTable('parties')){
        $groupPackageVendor=\Illuminate\Support\Facades\DB::table('parties')->where('id',$groupPackage->vendor_party_id)->value('display_name');
    }
    // ERP10.28.4 — once the package commercial is saved, component services are
    // operational only. Their own sale/purchase figures must not drive booking value.
    $groupPackageOperationalMode=$groupPackageEligible && (bool)$groupPackage;
    if($groupPackageOperationalMode){
        $operationalValue=(float)($groupPackage->total_customer_price ?? 0);
    }
    $groupAirCount=$airServices->count();
    $groupHotelCount=$hotelServices->count();
    $groupTransportCount=$otherServices->filter(fn($s)=>strtoupper((string)$s->product?->category)==='TRANSPORT')->count();
    $groupServicesStarted=($groupAirCount+$groupHotelCount+$groupTransportCount)>0;
    $groupCoreServicesPresent=$groupAirCount>0 && $groupHotelCount>0 && $groupTransportCount>0;
    $showAirWorkspace=in_array($bookingType,['GENERAL','AIR_ONLY','UMRAH','GROUP','CORPORATE'],true);
    $showHotelWorkspace=in_array($bookingType,['GENERAL','HOTEL_ONLY','UMRAH','GROUP','CORPORATE'],true);
    $showGenericServices=!in_array($bookingType,['AIR_ONLY','HOTEL_ONLY'],true);

    $lastHotelService=$hotelServices->last();
    $lastHotelDetail=$lastHotelService ? $hotelDetailsByService->get($lastHotelService->id) : null;
    $canAddNextHotel=$hotelServices->isEmpty() || (bool)$lastHotelDetail;

    $hotelSavedCount=$hotelServices->filter(
        fn($service)=>(bool)$hotelDetailsByService->get($service->id)
    )->count();

    $hotelRoomReadyCount=$hotelServices->filter(function($service) use($hotelDetailsByService){
        $detail=$hotelDetailsByService->get($service->id);
        return (bool)($detail && $detail->roomsList->isNotEmpty());
    })->count();

    $hotelConfirmedCount=$hotelServices->filter(function($service) use($hotelDetailsByService){
        $detail=$hotelDetailsByService->get($service->id);
        return (bool)($detail && strtoupper((string)$detail->hotel_status)==='CONFIRMED');
    })->count();

    $hotelConfirmationNoCount=$hotelServices->filter(function($service) use($hotelDetailsByService){
        $detail=$hotelDetailsByService->get($service->id);
        return (bool)($detail && trim((string)$detail->confirmation_number)!=='');
    })->count();

    $hotelVoucherDraftAvailable=$hotelServices->isNotEmpty();
    $hotelVoucherFinalReady=
        $activePassengers->isNotEmpty()
        && $hotelServices->isNotEmpty()
        && $hotelSavedCount===$hotelServices->count()
        && $hotelRoomReadyCount===$hotelServices->count()
        && $hotelConfirmedCount===$hotelServices->count()
        && $hotelConfirmationNoCount===$hotelServices->count()
        && in_array($booking->status,['CONFIRMED','COMPLETED'],true);

    $allAirTicketRows=$airTicketRowsByService->flatten(1);
    $issuedTicketCount=$allAirTicketRows->filter(fn($t)=>in_array(strtoupper((string)$t->ticket_status),['ISSUED','REISSUED'],true))->count();
    $activeInvoice=$latestInvoice && in_array($latestInvoice->status,['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'],true) ? $latestInvoice : null;
    $directCancelBlocked=(bool)$activeInvoice || $issuedTicketCount>0;

    // Render-safe defaults for Supplier Costing summary.
    $forecastCost=0.0;
    $confirmedCost=0.0;
    $gross=0.0;
@endphp

<style>
/* General Booking keeps the authoritative app.css topbar contract. The
 * professional utility class is added after first paint, so scope this
 * correction to pages carrying the booking workspace and avoid changing the
 * shared Review & Process header or other ERP modules. */
body:has(.booking-compact) .topbar,
body:has(.booking-compact) .et-ui-utility-topbar{height:76px;min-height:76px;}
/* The shared utility rule is !important and can compress the profile row
 * after initialization. Keep this contract page-scoped to General Booking. */
html body:has(.booking-compact) .topbar,
html body:has(.booking-compact) .et-ui-utility-topbar{height:76px!important;min-height:76px!important;}
body:has(.booking-compact) .top-actions{flex:0 0 auto;min-width:max-content;flex-wrap:nowrap;align-items:center;}
body:has(.booking-compact) .user-chip{flex:0 0 auto;min-width:max-content;white-space:nowrap;}
body:has(.booking-compact) .user-chip strong,
body:has(.booking-compact) .user-chip small{white-space:nowrap;}
.booking-compact .panel{margin-bottom:14px}
.booking-hero.br-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;min-height:50px;margin-bottom:16px}
.booking-hero.br-top h1{font-size:20px;line-height:1.2;margin:0}
.booking-hero.br-top .meta{font-size:12px;color:#718096;margin-top:5px}
.booking-actions.br-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;overflow:visible;position:relative;z-index:30}
.booking-actions.br-header-actions{justify-content:flex-end}
.booking-actions.br-header-actions .br-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 10px;border:1px solid #ccdaea;border-radius:7px;background:#fff;color:#21466f;text-decoration:none;font-size:10px;font-weight:800;cursor:pointer}
.booking-actions.br-header-actions .br-badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:9px;font-weight:900;background:#eaf3ff;color:#1762b4}
@media(max-width:760px){.booking-hero.br-top{align-items:flex-start}.booking-actions.br-header-actions{justify-content:flex-start;width:100%}.booking-actions.br-header-actions .br-btn{flex:1}}
.booking-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.booking-metric{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px}
.booking-metric small{display:block;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:800;font-size:10px}
.booking-metric strong{display:block;font-size:20px;margin-top:6px;color:var(--text)}
.booking-metric span{display:block;font-size:11px;color:var(--muted);margin-top:4px}
.booking-head-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.booking-head-summary div{padding:8px 0;border-bottom:1px dashed var(--line)}
.booking-head-summary dt{font-size:10px;color:var(--muted);text-transform:uppercase;font-weight:800}
.booking-head-summary dd{margin:4px 0 0;font-weight:700}
.passenger-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.passenger-add-grid{display:grid;grid-template-columns:.75fr .75fr 1fr 1fr 1fr 1fr 1fr auto;gap:8px;align-items:end}
.passenger-table-wrap{overflow-x:auto;margin-top:10px;border:1px solid var(--line);border-radius:11px}
.passenger-table{width:100%;border-collapse:collapse;font-size:11px;min-width:920px}
.passenger-table th{background:#f4f7fb;color:#43536b;text-align:left;padding:8px;font-weight:900}
.passenger-table td{padding:8px;border-top:1px solid var(--line);vertical-align:middle}
.passenger-table .passenger-name{font-weight:800;color:var(--text)}
.passenger-table .action-cell{white-space:nowrap;text-align:right}
.passenger-empty{padding:18px;text-align:center;color:var(--muted)}
.workspace-stack{display:grid;gap:14px}
@if(!$overviewOnly)
.service-create-grid{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end}
.service-create-passengers{display:flex;gap:8px;flex-wrap:wrap}
.service-create-passengers .check-row{margin:0}
@endif
.workflow-more{margin-left:auto;position:relative}
.workflow-more>summary{list-style:none;cursor:pointer;font-weight:900;color:#153865;border:1px solid var(--line);border-radius:9px;padding:9px 12px;background:#fff}
.workflow-more>summary::-webkit-details-marker{display:none}
.workflow-more .menu{margin-top:8px;border:1px solid var(--line);border-radius:12px;padding:12px;background:#fff;box-shadow:0 12px 30px rgba(20,40,70,.10);min-width:min(560px,86vw)}
.workflow-danger{border:1px solid #f2c9c2;background:#fff8f7;border-radius:10px;padding:12px;margin-top:10px}
.workflow-correction{border:1px solid #d8e4f5;background:#f8fbff;border-radius:10px;padding:12px;margin-top:8px}
.workflow-note{font-size:11px;color:var(--muted);line-height:1.5;margin:7px 0}
.workflow-blocked{border:1px solid #f2d59e;background:#fff9ed;border-radius:9px;padding:10px;font-size:11px;color:#81551a;margin-top:8px}

.compact-add details>summary,.compact-service details>summary{cursor:pointer;font-weight:800;color:var(--primary)}
.compact-add .inside,.compact-service .inside{padding-top:12px}
@if(!$overviewOnly)
.air-service-shell{border:1px solid var(--line);border-radius:14px;background:#fff;padding:14px;margin-bottom:12px}
.air-service-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:10px}
.air-service-head h3{margin:0;font-size:16px}
.air-service-head p{margin:4px 0 0;color:var(--muted);font-size:11px}
.air-service-mini{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:10px}
.air-service-mini div{background:#f7f9fc;border:1px solid var(--line);border-radius:9px;padding:8px}
.air-service-mini small{display:block;color:var(--muted);font-size:9px;text-transform:uppercase;font-weight:800}
.air-service-mini strong{display:block;margin-top:4px;font-size:12px}
.other-service-list{display:grid;gap:8px}
.other-service-row{display:grid;grid-template-columns:1.4fr .7fr .7fr .7fr auto;gap:8px;align-items:center;border:1px solid var(--line);border-radius:10px;padding:9px}
@endif
.status-pill.ready{background:#eaf0ff;color:#315ee8}
.workflow-bar{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.workflow-actions{display:flex;gap:8px;flex-wrap:wrap}
.bridge-mini{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.bridge-mini .text{font-size:12px;color:var(--muted)}







@if(!$overviewOnly)
/* ERP-10.18.6 Approved Hotel Stay Layout */
.hotel-stays-header{border-radius:12px!important;padding:12px 14px!important}
.compact-hotel-card{border-radius:12px!important;box-shadow:0 2px 7px rgba(27,51,78,.03)}
.compact-hotel-summary{grid-template-columns:40px minmax(220px,1.6fr) minmax(130px,1fr) repeat(3,minmax(95px,.65fr)) auto!important;padding:10px 13px!important}
.hotel-stay-number{width:30px!important;height:30px!important}
.compact-hotel-title strong{font-size:11px!important}
.compact-hotel-title span{font-size:8px!important}
.compact-hotel-editor>summary{padding:8px 12px!important;background:#f8fafc!important}
.compact-hotel-editor form{padding:10px 12px 12px!important}
.single-hotel-main{grid-template-columns:2.1fr .55fr .55fr .8fr 1.2fr 1fr 1fr .5fr!important;gap:7px!important}
.single-room-plan{grid-template-columns:1fr .65fr .75fr 1fr 1.6fr!important;gap:7px!important}
.single-commercial-row{grid-template-columns:1fr 1fr 1.25fr 1fr 1fr 1fr!important;gap:7px!important}
.compact-profit-bar{margin-top:7px!important}
.compact-hotel-footer{margin-top:7px!important}
.smart-date-control input{font-weight:800!important}
.compact-capacity-bar{padding:6px 9px!important;margin-top:6px!important}
@media(max-width:1150px){
  .single-hotel-main{grid-template-columns:repeat(4,minmax(0,1fr))!important}
  .single-room-plan,.single-commercial-row{grid-template-columns:repeat(3,minmax(0,1fr))!important}
}
@media(max-width:760px){
  .single-hotel-main,.single-room-plan,.single-commercial-row{grid-template-columns:1fr!important}
}


.legacy-empty-hotel-card{border-color:#b9d4f4!important}
.legacy-empty-hotel-card .compact-hotel-summary{background:#fbfdff}

.hotel-sequence-note{margin:-2px 0 10px;border:1px solid #cfe3fa;background:#eef7ff;color:#355c84;border-radius:9px;padding:8px 10px;font-size:9px}
.smart-date-field>span em{display:inline-block;margin-left:5px;border-radius:999px;background:#eaf3ff;color:#1764c0;padding:2px 5px;font-style:normal;font-size:7px;font-weight:900}
.smart-date-control{border:1px solid var(--line);border-radius:8px;background:#fff;overflow:hidden}
.smart-date-control input{border:0!important;border-radius:0!important;padding-bottom:3px!important}

.hotel-stays-header{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;border:1px solid var(--line);border-radius:14px;background:#fff;padding:13px 14px;margin-bottom:10px}
.hotel-stays-header h3{margin:6px 0 0;font-size:16px}.hotel-stays-header p{margin:4px 0 0;color:var(--muted);font-size:10px}
.hotel-stays-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.hotel-stays-actions form{margin:0}
.hotel-stay-number{width:32px;height:32px;border-radius:999px;background:#1464d2;color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900}
.single-hotel-main{display:grid;grid-template-columns:2.2fr .55fr .55fr .8fr 1.2fr 1fr 1fr .55fr;gap:8px;align-items:end}
.hotel-picker-line{display:grid;grid-template-columns:1fr auto;gap:6px}
.new-hotel-fields{display:grid;grid-template-columns:2fr 1.25fr .55fr .55fr;gap:6px;margin-top:6px;padding:8px;border:1px solid #cfe0f4;border-radius:9px;background:#f8fbff}
.single-room-plan-title,.single-commercial-title{border-top:1px solid var(--line);margin-top:10px;padding-top:10px}
.single-room-plan-title strong,.single-commercial-title strong{display:block;font-size:11px}
.single-room-plan-title span{display:block;font-size:9px;color:var(--muted);margin-top:2px}
.single-room-plan{display:grid;grid-template-columns:1fr .7fr .8fr .9fr 1fr 1.5fr;gap:8px;align-items:end;margin-top:7px}
.single-commercial-row{display:grid;grid-template-columns:1fr 1fr 1.3fr 1fr 1fr 1fr;gap:8px;align-items:end;margin-top:7px}
@media(max-width:1150px){.single-hotel-main{grid-template-columns:repeat(4,minmax(0,1fr))}.single-room-plan,.single-commercial-row{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.hotel-stays-header{display:block}.hotel-stays-actions{margin-top:10px}.single-hotel-main,.single-room-plan,.single-commercial-row,.new-hotel-fields{grid-template-columns:1fr}}

.compact-hotel-card{border:1px solid var(--line);border-radius:14px;background:#fff;margin-bottom:12px;overflow:hidden}
.compact-hotel-summary{display:grid;grid-template-columns:38px minmax(220px,1.5fr) minmax(130px,1fr) repeat(3,minmax(105px,.7fr)) auto;gap:10px;align-items:center;padding:11px 13px}
.compact-hotel-number{width:32px;height:32px;border-radius:9px;background:#eef6ff;display:flex;align-items:center;justify-content:center}
.compact-hotel-title strong{display:block;font-size:12px}.compact-hotel-title span{display:block;font-size:9px;color:var(--muted);margin-top:3px}
.compact-hotel-summary-stat small{display:block;font-size:8px;color:var(--muted);text-transform:uppercase;font-weight:900}
.compact-hotel-summary-stat strong{display:block;font-size:10px;margin-top:3px}
.compact-hotel-summary-stat.margin strong{color:#0b7a49}
.compact-hotel-editor{border-top:1px solid var(--line)}
.compact-hotel-editor>summary{cursor:pointer;list-style:none;padding:9px 13px;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;font-size:10px;font-weight:900;color:#314860}
.compact-hotel-editor>summary::-webkit-details-marker{display:none}
.compact-hotel-editor form{padding:12px}
.compact-hotel-grid{display:grid;gap:8px}.compact-hotel-main{grid-template-columns:2fr 1fr 1.2fr 1fr 1fr .7fr}
.compact-readonly{border:1px solid var(--line);border-radius:8px;background:#f7f9fc;padding:7px 8px}
.compact-readonly span{display:block;font-size:8px;color:var(--muted);font-weight:800;text-transform:uppercase}
.compact-readonly strong{display:block;font-size:10px;margin-top:3px}
.compact-hotel-subhead{display:flex;justify-content:space-between;gap:10px;align-items:center;border-top:1px solid var(--line);margin-top:10px;padding-top:10px}
.compact-hotel-subhead strong{display:block;font-size:11px}.compact-hotel-subhead span{display:block;font-size:9px;color:var(--muted);margin-top:2px}
.compact-room-lines{display:flex;flex-direction:column;gap:6px;margin-top:7px}
.compact-room-line{display:grid;grid-template-columns:1.1fr .65fr .8fr .65fr 1fr 1.3fr auto;gap:7px;align-items:end;border:1px solid #dce5ef;border-radius:9px;background:#fbfcfe;padding:8px}
.compact-room-line .hidden{display:none}
.compact-room-remove{margin-bottom:1px}
.compact-capacity-bar{display:flex;align-items:center;gap:18px;flex-wrap:wrap;border-radius:8px;background:#f1f8f4;border:1px solid #d3eadc;padding:8px 10px;margin-top:8px;font-size:9px}
.compact-capacity-bar .ok{color:#08794c}.compact-capacity-bar .bad{color:#b42318}
.compact-pricing-row{display:grid;grid-template-columns:1fr 1fr 1.4fr 1fr 1fr 1fr;gap:8px;border-top:1px solid var(--line);margin-top:10px;padding-top:10px}
.compact-inline-fields{display:grid;grid-template-columns:.8fr 1fr;gap:5px}
.compact-profit-bar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin-top:9px}
.compact-profit-bar>div{border:1px solid var(--line);border-radius:8px;background:#f7f9fc;padding:7px 8px}
.compact-profit-bar small{display:block;font-size:8px;color:var(--muted);text-transform:uppercase;font-weight:900}
.compact-profit-bar strong{display:block;font-size:10px;margin-top:3px}
.compact-hotel-footer{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;margin-top:9px}
@media(max-width:1200px){
  .compact-hotel-summary{grid-template-columns:38px 1.5fr repeat(2,1fr) auto}
  .compact-hotel-summary-stat:nth-of-type(3),.compact-hotel-summary-stat:nth-of-type(4){display:none}
  .compact-hotel-main{grid-template-columns:repeat(3,minmax(0,1fr))}
  .compact-room-line{grid-template-columns:repeat(3,minmax(0,1fr))}
  .compact-pricing-row{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:760px){
  .compact-hotel-summary{grid-template-columns:32px 1fr auto}
  .compact-hotel-summary-stat{display:none!important}
  .compact-hotel-main,.compact-room-line,.compact-pricing-row,.compact-profit-bar,.compact-hotel-footer{grid-template-columns:1fr}
}

.hotel-start-panel{border:1px solid #cfe0f4;background:linear-gradient(180deg,#fff 0%,#f8fbff 100%);border-radius:14px;padding:14px;margin-bottom:12px}
.hotel-start-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}
.hotel-start-head h3{margin:6px 0 0;font-size:16px}.hotel-start-head p{margin:4px 0 0;color:var(--muted);font-size:10px;line-height:1.5}
.hotel-start-grid{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:end;margin-top:12px}
.hotel-start-passengers>span{display:block;font-size:9px;font-weight:900;color:#42546c;margin-bottom:6px;text-transform:uppercase}
.hotel-start-passenger-list{display:flex;flex-wrap:wrap;gap:6px}
.hotel-start-passenger-list .check-row{margin:0;background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 8px}
.hotel-start-action .btn{min-width:180px}
.hotel-start-empty{border:1px dashed #cbd8e6;border-radius:10px;padding:12px;color:var(--muted);font-size:10px;margin-top:10px}
.hotel-type-chip{display:inline-block;border-radius:999px;background:#eaf3ff;color:#1764c0;padding:4px 7px;font-size:8px;font-weight:900}
@media(max-width:760px){.hotel-start-grid{grid-template-columns:1fr}.hotel-start-action .btn{width:100%}}

.hotel-service-shell{border:1px solid var(--line);border-radius:14px;background:#fff;padding:14px;margin-bottom:12px}
.hotel-service-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:10px}
.hotel-service-head h3{margin:2px 0 0;font-size:17px}.hotel-service-head p{margin:4px 0 0;color:var(--muted);font-size:11px}
.hotel-head-actions{display:flex;gap:8px;align-items:center}
.hotel-metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-bottom:10px}
.hotel-metrics>div,.hotel-profit-row>div{border:1px solid var(--line);background:#f7f9fc;border-radius:9px;padding:8px}
.hotel-metrics small,.hotel-profit-row small{display:block;font-size:8px;text-transform:uppercase;font-weight:900;color:var(--muted)}
.hotel-metrics strong,.hotel-profit-row strong{display:block;margin-top:4px;font-size:11px}
.hotel-section{border-top:1px solid var(--line);padding-top:12px;margin-top:12px}
.hotel-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
.hotel-section-title strong{font-size:11px}.hotel-section-title span{display:block;font-size:9px;color:var(--muted);font-weight:600}
.hotel-grid{display:grid;gap:8px}.hotel-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
.hotel-readonly{border:1px solid var(--line);border-radius:8px;background:#f7f9fc;padding:7px 9px}
.hotel-readonly span{display:block;font-size:8px;color:var(--muted);font-weight:800;text-transform:uppercase}
.hotel-readonly strong{display:block;font-size:11px;margin-top:3px}.hotel-readonly.total{background:#eef7ff;border-color:#cfe3fa}
.hotel-room-card{border:1px solid #d8e3ef;border-radius:10px;background:#fbfcfe;padding:10px;margin-top:8px}
.hotel-room-head{display:flex;justify-content:space-between;gap:8px;align-items:center;margin-bottom:8px}
.hotel-room-head strong{font-size:11px}
.hotel-room-passengers{margin-top:8px}.hotel-room-passengers>span{display:block;font-size:9px;font-weight:900;color:#40536d;margin-bottom:5px}
.hotel-passenger-checks{display:flex;flex-wrap:wrap;gap:6px}
.hotel-passenger-checks .check-row{margin:0;background:#fff;border:1px solid var(--line);border-radius:8px;padding:6px 8px}
.hotel-commercial-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.hotel-commercial-card{border:1px solid var(--line);border-radius:11px;padding:10px;background:#fff}
.hotel-commercial-card.customer{background:#f7fbff}.hotel-commercial-card.supplier{background:#f8fcfa}
.hotel-commercial-card.agent{background:#fcf8ff}.hotel-commercial-card.salesperson{background:#fffaf2}
.hotel-commercial-card label{margin-top:7px}.hotel-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hotel-profit-row{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:12px}
.hotel-save-bar{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-top:10px;border-top:1px solid var(--line);padding-top:10px}
.hotel-save-bar span{font-size:9px;color:var(--muted);line-height:1.5;max-width:75%}
.hotel-readonly-view{padding:12px;border:1px dashed var(--line);border-radius:9px;color:var(--muted);font-size:11px}.hotel-readonly-view strong,.hotel-readonly-view span{display:block}
@media(max-width:1100px){.hotel-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.hotel-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.hotel-metrics,.hotel-grid-4,.hotel-commercial-grid,.hotel-profit-row,.hotel-two{grid-template-columns:1fr}.hotel-save-bar{display:block}.hotel-save-bar span{max-width:none;display:block;margin-bottom:8px}}
@endif

.passenger-toolbar{align-items:flex-start}
.passenger-add-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.passenger-add-actions .btn{white-space:nowrap}
.passenger-picker{display:none;width:100%;max-width:100%;margin-top:12px;border:1px solid #d7e2ef;border-radius:12px;background:#fbfcfe;padding:12px;box-sizing:border-box;overflow:visible}
.passenger-picker.open{display:block}
.passenger-picker-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.passenger-picker-head strong{font-size:12px;color:#263950}
.passenger-picker-head span{display:block;font-size:9px;color:var(--muted);margin-top:2px}
.passenger-picker-close{border:1px solid var(--line);background:#fff;border-radius:8px;padding:6px 9px;font-size:10px;font-weight:800;color:#53657a;cursor:pointer}
.passenger-picker-tabs{display:flex;gap:6px;border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:10px}
.passenger-picker-tab{border:1px solid var(--line);background:#f5f8fc;color:#53657a;border-radius:8px;padding:7px 11px;font-size:10px;font-weight:900;cursor:pointer}
.passenger-picker-tab.active{background:#1464d2;color:#fff;border-color:#1464d2}
.passenger-picker-panel{display:none}.passenger-picker-panel.active{display:block}
.saved-passenger-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:end;margin-bottom:8px}
.saved-passenger-search input{width:100%;min-width:0}
.saved-passenger-note{font-size:9px;color:var(--muted);line-height:1.45;margin-bottom:8px}
.saved-passenger-results{border:1px solid var(--line);border-radius:10px;overflow:auto;max-height:260px;background:#fff}
.saved-passenger-row{display:grid;grid-template-columns:34px minmax(220px,1.6fr) 72px 118px 108px;gap:8px;align-items:center;padding:8px 10px;border-top:1px solid var(--line);font-size:10px;min-width:650px}
.saved-passenger-row:first-child{border-top:0}
.saved-passenger-row.head{position:sticky;top:0;z-index:2;background:#eef3f9;font-weight:900;color:#47566b}
.saved-passenger-name strong{display:block;font-size:10px}
.saved-passenger-name small{display:block;color:var(--muted);margin-top:2px;line-height:1.35}
.previous-badge{display:inline-block;border-radius:999px;background:#e9f7ef;color:#08794c;padding:2px 6px;font-size:8px;font-weight:900;margin-left:4px}
.already-badge{display:inline-block;border-radius:999px;background:#f1f3f6;color:#667085;padding:2px 6px;font-size:8px;font-weight:900}
.saved-passenger-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:9px;padding-top:9px;border-top:1px solid var(--line)}
.saved-passenger-footer .check-row{font-size:9px;color:#526277}
.passenger-master-info{background:#eef7ff;border:1px solid #cfe3fa;border-radius:9px;padding:8px 10px;font-size:9px;line-height:1.45;color:#345273;margin-bottom:8px}
.new-passenger-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
.new-passenger-grid .wide{grid-column:span 2}
.passenger-source{font-size:8px;color:#1464d2;font-weight:900;text-transform:uppercase;letter-spacing:.03em;margin-top:2px}
.passenger-current-label{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px;margin-bottom:6px}
.passenger-current-label strong{font-size:10px;text-transform:uppercase;letter-spacing:.035em;color:#43536b}
.passenger-current-label span{font-size:9px;color:var(--muted)}

.passenger-pax-stack{display:flex;flex-direction:column;gap:4px;min-width:120px}
.passenger-pax-system{font-size:9px;color:var(--muted)}
.passenger-pax-system strong{color:#42526b}
.passenger-pax-override{display:flex;align-items:center;gap:5px}
.passenger-pax-override select{min-width:82px;padding:5px 7px;font-size:10px}
.pax-override-badge{display:inline-block;border-radius:999px;background:#fff3df;color:#9a5b08;padding:2px 6px;font-size:8px;font-weight:900}
.pax-auto-badge{display:inline-block;border-radius:999px;background:#edf7f1;color:#08794c;padding:2px 6px;font-size:8px;font-weight:900}

@media(max-width:900px){
  .new-passenger-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:700px){
  .passenger-toolbar{display:block}
  .passenger-add-actions{justify-content:flex-start;margin-top:10px}
  .passenger-add-actions .btn{flex:1 1 auto;text-align:center}
  .passenger-picker{padding:10px}
  .saved-passenger-results{max-height:52vh}
  .saved-passenger-row{grid-template-columns:30px minmax(180px,1fr) 70px;min-width:410px}
  .saved-passenger-row>*:nth-child(4),.saved-passenger-row>*:nth-child(5){display:none}
  .saved-passenger-search{grid-template-columns:1fr}
  .saved-passenger-search .btn{width:100%}
  .saved-passenger-footer{align-items:stretch}
  .saved-passenger-footer .btn{width:100%}
  .new-passenger-grid{grid-template-columns:1fr}
  .new-passenger-grid .wide{grid-column:auto}
}

@media(max-width:1100px){.booking-head-summary,.booking-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.passenger-add-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:700px){.booking-head-summary,.booking-metrics,.air-service-mini{grid-template-columns:1fr}.other-service-row{grid-template-columns:1fr}.passenger-add-grid{grid-template-columns:1fr 1fr}.service-create-grid{grid-template-columns:1fr}}

@if(!$overviewOnly)
/* ERP-10.18.11 — approved smart Hotel two-line entry */
.smart-two-line-hotel .compact-hotel-editor form{
    padding:10px 12px 12px!important;
}
.hotel-identity-row{
    display:grid;
    grid-template-columns:minmax(240px,2.2fr) minmax(120px,1fr) 82px 92px 110px minmax(155px,1.35fr);
    gap:8px;
    align-items:end;
}
.hotel-stay-room-row{
    display:grid;
    grid-template-columns:minmax(145px,1.15fr) minmax(145px,1.15fr) 72px minmax(120px,1fr) 82px 90px minmax(135px,1fr) minmax(160px,1.35fr);
    gap:8px;
    align-items:end;
    border-top:1px solid var(--line);
    margin-top:9px;
    padding-top:9px;
}
.hotel-commercial-row{
    display:grid;
    grid-template-columns:1fr .8fr .8fr 1.35fr 1fr 1fr 1fr;
    gap:8px;
    align-items:end;
    margin-top:7px;
}
.hotel-source-line{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    gap:6px;
    align-items:end;
}
.new-hotel-source[hidden],
.new-city-source[hidden],
.existing-hotel-source[hidden],
.existing-city-source[hidden],
.sharing-capacity-wrap[hidden]{
    display:none!important;
}
.hotel-auto-box{
    min-height:38px;
    border:1px solid var(--line);
    border-radius:8px;
    background:#f7f9fc;
    padding:6px 8px;
    overflow:hidden;
}
.hotel-auto-box>span{
    display:flex;
    gap:4px;
    align-items:center;
    font-size:7px;
    color:var(--muted);
    font-weight:900;
    text-transform:uppercase;
    white-space:nowrap;
}
.hotel-auto-box>span em{
    border-radius:999px;
    padding:1px 4px;
    background:#e9f8ef;
    color:#168152;
    font-style:normal;
    font-size:6px;
}
.hotel-auto-box>strong{
    display:block;
    margin-top:4px;
    font-size:10px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.hotel-nights-box strong{
    font-size:13px;
}
.hotel-room-note{
    min-width:0;
}
.smart-two-line-hotel label>span{
    white-space:nowrap;
}
.smart-two-line-hotel input,
.smart-two-line-hotel select{
    min-width:0;
}
@media(max-width:1280px){
    .hotel-identity-row{
        grid-template-columns:2fr 1fr 80px 90px 105px 1.25fr;
    }
    .hotel-stay-room-row{
        grid-template-columns:1.15fr 1.15fr 70px 1fr 78px 86px 1fr 1.2fr;
    }
}
@media(max-width:1050px){
    .hotel-identity-row{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }
    .hotel-stay-room-row{
        grid-template-columns:repeat(4,minmax(0,1fr));
    }
    .hotel-commercial-row{
        grid-template-columns:repeat(4,minmax(0,1fr));
    }
}
@media(max-width:760px){
    .hotel-identity-row,
    .hotel-stay-room-row,
    .hotel-commercial-row{
        grid-template-columns:1fr;
    }
}


/* ERP-10.18.13 — explicit Hotel Stay add/remove controls */
.hotel-stays-actions .btn[disabled]{
    opacity:.5;
    cursor:not-allowed;
}
.hotel-add-control-note{
    margin:-2px 0 10px;
    padding:7px 10px;
    border:1px solid #dce7f2;
    border-radius:9px;
    background:#f8fbff;
    color:#53677d;
    font-size:9px;
}
.hotel-stay-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:6px;
    flex-wrap:wrap;
}
.hotel-stay-actions form{
    margin:0;
}
.hotel-stay-remove{
    padding:5px 8px!important;
}


/* ERP-10.19 — Hotel Voucher & Travel Readiness */
.hotel-readiness-panel{border:1px solid var(--line);border-radius:14px;background:#fff;padding:13px 14px;margin:0 0 14px}
.hotel-readiness-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.hotel-readiness-head h3{margin:6px 0 0;font-size:16px}
.hotel-readiness-head p{margin:4px 0 0;color:var(--muted);font-size:9px;max-width:720px;line-height:1.45}
.hotel-readiness-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.hotel-readiness-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;margin-top:10px}
.hotel-ready-item{border:1px solid var(--line);border-radius:8px;padding:7px 8px}
.hotel-ready-item.ready{background:#effaf4;border-color:#d0ebdc}
.hotel-ready-item.pending{background:#fff9ea;border-color:#efdfb9}
.hotel-ready-item small{display:block;color:var(--muted);text-transform:uppercase;font-size:7px;font-weight:900}
.hotel-ready-item strong{display:block;margin-top:3px;font-size:9px}
.hotel-voucher-link{text-decoration:none}
@media(max-width:900px){.hotel-readiness-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.hotel-readiness-head{display:block}.hotel-readiness-actions{justify-content:flex-start;margin-top:9px}.hotel-readiness-grid{grid-template-columns:1fr}}
@endif


/* ERP-10.28.3 — guided Group Travel Package staff flow */
.group-booking-flow{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:6px;margin:0 0 14px}
.group-booking-step{display:block;border:1px solid var(--line);border-radius:10px;background:#fff;padding:8px 9px;text-decoration:none;color:inherit;min-width:0}
.group-booking-step small{display:block;font-size:7px;font-weight:900;text-transform:uppercase;color:var(--muted)}
.group-booking-step strong{display:block;margin-top:3px;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.group-booking-step.done{background:#f1faf5;border-color:#d1eadb}.group-booking-step.current{background:#eef5ff;border-color:#cbdff8}.group-booking-step.locked{opacity:.5;pointer-events:none}
.group-package-step-card{border:1px solid #cfdef0;background:#fbfdff}.group-package-step-card .bridge-mini{align-items:center}
.group-package-status{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.group-package-status span{display:inline-flex;padding:4px 7px;border-radius:999px;background:#eef3f8;font-size:8px;font-weight:800;color:#53677d}.group-package-status span.ready{background:#eaf8f0;color:#1e704a}
.package-services-gate{border:1px dashed #cbd7e5;background:#fbfcfe;border-radius:13px;padding:18px;text-align:center;margin-bottom:14px}.package-services-gate h3{margin:0 0 5px;font-size:14px}.package-services-gate p{margin:0 auto 10px;max-width:620px;color:var(--muted);font-size:9px;line-height:1.45}
/* Group package components are operational only: hide component-level commercial costing UI. */
.group-package-operational .hotel-commercial-grid,
.group-package-operational .hotel-profit-row,
.group-package-operational .single-commercial-title,
.group-package-operational .single-commercial-row{display:none!important}
.group-package-operational .package-operational-note{margin:8px 0 10px;padding:8px 10px;border-radius:9px;background:#eef8f3;border:1px solid #d3eadc;color:#236747;font-size:9px;font-weight:700}
@media(max-width:900px){.group-booking-flow{grid-template-columns:repeat(3,1fr)}}
@media(max-width:620px){.group-booking-flow{grid-template-columns:1fr 1fr}}

</style>

<div class="booking-compact {{ $groupPackageOperationalMode ? 'group-package-operational' : '' }}" data-et-server-overview-only="1">
    <div class="booking-hero br-top">
        <div>
            <span class="eyebrow">BOOKING WORKSPACE · ERP-10.20</span>
            <h1>{{ $booking->booking_no }}</h1>
            <div class="meta">{{ $booking->customer?->display_name }} · {{ str_replace('_',' ',$booking->booking_type) }} · {{ $booking->branch?->name }}</div>
        </div>
        <div class="booking-actions br-actions br-header-actions">
            <span class="status-pill br-badge {{ $booking->status==='CONFIRMED'?'active':($booking->status==='CANCELLED'?'closed':'info') }}">{{ str_replace('_',' ',$booking->status) }}</span>
            <a class="btn btn-ghost br-btn" href="{{ route('operations.bookings.preview',$booking) }}" target="_blank" rel="noopener">Client Preview</a>
            <button type="button" class="btn btn-ghost br-btn et-booking-focus-btn" data-et-booking-focus-menu="1" aria-expanded="false" aria-label="Open booking menu">☰ Menu</button>
            <a class="btn btn-ghost br-btn" href="{{ route('operations.bookings.index') }}">Booking Register</a>
        </div>
    </div>

    <div class="booking-metrics">
        <div class="booking-metric"><small>Passengers</small><strong>{{ $activePassengers->count() }}</strong><span>Adult {{ $activePassengers->where('pax_type','ADULT')->count() }} · Child {{ $activePassengers->where('pax_type','CHILD')->count() }} · Infant {{ $activePassengers->where('pax_type','INFANT')->count() }}</span></div>
        <div class="booking-metric"><small>Tickets</small><strong>{{ $ticketCount }}</strong><span>{{ $airServices->count() }} Air Ticket service(s)</span></div>
        <div class="booking-metric"><small>Booking Value</small><strong>{{ $booking->currency_code }} {{ number_format($operationalValue,2) }}</strong><span>{{ $groupPackageOperationalMode ? 'From Group Package passenger rates' : 'Derived from saved passenger tickets + other services' }}</span></div>
        <div class="booking-metric"><small>Travel Status</small><strong style="font-size:17px">{{ $booking->status==='CONFIRMED'?'Confirmed':'Pending' }}</strong><span>{{ $booking->travel_start_date?->format('d M Y') ?: 'Travel dates not final' }}</span></div>
    </div>

    @if($groupPackageEligible)
    <div class="group-booking-flow" aria-label="Group package booking workflow">
        <a class="group-booking-step done" href="#booking-header"><small>Step 1</small><strong>✓ Booking</strong></a>
        <a class="group-booking-step {{ $activePassengers->isNotEmpty()?'done':'current' }}" href="#booking-passengers"><small>Step 2</small><strong>{{ $activePassengers->isNotEmpty()?'✓ ':'' }}Passengers</strong></a>
        <a class="group-booking-step {{ $groupPackage?'done':($activePassengers->isNotEmpty()?'current':'locked') }}" href="{{ $groupPackageRouteReady && $activePassengers->isNotEmpty() ? route('operations.bookings.group-package.edit',$booking) : '#group-package-step' }}"><small>Step 3</small><strong>{{ $groupPackage?'✓ ':'' }}Package</strong></a>
        <a class="group-booking-step {{ $groupPackage ? ($groupCoreServicesPresent?'done':'current') : 'locked' }}" href="#package-services"><small>Step 4</small><strong>{{ $groupCoreServicesPresent?'✓ ':'' }}Services</strong></a>
        <a class="group-booking-step {{ $groupPackage && $groupCoreServicesPresent ? (in_array($booking->status,['CONFIRMED','COMPLETED'],true)?'done':'current') : 'locked' }}" href="#booking-workflow"><small>Step 5</small><strong>{{ in_array($booking->status,['CONFIRMED','COMPLETED'],true)?'✓ ':'' }}Review</strong></a>
        <a class="group-booking-step {{ in_array($booking->status,['CONFIRMED','COMPLETED'],true)?'done':'locked' }}" href="#booking-workflow"><small>Step 6</small><strong>{{ in_array($booking->status,['CONFIRMED','COMPLETED'],true)?'✓ ':'' }}Confirm</strong></a>
    </div>
    @endif

    @if($booking->status==='CONFIRMED' && $serviceIssues->isNotEmpty())
    <div class="callout warn" style="margin-bottom:14px"><strong>Booking needs review.</strong> {{ $serviceIssues->unique()->implode(' ') }}</div>
    @endif

    <div class="panel" id="booking-header">
        <div class="panel-head">
            <div><h3>Booking Header</h3><p>Customer, branch, agent, salesperson and travel context.</p></div>
            @if($booking->isEditable())<span class="status-pill open">Editable</span>@endif
        </div>
        <dl class="booking-head-summary">
            <div><dt>Booking Type</dt><dd>{{ str_replace('_',' ',$booking->booking_type) }}</dd></div>
            <div><dt>Booking Date</dt><dd>{{ $booking->booking_date?->format('d M Y') }}</dd></div>
            <div><dt>Customer</dt><dd>{{ $booking->customer?->display_name }}</dd></div>
            <div><dt>Branch</dt><dd>{{ $booking->branch?->name }}</dd></div>
            <div><dt>Agent / Service Partner</dt><dd>{{ $booking->agent?->display_name ?: 'Direct / None' }}</dd></div>
            <div><dt>Salesperson</dt><dd>{{ $booking->salesperson?->name ?: '—' }}</dd></div>
            <div><dt>Travel</dt><dd>{{ $booking->travel_start_date?->format('d M Y') ?: '—' }} → {{ $booking->travel_end_date?->format('d M Y') ?: '—' }}</dd></div>
            <div><dt>Currency</dt><dd>{{ $booking->currency_code }}</dd></div>
        </dl>
        @if($booking->isEditable())
        <details class="compact-add" style="margin-top:12px">
            <summary>Edit Booking Header</summary>
            <div class="inside">
                <form method="POST" action="{{ route('operations.bookings.update',$booking) }}">@csrf @method('PUT')
                    @include('operations.bookings.partials.header-fields',['booking'=>$booking])
                    <div class="form-actions"><button class="btn btn-primary">Save Booking Header</button></div>
                </form>
            </div>
        </details>
        @endif
    </div>

    <div class="workspace-stack">
        <div class="panel" id="booking-passengers">
            <div class="passenger-toolbar">
                <div><h3 style="margin:0">Passengers</h3><p class="muted-copy" style="margin:4px 0 0">Add each traveller once. Current booking passengers stay visible below and are reused by tickets, hotels and other services.</p></div>
                @if($booking->isEditable() && auth()->user()->hasPermission('passengers.manage'))
                <div class="passenger-add-actions">
                    <button type="button" class="btn btn-secondary btn-small" data-passenger-open="saved" aria-expanded="false" aria-controls="booking-passenger-picker">Reuse Previous</button>
                    <button type="button" class="btn btn-primary btn-small" data-passenger-open="new" aria-expanded="false" aria-controls="booking-passenger-picker">+ New Passenger</button>
                </div>
                @endif
            </div>

            @if($booking->isEditable() && auth()->user()->hasPermission('passengers.manage'))
            <div class="passenger-picker" data-passenger-picker id="booking-passenger-picker">
                <div class="passenger-picker-head">
                    <div><strong data-passenger-picker-title>Add Passenger</strong><span>Select an existing traveller or create a new passenger without leaving this booking.</span></div>
                    <button type="button" class="passenger-picker-close" data-passenger-close aria-label="Close passenger panel">Close</button>
                </div>
                <div class="passenger-picker-tabs">
                    <button type="button" class="passenger-picker-tab active" data-passenger-tab="saved">Saved / Previous Travellers</button>
                    <button type="button" class="passenger-picker-tab" data-passenger-tab="new">Create New Passenger</button>
                </div>

                <div class="passenger-picker-panel active" data-passenger-panel="saved">
                    <div class="passenger-master-info">
                        <strong>Reusable Passenger Master:</strong> select a saved traveller below. ERP adds a booking snapshot, so later passport/profile edits do not rewrite this booking.
                    </div>

                    <div class="saved-passenger-search">
                        <input type="search" placeholder="Search name, passport, CNIC / ID, mobile or email…" data-passenger-search autocomplete="off">
                        <button type="button" class="btn btn-secondary btn-small" data-passenger-refresh>Search</button>
                    </div>
                    <div class="saved-passenger-note">
                        Previous travellers for <strong>{{ $booking->customer?->display_name }}</strong> are prioritised. Age Type is calculated for <strong>{{ ($booking->travel_start_date ?: $booking->booking_date)?->format('d M Y') }}</strong> when DOB is available.
                    </div>

                    <form method="POST" action="{{ route('operations.bookings.passengers.attach-profile',$booking) }}">
                        @csrf
                        <div class="saved-passenger-results">
                            <div class="saved-passenger-row head">
                                <div>Use</div><div>Passenger</div><div>Age</div><div>Passport</div><div>Expiry</div>
                            </div>
                            <div data-passenger-results>
                                <div style="padding:16px;text-align:center;color:var(--muted);font-size:10px">Search or open this panel to load saved passengers.</div>
                            </div>
                        </div>
                        <div class="saved-passenger-footer">
                            <label class="check-row" style="margin:0"><input type="checkbox" name="make_first_lead" value="1"><span>Make first selected traveller Lead / Family Head if no lead exists</span></label>
                            <button class="btn btn-success btn-small">Add Selected Travellers</button>
                        </div>
                    </form>
                </div>

                <div class="passenger-picker-panel" data-passenger-panel="new">
                    <div class="passenger-master-info">
                        Create the traveller once. ERP saves the person to Passenger Master and adds an immutable snapshot to this booking. DOB automatically determines Adult / Child / Infant where available.
                    </div>

                    <form method="POST" action="{{ route('operations.bookings.passengers.store',$booking) }}">@csrf
                        <div class="new-passenger-grid">
                            <label><span>Title</span><select name="title"><option value="">—</option>@foreach(['MR','MRS','MS','MISS','MSTR'] as $v)<option>{{ $v }}</option>@endforeach</select></label>
                            <label><span>First Name *</span><input name="first_name" required></label>
                            <label><span>Middle Name</span><input name="middle_name"></label>
                            <label><span>Last Name *</span><input name="last_name" required></label>

                            <label><span>Gender</span><select name="gender"><option value="">—</option><option value="MALE">Male</option><option value="FEMALE">Female</option></select></label>
                            <label><span>Date of Birth</span><input type="date" name="date_of_birth"></label>
                            <label><span>Passenger Type if DOB unavailable</span><select name="pax_type"><option value="ADULT">Adult</option><option value="CHILD">Child</option><option value="INFANT">Infant</option></select></label>
                            <label><span>Nationality</span><input name="nationality_country_code" maxlength="2" placeholder="PK"></label>

                            <label><span>Passport No.</span><input name="passport_no"></label>
                            <label><span>Passport Expiry</span><input type="date" name="passport_expiry"></label>
                            <label><span>CNIC / National ID</span><input name="national_id"></label>
                            <label><span>Mobile</span><input name="mobile"></label>

                            <label class="wide"><span>Email</span><input type="email" name="email"></label>
                            <label class="wide"><span>Notes</span><input name="notes" placeholder="Optional passenger / travel-document note"></label>
                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:10px">
                            <label class="check-row" style="margin:0"><input type="checkbox" name="is_lead" value="1"><span>Lead / family head passenger</span></label>
                            <button class="btn btn-success btn-small">Save & Add Passenger</button>
                        </div>
                    </form>
                </div>
            </div>
            @endif

            <div class="passenger-current-label"><strong>Current Booking Passengers</strong><span>{{ $activePassengers->count() }} active</span></div>

            <div class="passenger-table-wrap">
                <table class="passenger-table">
                    <thead><tr><th>#</th><th>Passenger</th><th>Age Type / Fare As</th><th>Date of Birth</th><th>Passport No.</th><th>Passport Expiry</th><th>Nationality</th><th>Lead</th><th>Status</th><th class="action-cell">Action</th></tr></thead>
                    <tbody>
                    @forelse($booking->passengers as $p)
                    <tr>
                        <td>{{ $p->passenger_no }}</td>
                        <td><span class="passenger-name">{{ $p->fullName() }}</span>@if($p->passenger_profile_id)<div class="passenger-source">Saved Passenger</div>@endif</td>
                        @php
                            $systemPax=$p->calculated_pax_type ?: $p->pax_type;
                            $paxOverridden=$systemPax==='CHILD' && $p->pax_type==='ADULT';
                        @endphp
                        <td>
                            <div class="passenger-pax-stack">
                                <div class="passenger-pax-system">
                                    Age Type: <strong>{{ $systemPax }}</strong>
                                    @if($paxOverridden)
                                        <span class="pax-override-badge">CHILD → ADULT Override</span>
                                    @else
                                        <span class="pax-auto-badge">Auto</span>
                                    @endif
                                </div>

                                @if(
                                    $systemPax==='CHILD'
                                    && $booking->isEditable()
                                    && $p->is_active
                                    && auth()->user()->hasPermission('passengers.manage')
                                )
                                    <form method="POST" action="{{ route('operations.bookings.passengers.fare-type',[$booking,$p]) }}" class="passenger-pax-override">
                                        @csrf @method('PUT')
                                        <span style="font-size:9px;font-weight:800;color:#52647a">Fare As</span>
                                        <select name="pax_type">
                                            <option value="CHILD" {{ $p->pax_type==='CHILD'?'selected':'' }}>CHILD</option>
                                            <option value="ADULT" {{ $p->pax_type==='ADULT'?'selected':'' }}>ADULT</option>
                                        </select>
                                        <button class="btn btn-secondary btn-small">Apply</button>
                                    </form>
                                @else
                                    <div><strong>{{ $p->pax_type }}</strong></div>
                                @endif
                            </div>
                        </td>
                        <td>{{ $p->date_of_birth?->format('d M Y') ?: '—' }}</td>
                        <td>{{ $p->passport_no ?: '—' }}</td>
                        <td>{{ $p->passport_expiry?->format('d M Y') ?: '—' }}</td>
                        <td>{{ $p->nationality_country_code ?: '—' }}</td>
                        <td>{{ $p->is_lead ? 'Lead' : '—' }}</td>
                        <td><span class="status-pill {{ $p->is_active?'active':'closed' }}">{{ $p->is_active?'ACTIVE':'REMOVED' }}</span></td>
                        <td class="action-cell">
                            @if($booking->isEditable()&&$p->is_active&&auth()->user()->hasPermission('passengers.manage'))
                            <form method="POST" action="{{ route('operations.bookings.passengers.deactivate',[$booking,$p]) }}" onsubmit="return confirm('Remove this passenger from the active booking? History will be preserved.')" style="display:inline">@csrf<button class="btn btn-danger-soft btn-small">Remove</button></form>
                            @else
                            —
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="passenger-empty">No passengers yet. Add travellers before creating passenger-linked services.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if(!$overviewOnly && $groupPackageEligible)
        <div class="panel group-package-step-card" id="group-package-step">
            <div class="bridge-mini">
                <div>
                    <h3 style="margin:0 0 4px">Step 3 · Group Package</h3>
                    @if($activePassengers->isEmpty())
                        <div class="text">Add passengers first. Adult / Child / Infant counts are required before package pricing can be created.</div>
                    @elseif(!$groupPackage)
                        <div class="text">Passengers are ready. Enter the package vendor and Adult / Child / Infant purchase and selling rates.</div>
                    @else
                        <div class="text"><strong>{{ $groupPackage->package_name ?: 'Group Travel Package' }}</strong> · {{ $groupPackageVendor ?: 'Vendor not selected' }} · Sale {{ $booking->currency_code }} {{ number_format((float)$groupPackage->total_customer_price,2) }} · Margin {{ $booking->currency_code }} {{ number_format((float)$groupPackage->gross_margin,2) }}</div>
                        <div class="group-package-status"><span class="ready">Visa ISSUED / INCLUDED</span><span>Air {{ $groupAirCount }}</span><span>Hotel {{ $groupHotelCount }}</span><span>Transport {{ $groupTransportCount }}</span></div>
                    @endif
                </div>
                <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap">
                    @if(!$groupPackageInstalled)
                        <span class="status-pill open">Database Upgrade required</span>
                    @elseif(!$groupPackageRouteReady)
                        <span class="status-pill open">Route refresh required</span>
                    @elseif($activePassengers->isEmpty())
                        <a class="btn btn-secondary" href="#booking-passengers">Add Passengers First</a>
                    @else
                        <a class="btn btn-primary" href="{{ route('operations.bookings.group-package.edit',$booking) }}">{{ $groupPackage ? 'Edit Group Package' : 'Create Group Package' }}</a>
                    @endif
                </div>
            </div>
        </div>
        @endif

        @if(!$overviewOnly && $groupPackageEligible && !$groupPackage)
            <div class="package-services-gate" id="package-services">
                <h3>Step 4 · Services unlock after the package is saved</h3>
                <p>This keeps the staff flow simple: Booking → Passengers → Group Package → Flights / Hotels / Transport.</p>
                @if($activePassengers->isNotEmpty() && $groupPackageRouteReady)
                    <a class="btn btn-primary" href="{{ route('operations.bookings.group-package.edit',$booking) }}">Create Group Package</a>
                @endif
            </div>
        @else
            <div id="package-services"></div>

        @if(!$overviewOnly && $showAirWorkspace)
        @if($airServices->isEmpty() && $booking->isEditable() && $airProduct && auth()->user()->hasPermission('booking_services.manage'))
        <div class="panel">
            <div class="panel-head"><div><h3>Air Ticket</h3><p>{{ $groupPackageOperationalMode ? 'Add the package flight and link passengers. Package pricing is already captured in Step 3; only operational flight/ticket details are needed here.' : 'Create one Air Ticket service container and choose the passengers. PNR, flight, individual ticket numbers, sale and purchase fares are entered in the batch workspace.' }}</p></div></div>
            <form method="POST" action="{{ route('operations.bookings.services.store',$booking) }}">@csrf
                <input type="hidden" name="product_service_id" value="{{ $airProduct->id }}">
                <input type="hidden" name="description" value="Air Ticket">
                <div class="service-create-grid">
                    <div>
                        <span style="display:block;font-weight:800;margin-bottom:8px">Link Passenger(s)</span>
                        <div class="service-create-passengers">
                            @foreach($activePassengers as $p)<label class="check-row"><input type="checkbox" name="passenger_ids[]" value="{{ $p->id }}"><span>#{{ $p->passenger_no }} · {{ $p->fullName() }} · {{ $p->pax_type }}</span></label>@endforeach
                        </div>
                    </div>
                    <button class="btn btn-primary">Create Air Ticket Service</button>
                </div>
            </form>
        </div>
        @endif

        @foreach($airServices as $s)
        @php
            $linkedActive=$s->passengers->where('is_active',true);
            $serviceTickets=$airTicketRowsByService->get($s->id,collect());
        @endphp
        <div class="air-service-shell">
            <div class="air-service-head">
                <div><h3>Air Ticket Batch Entry</h3><p>{{ $groupPackageOperationalMode ? 'Enter flight details once and keep ticket numbers passenger-specific. No separate ticket sale or supplier price is entered for a Group Package.' : 'Enter common flight details once, keep ticket numbers passenger-specific, then apply Adult / Child / Infant sale and purchase fares.' }}</p></div>
                <span class="status-pill {{ $s->status==='CONFIRMED'?'active':'open' }}">{{ $s->status }}</span>
            </div>
            <div class="air-service-mini">
                <div><small>Linked Passengers</small><strong>{{ $linkedActive->count() }}</strong></div>
                <div><small>Actual Tickets</small><strong>{{ $serviceTickets->count() }}</strong></div>
                @if($groupPackageOperationalMode)
                    <div><small>Commercial Basis</small><strong>Included in Package</strong></div>
                    <div><small>Package Vendor</small><strong>{{ $groupPackageVendor ?: 'Set in Step 3' }}</strong></div>
                @else
                    <div><small>Customer Sale</small><strong>{{ $booking->currency_code }} {{ number_format((float)$serviceTickets->sum('selling_total'),2) }}</strong></div>
                    <div><small>Supplier Cost</small><strong>{{ $booking->currency_code }} {{ number_format((float)$serviceTickets->sum('net_supplier_cost'),2) }}</strong></div>
                @endif
            </div>

            @if($groupPackageOperationalMode)<div class="package-operational-note">Flight is an included package component. Ticket fare, supplier cost, markup and commission fields are intentionally hidden.</div>@endif
            @include('operations.bookings.partials.air-ticket-workspace',[
                'booking'=>$booking,'service'=>$s,'ticketRows'=>$serviceTickets,
                'airlines'=>$airlines,'sources'=>$sources,'vendors'=>$vendors,'agents'=>$agents,'staff'=>$staff,'activePassengers'=>$activePassengers,
                'packageOperationalMode'=>$groupPackageOperationalMode
            ])

            @if($booking->isEditable()&&$s->status!=='CANCELLED'&&auth()->user()->hasPermission('booking_services.manage'))
            <details class="compact-service" style="margin-top:10px">
                <summary>Manage service passenger links</summary>
                <div class="inside">
                    <form method="POST" action="{{ route('operations.bookings.services.update',[$booking,$s]) }}">@csrf @method('PUT')
                        <input type="hidden" name="product_service_id" value="{{ $s->product_service_id }}">
                        <input type="hidden" name="description" value="{{ $s->description ?: 'Air Ticket' }}">
                        <div class="service-create-passengers">
                            @foreach($activePassengers as $p)<label class="check-row"><input type="checkbox" name="passenger_ids[]" value="{{ $p->id }}" {{ $linkedActive->contains('id',$p->id)?'checked':'' }}><span>#{{ $p->passenger_no }} · {{ $p->fullName() }} · {{ $p->pax_type }}</span></label>@endforeach
                        </div>
                        <div class="form-actions"><button class="btn btn-ghost btn-small">Save Passenger Links</button></div>
                    </form>
                </div>
            </details>
            @endif
        </div>
        @endforeach

        @endif {{-- /showAirWorkspace --}}

        @if(!$overviewOnly && $showHotelWorkspace)
        @if(!$groupPackageOperationalMode && !$foreignCostUpgradeInstalled)
        <div class="callout warn" style="margin-bottom:12px"><strong>Hotel SAR/FX upgrade required.</strong> Run System → Health & Updates → Safe Database Upgrade, then Clear Application Cache before saving Hotel supplier costs.</div>
        @endif
        @if($groupPackageOperationalMode)<div class="package-operational-note">Hotel stays are included in the package price. Enter only accommodation details; separate hotel sale/supplier pricing is not part of this booking.</div>@endif
        <div class="hotel-stays-header">
            <div>
                <span class="hotel-type-chip">{{ $bookingType==='HOTEL_ONLY' ? 'HOTEL / ACCOMMODATION ONLY' : 'HOTEL BOOKING' }}</span>
                <h3>Hotel Stays</h3>
                <p>Passengers are entered once above. Every Hotel Stay automatically inherits all active Booking Passengers.</p>
            </div>

            <div class="hotel-stays-actions">
                <span class="status-pill active">{{ $hotelServices->count() }} stay(s)</span>

                @if($booking->isEditable() && auth()->user()->hasPermission('booking_services.manage') && $hotelProduct && $activePassengers->isNotEmpty())
                    @if($canAddNextHotel)
                        <form method="POST" action="{{ route('operations.bookings.services.store',$booking) }}">
                            @csrf
                            <input type="hidden" name="product_service_id" value="{{ $hotelProduct->id }}">
                            <input type="hidden" name="description" value="Hotel Accommodation">

                            <button class="btn btn-primary">
                                {{ $hotelServices->isEmpty() ? '+ Add Hotel Stay' : '+ Add Another Hotel Stay' }}
                            </button>
                        </form>
                    @else
                        <button class="btn btn-primary" type="button" disabled title="Save or remove the current Hotel Stay first.">
                            + Add Another Hotel Stay
                        </button>
                    @endif
                @endif
            </div>
        </div>

        @if(!$canAddNextHotel && $activePassengers->isNotEmpty())
            <div class="hotel-sequence-note">
                Save or remove the current Hotel Stay first. No Hotel Stay is created automatically.
                When you click <strong>+ Add Another Hotel Stay</strong>, its Check-in will start from the previous saved stay's Check-out date.
            </div>
        @endif

        @if($canAddNextHotel && $hotelServices->isNotEmpty() && $activePassengers->isNotEmpty())
            <div class="hotel-add-control-note">
                Add the next Hotel only when required. New Hotel Stays are never created automatically.
            </div>
        @endif

        @if($activePassengers->isEmpty())
            <div class="hotel-start-empty">Add at least one Booking Passenger before creating a Hotel Stay.</div>
        @endif

        @php
            $previousHotelCheckout=$booking->travel_start_date?->format('Y-m-d');
        @endphp
        @foreach($hotelServices as $hotelService)
            @php
                $currentHotelDetail=$hotelDetailsByService->get($hotelService->id);
            @endphp

            @if($currentHotelDetail)
                @include('operations.bookings.partials.hotel-workspace',[
                    'hotelService'=>$hotelService,
                    'hotelStayNo'=>$loop->iteration,
                    'hotelSuggestedCheckIn'=>$previousHotelCheckout,
                ])
            @else
                @include('operations.bookings.partials.hotel-workspace-empty',[
                    'hotelService'=>$hotelService,
                    'hotelStayNo'=>$loop->iteration,
                    'hotelSuggestedCheckIn'=>$previousHotelCheckout,
                ])
            @endif

            @if($currentHotelDetail && $currentHotelDetail->check_out)
                @php
                    $previousHotelCheckout=$currentHotelDetail->check_out->format('Y-m-d');
                @endphp
            @endif
        @endforeach

        @if($hotelServices->isNotEmpty())
        <div class="hotel-readiness-panel">
            <div class="hotel-readiness-head">
                <div>
                    <span class="hotel-type-chip">ACCOMMODATION DOCUMENT READINESS</span>
                    <h3>Accommodation Voucher Readiness</h3>
                    <p>These hotel checks feed the central Client Voucher engine. Generate, reissue and manage the customer-facing document from Operational Documents below.</p>
                </div>
                <div class="hotel-readiness-actions">
                    <span class="status-pill {{ $hotelVoucherFinalReady?'active':'open' }}">{{ $hotelVoucherFinalReady?'Accommodation ready':'Complete hotel details' }}</span>
                </div>
            </div>
            <div class="hotel-readiness-grid">
                <div class="hotel-ready-item {{ $activePassengers->isNotEmpty()?'ready':'pending' }}"><small>Passengers</small><strong>{{ $activePassengers->count() }} active</strong></div>
                <div class="hotel-ready-item {{ $hotelSavedCount===$hotelServices->count()?'ready':'pending' }}"><small>Hotel Details</small><strong>{{ $hotelSavedCount }}/{{ $hotelServices->count() }} saved</strong></div>
                <div class="hotel-ready-item {{ $hotelConfirmedCount===$hotelServices->count()?'ready':'pending' }}"><small>Hotel Status</small><strong>{{ $hotelConfirmedCount }}/{{ $hotelServices->count() }} confirmed</strong></div>
                <div class="hotel-ready-item {{ $hotelConfirmationNoCount===$hotelServices->count()?'ready':'pending' }}"><small>Confirmation No.</small><strong>{{ $hotelConfirmationNoCount }}/{{ $hotelServices->count() }} entered</strong></div>
                <div class="hotel-ready-item {{ in_array($booking->status,['CONFIRMED','COMPLETED'],true)?'ready':'pending' }}"><small>Booking</small><strong>{{ str_replace('_',' ',$booking->status) }}</strong></div>
            </div>
        </div>
        @endif

        @endif {{-- /showHotelWorkspace --}}

        @if(!$overviewOnly && $showGenericServices)
        <div class="panel">
            <div class="panel-head"><div><h3>Other Services / Products</h3><p>Visa, Transport, Umrah Package and miscellaneous services reuse the passengers already entered above. Air Ticket and Hotel use dedicated workspaces.</p></div></div>

            @if($otherServices->isNotEmpty())
            <div class="other-service-list">
                @foreach($otherServices as $s)
                @php
                    $displayServiceStatus=$genericServiceDisplayStatus($s);
                    $displayServiceStatusClass=$displayServiceStatus==='CONFIRMED'?'active':($displayServiceStatus==='READY'?'ready':'open');
                @endphp
                <div class="other-service-row">
                    <div><strong>{{ $s->product?->name ?: $s->description }}</strong><div class="muted-copy">{{ $s->description }}</div></div>
                    <div>@if($groupPackageOperationalMode)<strong>Included in Package</strong><div class="muted-copy">Operational component</div>@else<strong>{{ $s->currency_code }} {{ number_format((float)$s->line_total,2) }}</strong><div class="muted-copy">{{ $s->pricing_basis_snapshot }}</div>@endif</div>
                    <div><strong>{{ $s->passengers->where('is_active',true)->count() }}</strong><div class="muted-copy">Passengers</div></div>
                    <div><span class="status-pill {{ $displayServiceStatusClass }}">{{ $displayServiceStatus }}</span></div>
                    <div>@if(auth()->user()->hasPermission('service_details.view'))<a class="btn btn-ghost btn-small" href="{{ route('operations.bookings.services.details.edit',[$booking,$s]) }}">Details</a>@endif</div>
                </div>
                @endforeach
            </div>
            @else
            <div class="muted-copy">No other services in this booking.</div>
            @endif

            @if($booking->isEditable() && auth()->user()->hasPermission('booking_services.manage'))
            <details class="compact-service" style="margin-top:12px">
                <summary>+ Add Service / Product</summary>
                <div class="inside">
                    <form method="POST" action="{{ route('operations.bookings.services.store',$booking) }}" class="service-editor" data-service-editor>@csrf
                        <label><span>Product / Service</span><select name="product_service_id" required data-product-select><option value="">Select service</option>@foreach($products->reject(fn($p)=>in_array(strtoupper((string)$p->category),['AIR_TICKET','HOTEL'],true)) as $p)<option value="{{ $p->id }}" data-basis="{{ $p->pricing_basis }}" data-link-mode="{{ $p->passenger_link_mode }}" data-category="{{ strtoupper((string)$p->category) }}">{{ $p->code }} · {{ $p->name }}</option>@endforeach</select></label>
                        <div class="callout" data-pricing-help style="display:none;margin:10px 0"></div>
                        <label data-service-description><span>Description</span><input name="description" required></label>
                        @if($groupPackageOperationalMode)<div class="package-operational-note">This service is included in the Group Package. No separate sale price is required.</div>@endif
                        <div class="compact-grid" data-generic-price {{ $groupPackageOperationalMode ? 'style=display:none' : '' }}><label><span data-qty-label>Quantity</span><input type="number" step="0.001" min="0.001" name="quantity" value="1" required data-quantity></label><label><span data-price-label>Unit Price</span><input type="number" step="0.01" min="0" name="unit_price" value="0" required data-unit-price></label></div>
                        <div class="compact-grid" data-generic-dates><label><span>Service From</span><input type="date" name="service_from"></label><label><span>Service To</span><input type="date" name="service_to"></label></div>
                        <div data-passenger-picker><span style="display:block;font-weight:800;margin-bottom:8px">Link Passenger(s)</span><div class="service-create-passengers">@foreach($activePassengers as $p)<label class="check-row"><input type="checkbox" name="passenger_ids[]" value="{{ $p->id }}" data-passenger-checkbox><span>#{{ $p->passenger_no }} · {{ $p->fullName() }} · {{ $p->pax_type }}</span></label>@endforeach</div></div>
                        <div class="callout good" style="margin-top:10px;{{ $groupPackageOperationalMode ? 'display:none' : '' }}" data-generic-total><strong>Total:</strong> <span data-total-preview>{{ $booking->currency_code }} 0.00</span></div>
                        <div class="form-actions"><button class="btn btn-primary btn-small">Add Service</button></div>
                    </form>
                </div>
            </details>
            @endif
        </div>
    </div>

        @endif {{-- /showGenericServices --}}
        @endif {{-- /group package service gate --}}

    <div class="panel" id="booking-workflow">
        <div class="workflow-bar">
            <div>
                <h3 style="margin:0">Booking Workflow</h3>
                <div class="muted-copy">Review passengers, saved passenger tickets and services, then confirm. Confirmation does not post accounting by itself.</div>
            </div>
            <div class="workflow-actions">
                @if(in_array($booking->status,['DRAFT','PENDING_CONFIRMATION'])&&auth()->user()->hasPermission('bookings.submit'))<form method="POST" action="{{ route('operations.bookings.submit',$booking) }}">@csrf<button class="btn btn-warning">Submit for Approval</button></form>@endif
                @if(in_array($booking->status,['DRAFT','PENDING_CONFIRMATION'])&&auth()->user()->hasPermission('bookings.confirm'))
                    @if(!$groupPackageEligible || ($groupPackage && $groupCoreServicesPresent))
                        <form method="POST" action="{{ route('operations.bookings.confirm',$booking) }}">@csrf<button class="btn btn-success">Confirm Booking</button></form>
                    @else
                        <button class="btn btn-secondary" type="button" disabled>Complete Package Steps First</button>
                    @endif
                @endif

                @if(($booking->status==='CONFIRMED'&&auth()->user()->hasPermission('bookings.confirm')) || (!in_array($booking->status,['CANCELLED','COMPLETED'])&&auth()->user()->hasPermission('bookings.cancel')))
                <details class="workflow-more">
                    <summary>More Actions ▾</summary>
                    <div class="menu">
                        @if($booking->status==='CONFIRMED'&&auth()->user()->hasPermission('bookings.confirm'))
                        <div class="workflow-correction">
                            <strong>Reopen for Correction</strong>
                            <p class="workflow-note">Use when a confirmed booking needs operational correction. The action is audit logged; active Sales Invoices may block reopening.</p>
                            <form method="POST" action="{{ route('operations.bookings.reopen',$booking) }}">@csrf
                                <label><span>Reason for Reopening *</span><textarea name="reopen_reason" rows="2" required placeholder="e.g. passenger/ticket details need correction"></textarea></label>
                                <div class="form-actions" style="margin-top:8px"><button class="btn btn-ghost btn-small">Reopen for Correction</button></div>
                            </form>
                        </div>
                        @endif

                        @if(!in_array($booking->status,['CANCELLED','COMPLETED'])&&auth()->user()->hasPermission('bookings.cancel'))
                        <div class="workflow-danger">
                            <strong>Cancel Booking</strong>
                            <p class="workflow-note">Cancellation preserves the booking, passengers, services and audit history. It never deletes accounting or operational history.</p>
                            @if($directCancelBlocked)
                            <div class="workflow-blocked">
                                <strong>Direct cancellation is blocked.</strong><br>
                                @if($activeInvoice)Active Sales Invoice: {{ $activeInvoice->invoice_no }} · {{ $activeInvoice->status }}. @endif
                                @if($issuedTicketCount>0)Issued/Reissued passenger tickets: {{ $issuedTicketCount }}. @endif
                                Resolve the linked financial/ticket document first through the appropriate Credit / Refund / Void workflow, then cancel the booking.
                            </div>
                            @else
                            <form method="POST" action="{{ route('operations.bookings.cancel',$booking) }}" onsubmit="return confirm('Cancel booking {{ $booking->booking_no }}? All history will be retained.')">@csrf
                                <label><span>Cancellation Reason *</span><textarea name="cancellation_reason" rows="2" required placeholder="Customer cancelled, duplicate booking, pricing issue, etc."></textarea></label>
                                <div class="form-actions" style="margin-top:8px"><button class="btn btn-danger">Cancel Booking</button></div>
                            </form>
                            @endif
                        </div>
                        @endif
                    </div>
                </details>
                @endif
            </div>
        </div>
        @if($groupPackageEligible && (!$groupPackage || !$groupCoreServicesPresent) && !in_array($booking->status,['CONFIRMED','COMPLETED','CANCELLED'],true))
            <div class="callout" style="margin-top:12px"><strong>Group package flow:</strong> complete Passengers → Package → at least one Flight, Hotel and Transport entry before confirmation.</div>
        @endif
        @if($booking->isEditable() && $serviceIssues->isNotEmpty())
        <div class="callout warn" style="margin-top:12px"><strong>Booking cannot be confirmed yet.</strong> {{ $serviceIssues->unique()->implode(' ') }}</div>
        @endif
    </div>

    <div class="panel">
        <div class="bridge-mini">
            <div>
                <h3 style="margin:0 0 4px">Client Voucher / Operational Documents</h3>
                <div class="text">Customer-facing travel document only. It never posts GL entries and never exposes supplier cost, gross margin, ledger data or internal accounting.</div>
            </div>
            <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap">
                <?php if (!$clientVoucherIssueControlsInstalled): ?>
                    <?php if ($clientVoucherTable): ?>
                        <a class="btn btn-secondary" href="{{ route('operations.bookings.travel-voucher.edit',$booking) }}">Manage / Preview Voucher</a>
                    <?php endif; ?>
                    <span class="status-pill open">Safe Database Upgrade required</span>
                <?php endif; ?>

                <?php if ($clientVoucherIssueControlsInstalled && $clientVoucherVoided): ?>
                    <a class="btn btn-danger" href="{{ route('operations.bookings.travel-voucher.show',$booking) }}" target="_blank">{{ $clientVoucher?->voucher_no ?: 'Client Voucher' }} · VOIDED</a>
                    <a class="btn btn-secondary" href="{{ route('operations.bookings.travel-voucher.edit',$booking) }}">Manage Voucher</a>
                    <?php if ($clientVoucherCanGenerate && $clientVoucherIssueRouteReady && auth()->user()->hasPermission('service_details.manage')): ?>
                        <form method="POST" action="{{ route('operations.bookings.travel-voucher.issue',$booking) }}">@csrf<button class="btn btn-success">Reissue Voucher</button></form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($clientVoucherIssueControlsInstalled && !$clientVoucherVoided && $clientVoucherIssued): ?>
                    <a class="btn btn-success" href="{{ route('operations.bookings.travel-voucher.show',$booking) }}" target="_blank">
                        {{ $clientVoucher?->voucher_no }} · ISSUED
                        <?php if ($clientVoucherRevision > 1): ?>
                            · REV {{ $clientVoucherRevision }}
                        <?php endif; ?>
                    </a>
                    <a class="btn btn-secondary" href="{{ route('operations.bookings.travel-voucher.edit',$booking) }}">Manage Voucher</a>
                    <?php if ($clientVoucherCanGenerate && $clientVoucherIssueRouteReady && auth()->user()->hasPermission('service_details.manage')): ?>
                        <form method="POST" action="{{ route('operations.bookings.travel-voucher.issue',$booking) }}">@csrf<button class="btn btn-primary">Reissue Voucher</button></form>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($clientVoucherIssueControlsInstalled && !$clientVoucherVoided && !$clientVoucherIssued && $clientVoucherCanGenerate): ?>
                    <?php if ($clientVoucherIssueRouteReady && auth()->user()->hasPermission('service_details.manage')): ?>
                        <form method="POST" action="{{ route('operations.bookings.travel-voucher.issue',$booking) }}">@csrf<button class="btn btn-success">Generate Client Voucher</button></form>
                    <?php endif; ?>
                    <a class="btn btn-secondary" href="{{ route('operations.bookings.travel-voucher.edit',$booking) }}">Manage / Preview</a>
                <?php endif; ?>

                <?php if ($clientVoucherIssueControlsInstalled && !$clientVoucherVoided && !$clientVoucherIssued && !$clientVoucherCanGenerate): ?>
                    <a class="btn btn-secondary" href="{{ route('operations.bookings.travel-voucher.edit',$booking) }}">Set Up / Preview</a>
                    <span class="status-pill open">Available after booking confirmation</span>
                <?php endif; ?>
            </div>
        </div>
        @if($clientVoucherIssueControlsInstalled && $clientVoucherCanGenerate && !$clientVoucherIssued && !$clientVoucherVoided && !$clientVoucher)
            <div class="muted-copy" style="margin-top:8px">Generating creates the operational voucher record and secure read-only QR/link. It does not create or change the Sales Invoice.</div>
        @endif
        @if($clientVoucherIssueControlsInstalled && !$clientVoucherIssueRouteReady)
            <div class="muted-copy" style="margin-top:8px;color:#9a6700"><strong>Route cache refresh required:</strong> run Clear Application Cache once. Booking pages remain available while the new voucher action route is waiting to be loaded.</div>
        @endif
    </div>

    <div class="panel">
        <div class="bridge-mini">
            <div>
                <h3 style="margin:0 0 4px">Commercial & Accounting Bridge</h3>
                <div class="text">Booking stays operational. Sales Invoice creates Customer Receivable / Revenue after approval and posting.</div>
            </div>
            <div>
                @if($latestInvoice)
                    <a class="btn btn-primary" href="{{ route('sales.invoices.show',$latestInvoice) }}">{{ $latestInvoice->invoice_no }} · {{ $latestInvoice->status }}</a>
                @elseif($groupPackageOperationalMode)
                    <span class="status-pill ready">Package Sale {{ $booking->currency_code }} {{ number_format((float)$groupPackage->total_customer_price,2) }}</span>
                @elseif($booking->status==='CONFIRMED' && auth()->user()->hasPermission('sales_invoices.create'))
                    <form method="POST" action="{{ route('sales.invoices.from-booking',$booking) }}">@csrf<button class="btn btn-primary">Create Sales Invoice</button></form>
                @else
                    <span class="status-pill open">Invoice not created</span>
                @endif
            </div>
        </div>
    </div>

    @if(!$overviewOnly && auth()->user()->hasPermission('costing.view') && in_array($booking->status,['CONFIRMED','COMPLETED'],true))
    <div class="panel">
        @php
            if($groupPackageOperationalMode){
                $forecastCost=(float)($groupPackage->total_vendor_cost ?? 0);
                $confirmedCost=$forecastCost;
                $saleBase=(float)($groupPackage->total_customer_price ?? 0);
                $gross=(float)($groupPackage->gross_margin ?? ($saleBase-$forecastCost));
            }else{
                $costRows=\App\Models\ServiceCostAllocation::query()->where('booking_id',$booking->id)->where('status','!=','CANCELLED')->get();
                $forecastCost=(float)$costRows->sum('base_total_cost');
                $confirmedCost=(float)$costRows->where('status','CONFIRMED')->sum('base_total_cost');
                $saleBase=$booking->salesInvoices->where('status','POSTED')->sum(fn($i)=>(float)$i->grand_total*(float)($i->exchange_rate?:1));
                if($saleBase<=0)$saleBase=$operationalValue;
                $gross=$saleBase-$forecastCost;
            }
        @endphp
        <div class="bridge-mini">
            <div><h3 style="margin:0 0 4px">{{ $groupPackageOperationalMode ? 'Package Costing & Gross Margin' : 'Supplier Costing & Gross Margin' }}</h3><div class="text">{{ $groupPackageOperationalMode ? 'Package vendor cost' : 'Forecast cost' }} {{ number_format((float)($forecastCost ?? 0),2) }} · {{ $groupPackageOperationalMode ? 'Package sale' : 'Confirmed' }} {{ number_format((float)($groupPackageOperationalMode ? ($groupPackage->total_customer_price ?? 0) : ($confirmedCost ?? 0)),2) }} · Gross margin {{ number_format((float)($gross ?? 0),2) }}</div></div>
            @if(!$groupPackageOperationalMode)<a class="btn btn-ghost" href="{{ route('operations.bookings.costing.show',$booking) }}">Open Costing</a>@endif
        </div>
    </div>
    @endif
</div>

@if(!$overviewOnly)
<script>
(function(){
    const installed=@json($foreignCostUpgradeInstalled);
    if(!installed)return;
    const meta=@json($hotelFxMeta);
    const currentFx=@json($currentSarFx);
    const baseCurrency=@json($bookingBaseCurrency);
    const hasErrors=@json($errors->any());
    document.querySelectorAll('form[action*="/hotel-workspace"]').forEach(form=>{
        const m=(form.getAttribute('action')||'').match(/\/services\/(\d+)\/hotel-workspace/); if(!m)return;
        const serviceId=m[1], row=meta[serviceId]||{};
        const customer=form.querySelector('[name="customer_price"]');
        const supplier=form.querySelector('[name="supplier_price"]');
        if(customer){const span=customer.closest('label')?.querySelector('span');if(span)span.textContent='Customer Price ('+baseCurrency+')';}
        if(!supplier)return;
        const label=supplier.closest('label'), span=label?.querySelector('span'); if(span)span.textContent='Supplier Cost (SAR)';
        if(!hasErrors){
            if(row.foreign!==null && row.foreign!==undefined)supplier.value=Number(row.foreign).toFixed(2);
            else if(row.base!==undefined)supplier.value='0.00';
        }
        let help=label?.querySelector('[data-hotel-fx-help]');
        if(!help && label){help=document.createElement('small');help.dataset.hotelFxHelp='1';label.appendChild(help);}
        const rate=Number(row.fx||currentFx?.rate||0), rateDate=row.fx_date||currentFx?.effective_date||'';
        const legacyBase=row.foreign===null && Number(row.base||0)>0 ? Number(row.base||0) : 0;
        const sync=()=>{const cost=Number(supplier.value||0);const base=cost*rate;if(help){if(legacyBase>0 && cost<=0){help.textContent='Legacy '+baseCurrency+' supplier cost '+legacyBase.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' is preserved until you enter the SAR vendor cost.';}else{help.textContent=rate>0?'FX: 1 SAR = '+baseCurrency+' '+rate.toFixed(4)+(rateDate?' · '+rateDate:'')+' · Cost '+baseCurrency+' '+base.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}):'SAR → '+baseCurrency+' rate not configured for booking date.';}}};
        supplier.addEventListener('input',sync);sync();
        if(row.fx && label && !form.querySelector('[data-hotel-refresh-fx]')){
            const b=document.createElement('button');b.type='submit';b.name='refresh_fx_rate';b.value='1';b.className='btn btn-ghost btn-small';b.dataset.hotelRefreshFx='1';b.textContent='Refresh FX Rate';b.style.marginTop='5px';label.appendChild(b);
        }
    });
})();
</script>
@endif

@if(!$overviewOnly)
<script>
(function(){
    function money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(v||0)}
    function refresh(editor){
        const product=editor.querySelector('[data-product-select]');
        const opt=product && product.selectedOptions.length ? product.selectedOptions[0] : null;
        const basis=(opt?.dataset.basis || '').toUpperCase();
        const mode=(opt?.dataset.linkMode || '').toUpperCase();
        const category=(opt?.dataset.category || '').toUpperCase();
        const isAir=category==='AIR_TICKET';
        const isHotel=category==='HOTEL';
        const isVisa=category==='VISA';
        const isTransport=category==='TRANSPORT';
        const isStructural=isAir||isHotel;
        const isDedicatedCommercial=isVisa||isTransport;
        const picker=editor.querySelector('[data-passenger-picker]');
        const checks=[...editor.querySelectorAll('[data-passenger-checkbox]')];
        const selected=checks.filter(x=>x.checked).length;
        const qty=editor.querySelector('[data-quantity]'), price=editor.querySelector('[data-unit-price]');
        const priceWrap=editor.querySelector('[data-generic-price]'), dateWrap=editor.querySelector('[data-generic-dates]'), totalWrap=editor.querySelector('[data-generic-total]'), descWrap=editor.querySelector('[data-service-description]');
        const help=editor.querySelector('[data-pricing-help]');
        if(mode==='NONE'){if(picker)picker.style.display='none';checks.forEach(x=>x.checked=false)}else if(picker)picker.style.display='block';
        if(isStructural){
            if(priceWrap)priceWrap.style.display='none'; if(dateWrap)dateWrap.style.display='none'; if(totalWrap)totalWrap.style.display='none'; if(descWrap)descWrap.style.display='none';
            if(qty){qty.value=isAir?Math.max(1,selected):1;qty.required=false}
            if(price){price.value=0;price.required=false}
            if(help){
                help.style.display='block';
                help.innerHTML=isAir
                    ? '<strong>Air Ticket:</strong> only link passengers here. PNR, ticket numbers, sale/purchase fares and flight dates are entered in the Air Ticket Batch Entry workspace.'
                    : '<strong>Hotel:</strong> link the travellers, add the service, then complete rooms, dates, customer pricing and supplier pricing in the Hotel Booking Workspace.';
            }
            return;
        }
        if(isDedicatedCommercial){
            if(priceWrap)priceWrap.style.display='none';
            if(totalWrap)totalWrap.style.display='none';
            if(dateWrap)dateWrap.style.display='none';
            if(descWrap)descWrap.style.display='block';
            if(price){price.value=0;price.required=false}
            if(qty){qty.value=isVisa?Math.max(1,selected):1;qty.readOnly=true}
            if(help){
                help.style.display='block';
                help.innerHTML=isVisa
                    ? '<strong>Visa:</strong> add/link the passengers first. Adult / Child / Infant <strong>Sale Price is entered in PKR</strong> and <strong>Supplier Cost is entered in SAR</strong> in Visa Details; dated FX converts the cost automatically.'
                    : '<strong>Transport:</strong> add the service first. <strong>Transport Sale is one PKR grand total</strong>; Vendor Cost is entered/picked in SAR and converted through the dated FX rate in Transport Details.';
            }
            return;
        }
        if(priceWrap)priceWrap.style.display='grid'; if(dateWrap)dateWrap.style.display='grid'; if(totalWrap)totalWrap.style.display='block'; if(descWrap)descWrap.style.display='block'; if(price)price.required=true;
        if(basis==='PER_PERSON'){if(qty){qty.value=selected;qty.readOnly=true} if(help){help.style.display='block';help.textContent='Quantity follows linked passengers automatically.'}}else{if(qty)qty.readOnly=false}
        const preview=editor.querySelector('[data-total-preview]'); if(preview)preview.textContent='{{ $booking->currency_code }} '+money(Number(qty?.value||0)*Number(price?.value||0));
    }
    document.querySelectorAll('[data-service-editor]').forEach(editor=>{editor.addEventListener('change',()=>refresh(editor));editor.addEventListener('input',()=>refresh(editor));refresh(editor)});
})();
</script>
@endif


@if(!$overviewOnly)
<script>
(function(){
    const money=v=>new Intl.NumberFormat('en-PK',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
    }).format(Number(v||0));

    function capacityPerRoom(type,sharing){
        if(type==='SINGLE')return 1;
        if(type==='DOUBLE')return 2;
        if(type==='TRIPLE')return 3;
        if(type==='QUAD')return 4;
        if(type==='SHARING')return Math.max(1,Number(sharing||0));
        return 1;
    }

    function refresh(form){
        const type=form.querySelector('[data-room-type]')?.value||'DOUBLE';
        const roomCount=Math.max(1,Number(form.querySelector('[data-room-count]')?.value||1));
        const sharingWrap=form.querySelector('[data-sharing-wrap]');
        const sharing=form.querySelector('[data-sharing-capacity]');

        if(type==='SHARING'){
            sharingWrap?.removeAttribute('hidden');
            if(sharing)sharing.required=true;
        }else{
            sharingWrap?.setAttribute('hidden','hidden');
            if(sharing){
                sharing.required=false;
                sharing.value='';
            }
        }

        const capacity=roomCount*capacityPerRoom(type,sharing?.value);
        const capacityOut=form.querySelector('[data-room-capacity]');
        const totalOut=form.querySelector('[data-total-capacity]');
        if(capacityOut)capacityOut.textContent=capacity+' Pax';
        if(totalOut)totalOut.textContent=String(capacity);

        const capacityBar=form.querySelector('[data-capacity-bar]');
        const bookingPax=Number(capacityBar?.dataset.bookingPassengers||0);
        const capacityStatus=form.querySelector('[data-capacity-status]');
        if(capacityStatus){
            const noBed=Math.max(0,bookingPax-capacity);
            capacityStatus.textContent=noBed>0 ? ('✓ Capacity OK · '+noBed+' No Bed') : '✓ Sufficient Capacity';
            capacityStatus.classList.add('ok');
            capacityStatus.classList.remove('bad');
        }

        const checkin=form.querySelector('[data-hotel-checkin]');
        const checkout=form.querySelector('[data-hotel-checkout]');
        const checkinValue=checkin?.value||'';
        const checkoutValue=checkout?.value||'';

        if(checkout && checkinValue)checkout.min=checkinValue;

        let nights=0;
        if(checkinValue&&checkoutValue){
            const a=new Date(checkinValue+'T00:00:00');
            const b=new Date(checkoutValue+'T00:00:00');
            nights=Math.max(0,Math.round((b-a)/86400000));
        }
        const nightsOut=form.querySelector('[data-hotel-nights]');
        if(nightsOut)nightsOut.textContent=String(nights);

        const customer=Number(form.querySelector('[data-customer-price]')?.value||0);
        const discountType=form.querySelector('[data-discount-type]')?.value||'NONE';
        const discountValue=Number(form.querySelector('[data-discount-value]')?.value||0);
        const discount=discountType==='PERCENT'
            ? customer*discountValue/100
            : (discountType==='FIXED'?discountValue:0);

        const customerNet=Math.max(0,customer-discount);
        const supplier=Number(form.querySelector('[data-supplier-price]')?.value||0);
        const agent=Number(form.querySelector('[data-agent-commission]')?.value||0);
        const staff=Number(form.querySelector('[data-staff-commission]')?.value||0);
        const net=customerNet-supplier-agent-staff;
        const margin=customerNet>0?net/customerNet*100:0;

        const outputs={
            '[data-customer-net]':money(customerNet),
            '[data-supplier-total]':money(supplier),
            '[data-net-contribution]':money(net),
        };

        Object.entries(outputs).forEach(([selector,value])=>{
            form.querySelectorAll(selector).forEach(el=>el.textContent=value);
        });

        form.querySelectorAll('[data-net-margin]').forEach(
            el=>el.textContent=margin.toFixed(2)
        );
    }

    const savedHotelServiceId=@json(session('hotel_saved_service_id'));

    function hotelDraftKey(form){
        const action=String(form.getAttribute('action')||'');
        const match=action.match(/\/services\/(\d+)\/hotel-workspace(?:$|\?)/);
        return match ? 'et-erp:hotel-workspace:'+match[1] : null;
    }

    function captureHotelDraft(form){
        const key=hotelDraftKey(form);
        if(!key)return;
        const data={};
        form.querySelectorAll('input[name],select[name],textarea[name]').forEach(el=>{
            const name=el.name;
            if(!name || name==='_token' || name==='_method')return;
            if((el.type==='checkbox'||el.type==='radio') && !el.checked)return;
            data[name]=el.value;
        });
        try{sessionStorage.setItem(key,JSON.stringify(data));}catch(e){}
    }

    function restoreHotelDraft(form){
        const key=hotelDraftKey(form);
        if(!key)return false;
        try{
            const raw=sessionStorage.getItem(key);
            if(!raw)return false;
            const data=JSON.parse(raw)||{};
            Object.entries(data).forEach(([name,value])=>{
                const elements=[...form.elements].filter(el=>el.name===name);
                elements.forEach(el=>{
                    if(el.type==='checkbox'||el.type==='radio')el.checked=String(el.value)===String(value);
                    else el.value=value ?? '';
                });
            });
            return true;
        }catch(e){return false;}
    }

    document.querySelectorAll('[data-smart-hotel-form]').forEach(form=>{
        const existingSource=form.querySelector('[data-existing-hotel-source]');
        const newSource=form.querySelector('[data-new-hotel-source]');
        const existingCitySource=form.querySelector('[data-existing-city-source]');
        const newCitySource=form.querySelector('[data-new-city-source]');

        const existingHotel=form.querySelector('[data-existing-hotel]');
        const newHotelName=form.querySelector('[data-new-hotel-name]');
        const newHotelCity=form.querySelector('[data-new-hotel-city-code]');

        const cityNameOut=form.querySelector('[data-existing-city-name]');
        const cityCodeOut=form.querySelector('[data-city-code-output]');
        const countryCodeOut=form.querySelector('[data-country-code-output]');
        const vendor=form.querySelector('[data-hotel-vendor]');

        const openNew=form.querySelector('[data-toggle-new-hotel]');
        const useExisting=form.querySelector('[data-use-existing-hotel]');

        function syncExistingHotel(){
            const option=existingHotel?.selectedOptions?.[0];

            const cityName=option?.dataset.cityName||'—';
            const cityCode=option?.dataset.cityCode||'—';
            const countryCode=option?.dataset.countryCode||'—';

            if(cityNameOut)cityNameOut.textContent=cityName;
            if(cityCodeOut)cityCodeOut.textContent=cityCode;
            if(countryCodeOut)countryCodeOut.textContent=countryCode;

            const defaultVendor=option?.dataset.defaultVendor||'';
            if(defaultVendor && vendor && !vendor.value){
                vendor.value=defaultVendor;
            }
        }

        function syncNewCity(){
            const option=newHotelCity?.selectedOptions?.[0];
            if(cityCodeOut)cityCodeOut.textContent=option?.value||'—';
            if(countryCodeOut)countryCodeOut.textContent=option?.dataset.country||'—';
        }

        function setNewHotelMode(enabled){
            if(enabled){
                existingSource?.setAttribute('hidden','hidden');
                existingCitySource?.setAttribute('hidden','hidden');

                newSource?.removeAttribute('hidden');
                newCitySource?.removeAttribute('hidden');

                if(existingHotel)existingHotel.disabled=true;
                if(newHotelName){
                    newHotelName.disabled=false;
                    newHotelName.required=true;
                }
                if(newHotelCity){
                    newHotelCity.disabled=false;
                    newHotelCity.required=true;
                }

                if(cityCodeOut)cityCodeOut.textContent='—';
                if(countryCodeOut)countryCodeOut.textContent='—';

                setTimeout(()=>newHotelName?.focus(),0);
            }else{
                newSource?.setAttribute('hidden','hidden');
                newCitySource?.setAttribute('hidden','hidden');

                existingSource?.removeAttribute('hidden');
                existingCitySource?.removeAttribute('hidden');

                if(existingHotel)existingHotel.disabled=false;
                if(newHotelName){
                    newHotelName.disabled=true;
                    newHotelName.required=false;
                    newHotelName.value='';
                }
                if(newHotelCity){
                    newHotelCity.disabled=true;
                    newHotelCity.required=false;
                    newHotelCity.value='';
                }

                syncExistingHotel();
            }
        }

        openNew?.addEventListener('click',()=>setNewHotelMode(true));
        useExisting?.addEventListener('click',()=>setNewHotelMode(false));
        existingHotel?.addEventListener('change',syncExistingHotel);
        newHotelCity?.addEventListener('change',syncNewCity);

        const draftKey=hotelDraftKey(form);
        if(savedHotelServiceId && draftKey && draftKey.endsWith(':'+String(savedHotelServiceId))){
            try{sessionStorage.removeItem(draftKey);}catch(e){}
        }else{
            restoreHotelDraft(form);
        }

        const hasNewHotelDraft=Boolean((newHotelName?.value||'').trim() || (newHotelCity?.value||'').trim());
        setNewHotelMode(hasNewHotelDraft);
        if(hasNewHotelDraft)syncNewCity(); else syncExistingHotel();

        form.addEventListener('input',()=>{captureHotelDraft(form);refresh(form)});
        form.addEventListener('change',()=>{captureHotelDraft(form);refresh(form)});
        form.addEventListener('submit',()=>captureHotelDraft(form));

        refresh(form);
    });
})();
</script>
@endif

<script>
(function(){
    document.querySelectorAll('[data-passenger-picker]').forEach(picker=>{
        const tabs=[...picker.querySelectorAll('[data-passenger-tab]')];
        const panels=[...picker.querySelectorAll('[data-passenger-panel]')];
        const search=picker.querySelector('[data-passenger-search]');
        const refresh=picker.querySelector('[data-passenger-refresh]');
        const results=picker.querySelector('[data-passenger-results]');
        const close=picker.querySelector('[data-passenger-close]');
        const title=picker.querySelector('[data-passenger-picker-title]');
        const openers=[...document.querySelectorAll(`[data-passenger-open][aria-controls="${picker.id}"]`)];
        const endpoint=@json(route('operations.bookings.passengers.search',$booking));
        let timer=null;
        let savedLoaded=false;

        function esc(v){
            return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
        }
        function setTab(name){
            tabs.forEach(x=>x.classList.toggle('active',x.dataset.passengerTab===name));
            panels.forEach(p=>p.classList.toggle('active',p.dataset.passengerPanel===name));
            if(title) title.textContent=name==='new'?'Create New Passenger':'Reuse Previous Traveller';
            if(name==='saved' && !savedLoaded) load();
        }
        function setOpen(open,name){
            const target=name||'saved';
            picker.classList.toggle('open',open);
            openers.forEach(b=>b.setAttribute('aria-expanded',open && b.dataset.passengerOpen===target?'true':'false'));
            if(open){
                setTab(target);
                requestAnimationFrame(()=>picker.scrollIntoView({behavior:'smooth',block:'nearest'}));
                if(target==='saved') setTimeout(()=>search?.focus(),120);
                else setTimeout(()=>picker.querySelector('[data-passenger-panel="new"] input[name="first_name"]')?.focus(),120);
            }
        }
        function render(profiles){
            if(!profiles.length){
                results.innerHTML='<div style="padding:16px;text-align:center;color:var(--muted);font-size:10px">No saved passenger found. Use “+ New Passenger”.</div>';
                return;
            }
            results.innerHTML=profiles.map(p=>{
                const previous=p.previous_for_customer?'<span class="previous-badge">Previous Traveller</span>':'';
                const already=p.already_in_booking?'<span class="already-badge">Already Added</span>':'';
                const disabled=p.already_in_booking?'disabled':'';
                const meta=[p.nationality||null,p.date_of_birth?'DOB '+p.date_of_birth:null,p.mobile||null].filter(Boolean).map(esc).join(' · ');
                return `<label class="saved-passenger-row">
                    <div><input type="checkbox" name="profile_ids[]" value="${p.id}" ${disabled}></div>
                    <div class="saved-passenger-name"><strong>${esc(p.name)} ${previous} ${already}</strong><small>${meta||'No additional details'}${p.last_used_at?' · Last used '+esc(p.last_used_at):''}</small></div>
                    <div><strong>${esc(p.pax_type)}</strong></div>
                    <div>${esc(p.passport_no||'—')}</div>
                    <div>${esc(p.passport_expiry||'—')}</div>
                </label>`;
            }).join('');
        }
        async function load(){
            results.innerHTML='<div style="padding:16px;text-align:center;color:var(--muted);font-size:10px">Searching saved passengers…</div>';
            try{
                const url=endpoint+'?q='+encodeURIComponent(search?.value||'');
                const response=await fetch(url,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
                if(!response.ok) throw new Error('Search request failed');
                const data=await response.json();
                render(data.profiles||[]);
                savedLoaded=true;
            }catch(e){
                results.innerHTML='<div style="padding:16px;text-align:center;color:#b42318;font-size:10px">Unable to load saved passengers. You can still use “+ New Passenger”.</div>';
            }
        }

        openers.forEach(button=>button.addEventListener('click',()=>{
            const name=button.dataset.passengerOpen||'saved';
            const alreadyOpen=picker.classList.contains('open');
            const current=tabs.find(x=>x.classList.contains('active'))?.dataset.passengerTab;
            if(alreadyOpen && current===name){ setOpen(false); return; }
            setOpen(true,name);
        }));
        close?.addEventListener('click',()=>setOpen(false));
        tabs.forEach(tab=>tab.addEventListener('click',()=>setTab(tab.dataset.passengerTab)));
        refresh?.addEventListener('click',load);
        search?.addEventListener('input',()=>{
            clearTimeout(timer);
            timer=setTimeout(load,300);
        });
        document.addEventListener('keydown',event=>{
            if(event.key==='Escape' && picker.classList.contains('open')) setOpen(false);
        });
    });
})();
</script>


@if(!$overviewOnly)
{{-- ERP10286 WORKSPACE OVERLAY START --}}
@include('operations.bookings.partials.air-itinerary-v10286')
@include('operations.bookings.partials.hotel-package-ui-v10286')
@include('operations.bookings.partials.transport-booking-v10286')
{{-- ERP10286 WORKSPACE OVERLAY END --}}
@endif
@endsection
