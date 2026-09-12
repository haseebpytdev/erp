<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Day-Zero Database Reset · Easy Ticket ERP</title>
    <style>
        :root{--bg:#f3f6fa;--card:#fff;--text:#17243a;--muted:#6b7a90;--line:#dfe7f0;--blue:#1769d2;--green:#158754;--red:#bb2d3b;--amber:#8a5a00}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .page{width:min(1500px,calc(100% - 28px));margin:18px auto 40px}
        .topbar,.card{background:var(--card);border:1px solid var(--line);border-radius:12px}
        .topbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:16px 18px;margin-bottom:14px}
        .kicker{color:var(--blue);font-size:12px;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
        h1{margin:4px 0 0;font-size:25px;line-height:1.15}.sub{margin-top:5px;color:var(--muted);font-size:13px;line-height:1.45}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 13px;border:1px solid #d3deea;border-radius:7px;background:#fff;color:#26364d;text-decoration:none;font-size:12px;font-weight:800;cursor:pointer}
        .btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn.danger{background:var(--red);border-color:var(--red);color:#fff}.btn:disabled{opacity:.45;cursor:not-allowed}
        .notice{margin-bottom:14px;padding:13px 15px;border:1px solid #efc3c8;border-radius:10px;background:#fff0f1;color:#7e1d28;font-size:13px;line-height:1.5}.notice.success{border-color:#bfe3cf;background:#edf9f2;color:#17623c}.notice.info{border-color:#cfe0f7;background:#eff6ff;color:#285a97}.notice.warn{border-color:#ead59e;background:#fff9e8;color:#745100}
        .grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.metric{padding:14px;background:#fff;border:1px solid var(--line);border-radius:10px}.metric-label{color:#66778f;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.metric-value{margin-top:7px;font-size:22px;font-weight:800}.metric-sub{margin-top:4px;color:var(--muted);font-size:10.5px}
        .card{margin-bottom:14px;overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid #e8edf3}.card-title{font-size:16px;font-weight:800}.card-note{margin-top:3px;color:var(--muted);font-size:11px;line-height:1.45}.card-body{padding:14px 16px}.table-wrap{overflow:auto}
        table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:9px 10px;border-bottom:1px solid #e9eef4;text-align:left;vertical-align:top}th{background:#f5f8fc;color:#596b84;font-size:9.5px;text-transform:uppercase;letter-spacing:.035em}td.num{text-align:right;font-variant-numeric:tabular-nums}.tag{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:800}.tag.clear{background:#fff0f1;color:#a32532}.tag.preserve{background:#edf9f2;color:#17623c}.tag.review{background:#fff7e7;color:#855b00}.tag.reset_counter{background:#eef5ff;color:#285f9f}
        .steps{display:grid;grid-template-columns:1fr 1fr;gap:12px}.step{padding:13px;border:1px solid var(--line);border-radius:9px;background:#fbfcfe}.step-title{font-size:13px;font-weight:800}.step-text{margin:4px 0 12px;color:var(--muted);font-size:11px;line-height:1.5}label{display:block;margin:8px 0 5px;font-size:10px;font-weight:800}input[type="text"]{width:100%;min-height:40px;padding:8px 10px;border:1px solid #cfdbe8;border-radius:6px;background:#fff;color:#17243a;font-size:12px}.check{display:flex;align-items:flex-start;gap:8px;margin:10px 0 12px;color:#4c5d74;font-size:10.5px;line-height:1.45}.check input{margin-top:2px}.small{color:var(--muted);font-size:10px;line-height:1.45}.locked{padding:12px;border:1px solid #ead59e;border-radius:8px;background:#fff9e8;color:#745100;font-size:11px;line-height:1.5}
        @media(max-width:1050px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.steps{grid-template-columns:1fr}}@media(max-width:650px){.grid{grid-template-columns:1fr}.topbar{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <div class="kicker">Fresh Production Start · ERP-11.3.242 Preview</div>
            <h1>Day-Zero Database Reset</h1>
            <div class="sub">Live-schema inspection for a clean production start. Preview and full backup are enabled; destructive execution is intentionally locked until the plan is reviewed.</div>
        </div>
        <a class="btn" href="{{ url('/system/health') }}" onclick="if(history.length>1){event.preventDefault();history.back();}">← Back</a>
    </div>

    @if(session('reset_success'))<div class="notice success">{{ session('reset_success') }}</div>@endif
    @if(session('reset_error'))<div class="notice">{{ session('reset_error') }}</div>@endif
    @if($errors->any())
        <div class="notice"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="notice warn">
        <strong>No delete can run from this preview build.</strong> The live database is being inspected table-by-table. Any table that is not positively classified is marked REVIEW and blocks future execution until explicitly resolved.
    </div>

    <div class="grid">
        <div class="metric"><div class="metric-label">Rows marked clear</div><div class="metric-value">{{ number_format((int)$plan['rows_to_clear']) }}</div><div class="metric-sub">Known UAT/business/runtime rows</div></div>
        <div class="metric"><div class="metric-label">Clear tables</div><div class="metric-value">{{ number_format((int)$plan['clear_tables']) }}</div><div class="metric-sub">Would be emptied after approval</div></div>
        <div class="metric"><div class="metric-label">Counter tables</div><div class="metric-value">{{ number_format((int)$plan['counter_tables']) }}</div><div class="metric-sub">Would restart numbering</div></div>
        <div class="metric"><div class="metric-label">Preserved tables</div><div class="metric-value">{{ number_format((int)$plan['preserved_tables']) }}</div><div class="metric-sub">Security/system foundation</div></div>
        <div class="metric"><div class="metric-label">Needs review</div><div class="metric-value">{{ number_format((int)$plan['review_tables']) }}</div><div class="metric-sub">Must reach zero before enablement</div></div>
    </div>

    @if(!empty($plan['warnings']))
        <div class="notice"><strong>Schema/count warnings detected.</strong> Execution remains blocked. @foreach($plan['warnings'] as $warning)<div class="small"><strong>{{ $warning['table'] }}</strong> — {{ $warning['reason'] }}</div>@endforeach</div>
    @endif

    <div class="card">
        <div class="card-head"><div class="card-title">Live Day-Zero plan</div><div class="card-note">This list is generated from the actual connected database at page load. CLEAR and RESET COUNTER are candidates only; no mutation occurs in this release.</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Action</th><th>Table</th><th style="text-align:right">Rows</th><th>Reason</th></tr></thead>
                <tbody>
                @forelse($plan['items'] as $item)
                    <tr>
                        <td><span class="tag {{ $item['action'] }}">{{ $item['action_label'] }}</span></td>
                        <td><strong>{{ $item['table'] }}</strong></td>
                        <td class="num">{{ $item['rows'] === null ? '—' : number_format((int)$item['rows']) }}</td>
                        <td>{{ $item['reason'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No tables detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Safety boundary</div><div class="card-note">The existing Production Transaction Reset completion lock is not reopened. This Day-Zero tool uses a separate backup area and separate completion marker.</div></div>
        <div class="card-body">
            <div class="steps">
                <div class="step">
                    <div class="step-title">1. Download full pre-Day-Zero backup</div>
                    <div class="step-text">Creates a compressed JSON backup of every live table, including preserved tables. Keep this file before any future destructive release is enabled.</div>
                    <form method="POST" action="{{ route('system.production-data-reset.backup') }}">@csrf<button class="btn primary" type="submit">Download Full Database Backup</button></form>
                    @if($backupReady)<div class="notice info" style="margin:10px 0 0">Backup ready in this session: <strong>{{ $backupFilename }}</strong></div>@endif
                </div>

                <div class="step">
                    <div class="step-title">2. Destructive reset — LOCKED</div>
                    <div class="step-text">After the live table plan is reviewed, a later authorization release can enable execution. It will require the exact phrase below, a fresh backup, zero REVIEW tables, and final post-delete verification.</div>
                    <div class="locked"><strong>Execution enabled:</strong> NO<br><strong>Required phrase:</strong> {{ $plan['confirmation'] }}<br><strong>Current REVIEW tables:</strong> {{ number_format((int)$plan['review_tables']) }}</div>
                    <form method="POST" action="{{ route('system.production-data-reset.execute') }}" style="margin-top:10px">@csrf
                        <label for="confirmation">Confirmation phrase</label>
                        <input id="confirmation" type="text" name="confirmation" value="{{ old('confirmation') }}" disabled>
                        <label class="check"><input type="checkbox" name="acknowledge" value="1" disabled><span>I understand the approved Day-Zero reset will permanently clear the reviewed business/UAT data.</span></label>
                        <button class="btn danger" type="submit" disabled>Day-Zero Reset Locked</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if($plan['completed'])
        <div class="notice success">A Day-Zero completion marker already exists. Completed: {{ $plan['completed']['completed_at'] ?? 'Unknown' }} · By: {{ $plan['completed']['actor_name'] ?? 'Unknown' }}</div>
    @endif
</div>
</body>
</html>
