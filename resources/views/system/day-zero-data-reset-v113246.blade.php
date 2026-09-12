<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Day-Zero Execution · Easy Ticket ERP</title>
    <style>
        :root{--bg:#f3f6fa;--card:#fff;--text:#17243a;--muted:#6b7a90;--line:#dfe7f0;--blue:#1769d2;--green:#158754;--red:#bb2d3b;--amber:#8a5a00}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{width:min(1500px,calc(100% - 28px));margin:18px auto 40px}.topbar,.card{background:var(--card);border:1px solid var(--line);border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;margin-bottom:14px}.kicker{color:var(--red);font-size:12px;font-weight:900;letter-spacing:.05em;text-transform:uppercase}h1{margin:4px 0 0;font-size:25px}.sub{margin-top:5px;color:var(--muted);font-size:13px;line-height:1.45}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border:1px solid #d3deea;border-radius:7px;background:#fff;color:#26364d;text-decoration:none;font-size:12px;font-weight:900;cursor:pointer}.btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.danger{background:var(--red);border-color:var(--red);color:#fff}.btn:disabled{opacity:.45;cursor:not-allowed}.notice{margin-bottom:14px;padding:13px 15px;border:1px solid #efc3c8;border-radius:10px;background:#fff0f1;color:#7e1d28;font-size:13px;line-height:1.5}.notice.success{border-color:#bfe3cf;background:#edf9f2;color:#17623c}.notice.info{border-color:#cfe0f7;background:#eff6ff;color:#285a97}.notice.warn{border-color:#ead59e;background:#fff9e8;color:#745100}.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.metric,.gate{padding:14px;background:#fff;border:1px solid var(--line);border-radius:10px}.metric-label,.gate-label{color:#66778f;font-size:10px;font-weight:900;text-transform:uppercase}.metric-value{margin-top:7px;font-size:22px;font-weight:900}.metric-sub{margin-top:4px;color:var(--muted);font-size:10.5px}.card{margin-bottom:14px;overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid #e8edf3}.card-title{font-size:16px;font-weight:900}.card-note{margin-top:3px;color:var(--muted);font-size:11px;line-height:1.45}.card-body{padding:14px 16px}.gates{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.gate-value{margin-top:6px;font-size:17px;font-weight:900}.good{color:var(--green)}.bad{color:var(--red)}.order{padding:11px;border:1px solid #d9e4ef;border-radius:8px;background:#f7faff;font-size:10px;line-height:1.65;word-break:break-word}.steps{display:grid;grid-template-columns:1fr 1fr;gap:12px}.step{padding:14px;border:1px solid var(--line);border-radius:9px;background:#fbfcfe}.step-title{font-size:14px;font-weight:900}.step-text{margin:5px 0 12px;color:var(--muted);font-size:11px;line-height:1.5}.armed{padding:12px;border:1px solid #efb5bc;border-radius:8px;background:#fff0f1;color:#7e1d28;font-size:11px;line-height:1.55}.locked{padding:12px;border:1px solid #ead59e;border-radius:8px;background:#fff9e8;color:#745100;font-size:11px;line-height:1.55}label{display:block;margin:9px 0 5px;font-size:11px;font-weight:900}input[type="text"]{width:100%;min-height:42px;padding:8px 10px;border:1px solid #cfdbe8;border-radius:6px;background:#fff;color:#17243a;font-size:12px}.check{display:flex;align-items:flex-start;gap:8px;margin:11px 0 13px;color:#4c5d74;font-size:11px;line-height:1.45}.check input{margin-top:2px}.small{color:var(--muted);font-size:10px;line-height:1.45}@media(max-width:1200px){.gates{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:900px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.steps{grid-template-columns:1fr}}@media(max-width:650px){.grid,.gates{grid-template-columns:1fr}.topbar{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <div class="kicker">Fresh Production Start · {{ config('et_erp_release.release', 'ERP') }} · Controlled Execution</div>
            <h1>Day-Zero Database Reset</h1>
            <div class="sub">The production safety plan has been resolved. Execution is permitted only while every live gate below still passes, a fresh full backup remains valid, the exact confirmation phrase is entered, and the permanent acknowledgement is checked.</div>
        </div>
        <a class="btn" href="{{ url('/system/health') }}" onclick="if(history.length>1){event.preventDefault();history.back();}">← Back</a>
    </div>

    @if(session('reset_success'))<div class="notice success"><strong>{{ session('reset_success') }}</strong></div>@endif
    @if(session('reset_error'))<div class="notice"><strong>Execution stopped:</strong> {{ session('reset_error') }}</div>@endif
    @if($errors->any())<div class="notice"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    @if($plan['completed'])
        <div class="notice success"><strong>Day-Zero is permanently completed and locked.</strong> Completed: {{ $plan['completed']['completed_at'] ?? 'Unknown' }} · By: {{ $plan['completed']['actor_name'] ?? 'Unknown' }} · Rows cleared: {{ number_format((int)($plan['completed']['rows_deleted'] ?? 0)) }} · Tables cleared: {{ number_format((int)($plan['completed']['tables_cleared'] ?? 0)) }}</div>
    @else
        <div class="notice warn"><strong>Permanent operation.</strong> This release can clear approved business/UAT data. The server re-runs the complete live safety plan immediately before mutation; any new REVIEW table, unresolved FK blocker, dependency cycle, warning, incomplete delete order, invalid counter plan or stale backup aborts execution.</div>
    @endif

    <div class="grid">
        <div class="metric"><div class="metric-label">Rows marked clear</div><div class="metric-value">{{ number_format((int)$plan['rows_to_clear']) }}</div><div class="metric-sub">Current live rows targeted</div></div>
        <div class="metric"><div class="metric-label">Clear tables</div><div class="metric-value">{{ number_format((int)$plan['clear_tables']) }}</div><div class="metric-sub">Must become empty</div></div>
        <div class="metric"><div class="metric-label">Counter tables</div><div class="metric-value">{{ number_format((int)$plan['counter_tables']) }}</div><div class="metric-sub">Document numbering restart</div></div>
        <div class="metric"><div class="metric-label">Preserved tables</div><div class="metric-value">{{ number_format((int)$plan['preserved_tables']) }}</div><div class="metric-sub">Security/system foundation</div></div>
        <div class="metric"><div class="metric-label">Needs review</div><div class="metric-value">{{ number_format((int)$plan['review_tables']) }}</div><div class="metric-sub">Must remain zero</div></div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Final execution gates</div><div class="card-note">All values are generated from the connected production database on this request.</div></div>
        <div class="card-body">
            <div class="gates">
                <div class="gate"><div class="gate-label">Review tables</div><div class="gate-value {{ (int)$plan['review_tables'] === 0 ? 'good' : 'bad' }}">{{ number_format((int)$plan['review_tables']) }}</div></div>
                <div class="gate"><div class="gate-label">Unresolved FK blockers</div><div class="gate-value {{ (int)$plan['fk_blockers'] === 0 ? 'good' : 'bad' }}">{{ number_format((int)$plan['fk_blockers']) }}</div></div>
                <div class="gate"><div class="gate-label">Dependency cycles</div><div class="gate-value {{ (int)$plan['dependency_cycles'] === 0 ? 'good' : 'bad' }}">{{ number_format((int)$plan['dependency_cycles']) }}</div></div>
                <div class="gate"><div class="gate-label">Dependency plan</div><div class="gate-value {{ $plan['dependency_plan_ready'] ? 'good' : 'bad' }}">{{ $plan['dependency_plan_ready'] ? 'PASS' : 'BLOCKED' }}</div></div>
                <div class="gate"><div class="gate-label">Counter reset plan</div><div class="gate-value {{ $plan['counter_reset_ready'] ? 'good' : 'bad' }}">{{ $plan['counter_reset_ready'] ? 'PASS' : 'BLOCKED' }}</div></div>
                <div class="gate"><div class="gate-label">Fresh backup</div><div class="gate-value {{ $backupReady ? 'good' : 'bad' }}">{{ $backupReady ? 'PASS' : 'REQUIRED' }}</div></div>
            </div>

            <div class="notice info" style="margin:12px 0 0"><strong>Raw FK relationships requiring neutralization:</strong> {{ number_format((int)$plan['raw_fk_blockers']) }}. These are informational only when every item below is schema-proven nullable and unresolved blockers remain zero.</div>

            @if(!empty($plan['neutralization_preview']))
                <div class="notice success" style="margin:12px 0 0"><strong>Preserved-master neutralization plan.</strong> @foreach($plan['neutralization_preview'] as $item)<div class="small"><strong>{{ $item['child_table'] }}.{{ $item['child_column'] }}</strong> → {{ $item['parent_table'] }}.{{ $item['parent_column'] }} · {{ strtoupper($item['status'] ?? 'UNKNOWN') }} · {{ $item['operation'] }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['dependency_cycle_edges']))
                <div class="notice success" style="margin:12px 0 0"><strong>Cycle-break plan.</strong> @foreach($plan['dependency_cycle_edges'] as $edge)<div class="small"><strong>{{ $edge['child_table'] }}.{{ $edge['child_column'] }}</strong> → {{ $edge['parent_table'] }}.{{ $edge['parent_column'] }} · breakable={{ !empty($edge['can_break_with_null']) ? 'YES' : 'NO' }} · {{ $edge['operation'] }}</div>@endforeach</div>
            @endif

            <div class="step-title" style="margin-top:14px">Verified child-before-parent delete order</div>
            <div class="order">{{ $plan['delete_order'] ? implode(' → ', $plan['delete_order']) : 'No complete delete order available.' }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Safety boundary and execution</div><div class="card-note">A separate Day-Zero completion marker permanently blocks a second run after success.</div></div>
        <div class="card-body">
            <div class="steps">
                <div class="step">
                    <div class="step-title">1. Download a fresh full backup</div>
                    <div class="step-text">Download immediately before reset. It contains every live table, including preserved foundation tables.</div>
                    @if(!$plan['completed'])
                        <form method="POST" action="{{ route('system.production-data-reset.backup') }}">@csrf<button class="btn primary" type="submit">Download Full Database Backup</button></form>
                    @endif
                    @if($backupReady)<div class="notice info" style="margin:10px 0 0">Fresh backup validated in this session: <strong>{{ $backupFilename }}</strong></div>@endif
                </div>

                <div class="step">
                    <div class="step-title">2. Permanent Day-Zero reset</div>
                    @if($plan['completed'])
                        <div class="locked"><strong>Execution:</strong> PERMANENTLY LOCKED<br>The completion marker already exists. This reset cannot run again.</div>
                    @elseif($executionReady)
                        <div class="armed"><strong>Execution engine:</strong> ENABLED<br><strong>Live safety plan:</strong> PASS<br><strong>Fresh backup:</strong> PASS<br><strong>Required phrase:</strong> {{ $plan['confirmation'] }}<br><strong>Action:</strong> clear {{ number_format((int)$plan['clear_tables']) }} tables, reset {{ number_format((int)$plan['counter_tables']) }} counter tables, preserve {{ number_format((int)$plan['preserved_tables']) }} foundation tables.</div>
                        <form method="POST" action="{{ route('system.production-data-reset.execute') }}" style="margin-top:10px">@csrf
                            <label for="confirmation">Type the confirmation phrase exactly</label>
                            <input id="confirmation" type="text" name="confirmation" value="{{ old('confirmation') }}" autocomplete="off">
                            <label class="check"><input type="checkbox" name="acknowledge" value="1"><span>I understand this permanently clears the approved production/UAT business data and cannot be undone except from the downloaded backup.</span></label>
                            <button class="btn danger" type="submit" onclick="return confirm('FINAL WARNING: Permanently reset the ERP to Day Zero now?');">PERMANENTLY RESET ERP TO DAY ZERO</button>
                        </form>
                    @else
                        <div class="locked"><strong>Execution engine:</strong> {{ $executionEnabled ? 'ENABLED' : 'DISABLED' }}<br><strong>Live safety plan:</strong> {{ $plan['safety_preview_ready'] ? 'PASS' : 'BLOCKED' }}<br><strong>Fresh backup:</strong> {{ $backupReady ? 'PASS' : 'REQUIRED' }}<br>Execution remains unavailable until every safety gate and fresh-backup requirement passes.</div>
                        <button class="btn danger" type="button" disabled style="margin-top:10px">Day-Zero Reset Blocked</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
