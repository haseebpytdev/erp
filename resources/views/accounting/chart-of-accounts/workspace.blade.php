@extends($layoutMeta['layout'])
@section($layoutMeta['title_section'] ?? 'title','Chart of Accounts')
@section($layoutMeta['content_section'] ?? 'content')
<div data-et-coa-runtime="ERP-11.3.10" style="display:none">ERP-11.3.10 ACTIVE MIDDLEWARE WORKSPACE</div>
<style>
.coa-wrap{max-width:1480px;margin:0 auto;padding:2px 0 28px}.coa-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px}.coa-kicker{font-size:11px;letter-spacing:.08em;font-weight:800;color:#3867d6;text-transform:uppercase}.coa-head h2{font-size:26px;line-height:1.2;margin:4px 0 6px;color:#13233c}.coa-muted{color:#718096;font-size:13px}.coa-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid #d7dfeb;border-radius:9px;padding:9px 13px;background:#fff;color:#183153;text-decoration:none;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap}.coa-btn:hover{background:#f7f9fc}.coa-primary{background:#1769e0;border-color:#1769e0;color:#fff}.coa-primary:hover{background:#0f5cc7;color:#fff}.coa-grid-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:14px}.coa-stat{background:#fff;border:1px solid #e0e6ef;border-radius:12px;padding:13px 14px}.coa-stat .label{font-size:10px;letter-spacing:.07em;text-transform:uppercase;color:#6d7b90;font-weight:800}.coa-stat .value{font-size:21px;font-weight:800;color:#17253a;margin-top:5px}.coa-main{display:grid;grid-template-columns:minmax(330px,400px) minmax(0,1fr);gap:14px;align-items:stretch}.coa-card{background:#fff;border:1px solid #e0e6ef;border-radius:12px;box-shadow:0 1px 2px rgba(16,24,40,.02)}.coa-card-hd{padding:16px 17px 12px;border-bottom:1px solid #edf1f6}.coa-card-hd h3{font-size:15px;margin:0 0 3px;color:#17253a}.coa-card-bd{padding:16px 17px}.coa-form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.coa-field{margin-bottom:11px}.coa-field label{display:block;font-size:11px;font-weight:800;color:#40506a;margin-bottom:5px}.coa-field input,.coa-field select,.coa-field textarea{width:100%;border:1px solid #d7dfea;border-radius:8px;background:#fff;padding:9px 10px;color:#17253a;font-size:12px;outline:none}.coa-field input:focus,.coa-field select:focus,.coa-field textarea:focus{border-color:#76a8ee;box-shadow:0 0 0 3px rgba(23,105,224,.08)}.coa-field input[readonly]{background:#eef3f8;color:#42526a;cursor:not-allowed;font-weight:800}.coa-help{font-size:10px;color:#7a889c;margin-top:4px;line-height:1.4}.coa-codebox{display:flex;gap:7px}.coa-codebox input{flex:1}.coa-preview{min-width:82px;border:1px solid #dbe5f4;background:#f1f6ff;border-radius:8px;padding:9px 8px;font-size:11px;font-weight:800;color:#1b5fbf;text-align:center;white-space:nowrap}.coa-check{display:flex;gap:8px;align-items:flex-start;font-size:11px;color:#40506a;margin:9px 0}.coa-check input{margin-top:2px}.coa-alert{border-radius:9px;padding:10px 12px;margin:0 0 12px;font-size:12px}.coa-success{background:#edf9f2;border:1px solid #c9eed7;color:#22643a}.coa-error{background:#fff2f2;border:1px solid #f0cccc;color:#8b2c2c}.coa-info{background:#f3f7ff;border:1px solid #dce7fb;color:#385270}.coa-right-card{height:100%;display:flex;flex-direction:column}.coa-right-card .coa-card-bd{display:flex;flex:1;flex-direction:column}.coa-guard{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.coa-guard-item{border:1px solid #e6ebf2;border-radius:10px;padding:11px 12px;background:#fbfcfe}.coa-guard-item strong{display:block;font-size:11px;color:#223754;margin-bottom:3px}.coa-guard-item span{font-size:10px;color:#718096;line-height:1.45}.coa-setup{margin-top:auto;padding-top:14px}.coa-setup-title{font-size:11px;font-weight:800;color:#223754;margin-bottom:8px}.coa-setup-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.coa-setup-step{border:1px solid #dfe7f2;border-radius:9px;background:#f8fbff;padding:10px}.coa-setup-step b{display:block;font-size:10px;color:#1f5fae;margin-bottom:3px}.coa-setup-step span{font-size:10px;color:#6f7f95;line-height:1.4}.coa-register{grid-column:1/-1;margin-top:0;min-width:0;overflow:hidden}.coa-register-head{padding:15px 16px 14px;border-bottom:1px solid #edf1f6}.coa-register-title{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:11px}.coa-register-title h3{font-size:15px;margin:0 0 3px;color:#17253a}.coa-tools{display:grid;grid-template-columns:minmax(220px,2fr) minmax(130px,.8fr) minmax(120px,.7fr) minmax(105px,.6fr) auto auto;gap:8px;align-items:center;width:100%}.coa-tools input,.coa-tools select{height:36px;min-width:0;width:100%;border:1px solid #d7dfea;border-radius:8px;padding:0 10px;background:#fff;font-size:12px;color:#23354f}.coa-table-wrap{width:100%;overflow-x:hidden;border-top:0}.coa-table{width:100%;border-collapse:collapse;table-layout:fixed}.coa-table col:nth-child(1){width:28%}.coa-table col:nth-child(2){width:9%}.coa-table col:nth-child(3){width:13%}.coa-table col:nth-child(4){width:8%}.coa-table col:nth-child(5){width:8%}.coa-table col:nth-child(6){width:15%}.coa-table col:nth-child(7){width:10%}.coa-table col:nth-child(8){width:9%}.coa-table th{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#758399;font-weight:800;background:#f8fafc;padding:10px 9px;border-bottom:1px solid #e7ecf3;text-align:left;white-space:normal}.coa-table td{padding:10px 9px;border-bottom:1px solid #edf1f6;font-size:10.5px;color:#35445a;vertical-align:middle;overflow-wrap:anywhere}.coa-table tr:last-child td{border-bottom:0}.coa-code{font-weight:800;color:#1769e0;background:#eef5ff;border-radius:999px;padding:4px 8px;display:inline-block;margin-right:4px}.coa-name{font-weight:800;color:#1c2c45}.coa-sub{font-size:9px;color:#8995a7;margin-top:3px;text-transform:uppercase}.coa-pill{display:inline-flex;padding:4px 7px;border-radius:999px;background:#f1f4f8;font-size:9px;font-weight:800;text-transform:uppercase;color:#516177;max-width:100%}.coa-active{background:#eaf8ef;color:#21824a}.coa-parent{max-width:none}.coa-parent strong{display:block;color:#3a4d68}.coa-parent small{display:block;color:#8390a3;line-height:1.25;margin-top:2px}.coa-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border-top:1px solid #edf1f6;flex-wrap:wrap}.coa-page-meta{font-size:11px;color:#718096}.coa-pages{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.coa-page{min-width:32px;height:31px;padding:0 8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dce3ed;border-radius:7px;color:#34465f;text-decoration:none;font-size:11px;background:#fff}.coa-page.active{background:#1769e0;border-color:#1769e0;color:#fff;font-weight:800}.coa-page.disabled{opacity:.45;pointer-events:none}.coa-inline-status{font-size:10px;color:#718096;margin-top:4px;min-height:14px}.coa-actions{display:flex;gap:6px;white-space:normal}.coa-action{font-size:10px;font-weight:800;color:#1769e0;text-decoration:none}.coa-schema{font-size:9px;color:#a0aabc;margin-top:8px}.coa-submit{width:100%;margin-top:4px}.coa-root-note{display:none;margin-top:5px}.coa-root-note.show{display:block}.coa-hide{display:none!important}
@media(max-width:1250px){.coa-tools{grid-template-columns:minmax(220px,1.7fr) repeat(3,minmax(105px,.7fr)) auto}.coa-tools .coa-reset{grid-column:auto}.coa-table col:nth-child(6){width:13%}.coa-table col:nth-child(1){width:30%}}
@media(max-width:1100px){.coa-grid-stats{grid-template-columns:repeat(3,1fr)}.coa-main{grid-template-columns:1fr}.coa-register{grid-column:auto}.coa-right-card{height:auto}.coa-setup{margin-top:14px}.coa-tools{grid-template-columns:1fr 1fr 1fr}.coa-tools input{grid-column:1/-1}.coa-tools .coa-btn{width:100%}}
@media(max-width:760px){.coa-head{flex-direction:column}.coa-grid-stats{grid-template-columns:repeat(2,1fr)}.coa-form-row{grid-template-columns:1fr}.coa-guard{grid-template-columns:1fr}.coa-setup-grid{grid-template-columns:1fr}.coa-tools{grid-template-columns:1fr}.coa-tools input{grid-column:auto}.coa-card-bd{padding:13px}.coa-register-head{padding:13px}.coa-register-title{align-items:flex-start;flex-direction:column}.coa-table col:nth-child(3),.coa-table th:nth-child(3),.coa-table td:nth-child(3),.coa-table col:nth-child(5),.coa-table th:nth-child(5),.coa-table td:nth-child(5),.coa-table col:nth-child(6),.coa-table th:nth-child(6),.coa-table td:nth-child(6){display:none}.coa-table col:nth-child(1){width:42%}.coa-table col:nth-child(2){width:15%}.coa-table col:nth-child(4){width:15%}.coa-table col:nth-child(7){width:16%}.coa-table col:nth-child(8){width:12%}}
</style>

<div class="coa-wrap">
    <div class="coa-head">
        <div>
            <div class="coa-kicker">Accounting Foundation · ERP-11.3.10</div>
            <h2>Chart of Accounts</h2>
            <div class="coa-muted">Controlled ledger structure with parent-driven account codes and server-side pagination.</div>
        </div>
        <a class="coa-btn" href="{{ route('accounting.mappings.index') }}">Account Mappings →</a>
    </div>

    @if(session('success'))<div class="coa-alert coa-success">{{ session('success') }}</div>@endif
    @if($schemaError)<div class="coa-alert coa-error"><strong>Chart unavailable:</strong> {{ $schemaError }}</div>@endif
    @if($errors->any())
        <div class="coa-alert coa-error"><strong>Please correct the following:</strong><ul style="margin:6px 0 0 17px">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="coa-grid-stats">
        @foreach(['asset'=>'Assets','liability'=>'Liabilities','equity'=>'Equity','income'=>'Income','expense'=>'Expenses','total'=>'Total Accounts'] as $key=>$label)
            <div class="coa-stat"><div class="label">{{ $label }}</div><div class="value">{{ number_format($summary[$key] ?? 0) }}</div></div>
        @endforeach
    </div>

    <div class="coa-main">
        <div class="coa-card">
            <div class="coa-card-hd"><h3>Add Account</h3><div class="coa-muted">Select the parent and the ERP generates the next account code automatically. Account Code is read-only.</div></div>
            <div class="coa-card-bd">
                <form method="post" action="{{ route('accounting.chart-of-accounts.auto-store') }}" id="coa-create-form">
                    @csrf
                    <div class="coa-field">
                        <label for="coa-parent">Parent Account</label>
                        <select name="parent_id" id="coa-parent" @disabled($schemaError) required>
                            <option value="">No parent · controlled root account</option>
                            @foreach($parents as $parent)
                                <option value="{{ $parent['id'] }}" @selected((string)old('parent_id') === (string)$parent['id'])>{{ $parent['code'] }} · {{ $parent['name'] }}</option>
                            @endforeach
                        </select>
                        <div class="coa-help">For normal sub-accounts select a parent. Example: 1020 · Bank.</div>
                    </div>

                    <div class="coa-field">
                        <label for="coa-code">Account Code</label>
                        <div class="coa-codebox">
                            <input id="coa-code" name="code" readonly aria-readonly="true" value="{{ old('code') }}" placeholder="Auto after parent selection" autocomplete="off" @disabled($schemaError)>
                            <div class="coa-preview" id="coa-code-preview">AUTO</div>
                        </div>
                        <div class="coa-inline-status" id="coa-code-status">Select a parent and the ERP will reserve the next available code when you save.</div>
                        <div class="coa-help coa-root-note" id="coa-root-note">Root/control accounts remain manually coded because they define the controlled accounting structure.</div>
                    </div>

                    <div class="coa-field"><label for="coa-name">Account Name</label><input id="coa-name" name="name" value="{{ old('name') }}" required maxlength="190" placeholder="e.g. Meezan Bank - Main Account" @disabled($schemaError)></div>

                    <div class="coa-form-row">
                        <div class="coa-field">
                            <label for="coa-type">Account Type</label>
                            <select id="coa-type" name="type" required @disabled($schemaError)>
                                @foreach(['asset'=>'Asset','liability'=>'Liability','equity'=>'Equity','income'=>'Income','expense'=>'Expense'] as $value=>$label)<option value="{{ $value }}" @selected(old('type','asset')===$value)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="coa-field"><label for="coa-subtype">Subtype</label><input id="coa-subtype" name="subtype" value="{{ old('subtype') }}" maxlength="100" placeholder="e.g. BANK" @disabled($schemaError)></div>
                    </div>

                    <div class="coa-form-row">
                        <div class="coa-field"><label>Normal Balance</label><input id="coa-normal" value="DEBIT" readonly></div>
                        <div class="coa-field"><label for="coa-control-type">Control Type</label><input id="coa-control-type" name="control_type" value="{{ old('control_type') }}" maxlength="100" placeholder="Only for control account" @disabled($schemaError)></div>
                    </div>

                    <div class="coa-field"><label for="coa-notes">Notes</label><textarea id="coa-notes" name="notes" rows="2" maxlength="1000" placeholder="Optional internal note" @disabled($schemaError)>{{ old('notes') }}</textarea></div>

                    <label class="coa-check"><input type="hidden" name="allow_posting" value="0"><input type="checkbox" name="allow_posting" value="1" @checked(old('allow_posting',1)) @disabled($schemaError)> <span><strong>Allow direct journal posting</strong><br><span class="coa-muted">Keep enabled for normal Bank/Cash/expense/revenue posting ledgers.</span></span></label>
                    <label class="coa-check"><input type="hidden" name="is_control" value="0"><input type="checkbox" name="is_control" value="1" @checked(old('is_control')) @disabled($schemaError)> <span><strong>Control account (subledger-linked)</strong><br><span class="coa-muted">Use only for protected AR/AP/advance-style control accounts.</span></span></label>

                    <button class="coa-btn coa-primary coa-submit" type="submit" @disabled($schemaError)>Create Account</button>
                </form>
                <div class="coa-schema">Live source: {{ $schemaLabel }}</div>
            </div>
        </div>

        <div>
            <div class="coa-card coa-right-card">
                <div class="coa-card-hd"><h3>Accounting Guardrails</h3><div class="coa-muted">The workspace improves usability while the controlled accounting architecture remains unchanged.</div></div>
                <div class="coa-card-bd">
                    <div class="coa-guard">
                        <div class="coa-guard-item"><strong>✓ Parent-driven codes</strong><span>Child codes are previewed automatically and regenerated inside a database transaction at save time.</span></div>
                        <div class="coa-guard-item"><strong>✓ No hard-coded account IDs</strong><span>Accounting integrations continue resolving stable account codes/mappings rather than database IDs.</span></div>
                        <div class="coa-guard-item"><strong>✓ Customer/Vendor subledgers stay controlled</strong><span>Do not create one GL account per customer or supplier when the existing AR/AP control account and party subledger apply.</span></div>
                        <div class="coa-guard-item"><strong>✓ Pagination is server-side</strong><span>Only the selected 25/50/100 accounts are fetched for the register instead of rendering the whole chart on every page view.</span></div>
                    </div>
                    <div class="coa-setup">
                        <div class="coa-alert coa-info" style="margin:0 0 12px"><strong>Bank setup:</strong> choose <strong>1020 · Bank</strong> as Parent, enter the real bank name, use Type <strong>Asset</strong> and Subtype <strong>BANK</strong>. The code is generated automatically.</div>
                        <div class="coa-setup-title">Quick account setup</div>
                        <div class="coa-setup-grid">
                            <div class="coa-setup-step"><b>1 · Choose parent</b><span>The parent controls the numbering family and reporting structure.</span></div>
                            <div class="coa-setup-step"><b>2 · Name the ledger</b><span>Use the real operational name, such as Meezan Bank - Main Account.</span></div>
                            <div class="coa-setup-step"><b>3 · Create safely</b><span>The backend verifies the next code again at save time before creating the ledger.</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="coa-card coa-register">
            <div class="coa-register-head">
                <div class="coa-register-title">
                    <div><h3>Account Register</h3><div class="coa-muted">Search, filter and page through the chart without loading every account at once.</div></div>
                    <div class="coa-page-meta">{{ number_format($rows->total()) }} total account{{ $rows->total() === 1 ? '' : 's' }}</div>
                </div>
                <form method="get" class="coa-tools" action="{{ route('accounting.chart-of-accounts.workspace') }}">
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search code, account name, subtype or control type">
                    <select name="type"><option value="">All account types</option>@foreach(['asset'=>'Assets','liability'=>'Liabilities','equity'=>'Equity','income'=>'Income','expense'=>'Expenses'] as $value=>$label)<option value="{{ $value }}" @selected($filters['type']===$value)>{{ $label }}</option>@endforeach</select>
                    <select name="status"><option value="">All statuses</option><option value="active" @selected($filters['status']==='active')>Active</option><option value="inactive" @selected($filters['status']==='inactive')>Inactive</option></select>
                    <select name="per_page"><option value="25" @selected((int)$filters['per_page']===25)>25 rows</option><option value="50" @selected((int)$filters['per_page']===50)>50 rows</option><option value="100" @selected((int)$filters['per_page']===100)>100 rows</option></select>
                    <button class="coa-btn coa-primary" type="submit">Apply</button>
                    <a class="coa-btn coa-reset" href="{{ route('accounting.chart-of-accounts.workspace') }}">Reset</a>
                </form>
            </div>
            <div class="coa-table-wrap">
                <table class="coa-table">
                    <colgroup><col><col><col><col><col><col><col><col></colgroup>
                    <thead><tr><th>Code / Name</th><th>Type</th><th>Parent</th><th>Normal</th><th>Posting</th><th>Control</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    @forelse($rows as $row)
                        @php
                            $active = isset($row->active) ? (bool)$row->active : (!isset($row->status) || in_array(strtolower((string)$row->status),['active','enabled'],true));
                            $normal = $row->normal ?? (in_array(strtolower((string)$row->type),['asset','expense','cost'],true) ? 'DEBIT' : 'CREDIT');
                            $posting = !isset($row->posting) || (bool)$row->posting;
                            $control = isset($row->control_flag) && (bool)$row->control_flag;
                        @endphp
                        <tr>
                            <td><span class="coa-code">{{ $row->code }}</span> <span class="coa-name">{{ $row->name }}</span>@if(!empty($row->subtype))<div class="coa-sub">{{ $row->subtype }}</div>@endif</td>
                            <td><span class="coa-pill">{{ $row->type }}</span></td>
                            <td class="coa-parent">@if(!empty($row->parent_code))<strong>{{ $row->parent_code }}</strong><small>{{ $row->parent_name }}</small>@else<span class="coa-muted">—</span>@endif</td>
                            <td>{{ strtoupper((string)$normal) }}</td>
                            <td>{{ $posting ? 'Yes' : 'No' }}</td>
                            <td>@if($control)<strong>{{ $row->control_type ?? 'CONTROL' }}</strong>@else<span class="coa-muted">—</span>@endif</td>
                            <td><span class="coa-pill {{ $active ? 'coa-active' : '' }}">{{ $active ? 'Active' : 'Inactive' }}</span></td>
                            <td><div class="coa-actions">@if($hasNativeEdit)<a class="coa-action" href="{{ url('/accounting/chart-of-accounts/'.$row->id.'/edit') }}">Edit</a>@else<span class="coa-muted">—</span>@endif</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="padding:34px;text-align:center;color:#7b8798">No accounts match the current filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="coa-footer">
                <div class="coa-page-meta">@if($rows->total()) Showing {{ number_format($rows->firstItem()) }}–{{ number_format($rows->lastItem()) }} of {{ number_format($rows->total()) }} accounts @else 0 accounts @endif</div>
                @if($rows->lastPage()>1)
                    <div class="coa-pages">
                        <a class="coa-page {{ $rows->onFirstPage() ? 'disabled' : '' }}" href="{{ $rows->previousPageUrl() ?: '#' }}">‹</a>
                        @php
                            $start=max(1,$rows->currentPage()-2); $end=min($rows->lastPage(),$rows->currentPage()+2);
                        @endphp
                        @if($start>1)<a class="coa-page" href="{{ $rows->url(1) }}">1</a>@if($start>2)<span class="coa-page disabled">…</span>@endif @endif
                        @for($page=$start;$page<=$end;$page++)<a class="coa-page {{ $page===$rows->currentPage() ? 'active' : '' }}" href="{{ $rows->url($page) }}">{{ $page }}</a>@endfor
                        @if($end<$rows->lastPage())@if($end<$rows->lastPage()-1)<span class="coa-page disabled">…</span>@endif<a class="coa-page" href="{{ $rows->url($rows->lastPage()) }}">{{ $rows->lastPage() }}</a>@endif
                        <a class="coa-page {{ $rows->hasMorePages() ? '' : 'disabled' }}" href="{{ $rows->nextPageUrl() ?: '#' }}">›</a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script data-et-coa="ERP-11.3.10">
(function(){
'use strict';
const parent=document.getElementById('coa-parent'),code=document.getElementById('coa-code'),preview=document.getElementById('coa-code-preview'),status=document.getElementById('coa-code-status'),type=document.getElementById('coa-type'),normal=document.getElementById('coa-normal');
if(!parent||!code)return;
const endpoint=@json(route('accounting.chart-of-accounts.next-code'));
let token=0;
function setNormal(){normal.value=['asset','expense'].includes(type.value)?'DEBIT':'CREDIT'}
async function updateCode(){
  const parentId=parent.value;
  token++; const own=token;
  code.readOnly=true;
  if(!parentId){code.value='';preview.textContent='—';status.textContent='Select a parent account to generate the code.';return}
  code.value='';preview.textContent='…';status.textContent='Checking next available code…';
  try{
    const response=await fetch(endpoint+'?parent_id='+encodeURIComponent(parentId),{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
    const data=await response.json(); if(own!==token)return;
    if(!response.ok||!data.ok)throw new Error(data.message||'Could not calculate code');
    code.value=data.code;preview.textContent=data.code;status.textContent='Read-only preview — the backend regenerates and verifies this code again when you save.';
  }catch(error){if(own!==token)return;preview.textContent='ERROR';status.textContent=error.message||'Could not calculate code';}
}
parent.addEventListener('change',updateCode); type?.addEventListener('change',setNormal); setNormal(); updateCode();
})();
</script>
@endsection
