<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Post-Reset Financial Cleanup · Easy Ticket ERP</title>
<style>
:root{--bg:#f3f6fa;--card:#fff;--text:#17243a;--muted:#6b7a90;--line:#dfe7f0;--blue:#1769d2;--green:#158754;--red:#bb2d3b;--amber:#8a5a00}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{width:min(1480px,calc(100% - 28px));margin:18px auto 40px}.topbar,.card{background:#fff;border:1px solid var(--line);border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;margin-bottom:14px}.kicker{color:var(--blue);font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}h1{margin:4px 0 0;font-size:25px;line-height:1.15}.sub{margin-top:5px;color:var(--muted);font-size:13px;line-height:1.45}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid #d3deea;border-radius:7px;background:#fff;color:#26364d;text-decoration:none;font-size:12px;font-weight:800;cursor:pointer}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.danger{background:var(--red);border-color:var(--red);color:#fff}.notice{margin-bottom:14px;padding:13px 15px;border:1px solid #efc3c8;border-radius:10px;background:#fff0f1;color:#7e1d28;font-size:13px;line-height:1.5}.notice.success{border-color:#bfe3cf;background:#edf9f2;color:#17623c}.notice.info{border-color:#cfe0f7;background:#eff6ff;color:#285a97}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.metric{padding:14px;background:#fff;border:1px solid var(--line);border-radius:10px}.metric-label{color:#66778f;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.metric-value{margin-top:7px;font-size:22px;font-weight:800}.metric-sub{margin-top:4px;color:var(--muted);font-size:10.5px}.card{margin-bottom:14px;overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid #e8edf3}.card-title{font-size:16px;font-weight:800}.card-note{margin-top:3px;color:var(--muted);font-size:11px;line-height:1.45}.card-body{padding:14px 16px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:9px 10px;border-bottom:1px solid #e9eef4;text-align:left;vertical-align:top}th{background:#f5f8fc;color:#596b84;font-size:9.5px;text-transform:uppercase;letter-spacing:.035em}td.num{text-align:right;font-variant-numeric:tabular-nums}.tag{display:inline-flex;padding:3px 6px;border-radius:999px;background:#fff0f1;color:#a32532;font-size:9px;font-weight:800}.tag.zero{background:#fff7e7;color:#855b00}.steps{display:grid;grid-template-columns:1fr 1fr;gap:12px}.step{padding:13px;border:1px solid var(--line);border-radius:9px;background:#fbfcfe}.step-title{font-size:13px;font-weight:800}.step-text{margin:4px 0 12px;color:var(--muted);font-size:11px;line-height:1.5}label{display:block;margin:8px 0 5px;font-size:10px;font-weight:800}input[type="text"]{width:100%;min-height:40px;padding:8px 10px;border:1px solid #cfdbe8;border-radius:6px;background:#fff;color:#17243a;font-size:12px}.check{display:flex;align-items:flex-start;gap:8px;margin:10px 0 12px;color:#4c5d74;font-size:10.5px;line-height:1.45}.check input{margin-top:2px}.small{color:var(--muted);font-size:10px;line-height:1.45}.completed{padding:12px;border:1px solid #bfe3cf;border-radius:8px;background:#edf9f2;color:#17623c;line-height:1.5;font-size:11px}@media(max-width:900px){.grid4{grid-template-columns:repeat(2,minmax(0,1fr))}.steps{grid-template-columns:1fr}.topbar{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<div class="page">
<div class="topbar"><div><div class="kicker">System Maintenance · ERP-10.31.79</div><h1>Final Go-Live Financial Reconciliation</h1><div class="sub">Reconcile any remaining Payables, Supplier Cost, Gross Profit, account opening/closing balance and dashboard financial residue before staff begin live entries.</div></div><a class="btn" href="{{ route('system.production-data-reset.index') }}">← Production Reset</a></div>

@if(session('cleanup_success'))<div class="notice success">{{ session('cleanup_success') }}</div>@endif
@if(session('cleanup_error'))<div class="notice">{{ session('cleanup_error') }}</div>@endif
@if($errors->any())<div class="notice"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

@if(!$plan['main_reset_completed'])
<div class="notice">Main Production Transaction Reset completion marker was not found. This cleanup is intentionally blocked.</div>
@else
<div class="notice info"><strong>Expected clean dashboard after completion:</strong> Payables 0.00 · Period Supplier Cost 0.00 · Gross Profit 0.00 · no old supplier-cost/profit trend residue. Company, Staff/RBAC, COA structure, Travel Masters and Transporter data remain preserved.</div>
<div class="notice info"><strong>Main transaction reset is confirmed complete.</strong> This page does not rerun bookings/invoices deletion. This is the second-pass reconciliation for any remaining Dashboard Payables, Supplier Cost or Gross Profit values after the production reset. It does not restore or rerun deleted bookings/invoices.</div>
@endif

<div class="grid4">
<div class="metric"><div class="metric-label">Residual rows</div><div class="metric-value">{{ number_format((int)$plan['rows_to_delete']) }}</div><div class="metric-sub">Rows in residual financial/summary tables</div></div>
<div class="metric"><div class="metric-label">Balance rows</div><div class="metric-value">{{ number_format((int)$plan['rows_to_zero']) }}</div><div class="metric-sub">Master/structural rows with non-zero financial state</div></div>
<div class="metric"><div class="metric-label">Tables to clean</div><div class="metric-value">{{ number_format((int)$plan['tables_to_clean']) }}</div><div class="metric-sub">Detected from the live schema</div></div>
<div class="metric"><div class="metric-label">Cleanup state</div><div class="metric-value">{{ $plan['completed'] ? 'LOCKED' : 'READY' }}</div><div class="metric-sub">{{ $plan['completed'] ? 'Already completed once' : 'Backup + confirmation required' }}</div></div>
</div>

<div class="card"><div class="card-head"><div class="card-title">Live residual financial plan</div><div class="card-note">Only detected non-zero residue is shown. Company identity, staff, RBAC, accounting structure, travel masters and transporter identity remain preserved.</div></div><div class="table-wrap"><table><thead><tr><th>Action</th><th>Table</th><th style="text-align:right">Affected rows</th><th>Columns / reason</th></tr></thead><tbody>
@forelse($plan['items'] as $item)
<tr><td><span class="tag {{ $item['action']==='zero_columns' ? 'zero' : '' }}">{{ $item['action_label'] }}</span></td><td><strong>{{ $item['table'] }}</strong></td><td class="num">{{ number_format((int)$item['rows']) }}</td><td>@if(!empty($item['columns']))<div><strong>{{ implode(', ', $item['columns']) }}</strong></div>@endif<div>{{ $item['reason'] }}</div></td></tr>
@empty
<tr><td colspan="4">No residual financial rows were detected by the cleanup scanner.</td></tr>
@endforelse
</tbody></table></div></div>

<div class="card"><div class="card-head"><div class="card-title">One-time cleanup</div><div class="card-note">A separate compressed backup is required before this cleanup. The server validates it before any delete/update starts.</div></div><div class="card-body">
@if($plan['completed'])
<div class="completed"><strong>Post-reset financial cleanup already completed and locked.</strong><br>Completed: {{ $plan['completed']['completed_at'] ?? 'Unknown' }}<br>By: {{ $plan['completed']['actor_name'] ?? 'Unknown' }}<br>Rows deleted: {{ number_format((int)($plan['completed']['rows_deleted'] ?? 0)) }}<br>Rows zeroed: {{ number_format((int)($plan['completed']['rows_zeroed'] ?? 0)) }}</div>
@else
<div class="steps">
<div class="step"><div class="step-title">1. Download financial-residue backup</div><div class="step-text">Backs up every table this cleanup will touch before anything is changed.</div><form method="POST" action="{{ route('system.post-reset-financial-cleanup.backup') }}">@csrf<button class="btn primary" type="submit" {{ !$plan['main_reset_completed'] ? 'disabled' : '' }}>Download Financial Residue Backup</button></form>@if($backupReady)<div class="notice success" style="margin:10px 0 0">Backup ready: <strong>{{ $backupFilename }}</strong></div>@endif</div>
<div class="step"><div class="step-title">2. Clear residual financial state</div><div class="step-text">Deletes residual cost/payable/summary rows and zeros recognized balance/cost fields on preserved structural/master rows. Application cache is flushed after successful verification.</div><form method="POST" action="{{ route('system.post-reset-financial-cleanup.execute') }}">@csrf<label for="confirmation">Type exactly: {{ $plan['confirmation'] }}</label><input id="confirmation" name="confirmation" type="text" autocomplete="off" value="{{ old('confirmation') }}" placeholder="{{ $plan['confirmation'] }}"><label class="check"><input name="acknowledge" value="1" type="checkbox"><span>I understand that the displayed residual financial state will be permanently removed while company/master/transport identities remain preserved.</span></label><button class="btn danger" type="submit" onclick="return confirm('Final confirmation: clear the displayed residual financial state now?');" {{ !$plan['main_reset_completed'] ? 'disabled' : '' }}>Clear Financial Residue</button>@if(!$backupReady)<div class="small" style="margin-top:8px;color:#8a5a00">You may type the confirmation now, but the server will refuse execution until a valid backup exists in this signed-in session.</div>@endif</form></div>
</div>
@endif
</div></div>
</div>
</body>
</html>
