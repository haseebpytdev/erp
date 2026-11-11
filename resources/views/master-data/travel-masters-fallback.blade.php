@extends('layouts.app')
@section('title','Travel Masters')
@section('page_title','Travel Masters')
@section('content')
<style>
.tm-safe{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px}.tm-safe h2{margin:0 0 6px}.tm-safe p{color:#667085}.tm-safe-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.tm-safe-tabs a{padding:8px 11px;border:1px solid #d0d5dd;border-radius:8px;text-decoration:none;color:#344054;background:#fff;font-weight:700;font-size:12px}.tm-safe-note{margin-top:14px;padding:11px 13px;border:1px solid #f59e0b;background:#fffbeb;border-radius:9px;color:#92400e}
</style>
<div class="tm-safe">
<h2>Travel Masters</h2>
<p>{{ $message }}</p>
<div class="tm-safe-tabs">
@foreach(['overview'=>'Overview','hotels'=>'Hotels','transport'=>'Transport Companies','vehicle-types'=>'Vehicle Types','transport-routes'=>'Transport Routes','transport-rates'=>'Transport Vendor Rates','saudi-visa'=>'Saudi Visa Companies','pakistan-iata'=>'Pakistan Visa / IATA','airlines'=>'Airlines','booking-sources'=>'Booking Sources','products'=>'Products & Services'] as $key=>$label)
<a href="{{ route('master-data.travel.index',['tab'=>$key]) }}">{{ $label }}</a>
@endforeach
</div>
<div class="tm-safe-note"><strong>Recovery mode:</strong> Travel Masters could not render its enhanced or stable view.</div>
@if(!empty($diagnostic))<div class="tm-safe-note" style="margin-top:10px;background:#fff1f2;border-color:#fecdd3;color:#9f1239"><strong>Super Admin diagnostic:</strong> {{ $diagnostic }}</div>@endif
</div>
@endsection
