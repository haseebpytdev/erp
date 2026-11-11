@extends('layouts.app')
@section('title','Company Profile')
@section('page_title','Company Profile')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ORGANIZATION</span><h1>Company accounting & report identity</h1><p>Legal identity remains separate from branch contact details. Printable reports combine this Company Profile with the selected Branch profile.</p></div><a href="{{ route('organization.index') }}" class="btn btn-ghost">Back</a></div>
<form method="POST" action="{{ route('organization.company.update') }}" class="panel" enctype="multipart/form-data">@csrf @method('PUT')
<div class="form-grid">
<label><span>Business / Brand Name</span><input name="name" value="{{ old('name',$company->name) }}" required></label>
<label><span>Legal Company Name</span><input name="legal_name" value="{{ old('legal_name',$company->legal_name) }}"></label>
<label><span>Company Code</span><input name="code" value="{{ old('code',$company->code) }}" required></label>
<label><span>Tax / Registration No.</span><input name="tax_registration_no" value="{{ old('tax_registration_no',$company->tax_registration_no) }}"></label>
<label><span>Base Currency</span><select name="base_currency" required>@foreach($currencies as $currency)<option value="{{ $currency->code }}" @selected(old('base_currency',$company->base_currency)===$currency->code)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></label>
<label><span>Timezone</span><input name="timezone" value="{{ old('timezone',$company->timezone) }}" required></label>
<label><span>Country Code</span><input name="country_code" maxlength="2" value="{{ old('country_code',$company->country_code) }}" required></label>
<label><span>Company Email</span><input type="email" name="email" value="{{ old('email',$company->email) }}"></label>
<label><span>Company Phone</span><input name="phone" value="{{ old('phone',$company->phone) }}"></label>
<label><span>Website</span><input name="website" placeholder="www.easyticket.pk" value="{{ old('website',$company->website) }}"></label>
<label class="span-2"><span>Company Registered / Main Address</span><textarea name="address" rows="3">{{ old('address',$company->address) }}</textarea></label>
</div>

<div style="border-top:1px solid #e4e7ec;margin-top:22px;padding-top:20px">
    <div class="panel-head"><div><h3>Printable Report Logo</h3><p>Used on Customer/Vendor ledgers and future printable accounting reports. The file is normalized to a compact JPEG for reliable cPanel/PDF output.</p></div></div>
    <div style="display:grid;grid-template-columns:160px 1fr;gap:22px;align-items:center">
        <div style="height:110px;border:1px solid #d0d5dd;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#fafbfc;overflow:hidden">
            @if($company->logo_data_uri)
                <img src="{{ $company->logo_data_uri }}" alt="Company report logo" style="max-width:145px;max-height:95px;object-fit:contain">
            @else
                <strong style="font-size:25px;color:#0b2c5f">{{ strtoupper($company->code ?: 'ERP') }}</strong>
            @endif
        </div>
        <div>
            <label><span>Upload Logo (JPG / PNG / WEBP, max 1 MB)</span><input type="file" name="report_logo" accept="image/jpeg,image/png,image/webp"></label>
            @if($company->logo_data_uri)<label style="display:flex;align-items:center;gap:8px;margin-top:10px"><input type="checkbox" name="remove_report_logo" value="1" style="width:auto"><span>Remove current report logo</span></label>@endif
        </div>
    </div>
</div>

<div class="callout" style="margin-top:18px"><strong>Header precedence:</strong> Company/Legal Name + Report Logo come from this profile. Branch Name, Address, Phone and Email come from the selected Branch when available; Company contact fields are used as fallback. Website and Tax/Registration come from Company Profile.</div>
<div class="form-actions"><button class="btn btn-primary">Save Company Profile</button></div>
</form>
@endsection
