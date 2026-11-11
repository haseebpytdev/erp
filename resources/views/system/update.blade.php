@extends('layouts.app')
@section('title','System Health & Updates')
@section('page_title','System Health & Updates')
@section('content')
@php
    $database = $database ?? false;
    $erp08Ready = $erp08Ready ?? false;
    $erp09Ready = $erp09Ready ?? false;
    $erp096Ready = $erp096Ready ?? false;
    $erp10Ready = $erp10Ready ?? false;
    $erp101Ready = $erp101Ready ?? false;
    $phpVersion = $phpVersion ?? PHP_VERSION;
    $laravelVersion = $laravelVersion ?? app()->version();
@endphp

<div class="page-intro">
    <div>
        <span class="eyebrow">NO-SSH MAINTENANCE</span>
        <h1>Safe web-based application maintenance</h1>
        <p>This page exists because the hosting has no SSH or Terminal. Only Super Admin can run database upgrades and cache maintenance.</p>
    </div>
</div>

<div class="metric-grid">
    <div class="metric-card"><div class="metric-label">Application</div><div class="metric-value">v{{ config('erp.version') }}</div><div class="metric-note">{{ config('erp.phase') }}</div></div>
    <div class="metric-card"><div class="metric-label">Database</div><div class="metric-value {{ $database ? 'ok' : 'bad' }}">{{ $database ? 'Connected' : 'Offline' }}</div></div>
    <div class="metric-card"><div class="metric-label">Report Header</div><div class="metric-value {{ $erp096Ready ? 'ok' : '' }}">{{ $erp096Ready ? 'Ready' : 'Pending' }}</div><div class="metric-note">Logo + website + Branch contact inheritance</div></div>
    <div class="metric-card"><div class="metric-label">Runtime</div><div class="metric-value">PHP {{ $phpVersion }}</div><div class="metric-note">Laravel {{ $laravelVersion }}</div></div>
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head"><div><h3>Database Upgrade</h3><p>Run pending Laravel migrations without Terminal.</p></div></div>
        @if(!$erp08Ready)
            <div class="callout warn"><strong>ERP-08 schema is incomplete.</strong> Do not continue until the specialized service tables are current.</div>
        @elseif(!$erp09Ready)
            <div class="callout warn"><strong>ERP-09 migration pending.</strong> Run the database upgrade once.</div>
        @elseif(!$erp096Ready)
            <div class="callout warn"><strong>ERP-09.6 report identity migration pending.</strong> Run the database upgrade once to add Company Website and Report Logo fields.</div>
        @elseif(!$erp10Ready)
            <div class="callout warn"><strong>ERP-10 Supplier Costing migration pending.</strong> Run the database upgrade once to create supplier cost allocations, permissions and the Other Direct Cost mapping.</div>
        @elseif(!$erp101Ready)
            <div class="callout warn"><strong>ERP-10.1 Air Ticket Commercial migration pending.</strong> Run the database upgrade once to enable multiple tickets per service, supplier purchase breakdown and commission allocations.</div>
        @else
            <div class="callout good"><strong>Database is current through ERP-10.1.</strong> Air tickets are now atomic commercial records with customer sale, supplier purchase and forecast commission links.</div>
        @endif
        <form method="POST" action="{{ route('system.update.migrate') }}">@csrf<button class="btn btn-primary">Run Safe Database Upgrade</button></form>
    </div>

    <div class="panel">
        <div class="panel-head"><div><h3>Application Cache</h3><p>Use after replacing application files if a page appears stale.</p></div></div>
        <p class="muted-copy">This clears Laravel route, config, event and view caches. It does not delete business data.</p>
        <form method="POST" action="{{ route('system.update.clear-cache') }}">@csrf<button class="btn btn-ghost">Clear Application Cache</button></form>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><div><h3>ERP-10.1 Ticket Commercial Boundary</h3><p>Ticket-level sale, purchase and commissions are visible without prematurely posting AP or commission liabilities.</p></div></div>
    <div class="check-list">
        <div class="check-item"><span class="check-dot done">✓</span><span>Customer Sales Invoice remains the revenue/AR document</span><strong>Sales</strong></div>
        <div class="check-item"><span class="check-dot done">✓</span><span>Estimated/Confirmed supplier costs do not create Journal Entries or Vendor Payables</span><strong>No GL yet</strong></div>
        <div class="check-item"><span class="check-dot done">✓</span><span>Confirmed supplier cost is the controlled precursor for the future Vendor Bill/AP workflow</span><strong>ERP-10</strong></div>
        <div class="check-item"><span class="check-dot done">✓</span><span>Booking profitability shows Customer Sale − Forecast Supplier Cost = Gross Margin</span><strong>Commercial</strong></div>
        <div class="check-item"><span class="check-dot done">✓</span><span>Vendor remains a Party subledger identity; no separate GL account is created per supplier</span><strong>2110 later</strong></div>
        <div class="check-item"><span class="check-dot done">✓</span><span>Supplier commission/discount/PLB reduce ticket purchase cost; Agent and Salesperson commission remain separate forecast allocations</span><strong>Separated</strong></div>
    </div>
</div>
@endsection
