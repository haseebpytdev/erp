@extends('layouts.app')
@section('title','Dashboard')
@section('page_title','Dashboard')

@section('content')
<style id="erp-dashboard-inline-styles">
.erp-dashboard{--db-ink:#17213b;--db-muted:#667085;--db-border:#e4e9f2;--db-bg:#f6f8fc;--db-blue:#315ee8;--db-blue2:#eaf0ff;--db-green:#138a57;--db-green2:#e9f8f1;--db-amber:#c97912;--db-amber2:#fff5df;--db-red:#d33d4c;--db-red2:#fff0f1;--db-violet:#6d48d7;--db-violet2:#f0ecff;--db-cyan:#087f9c;--db-cyan2:#e8f8fc;color:var(--db-ink);font-size:14px}.erp-dashboard *{box-sizing:border-box}.dash-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:22px 24px;border:1px solid #dce4f4;border-radius:14px;background:linear-gradient(115deg,#fff 0%,#f5f8ff 66%,#eef4ff 100%);box-shadow:0 7px 24px rgba(43,66,122,.07)}.dash-kicker,.dash-panel-head span,.dash-section-heading span{font-size:11px;font-weight:800;letter-spacing:.08em;color:#6c7da7}.dash-hero h1{margin:3px 0 4px;font-size:24px;line-height:1.15;color:#12224d}.dash-hero p{margin:0;color:var(--db-muted);font-size:12px}.dash-hero-actions{display:flex;gap:8px;flex-wrap:wrap}.dash-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 14px;border:1px solid #cad5ea;border-radius:8px;background:#fff;color:#29406e;text-decoration:none;font-weight:700;font-size:12px;cursor:pointer}.dash-btn.primary{background:#315ee8;color:#fff;border-color:#315ee8;box-shadow:0 4px 12px rgba(49,94,232,.2)}.dash-btn.compact{min-height:34px}.dash-filterbar{margin-top:12px;display:flex;align-items:end;gap:10px;padding:12px;border:1px solid var(--db-border);border-radius:10px;background:#fff}.dash-filterbar label{display:flex;flex-direction:column;gap:5px;min-width:150px}.dash-filterbar label span{font-size:10px;font-weight:800;color:#7a849a;text-transform:uppercase;letter-spacing:.04em}.dash-filterbar select,.dash-filterbar input{height:34px;border:1px solid #d8dfeb;border-radius:7px;background:#fff;padding:0 10px;color:#24324f;font-size:12px}.dash-custom-dates{display:none;align-items:end;gap:8px}.dash-custom-dates.show{display:flex}.dash-reset{margin-left:auto;align-self:center;color:#61708f;font-size:12px;text-decoration:none;font-weight:700}.dash-kpi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:12px}.dash-kpi{position:relative;min-height:118px;padding:14px 13px;border:1px solid var(--db-border);border-radius:11px;background:#fff;text-decoration:none;color:inherit;overflow:hidden;transition:.15s ease;box-shadow:0 2px 7px rgba(35,52,88,.035)}.dash-kpi:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(35,52,88,.09)}.dash-kpi:before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:#315ee8}.dash-kpi-icon{position:absolute;right:12px;top:13px;width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800}.dash-kpi-label{font-size:9px;line-height:1.25;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#72809a;padding-right:28px}.dash-kpi-value{font-size:16px;line-height:1.18;font-weight:800;margin-top:13px;white-space:nowrap;letter-spacing:-.025em;overflow:hidden;text-overflow:ellipsis}.dash-kpi-note{font-size:9px;line-height:1.2;color:#8a93a5;margin-top:7px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tone-blue:before{background:#315ee8}.tone-blue .dash-kpi-icon{background:#eaf0ff;color:#315ee8}.tone-indigo:before{background:#4f46c6}.tone-indigo .dash-kpi-icon{background:#eeedff;color:#4f46c6}.tone-amber:before{background:#d48a18}.tone-amber .dash-kpi-icon{background:#fff5df;color:#bd750c}.tone-violet:before{background:#7b54d8}.tone-violet .dash-kpi-icon{background:#f0ecff;color:#6d48d7}.tone-cyan:before{background:#0c8ead}.tone-cyan .dash-kpi-icon{background:#e8f8fc;color:#087f9c}.tone-green:before{background:#169864}.tone-green .dash-kpi-icon{background:#e9f8f1;color:#138a57}.dash-mini-strip{display:grid;grid-template-columns:repeat(4,1fr);margin-top:10px;border:1px solid var(--db-border);border-radius:9px;background:#fff;overflow:hidden}.dash-mini-strip>div{padding:10px 14px;border-right:1px solid var(--db-border);display:flex;align-items:center;justify-content:space-between;gap:10px}.dash-mini-strip>div:last-child{border-right:0}.dash-mini-strip span{color:#78849c;font-size:11px}.dash-mini-strip strong{font-size:12px;color:#20345f}.dash-section-heading{display:flex;align-items:end;justify-content:space-between;margin:20px 2px 8px}.dash-section-heading h2{font-size:17px;margin:2px 0 0;color:#1d2c4e}.dash-section-heading a,.dash-panel-head a{color:#315ee8;text-decoration:none;font-size:11px;font-weight:700}.dash-ops-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.dash-op-card{display:block;padding:14px;border:1px solid var(--db-border);border-radius:11px;background:#fff;text-decoration:none;color:inherit}.dash-op-top{display:flex;justify-content:space-between;align-items:flex-start}.dash-op-top span{font-size:10px;color:#74809a;font-weight:800;letter-spacing:.05em}.dash-op-top strong{display:block;font-size:25px;color:#1e2f55;margin-top:2px}.op-badge{width:35px;height:35px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800}.op-badge.blue{background:#eaf0ff;color:#315ee8}.op-badge.indigo{background:#eeedff;color:#5448c8}.op-badge.amber{background:#fff4dd;color:#c47a10}.op-badge.green{background:#e9f8f1;color:#138a57}.dash-op-list{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;margin-top:12px;padding-top:10px;border-top:1px solid #edf0f5}.dash-op-list span{display:flex;justify-content:space-between;color:#758197;font-size:11px}.dash-op-list b{color:#293855}.danger-text{color:#d33d4c!important}.dash-chart-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(290px,.85fr);gap:10px;margin-top:14px}.dash-panel{border:1px solid var(--db-border);border-radius:11px;background:#fff;box-shadow:0 2px 7px rgba(35,52,88,.03);overflow:hidden}.dash-panel-head{min-height:57px;padding:12px 15px;border-bottom:1px solid #edf0f5;display:flex;align-items:center;justify-content:space-between;gap:12px}.dash-panel-head h3{font-size:14px;margin:2px 0 0;color:#1d2d50}.chart-period,.panel-count{font-size:10px;padding:4px 8px;border:1px solid #dde4f1;border-radius:999px;color:#70809b;background:#fafbfe}.chart-wrap{padding:12px 14px 8px;height:290px}.donut-wrap{height:205px;padding:12px 26px 4px}.product-legend{padding:5px 15px 13px;display:grid;grid-template-columns:1fr 1fr;gap:6px}.product-legend div{display:flex;align-items:center;gap:7px;font-size:10px;color:#65718a}.product-legend i{width:8px;height:8px;border-radius:50%;display:block}.dash-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px}.travel-list{padding:2px 0}.travel-row{display:grid;grid-template-columns:46px minmax(0,1fr) 45px auto;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid #f0f2f6;text-decoration:none;color:inherit}.travel-row:last-child{border-bottom:0}.travel-date{width:38px;height:38px;border-radius:8px;background:#eef3ff;color:#315ee8;display:flex;flex-direction:column;align-items:center;justify-content:center}.travel-date strong{font-size:15px;line-height:14px}.travel-date span{font-size:8px;font-weight:800}.travel-main strong{display:block;font-size:11px}.travel-main span{display:block;font-size:10px;color:#7d8798;margin-top:2px}.travel-pax{text-align:center}.travel-pax strong{display:block;font-size:12px}.travel-pax span{font-size:9px;color:#8a94a7}.status-pill{display:inline-flex;align-items:center;justify-content:center;padding:3px 7px;border-radius:999px;background:#eef2f7;color:#5c687c;font-size:9px;font-weight:800;text-transform:uppercase;white-space:nowrap}.status-pill.confirmed,.status-pill.approved,.status-pill.issued,.status-pill.posted{background:#e9f8f1;color:#12794e}.status-pill.pending,.status-pill.pending_review,.status-pill.pending_approval,.status-pill.booked{background:#fff4dd;color:#a96008}.status-pill.cancelled,.status-pill.void,.status-pill.rejected{background:#fff0f1;color:#c73343}.alert-list{padding:8px 12px}.dash-alert{display:grid;grid-template-columns:9px 1fr auto;align-items:center;gap:9px;padding:8px 7px;color:#35415a;text-decoration:none;font-size:11px;border-bottom:1px solid #f0f2f6}.dash-alert:last-child{border-bottom:0}.alert-dot{width:7px;height:7px;border-radius:50%;background:#8290a6}.dash-alert.warning .alert-dot{background:#d48a18}.dash-alert.danger .alert-dot{background:#d33d4c}.dash-alert.info .alert-dot{background:#315ee8}.dash-alert.success .alert-dot{background:#169864}.dash-alert b{color:#315ee8}.approval-box{margin:4px 12px 12px;border-radius:8px;background:#f6f8fc;border:1px solid #e7ebf3;padding:8px 10px}.approval-title{font-size:10px;font-weight:800;color:#6d7890;margin-bottom:4px}.approval-box a{display:flex;justify-content:space-between;text-decoration:none;color:#3b4862;padding:5px 0;font-size:11px}.approval-box strong{color:#315ee8}.outstanding-grid .dash-panel{min-height:205px}.money-list{padding:6px 14px 10px}.money-list a{display:flex;justify-content:space-between;gap:15px;padding:8px 0;border-bottom:1px solid #f0f2f6;color:#3a4760;text-decoration:none;font-size:11px}.money-list a:last-child{border-bottom:0}.money-list strong{color:#1f365e;white-space:nowrap}.dash-table-wrap{overflow:auto}.dash-table{width:100%;border-collapse:collapse;font-size:10px}.dash-table th{padding:8px 10px;text-align:left;color:#7a8498;font-size:9px;text-transform:uppercase;letter-spacing:.04em;background:#fafbfc;border-bottom:1px solid #e9edf4}.dash-table td{padding:8px 10px;border-bottom:1px solid #f0f2f6;color:#3b475d}.dash-table tr:last-child td{border-bottom:0}.dash-table a{text-decoration:none;color:#29406e}.dash-table a strong{display:block}.dash-table a small{display:block;color:#8b94a4;margin-top:2px}.dash-table .right{text-align:right}.dash-table .money{font-weight:700;color:#1c6e4c}.transaction-list{padding:3px 0}.transaction-list>a{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:9px 14px;border-bottom:1px solid #f0f2f6;text-decoration:none;color:#3a4760}.transaction-list>a:last-child{border-bottom:0}.transaction-list strong{display:block;font-size:11px}.transaction-list span{display:block;font-size:9px;color:#8992a4;margin-top:2px}.transaction-amount{text-align:right;font-size:11px;font-weight:700;color:#1f365e}.transaction-amount small{display:block;font-size:9px;font-weight:500;color:#8992a4;margin-top:2px}.dash-empty{padding:25px 15px;text-align:center;color:#8a94a7;font-size:11px}.dash-empty-cell{text-align:center!important;color:#8a94a7!important;padding:20px!important}.quick-panel{margin-top:10px}.quick-actions{display:grid;grid-template-columns:repeat(8,1fr);gap:8px;padding:12px}.quick-actions a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;min-height:68px;border:1px solid #e1e6ef;border-radius:9px;background:#fbfcfe;text-decoration:none;color:#40506c;font-size:10px;font-weight:700;transition:.15s}.quick-actions a:hover{background:#f1f5ff;border-color:#cad7f3;color:#315ee8}.quick-actions b{font-size:17px;color:#315ee8}.chart-fallback{padding:12px 15px}.chart-fallback .fallback-bars{display:grid;gap:6px}.chart-fallback .bar-row{display:grid;grid-template-columns:46px 1fr;gap:8px;align-items:center;font-size:9px;color:#7a8498}.chart-fallback .bar-track{height:9px;background:#eef1f6;border-radius:999px;overflow:hidden}.chart-fallback .bar-fill{height:100%;background:#315ee8;border-radius:999px}
@media(max-width:1250px){.dash-kpi-grid{grid-template-columns:repeat(3,1fr)}.quick-actions{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.dash-hero{align-items:flex-start;flex-direction:column}.dash-filterbar{flex-wrap:wrap}.dash-reset{margin-left:0}.dash-ops-grid{grid-template-columns:1fr 1fr}.dash-chart-grid,.dash-grid-2{grid-template-columns:1fr}.dash-mini-strip{grid-template-columns:1fr 1fr}.dash-mini-strip>div:nth-child(2){border-right:0}.dash-mini-strip>div:nth-child(-n+2){border-bottom:1px solid var(--db-border)}}
@media(max-width:640px){.dash-kpi-grid{grid-template-columns:1fr 1fr}.dash-ops-grid{grid-template-columns:1fr}.dash-custom-dates.show{width:100%;flex-wrap:wrap}.dash-filterbar label{min-width:130px;flex:1}.quick-actions{grid-template-columns:repeat(2,1fr)}.travel-row{grid-template-columns:42px 1fr 38px}.travel-row .status-pill{grid-column:2/4;justify-self:start}.dash-mini-strip{grid-template-columns:1fr}.dash-mini-strip>div{border-right:0!important;border-bottom:1px solid var(--db-border)!important}.dash-mini-strip>div:last-child{border-bottom:0!important}.dash-kpi-value{font-size:15px}}
.staff-snapshot{display:grid;grid-template-columns:1fr 1fr;gap:9px;padding:12px}.staff-snapshot>div{border:1px solid #e7ebf3;border-radius:9px;background:#fafbfe;padding:13px}.staff-snapshot span{display:block;font-size:10px;color:#7b879d}.staff-snapshot strong{display:block;font-size:20px;color:#27406f;margin-top:5px}
.money-list>div{display:flex;justify-content:space-between;gap:15px;padding:8px 0;border-bottom:1px solid #f0f2f6;color:#3a4760;font-size:11px}.money-list>div:last-child{border-bottom:0}
.transaction-static{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:9px 14px;border-bottom:1px solid #f0f2f6;color:#3a4760}.transaction-static:last-child{border-bottom:0}.transaction-static strong{display:block;font-size:11px}.transaction-static span{display:block;font-size:9px;color:#8992a4;margin-top:2px}
.booking-static{display:flex;flex-direction:column;gap:2px}.booking-static strong{color:#29406e}.booking-static small{color:#8b94a4}


/* ERP10.23.1: self-contained server-rendered dashboard charts */
.server-trend-chart{padding:8px 10px 2px}.server-trend-chart svg{display:block;width:100%;height:275px}.server-chart-grid{stroke:#e9edf4;stroke-width:1}.server-chart-axis{fill:#8490a4;font-size:9px}.server-chart-sales{fill:none;stroke:#315ee8;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}.server-chart-cost{fill:none;stroke:#d48a18;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}.server-chart-profit{fill:none;stroke:#169864;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}.server-point-sales{fill:#fff;stroke:#315ee8;stroke-width:2}.server-point-cost{fill:#fff;stroke:#d48a18;stroke-width:2}.server-point-profit{fill:#fff;stroke:#169864;stroke-width:2}.server-chart-legend{display:flex;justify-content:center;gap:18px;flex-wrap:wrap;padding:0 12px 12px;font-size:10px;color:#68758c}.server-chart-legend span{display:inline-flex;align-items:center;gap:6px}.server-chart-legend i{width:16px;height:3px;border-radius:999px;display:inline-block}.server-donut-area{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:205px;padding:13px 20px 4px}.server-donut{width:156px;height:156px;border-radius:50%;position:relative;display:flex;align-items:center;justify-content:center;box-shadow:inset 0 0 0 1px rgba(22,34,62,.05)}.server-donut:after{content:"";position:absolute;inset:30px;border-radius:50%;background:#fff;box-shadow:0 0 0 1px #edf0f5}.server-donut-center{position:relative;z-index:2;text-align:center;width:88px}.server-donut-center strong{display:block;color:#1d2d50;font-size:14px;line-height:1.15}.server-donut-center span{display:block;color:#8993a6;font-size:9px;margin-top:3px}.product-legend.server{width:100%;padding-top:8px}.product-legend.server>div{justify-content:space-between;gap:10px}.product-legend.server .legend-left{display:flex;align-items:center;gap:7px;min-width:0}.product-legend.server .legend-values{margin-left:auto;text-align:right;white-space:nowrap;color:#35415a;font-weight:700}.product-legend.server .legend-values small{display:block;color:#8b94a5;font-size:8px;font-weight:600;margin-top:1px}.dash-panel,.dash-kpi,.dash-op-card,.dash-filterbar,.dash-hero{isolation:isolate}

</style>
@php
    $money = fn($v) => 'PKR '.number_format((float)$v,2);
    $metric = function($value,$available=true) use ($money){ return $available ? $money($value) : '—'; };
    // Large dashboard KPI cards intentionally omit the currency prefix so long totals remain fully visible.
    $kpiMoney = fn($v) => number_format((float)$v,2);
    $kpiMetric = fn($value,$available=true) => $available ? number_format((float)$value,2) : '—';
    $branchName = $branchId ? optional($branches->firstWhere('id',$branchId))->name : 'All Branches';
    $canViewBookings = auth()->user()->hasPermission('bookings.view');
    $canViewInvoices = auth()->user()->hasPermission('sales_invoices.view');
    $canViewJournals = auth()->user()->hasPermission('journals.view');
    $canViewCosting = auth()->user()->hasPermission('costing.view');
    $canViewParties = auth()->user()->hasPermission('parties.view');

    // Server-rendered charts: no CDN or public dashboard asset dependency.
    $compactNumber = function($value){
        $value = (float)$value;
        $abs = abs($value);
        if($abs >= 10000000) return number_format($value/10000000,1).'Cr';
        if($abs >= 1000000) return number_format($value/1000000,1).'M';
        if($abs >= 1000) return number_format($value/1000,0).'K';
        return number_format($value,0);
    };
    $trendLabels = $trend['labels'] ?? [];
    $trendSales = array_map('floatval',$trend['sales'] ?? []);
    $trendCosts = array_map('floatval',$trend['costs'] ?? []);
    $trendProfit = array_map('floatval',$trend['profit'] ?? []);
    $trendValues = array_merge($trendSales,$trendCosts,$trendProfit,[0]);
    $trendMin = min($trendValues);
    $trendMax = max($trendValues);
    $trendMin = min(0,$trendMin);
    $trendMax = max(0,$trendMax);
    if(abs($trendMax-$trendMin) < 0.0001){ $trendMax = $trendMin + 1; }
    $chartW = 760; $chartH = 270; $padL = 52; $padR = 18; $padT = 18; $padB = 38;
    $plotW = $chartW-$padL-$padR; $plotH = $chartH-$padT-$padB;
    $xFor = function($index) use ($trendLabels,$padL,$plotW){
        $count=max(1,count($trendLabels)); return $count<=1 ? $padL+$plotW/2 : $padL+($plotW*$index/($count-1));
    };
    $yFor = function($value) use ($trendMin,$trendMax,$padT,$plotH){ return $padT+(($trendMax-(float)$value)/($trendMax-$trendMin))*$plotH; };
    $pointsFor = function($values) use ($xFor,$yFor){
        $points=[]; foreach($values as $i=>$value){$points[]=round($xFor($i),2).','.round($yFor($value),2);} return implode(' ',$points);
    };
    $trendSalesPoints=$pointsFor($trendSales); $trendCostPoints=$pointsFor($trendCosts); $trendProfitPoints=$pointsFor($trendProfit);

    $chartColors=['#315ee8','#169864','#d48a18','#7b54d8','#0c8ead','#d33d4c','#6d7890','#2f7dce'];
    $mixLabels=$productMix['labels'] ?? [];
    $mixValues=array_map('floatval',$productMix['values'] ?? []);
    $mixTotal=array_sum($mixValues);
    $mixStops=[]; $mixCursor=0.0;
    foreach($mixValues as $i=>$value){
        if($mixTotal<=0 || $value<=0) continue;
        $next=$mixCursor+($value/$mixTotal*100);
        $mixStops[]=$chartColors[$i%count($chartColors)].' '.round($mixCursor,3).'% '.round($next,3).'%';
        $mixCursor=$next;
    }
    $mixGradient=$mixStops ? 'conic-gradient('.implode(',',$mixStops).')' : 'conic-gradient(#e9edf5 0 100%)';
@endphp
<div class="erp-dashboard" data-dashboard-root>
    <section class="dash-hero">
        <div>
            <div class="dash-kicker">MANAGEMENT OVERVIEW</div>
            <h1>{{ $erpCompany?->name ?? 'Easy Ticket Travel ERP' }}</h1>
            <p>{{ $periodLabel }} · {{ $branchName ?: 'Selected Branch' }} · Updated {{ now()->format('d M Y, h:i A') }}</p>
        </div>
        <div class="dash-hero-actions">
            @if(auth()->user()->hasPermission('bookings.create'))
                <a class="dash-btn primary" href="{{ route('operations.bookings.create') }}">＋ New Booking</a>
            @endif
            @if($canViewInvoices)
                <a class="dash-btn" href="{{ route('sales.invoices.index') }}">Sales Invoices</a>
            @endif
        </div>
    </section>

    <form class="dash-filterbar" method="GET" action="{{ route('dashboard') }}">
        <label><span>Period</span><select name="period" onchange="this.form.submit()">
            @foreach(['today'=>'Today','month'=>'This Month','3m'=>'Last 3 Months','6m'=>'Last 6 Months','12m'=>'Last 12 Months','fy'=>'Financial Year','custom'=>'Custom'] as $k=>$label)
                <option value="{{ $k }}" {{ $periodKey===$k?'selected':'' }}>{{ $label }}</option>
            @endforeach
        </select></label>
        @if($canChooseBranch && $branches->isNotEmpty())
            <label><span>Branch</span><select name="branch_id" onchange="this.form.submit()">
                <option value="">All Branches</option>
                @foreach($branches as $branch)
                    <option value="{{ $branch->id }}" {{ (int)$branchId===(int)$branch->id?'selected':'' }}>{{ $branch->name ?? ('Branch #'.$branch->id) }}</option>
                @endforeach
            </select></label>
        @endif
        <div class="dash-custom-dates {{ $periodKey==='custom'?'show':'' }}">
            <label><span>From</span><input type="date" name="start" value="{{ request('start',$periodStart->format('Y-m-d')) }}"></label>
            <label><span>To</span><input type="date" name="end" value="{{ request('end',$periodEnd->format('Y-m-d')) }}"></label>
            <button class="dash-btn compact" type="submit">Apply</button>
        </div>
        <a class="dash-reset" href="{{ route('dashboard') }}">Reset</a>
    </form>

    @if($canSeeFinance)
    <section class="dash-kpi-grid financial">
        @if($canViewInvoices)<a class="dash-kpi tone-blue" href="{{ route('sales.invoices.index',['date'=>'today']) }}">@else<div class="dash-kpi tone-blue">@endif
            <div class="dash-kpi-icon">↗</div><div class="dash-kpi-label">Today Sales</div><div class="dash-kpi-value" title="{{ $kpiMoney($finance['today_sales']) }}">{{ $kpiMoney($finance['today_sales']) }}</div><div class="dash-kpi-note">Posted invoice value</div>
        @if($canViewInvoices)</a>@else</div>@endif
        @if($canViewInvoices)<a class="dash-kpi tone-indigo" href="{{ route('sales.invoices.index') }}">@else<div class="dash-kpi tone-indigo">@endif
            <div class="dash-kpi-icon">▥</div><div class="dash-kpi-label">{{ $periodKey==='month'?'Month Sales':'Period Sales' }}</div><div class="dash-kpi-value" title="{{ $kpiMoney($finance['period_sales']) }}">{{ $kpiMoney($finance['period_sales']) }}</div><div class="dash-kpi-note">{{ $periodKey==='month'?'Current month sales':'Selected period sales' }}</div>
        @if($canViewInvoices)</a>@else</div>@endif
        @if($canViewJournals)<a class="dash-kpi tone-amber" href="{{ route('accounting.reports.index',['report'=>'receivables']) }}">@else<div class="dash-kpi tone-amber">@endif
            <div class="dash-kpi-icon">◎</div><div class="dash-kpi-label">Receivables</div><div class="dash-kpi-value" title="{{ $kpiMetric($finance['receivable'],$finance['receivable_available']) }}">{{ $kpiMetric($finance['receivable'],$finance['receivable_available']) }}</div><div class="dash-kpi-note">Outstanding customer balance</div>
        @if($canViewJournals)</a>@else</div>@endif
        @if($canViewCosting)<a class="dash-kpi tone-violet" href="{{ route('operations.costing.index') }}">@else<div class="dash-kpi tone-violet">@endif
            <div class="dash-kpi-icon">⇣</div><div class="dash-kpi-label">Payables</div><div class="dash-kpi-value" title="{{ $kpiMetric($finance['payable'],$finance['payable_available']) }}">{{ $kpiMetric($finance['payable'],$finance['payable_available']) }}</div><div class="dash-kpi-note">Confirmed supplier exposure</div>
        @if($canViewCosting)</a>@else</div>@endif
        @if($canViewJournals)<a class="dash-kpi tone-cyan" href="{{ route('accounting.ledgers.index') }}">@else<div class="dash-kpi tone-cyan">@endif
            <div class="dash-kpi-icon">▤</div><div class="dash-kpi-label">Cash & Bank</div><div class="dash-kpi-value" title="{{ $kpiMetric($finance['cash_bank'],$finance['cash_bank_available']) }}">{{ $kpiMetric($finance['cash_bank'],$finance['cash_bank_available']) }}</div><div class="dash-kpi-note">Current ledger balance</div>
        @if($canViewJournals)</a>@else</div>@endif
        @if($canViewJournals)<a class="dash-kpi tone-green" href="{{ route('accounting.reports.index',['report'=>'profit-loss']) }}">@else<div class="dash-kpi tone-green">@endif
            <div class="dash-kpi-icon">◆</div><div class="dash-kpi-label">Gross Profit</div><div class="dash-kpi-value" title="{{ $kpiMoney($finance['gross_profit']) }}">{{ $kpiMoney($finance['gross_profit']) }}</div><div class="dash-kpi-note">Sales less supplier cost</div>
        @if($canViewJournals)</a>@else</div>@endif
    </section>

    <section class="dash-mini-strip">
        <div><span>Today Collection</span><strong>{{ $finance['journal_flow_available']?$money($finance['today_collections']):'—' }}</strong></div>
        <div><span>Today Payments</span><strong>{{ $finance['journal_flow_available']?$money($finance['today_payments']):'—' }}</strong></div>
        <div><span>Period Supplier Cost</span><strong>{{ $money($finance['period_cost']) }}</strong></div>
        <div><span>Month Sales</span><strong>{{ $money($finance['month_sales']) }}</strong></div>
    </section>
    @endif

    <section class="dash-section-heading"><div><span>OPERATIONS</span><h2>Live booking activity</h2></div>@if($canViewBookings)<a href="{{ route('operations.bookings.index') }}">View all bookings →</a>@endif</section>
    <section class="dash-ops-grid">
        @if($canViewBookings)<a class="dash-op-card" href="{{ route('operations.bookings.index') }}">@else<div class="dash-op-card">@endif
            <div class="dash-op-top"><div><span>BOOKINGS</span><strong>{{ $operations['bookings']['total'] }}</strong></div><div class="op-badge blue">▣</div></div>
            <div class="dash-op-list"><span>Draft <b>{{ $operations['bookings']['draft'] }}</b></span><span>Pending <b>{{ $operations['bookings']['pending'] }}</b></span><span>Confirmed <b>{{ $operations['bookings']['confirmed'] }}</b></span><span>Cancelled <b>{{ $operations['bookings']['cancelled'] }}</b></span></div>
        @if($canViewBookings)</a>@else</div>@endif
        @if($canViewBookings)<a class="dash-op-card" href="{{ route('operations.bookings.index',['service'=>'AIR_TICKET']) }}">@else<div class="dash-op-card">@endif
            <div class="dash-op-top"><div><span>AIR TICKETS</span><strong>{{ $operations['tickets']['total'] }}</strong></div><div class="op-badge indigo">✈</div></div>
            <div class="dash-op-list"><span>Booked <b>{{ $operations['tickets']['booked'] }}</b></span><span>Issued <b>{{ $operations['tickets']['issued'] }}</b></span><span>Missing No. <b class="danger-text">{{ $operations['tickets']['missing_number'] }}</b></span><span>Refund/Void <b>{{ $operations['tickets']['refund_void'] }}</b></span></div>
        @if($canViewBookings)</a>@else</div>@endif
        @if($canViewBookings)<a class="dash-op-card" href="{{ route('operations.bookings.index',['service'=>'HOTEL']) }}">@else<div class="dash-op-card">@endif
            <div class="dash-op-top"><div><span>HOTELS</span><strong>{{ $operations['hotels']['upcoming'] }}</strong></div><div class="op-badge amber">▦</div></div>
            <div class="dash-op-list"><span>Check-in Today <b>{{ $operations['hotels']['check_in_today'] }}</b></span><span>In House <b>{{ $operations['hotels']['in_house'] }}</b></span><span>Check-out Today <b>{{ $operations['hotels']['check_out_today'] }}</b></span><span>Upcoming <b>{{ $operations['hotels']['upcoming'] }}</b></span></div>
        @if($canViewBookings)</a>@else</div>@endif
        @if($canViewBookings)<a class="dash-op-card" href="{{ route('operations.bookings.index',['service'=>'VISA']) }}">@else<div class="dash-op-card">@endif
            <div class="dash-op-top"><div><span>VISA</span><strong>{{ $operations['visa']['total'] }}</strong></div><div class="op-badge green">✓</div></div>
            <div class="dash-op-list"><span>Pending <b>{{ $operations['visa']['pending'] }}</b></span><span>Processing <b>{{ $operations['visa']['processing'] }}</b></span><span>Issued <b>{{ $operations['visa']['issued'] }}</b></span><span>Rejected <b>{{ $operations['visa']['rejected'] }}</b></span></div>
        @if($canViewBookings)</a>@else</div>@endif
    </section>

    @if($canSeeFinance)
    <section class="dash-chart-grid">
        <article class="dash-panel chart-panel">
            <div class="dash-panel-head"><div><span>FINANCIAL TREND</span><h3>Sales · Cost · Gross Profit</h3></div><div class="chart-period">Last 12 months</div></div>
            <div class="server-trend-chart" role="img" aria-label="Sales, supplier cost and gross profit trend for the last 12 months">
                <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" preserveAspectRatio="none">
                    @foreach(range(0,4) as $gridIndex)
                        @php($gridY=$padT+($plotH*$gridIndex/4))
                        @php($gridValue=$trendMax-(($trendMax-$trendMin)*$gridIndex/4))
                        <line class="server-chart-grid" x1="{{ $padL }}" y1="{{ $gridY }}" x2="{{ $chartW-$padR }}" y2="{{ $gridY }}" />
                        <text class="server-chart-axis" x="{{ $padL-7 }}" y="{{ $gridY+3 }}" text-anchor="end">{{ $compactNumber($gridValue) }}</text>
                    @endforeach
                    @foreach($trendLabels as $i=>$label)
                        <text class="server-chart-axis" x="{{ $xFor($i) }}" y="{{ $chartH-12 }}" text-anchor="middle">{{ $label }}</text>
                    @endforeach
                    @if($trendSalesPoints)<polyline class="server-chart-sales" points="{{ $trendSalesPoints }}" />@endif
                    @if($trendCostPoints)<polyline class="server-chart-cost" points="{{ $trendCostPoints }}" />@endif
                    @if($trendProfitPoints)<polyline class="server-chart-profit" points="{{ $trendProfitPoints }}" />@endif
                    @foreach($trendSales as $i=>$value)<circle class="server-point-sales" cx="{{ $xFor($i) }}" cy="{{ $yFor($value) }}" r="2.8"><title>Sales {{ $trendLabels[$i] ?? '' }}: {{ $money($value) }}</title></circle>@endforeach
                    @foreach($trendCosts as $i=>$value)<circle class="server-point-cost" cx="{{ $xFor($i) }}" cy="{{ $yFor($value) }}" r="2.8"><title>Cost {{ $trendLabels[$i] ?? '' }}: {{ $money($value) }}</title></circle>@endforeach
                    @foreach($trendProfit as $i=>$value)<circle class="server-point-profit" cx="{{ $xFor($i) }}" cy="{{ $yFor($value) }}" r="2.8"><title>Gross Profit {{ $trendLabels[$i] ?? '' }}: {{ $money($value) }}</title></circle>@endforeach
                </svg>
            </div>
            <div class="server-chart-legend"><span><i style="background:#315ee8"></i>Sales</span><span><i style="background:#d48a18"></i>Supplier Cost</span><span><i style="background:#169864"></i>Gross Profit</span></div>
        </article>
        <article class="dash-panel chart-panel compact-chart">
            <div class="dash-panel-head"><div><span>PRODUCT MIX</span><h3>Sales by Service</h3></div></div>
            <div class="server-donut-area">
                <div class="server-donut" style="background:{{ $mixGradient }}">
                    <div class="server-donut-center"><strong>{{ $compactNumber($mixTotal) }}</strong><span>Total Sales</span></div>
                </div>
            </div>
            <div class="product-legend server">
                @foreach($mixLabels as $i=>$label)
                    @php($value=(float)($mixValues[$i] ?? 0))
                    @php($pct=$mixTotal>0?($value/$mixTotal*100):0)
                    <div><span class="legend-left"><i style="background:{{ $chartColors[$i%count($chartColors)] }}"></i><span>{{ $label }}</span></span><span class="legend-values">{{ number_format($pct,1) }}%<small>{{ $money($value) }}</small></span></div>
                @endforeach
            </div>
        </article>
    </section>
    @endif

    <section class="dash-grid-2">
        <article class="dash-panel">
            <div class="dash-panel-head"><div><span>NEXT 7 DAYS</span><h3>Upcoming & Returning Travel</h3></div><span class="panel-count">{{ $operations['passengers']['next_7_days'] }} out · {{ $operations['passengers']['returning_7_days'] ?? 0 }} return</span></div>
            @if($upcomingTravel->isEmpty())
                <div class="dash-empty">No upcoming departures or returns found for the next 7 days.</div>
            @else
                <div class="travel-list">
                    @foreach($upcomingTravel as $row)
                        @if($canViewBookings)<a class="travel-row" href="{{ route('operations.bookings.show',$row->id) }}">@else<div class="travel-row">@endif
                            <div class="travel-date"><strong>{{ \Illuminate\Support\Carbon::parse($row->event_date ?? $row->travel_start_date)->format('d') }}</strong><span>{{ strtoupper(\Illuminate\Support\Carbon::parse($row->event_date ?? $row->travel_start_date)->format('M')) }}</span></div>
                            <div class="travel-main"><strong>{{ $row->booking_no ?? ('Booking #'.$row->id) }}</strong><span>{{ ($row->movement ?? 'DEPARTURE')==='RETURN'?'Returning':'Departure' }} · {{ $row->route ?: ($row->booking_type ?? 'Travel Booking') }}</span></div>
                            <div class="travel-pax"><strong>{{ $row->passenger_count }}</strong><span>pax</span></div>
                            <span class="status-pill {{ strtolower((string)($row->status??'')) }}">{{ $row->status ?? '—' }}</span>
                        @if($canViewBookings)</a>@else</div>@endif
                    @endforeach
                </div>
            @endif
        </article>

        <article class="dash-panel attention-panel">
            <div class="dash-panel-head"><div><span>ATTENTION</span><h3>Alerts & Exceptions</h3></div></div>
            <div class="alert-list">
                @foreach($alerts as $alert)
                    @php($alertLink = $alert['url'] && (empty($alert['permission']) || auth()->user()->hasPermission($alert['permission'])))
                    @if($alertLink)<a href="{{ $alert['url'] }}" class="dash-alert {{ $alert['level'] }}">@else<div class="dash-alert {{ $alert['level'] }}">@endif
                        <span class="alert-dot"></span><span>{{ $alert['label'] }}</span>@if($alertLink)<b>→</b>@endif
                    @if($alertLink)</a>@else</div>@endif
                @endforeach
            </div>
            @php($visibleApprovals = collect($approvals)->filter(fn($approval) => empty($approval['permission']) || auth()->user()->hasPermission($approval['permission'])))
            @if($visibleApprovals->isNotEmpty())
                <div class="approval-box"><div class="approval-title">Pending Approvals</div>
                    @foreach($visibleApprovals as $approval)
                        <a href="{{ $approval['url'] }}"><span>{{ $approval['label'] }}</span><strong>{{ $approval['count'] }}</strong></a>
                    @endforeach
                </div>
            @endif
        </article>
    </section>

    @if($canSeeFinance)
    <section class="dash-grid-2 outstanding-grid">
        <article class="dash-panel">
            <div class="dash-panel-head"><div><span>OUTSTANDING</span><h3>Top Customer Receivables</h3></div>@if($canViewJournals)<a href="{{ route('accounting.reports.index',['report'=>'receivables']) }}">Report →</a>@endif</div>
            @if($topReceivables->isEmpty())<div class="dash-empty">No party-level receivable data available yet.</div>@else
            <div class="money-list">@foreach($topReceivables as $row)@if($canViewJournals)<a href="{{ route('accounting.ledgers.customer',$row->party_id) }}">@else<div>@endif<span>{{ $row->name }}</span><strong>{{ $money($row->amount) }}</strong>@if($canViewJournals)</a>@else</div>@endif @endforeach</div>@endif
        </article>
        <article class="dash-panel">
            <div class="dash-panel-head"><div><span>OUTSTANDING</span><h3>Top Supplier Payables</h3></div>@if($canViewCosting)<a href="{{ route('operations.costing.index') }}">Costing →</a>@endif</div>
            @if($topPayables->isEmpty())<div class="dash-empty">No supplier-level payable/cost data available yet.</div>@else
            <div class="money-list">@foreach($topPayables as $row)@if($canViewParties)<a href="{{ route('master-data.parties.show',$row->party_id) }}">@else<div>@endif<span>{{ $row->name }}</span><strong>{{ $money($row->amount) }}</strong>@if($canViewParties)</a>@else</div>@endif @endforeach</div>@endif
        </article>
    </section>
    @endif

    <section class="dash-grid-2">
        <article class="dash-panel">
            <div class="dash-panel-head"><div><span>LATEST</span><h3>Recent Bookings</h3></div>@if($canViewBookings)<a href="{{ route('operations.bookings.index') }}">View all →</a>@endif</div>
            <div class="dash-table-wrap"><table class="dash-table"><thead><tr><th>Booking</th><th>Type</th><th>Pax</th><th>Status</th>@if($canSeeFinance)<th class="right">Value</th>@endif</tr></thead><tbody>
                @forelse($recentBookings as $row)<tr><td>@if($canViewBookings)<a href="{{ route('operations.bookings.show',$row->id) }}">@else<div class="booking-static">@endif<strong>{{ $row->booking_no ?? ('#'.$row->id) }}</strong><small>{{ !empty($row->booking_date)?\Illuminate\Support\Carbon::parse($row->booking_date)->format('d M Y'):'' }}</small>@if($canViewBookings)</a>@else</div>@endif</td><td>{{ str_replace('_',' ',$row->booking_type ?? '—') }}</td><td>{{ $row->passenger_count }}</td><td><span class="status-pill {{ strtolower((string)($row->status??'')) }}">{{ $row->status ?? '—' }}</span></td>@if($canSeeFinance)<td class="right money">{{ $money($row->invoice_total) }}</td>@endif</tr>@empty<tr><td colspan="5" class="dash-empty-cell">No booking records yet.</td></tr>@endforelse
            </tbody></table></div>
        </article>

        @if($canSeeFinance)
        <article class="dash-panel">
            <div class="dash-panel-head"><div><span>ACTIVITY</span><h3>Recent Accounting Transactions</h3></div>@if(auth()->user()->hasPermission('journals.view'))<a href="{{ route('accounting.journals.index') }}">Journals →</a>@endif</div>
            <div class="transaction-list">
                @forelse($recentTransactions as $row)
                    @php($transactionLink = empty($row->permission) || auth()->user()->hasPermission($row->permission))
                    @if($transactionLink)<a href="{{ $row->url }}">@else<div class="transaction-static">@endif<div><strong>{{ $row->type }}</strong><span>{{ $row->reference }} · {{ $row->status ?? '—' }}</span></div><div class="transaction-amount">{{ $row->amount!==null?$money($row->amount):'—' }}<small>{{ $row->date?\Illuminate\Support\Carbon::parse($row->date)->format('d M'):'' }}</small></div>@if($transactionLink)</a>@else</div>@endif
                @empty<div class="dash-empty">No recent accounting activity available.</div>@endforelse
            </div>
        </article>
        @else
        <article class="dash-panel">
            <div class="dash-panel-head"><div><span>OPERATIONS</span><h3>Passenger & Travel Snapshot</h3></div></div>
            <div class="staff-snapshot">
                <div><span>Total Passenger Records</span><strong>{{ number_format($operations['passengers']['total']) }}</strong></div>
                <div><span>Departing Next 7 Days</span><strong>{{ number_format($operations['passengers']['next_7_days']) }}</strong></div>
                <div><span>Issued Tickets</span><strong>{{ number_format($operations['tickets']['issued']) }}</strong></div>
                <div><span>Hotel Check-ins Today</span><strong>{{ number_format($operations['hotels']['check_in_today']) }}</strong></div>
            </div>
        </article>
        @endif
    </section>

    <section class="dash-panel quick-panel">
        <div class="dash-panel-head"><div><span>SHORTCUTS</span><h3>Quick Actions</h3></div></div>
        <div class="quick-actions">
            @if(auth()->user()->hasPermission('bookings.create'))<a href="{{ route('operations.bookings.create') }}"><b>＋</b><span>New Booking</span></a>@endif
            @if(auth()->user()->hasPermission('bookings.view'))<a href="{{ route('operations.bookings.index',['service'=>'AIR_TICKET']) }}"><b>✈</b><span>Air Tickets</span></a>@endif
            @if(auth()->user()->hasPermission('bookings.view'))<a href="{{ route('operations.bookings.index',['service'=>'HOTEL']) }}"><b>▦</b><span>Hotel Stays</span></a>@endif
            @if(auth()->user()->hasPermission('sales_invoices.view'))<a href="{{ route('sales.invoices.index') }}"><b>▧</b><span>Sales Invoice</span></a>@endif
            @if(auth()->user()->hasPermission('journals.create'))<a href="{{ route('accounting.journals.create',['type'=>'RECEIPT']) }}"><b>↓</b><span>Receipt Voucher</span></a>@endif
            @if(auth()->user()->hasPermission('journals.create'))<a href="{{ route('accounting.journals.create',['type'=>'PAYMENT']) }}"><b>↑</b><span>Payment Voucher</span></a>@endif
            @if(auth()->user()->hasPermission('journals.view'))<a href="{{ route('accounting.reports.index') }}"><b>▤</b><span>Reports</span></a>@endif
            @if(auth()->user()->hasPermission('travel_masters.view'))<a href="{{ route('master-data.travel.index') }}"><b>◎</b><span>Travel Masters</span></a>@endif
        </div>
    </section>
</div>

@endsection
