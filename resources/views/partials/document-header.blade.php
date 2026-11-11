@php
    $dhCompany=$company ?? ($booking->company ?? null) ?? ($invoice->company ?? null);
    $dhBranch=$branch ?? ($booking->branch ?? null) ?? ($invoice->branch ?? null);
    $dhCompanyName=$dhCompany?->legal_name ?: $dhCompany?->name ?: 'Travel ERP';
    $dhBrand=($dhCompany?->legal_name && $dhCompany?->name && $dhCompany->legal_name!==$dhCompany->name)?$dhCompany->name:null;
    $dhBranchName=$dhBranch?->name ?: 'Head Office';
    $dhAddress=$dhBranch?->address ?: $dhCompany?->address;
    $dhPhone=$dhBranch?->phone ?: $dhCompany?->phone;
    $dhEmail=$dhBranch?->email ?: $dhCompany?->email;
    $dhWebsite=$dhCompany?->website ? preg_replace('~^https?://~i','',$dhCompany->website) : null;
    $dhTax=$dhCompany?->tax_registration_no;
    $dhContacts=array_values(array_filter([$dhPhone,$dhEmail,$dhWebsite,$dhTax?'Tax/Reg: '.$dhTax:null]));
    $dhTitle=$documentTitle ?? null; $dhNo=$documentNumber ?? null; $dhDate=$documentDate ?? null; $dhStatus=$documentStatus ?? null;
@endphp
<style>
.dochead{display:grid;grid-template-columns:68px 1fr 210px;gap:14px;align-items:center;padding:0 0 10px;border-bottom:2px solid #0b2c5f;color:#101828}.dochead-logo{width:60px;height:60px;border:1px solid #cdd8e8;border-radius:10px;background:#f7f9fc;display:flex;align-items:center;justify-content:center;font-weight:900;color:#0b2c5f}.dochead-logo img{max-width:100%;max-height:100%;object-fit:contain}.dochead-main{text-align:center}.dochead-company{font-size:24px;font-weight:900;color:#0c2148;line-height:1.05}.dochead-brand{font-size:11px;font-weight:700;margin-top:2px}.dochead-address{font-size:10.5px;color:#344054;margin-top:4px}.dochead-contact{font-size:10px;color:#344054;margin-top:3px}.dochead-contact span+span:before{content:' | ';color:#98a2b3;margin:0 5px}.dochead-side{text-align:right;font-size:10px;color:#475467;line-height:1.45}.dochead-side strong{color:#0c2148}.dochead-title{text-align:center;font-size:20px;font-weight:900;color:#101828;margin:8px 0}.dochead-docmeta{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;font-size:10px;color:#475467;margin-top:-4px;margin-bottom:8px}.dochead-docmeta strong{color:#0c2148}
@media(max-width:760px){.dochead{grid-template-columns:54px 1fr}.dochead-side{display:none}.dochead-logo{width:50px;height:50px}.dochead-company{font-size:19px}}
@media print{.dochead{grid-template-columns:46px 1fr 140px;gap:7px;padding-bottom:5px}.dochead-logo{width:42px;height:42px;border-radius:6px}.dochead-company{font-size:14pt}.dochead-brand{font-size:6.5pt}.dochead-address{font-size:6.2pt;margin-top:2px}.dochead-contact{font-size:5.8pt;margin-top:2px}.dochead-side{font-size:5.8pt}.dochead-title{font-size:11.5pt;margin:4px 0}.dochead-docmeta{font-size:6pt;margin-bottom:4px}}
</style>
<div class="dochead">
    <div class="dochead-logo">@if($dhCompany?->logo_data_uri)<img src="{{ $dhCompany->logo_data_uri }}" alt="{{ $dhCompanyName }}">@else{{ strtoupper($dhCompany?->code ?: 'ERP') }}@endif</div>
    <div class="dochead-main"><div class="dochead-company">{{ $dhCompanyName }}</div>@if($dhBrand)<div class="dochead-brand">{{ $dhBrand }}</div>@endif<div class="dochead-address"><strong>{{ $dhBranchName }}</strong>@if($dhAddress) · {{ $dhAddress }}@endif</div>@if($dhContacts)<div class="dochead-contact">@foreach($dhContacts as $bit)<span>{{ $bit }}</span>@endforeach</div>@endif</div>
    <div class="dochead-side">@if($dhNo)<div><strong>Document</strong> · {{ $dhNo }}</div>@endif @if($dhDate)<div><strong>Date</strong> · {{ $dhDate instanceof \Carbon\CarbonInterface ? $dhDate->format('d M Y') : $dhDate }}</div>@endif @if($dhStatus)<div><strong>Status</strong> · {{ str_replace('_',' ',$dhStatus) }}</div>@endif</div>
</div>
@if($dhTitle)<div class="dochead-title">{{ strtoupper($dhTitle) }}</div><div class="dochead-docmeta">@if($dhNo)<span><strong>{{ $dhNo }}</strong></span>@endif @if($dhDate)<span>{{ $dhDate instanceof \Carbon\CarbonInterface ? $dhDate->format('d M Y') : $dhDate }}</span>@endif @if($dhStatus)<span>{{ str_replace('_',' ',$dhStatus) }}</span>@endif</div>@endif
