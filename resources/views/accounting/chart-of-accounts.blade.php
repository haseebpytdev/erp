@extends('layouts.app')
@section('title','Chart of Accounts')
@section('page_title','Chart of Accounts')
@section('content')
<div class="page-intro"><div><span class="eyebrow">ERP-03</span><h1>One controlled ledger structure for every future posting.</h1><p>Accounts are the general-ledger structure. Customers, vendors and agents will remain party subledgers and will post through mapped control accounts instead of receiving separate GL accounts.</p></div><a class="btn btn-ghost" href="{{ route('accounting.mappings.index') }}">Account Mappings</a></div>

<div class="metric-grid">
@foreach(['ASSET'=>'Assets','LIABILITY'=>'Liabilities','INCOME'=>'Income','EXPENSE'=>'Expenses'] as $key=>$label)
<div class="metric-card"><div class="metric-label">{{ $label }}</div><div class="metric-value">{{ $counts[$key] ?? 0 }}</div><div class="metric-note">GL accounts</div></div>
@endforeach
</div>

<div class="grid-2 uneven">
@if(auth()->user()->hasPermission('coa.manage'))
<div class="panel">
<div class="panel-head"><div><h3>Add Account</h3><p>Create a posting, header or control account.</p></div></div>
<form method="POST" action="{{ route('accounting.accounts.store') }}" class="form-stack">@csrf
<div class="form-grid"><label><span>Account Code</span><input name="code" required placeholder="e.g. 6100"></label><label><span>Account Name</span><input name="name" required placeholder="Office Rent"></label>
<label><span>Account Type</span><select name="account_type" required><option value="ASSET">Asset</option><option value="LIABILITY">Liability</option><option value="EQUITY">Equity</option><option value="INCOME">Income</option><option value="EXPENSE">Expense</option></select></label>
<label><span>Subtype</span><input name="account_subtype" placeholder="e.g. OPERATING_EXPENSE"></label>
<label class="span-2"><span>Parent Account (optional)</span><select name="parent_id"><option value="">No parent</option>@foreach($parents as $parent)<option value="{{ $parent->id }}">{{ $parent->code }} · {{ $parent->name }}</option>@endforeach</select></label>
<label><span>Control Type (only if control account)</span><input name="control_type" placeholder="e.g. TAX_PAYABLE"></label><label><span>Notes</span><input name="notes" placeholder="Optional internal note"></label></div>
<div class="check-row"><input type="checkbox" name="allow_posting" value="1" checked><span>Allow direct journal posting</span></div>
<div class="check-row"><input type="checkbox" name="is_control" value="1"><span>Control account (subledger-linked)</span></div>
<div class="callout">Normal balance is derived automatically: Assets/Expenses = Debit; Liabilities/Equity/Income = Credit.</div>
<div class="form-actions"><button class="btn btn-primary">Create Account</button></div>
</form>
</div>
@else
<div class="panel"><div class="panel-head"><div><h3>Read-only access</h3><p>Your role may review the ledger structure but cannot modify it.</p></div></div><div class="callout">Changes require the <strong>coa.manage</strong> permission.</div></div>
@endif

<div class="panel">
<div class="panel-head"><div><h3>Accounting Guardrails</h3><p>These rules become mandatory when ERP-04 posting is enabled.</p></div></div>
<div class="check-list">
<div class="check-item"><span class="check-dot done">✓</span><span>Vendor payable uses one AP control account, not one GL per vendor</span><strong>2110</strong></div>
<div class="check-item"><span class="check-dot done">✓</span><span>Customer balances use the AR party subledger</span><strong>1130</strong></div>
<div class="check-item"><span class="check-dot done">✓</span><span>Mapped accounts must remain active posting accounts</span><strong>Protected</strong></div>
<div class="check-item"><span class="check-dot done">✓</span><span>Hard-coded account IDs are prohibited; posting uses mapping keys</span><strong>{{ $mappedCount }} mapped</strong></div>
</div>
<div class="record-actions"><a class="btn btn-primary" href="{{ route('accounting.mappings.index') }}">Review Mappings</a></div>
</div>
</div>

<div class="panel">
<div class="panel-head"><div><h3>Account Register</h3><p>Edit classifications before transactions are introduced. No delete action is exposed.</p></div></div>
<form method="GET" class="coa-filter"><input name="q" value="{{ $search }}" placeholder="Search code or account name"><select name="type"><option value="">All account types</option>@foreach(['ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'] as $t)<option value="{{ $t }}" @selected($filterType===$t)>{{ ucfirst(strtolower($t)) }}</option>@endforeach</select><button class="btn btn-ghost">Filter</button><a class="btn btn-ghost" href="{{ route('accounting.accounts.index') }}">Reset</a></form>
<div class="table-wrap"><table><thead><tr><th>Code / Name</th><th>Type</th><th>Parent</th><th>Normal</th><th>Posting</th><th>Control</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($accounts as $account)
<tr><td><span class="code-chip">{{ $account->code }}</span><strong class="account-name">{{ $account->name }}</strong><small>{{ $account->account_subtype ?: '—' }}</small></td><td><span class="type-pill {{ strtolower($account->account_type) }}">{{ $account->account_type }}</span></td><td>{{ $account->parent?->code ?? '—' }}</td><td>{{ $account->normal_balance }}</td><td>{{ $account->allow_posting ? 'Yes' : 'Header' }}</td><td>@if($account->is_control)<strong>{{ $account->control_type ?: 'CONTROL' }}</strong>@else—@endif</td><td><span class="status-pill {{ $account->is_active?'active':'closed' }}">{{ $account->is_active?'ACTIVE':'INACTIVE' }}</span></td><td>@if(auth()->user()->hasPermission('coa.manage'))<details class="edit-details"><summary>Edit</summary><form method="POST" action="{{ route('accounting.accounts.update',$account) }}" class="mini-edit">@csrf @method('PUT')
<input name="code" value="{{ $account->code }}" required><input name="name" value="{{ $account->name }}" required>
<select name="account_type">@foreach(['ASSET','LIABILITY','EQUITY','INCOME','EXPENSE'] as $t)<option value="{{ $t }}" @selected($account->account_type===$t)>{{ $t }}</option>@endforeach</select><input name="account_subtype" value="{{ $account->account_subtype }}" placeholder="Subtype">
<select name="parent_id"><option value="">No parent</option>@foreach($parents as $parent)@if($parent->id!==$account->id)<option value="{{ $parent->id }}" @selected($account->parent_id===$parent->id)>{{ $parent->code }} · {{ $parent->name }}</option>@endif @endforeach</select><input name="control_type" value="{{ $account->control_type }}" placeholder="Control type"><input name="notes" value="{{ $account->notes }}" placeholder="Notes">
<label class="inline-check"><input type="checkbox" name="allow_posting" value="1" @checked($account->allow_posting)> Posting</label><label class="inline-check"><input type="checkbox" name="is_control" value="1" @checked($account->is_control)> Control</label><label class="inline-check"><input type="checkbox" name="is_active" value="1" @checked($account->is_active)> Active</label><button class="btn btn-small btn-primary">Save</button></form></details>@else<span class="muted-copy">View only</span>@endif</td></tr>
@empty<tr><td colspan="8">No accounts match the selected filter.</td></tr>@endforelse
</tbody></table></div>
</div>
@endsection
