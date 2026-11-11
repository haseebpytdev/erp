@if(count($operationalRows ?? []))
<div class="etcv-card" style="margin-top:1rem"><div class="etcv-section"><h2>Native Hotel / Transport vendor-cost correction</h2><p>Each source is rechecked during save. Customer selling price, Sales Invoices and posted accounting are read-only.</p>
@if($errors->has('correction') || $errors->has('booking'))<div class="alert alert-danger" role="alert">@foreach($errors->get('correction') as $message)<div>{{ $message }}</div>@endforeach @foreach($errors->get('booking') as $message)<div>{{ $message }}</div>@endforeach</div>@endif
<form method="post" action="{{ route('bookings.et-cv-voucher.operational-correction',['booking'=>$bookingId]) }}">@csrf
<div class="etcv-field"><label for="correction_reason">Correction reason</label><textarea id="correction_reason" name="correction_reason" required maxlength="2000"></textarea></div>
@foreach($operationalRows as $i=>$op) @php $kind=strtolower((string)($op['product_type']??'service')); $locked=!in_array(($op['status']??'available'), ['available','cost_required','vendor_required'], true); $costingNo=trim((string)($op['costing_no']??'')); $costingStatus=strtolower(trim((string)($op['costing_status']??''))); $costingLabel=$costingStatus!=='' ? ucwords(str_replace('_',' ', $costingStatus)) : ($locked ? 'Locked' : 'Unclaimed'); @endphp
<div class="etcv-record"><div class="etcv-record-title">{{ $op['product_type'] ?? 'Service' }} — {{ $op['detail'] ?? $op['source_reference'] ?? ('Source '.($i+1)) }}</div><div class="etcv-grid">
<input type="hidden" name="{{ $kind }}[{{ $i }}][source_key]" value="{{ $op['source_key'] ?? '' }}"><input type="hidden" name="{{ $kind }}[{{ $i }}][source_id]" value="{{ $op['source_id'] ?? 0 }}"><input type="hidden" name="{{ $kind }}[{{ $i }}][source_table]" value="{{ $op['source_type'] ?? '' }}"><input type="hidden" name="{{ $kind }}[{{ $i }}][display_index]" value="{{ $i }}">
<div class="etcv-field"><label>Existing Vendor</label><div class="etcv-preview-value">{{ $op['supplier_name'] ?? 'Unassigned' }}</div></div>
@if($kind === 'hotel')<div class="etcv-field"><label>Proposed Hotel</label><select name="{{ $kind }}[{{ $i }}][hotel_id]" @disabled($locked)><option value="">Keep existing</option>@foreach((array)($masterOptions['hotels']??[]) as $hotel)<option value="{{ $hotel['id']??0 }}" @selected((int)($op['hotel_id']??0)===(int)($hotel['id']??0))>{{ $hotel['label']??$hotel['value']??'' }}</option>@endforeach</select></div>@endif
@if($kind === 'transport')<div class="etcv-field"><label>Proposed Transport Company</label><select name="{{ $kind }}[{{ $i }}][transport_company_id]" @disabled($locked)><option value="">Keep existing</option>@foreach((array)($masterOptions['transportCompanies']??[]) as $company)<option value="{{ $company['id']??0 }}" @selected((int)($op['transport_company_id']??0)===(int)($company['id']??0))>{{ $company['name']??'' }}</option>@endforeach</select></div>@endif
<div class="etcv-field"><label>Proposed Vendor</label><select name="{{ $kind }}[{{ $i }}][vendor_id]" @disabled($locked)><option value="">Keep existing</option>@foreach((array)($masterOptions['vendors']??[]) as $vendor)<option value="{{ $vendor['id']??0 }}" @selected((int)($op['vendor_id']??0)===(int)($vendor['id']??0))>{{ $vendor['name']??('Vendor #'.($vendor['id']??'')) }}</option>@endforeach</select></div>
<div class="etcv-field"><label>{{ $kind === 'hotel' ? 'Total Stay Buying Cost (PKR)' : 'Total Buying Cost (PKR)' }}</label><div class="etcv-preview-value">{{ number_format((float)($op['source_cost'] ?? 0),2) }}</div></div>
<div class="etcv-field"><label>Revised {{ $kind === 'hotel' ? 'Total Stay Buying Cost' : 'Total Buying Cost' }} (PKR)</label>
@if($locked)
<input type="hidden" name="{{ $kind }}[{{ $i }}][cost_amount]" value="{{ number_format((float)($op['source_cost'] ?? 0),2,'.','') }}">
<input type="number" value="{{ number_format((float)($op['source_cost'] ?? 0),2,'.','') }}" disabled aria-disabled="true">
@else
<input type="number" min="0" step="0.01" name="{{ $kind }}[{{ $i }}][cost_amount]" value="{{ number_format((float)($op['source_cost'] ?? 0),2,'.','') }}">
@endif
</div>
<div class="etcv-field"><label>Currency</label><input name="{{ $kind }}[{{ $i }}][currency_code]" value="PKR" maxlength="3" @disabled($locked)></div><div class="etcv-field"><label>FX rate to PKR</label><input type="number" min="0.000001" step="0.000001" name="{{ $kind }}[{{ $i }}][fx_rate]" value="1" @disabled($locked)></div>
<div class="etcv-field"><label>Supplier Costing status</label><div class="etcv-preview-value">
@if($locked)
<span style="color:#b42318;font-weight:700">{{ $costingLabel }} — Locked</span>
@if($costingNo)<span> ({{ $costingNo }})</span>@endif
@else
<span style="color:#18794e;font-weight:700">Unclaimed — Editable</span>
@endif
</div></div>
</div></div>
@endforeach
<div class="etcv-actions"><button class="etcv-danger" type="submit">Confirm Native Vendor-Cost Correction</button></div></form></div></div>
@endif
