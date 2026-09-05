@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Visa Management')
@section($layoutMeta['content_section'] ?? 'content')
<style>
.vm147{max-width:1480px;margin:0 auto;padding:2px 0 28px;color:#17243a}.vm147 *{box-sizing:border-box}.vm147-top{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:13px}.vm147 h1{font-size:26px;margin:0;line-height:1.15}.vm147-sub{font-size:12px;color:#718096;margin-top:5px}.vm147-actions{display:flex;gap:7px;flex-wrap:wrap}.vm147-btn{display:inline-flex;align-items:center;justify-content:center;min-height:35px;padding:0 12px;border:1px solid #d2ddea;border-radius:8px;background:#fff;color:#203a5f;text-decoration:none;font-size:11px;font-weight:800;cursor:pointer}.vm147-btn.primary{background:#1769d2;border-color:#1769d2;color:#fff}.vm147-rule{border:1px solid #d7e8fb;background:#f2f8ff;border-radius:10px;padding:10px 13px;font-size:11px;font-weight:800;color:#174f8d;text-align:center;margin-bottom:11px}.vm147-alert{padding:10px 12px;border-radius:8px;margin-bottom:11px;font-size:11px}.vm147-ok{background:#eefaf3;border:1px solid #c7ebd5;color:#167848}.vm147-err{background:#fff2f2;border:1px solid #efcaca;color:#9b2929}.vm147-tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px}.vm147-tab{display:inline-flex;align-items:center;gap:7px;border:1px solid #d2ddea;background:#fff;color:#2b405d;text-decoration:none;border-radius:8px;padding:8px 12px;font-size:11px;font-weight:800}.vm147-tab.active{background:#13233b;border-color:#13233b;color:#fff}.vm147-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;border-radius:999px;background:#edf2f8;color:#63748b;font-size:9px}.vm147-tab.active .vm147-count{background:rgba(255,255,255,.15);color:#fff}.vm147-card{background:#fff;border:1px solid #dfe7f0;border-radius:11px;margin-bottom:12px;overflow:hidden}.vm147-head{padding:13px 15px;border-bottom:1px solid #e8edf3;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.vm147-head h2{font-size:15px;margin:0}.vm147-head p{font-size:10px;color:#718096;margin:4px 0 0;line-height:1.45}.vm147-body{padding:14px 15px}.vm147-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.vm147-field.w2{grid-column:span 2}.vm147-field.full{grid-column:1/-1}.vm147 label{display:block;font-size:9.5px;font-weight:800;color:#52627a;margin-bottom:4px}.vm147 input,.vm147 select,.vm147 textarea{width:100%;min-height:35px;border:1px solid #d2ddea;border-radius:7px;background:#fff;padding:7px 9px;font:inherit;font-size:11px;color:#17243a}.vm147 textarea{min-height:58px;resize:vertical}.vm147-note{margin-top:10px;border:1px solid #dbe9f7;background:#f8fbff;border-radius:8px;padding:9px 10px;font-size:10px;color:#5e728c;line-height:1.45}.vm147-save{text-align:right;margin-top:10px}.vm147-table-wrap{overflow:auto}.vm147 table{width:100%;border-collapse:collapse;font-size:10.5px}.vm147 th{background:#f5f8fc;color:#607087;padding:8px;text-align:left;font-size:9px;text-transform:uppercase;white-space:nowrap;border-bottom:1px solid #e2e8f0}.vm147 td{padding:9px 8px;border-bottom:1px solid #edf1f5;vertical-align:top}.vm147 tr:last-child td{border-bottom:0}.vm147-pill{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:800;background:#eaf8ef;color:#16804a}.vm147-pill.warn{background:#fff4d8;color:#8a6515}.vm147-empty{text-align:center;color:#7b899c;padding:22px!important}.vm147-source{font-size:8.5px;color:#8b98a8;margin-top:3px}.vm147-link{color:#1769d2;text-decoration:none;font-weight:800}.vm147-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:12px}.vm147-stat{background:#fff;border:1px solid #dfe7f0;border-radius:10px;padding:11px 13px}.vm147-stat label{font-size:9px;text-transform:uppercase;color:#718096}.vm147-stat strong{display:block;font-size:19px;margin-top:3px}.vm147-warning{border:1px solid #f0dfac;background:#fffaf0;color:#755b19;border-radius:9px;padding:10px 12px;font-size:10.5px;line-height:1.45;margin-bottom:12px}@media(max-width:1000px){.vm147-fields{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:680px){.vm147-top{flex-direction:column}.vm147-fields{grid-template-columns:1fr}.vm147-field.w2,.vm147-field.full{grid-column:auto}.vm147-stats{grid-template-columns:1fr}.vm147-tab{flex:1;justify-content:center;min-width:110px}}
</style>
<div class="vm147">
    <div class="vm147-top">
        <div><h1>Visa Management</h1><div class="vm147-sub">Uses the existing Travel Masters records. No duplicate Pakistani IATA or Saudi Company master is created here.</div></div>
        <div class="vm147-actions"><a class="vm147-btn" href="{{ url('/master-data/travel-masters') }}">← Travel Masters</a>@if($returnBooking)<a class="vm147-btn" href="{{ url('/operations/bookings/'.$returnBooking) }}">Back to Booking</a>@endif</div>
    </div>
    <div class="vm147-rule">Saudi Company → Pakistani IATA → ERP Vendor Account &nbsp; | &nbsp; Saudi Company and Pakistani IATA are reporting dimensions only.</div>
    @if(session('visa_master_success'))<div class="vm147-alert vm147-ok">{{ session('visa_master_success') }}</div>@endif
    @if($errors->any())<div class="vm147-alert vm147-err">{{ $errors->first() }}</div>@endif

    <div class="vm147-stats">
        <div class="vm147-stat"><label>Existing Pakistani IATA</label><strong>{{ count($iatas) }}</strong></div>
        <div class="vm147-stat"><label>Existing Saudi Companies</label><strong>{{ count($saudis) }}</strong></div>
        <div class="vm147-stat"><label>Visa Rates</label><strong>{{ count($rates) }}</strong></div>
    </div>

    <nav class="vm147-tabs">
        <a class="vm147-tab {{ $activeTab==='iata'?'active':'' }}" href="{{ route('travel-masters.visa-management',['tab'=>'iata']) }}">Pakistani IATA <span class="vm147-count">{{ count($iatas) }}</span></a>
        <a class="vm147-tab {{ $activeTab==='saudi'?'active':'' }}" href="{{ route('travel-masters.visa-management',['tab'=>'saudi']) }}">Saudi Companies <span class="vm147-count">{{ count($saudis) }}</span></a>
        <a class="vm147-tab {{ $activeTab==='rates'?'active':'' }}" href="{{ route('travel-masters.visa-management',['tab'=>'rates']) }}">Visa Rates <span class="vm147-count">{{ count($rates) }}</span></a>
    </nav>

    @if($activeTab==='iata')
        <div class="vm147-warning"><strong>Master authority:</strong> add/edit Pakistani IATA only from <a class="vm147-link" href="{{ url('/master-data/travel-masters') }}">Travel Masters → Pakistan Visa / IATA</a>. This page reads the same existing records and uses their linked Vendor Account.</div>
        <section class="vm147-card"><div class="vm147-head"><div><h2>Pakistani IATA</h2><p>Existing Travel Masters records used by Visa reporting and rate resolution.</p></div><a class="vm147-btn" href="{{ url('/master-data/travel-masters') }}">Manage in Travel Masters</a></div><div class="vm147-table-wrap"><table><thead><tr><th>Code / Name</th><th>IATA No.</th><th>Vendor Account</th><th>Status</th><th>Source</th></tr></thead><tbody>
        @forelse($iatas as $i)<tr><td><strong>{{ $i['name'] }}</strong><div class="vm147-source">{{ $i['code'] ?: '—' }}</div></td><td>{{ $i['iata_number'] ?: '—' }}</td><td>{{ $i['vendor_name'] ?: '—' }}</td><td><span class="vm147-pill {{ ($i['status']??'')==='READY'?'':'warn' }}">{{ $i['status'] ?? 'VENDOR LINK REQUIRED' }}</span></td><td>{{ $i['source_table'] }}</td></tr>@empty<tr><td class="vm147-empty" colspan="5">No existing Pakistan Visa / IATA records were detected. Add them in the native Travel Masters page.</td></tr>@endforelse
        </tbody></table></div></section>
    @endif

    @if($activeTab==='saudi')
        <div class="vm147-warning"><strong>Master authority:</strong> add/edit Saudi companies only from <a class="vm147-link" href="{{ url('/master-data/travel-masters') }}">Travel Masters → Saudi Visa Companies</a>. Vendor is never selected separately here; it resolves through Pakistani IATA.</div>
        <section class="vm147-card"><div class="vm147-head"><div><h2>Saudi Companies</h2><p>Existing Travel Masters records with their Pakistani IATA and resolved Vendor Account.</p></div><a class="vm147-btn" href="{{ url('/master-data/travel-masters') }}">Manage in Travel Masters</a></div><div class="vm147-table-wrap"><table><thead><tr><th>Code / Saudi Company</th><th>Pakistani IATA</th><th>Resolved Vendor</th><th>Contact</th><th>Link</th><th>Source</th></tr></thead><tbody>
        @forelse($saudis as $s)<tr><td><strong>{{ $s['name'] }}</strong><div class="vm147-source">{{ $s['code'] ?: '—' }}</div></td><td>{{ $s['pakistani_iata_name'] ?: '—' }}</td><td>{{ $s['vendor_name'] ?: '—' }}</td><td>{{ $s['phone'] ?: '—' }}</td><td><span class="vm147-pill {{ ($s['status']??'')==='READY'?'':'warn' }}">{{ $s['status'] ?? 'IATA LINK REQUIRED' }}</span></td><td>{{ $s['source_table'] }}</td></tr>@empty<tr><td class="vm147-empty" colspan="6">No existing Saudi Visa Company records were detected. Add them in the native Travel Masters page.</td></tr>@endforelse
        </tbody></table></div></section>
    @endif

    @if($activeTab==='rates')
        @if(!count($saudis))<div class="vm147-warning">No existing Saudi Visa Companies were detected from Travel Masters yet. Create/complete them there first, then return to Visa Rates.</div>@endif
        <section class="vm147-card">
            <div class="vm147-head"><div><h2>Add Visa Rate</h2><p>Rate is attached to the existing Saudi Company. Pakistani IATA and Vendor Account resolve from the native Travel Masters relationship.</p></div></div>
            <div class="vm147-body"><form method="post" action="{{ route('travel-masters.visa-management.rate.store',['tab'=>'rates']) }}">@csrf
                <div class="vm147-fields">
                    <div><label>Country *</label><input name="country" value="{{ old('country','Saudi Arabia') }}" required></div>
                    <div><label>Visa Type *</label><input name="visa_type" value="{{ old('visa_type','Umrah') }}" required></div>
                    <div class="vm147-field w2"><label>Saudi Company *</label><select id="visa-rate-saudi" name="saudi_master_key" required><option value="" data-iata="" data-vendor="">Select existing Saudi Company</option>@foreach($saudis as $s)<option value="{{ $s['master_key'] }}" data-iata="{{ $s['pakistani_iata_name'] }}" data-vendor="{{ $s['vendor_name'] }}" @selected(old('saudi_master_key')===$s['master_key']) @disabled(!($s['link_complete']??false))>{{ $s['name'] }}{{ !($s['link_complete']??false)?' · '.$s['status']:'' }}</option>@endforeach</select></div>
                    <div><label>Pakistani IATA (resolved)</label><input id="visa-rate-iata" value="" readonly aria-readonly="true"></div>
                    <div><label>Vendor Account (resolved)</label><input id="visa-rate-vendor" value="" readonly aria-readonly="true"></div>
                    <div><label>Cost Currency *</label><select name="cost_currency"><option>SAR</option><option>USD</option><option>AED</option><option>PKR</option></select></div>
                    <div><label>Cost Rate *</label><input name="cost_rate" type="number" step="0.01" min="0" required value="{{ old('cost_rate') }}"></div>
                    <div><label>Default Sale PKR *</label><input name="default_sale_pkr" type="number" step="0.01" min="0" required value="{{ old('default_sale_pkr') }}"></div>
                    <div><label>Effective From *</label><input name="effective_from" type="date" value="{{ old('effective_from',now()->toDateString()) }}" required></div>
                    <div><label>Effective To</label><input name="effective_to" type="date" value="{{ old('effective_to') }}"></div>
                    <div><label>Status *</label><select name="is_active" required><option value="1" @selected(old('is_active','1')==='1')>Active</option><option value="0" @selected(old('is_active')==='0')>Inactive</option></select></div>
                    <div class="vm147-field full"><label>Notes</label><textarea name="notes">{{ old('notes') }}</textarea></div>
                </div>
                <div class="vm147-note"><strong>Accounting:</strong> Saudi Company and Pakistani IATA are reporting references. Vendor cost is posted/resolved only against the Vendor Account linked to Pakistani IATA. Existing Travel Masters are not duplicated.</div>
                <div class="vm147-save"><button class="vm147-btn primary" type="submit">+ Add Visa Rate</button></div>
            </form></div>
        </section>
        <section class="vm147-card"><div class="vm147-head"><div><h2>Current Visa Rates</h2><p>Effective-dated rates used by GENERAL / MULTI-SERVICE Visa booking.</p></div></div><div class="vm147-table-wrap"><table><thead><tr><th>Country</th><th>Visa Type</th><th>Saudi Company</th><th>Pakistani IATA</th><th>Vendor Account</th><th>Cost</th><th>Default Sale</th><th>Effective</th><th>Status</th></tr></thead><tbody>
        @forelse($rates as $r)<tr><td>{{ $r['country'] }}</td><td>{{ $r['visa_type'] }}</td><td><strong>{{ $r['saudi_company_name'] }}</strong></td><td>{{ $r['pakistani_iata_name'] }}</td><td>{{ $r['vendor_name'] }}</td><td>{{ $r['cost_currency'] }} {{ number_format((float)$r['cost_rate'],2) }}</td><td>PKR {{ number_format((float)$r['default_sale_pkr'],2) }}</td><td>{{ $r['effective_from'] }} → {{ $r['effective_to'] ?: 'Open' }}</td><td><span class="vm147-pill">{{ $r['is_active']?'Active':'Inactive' }}</span></td></tr>@empty<tr><td class="vm147-empty" colspan="9">No Visa rates yet.</td></tr>@endforelse
        </tbody></table></div></section>
    @endif
</div>
@if($activeTab==='rates')
<script>
document.addEventListener('DOMContentLoaded',function(){
    var select=document.getElementById('visa-rate-saudi');
    var iata=document.getElementById('visa-rate-iata');
    var vendor=document.getElementById('visa-rate-vendor');
    if(!select||!iata||!vendor)return;
    var refresh=function(){var option=select.options[select.selectedIndex];iata.value=option?String(option.dataset.iata||''):'';vendor.value=option?String(option.dataset.vendor||''):'';};
    select.addEventListener('change',refresh);refresh();
});
</script>
@endif
@endsection
