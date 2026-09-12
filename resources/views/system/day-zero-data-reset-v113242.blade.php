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
        .audit-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px}.audit{padding:12px;border:1px solid var(--line);border-radius:9px;background:#fbfcfe}.audit-label{font-size:9.5px;font-weight:800;text-transform:uppercase;color:#66778f}.audit-value{margin-top:5px;font-size:17px;font-weight:800}.audit-value.good{color:var(--green)}.audit-value.bad{color:var(--red)}
        .card{margin-bottom:14px;overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid #e8edf3}.card-title{font-size:16px;font-weight:800}.card-note{margin-top:3px;color:var(--muted);font-size:11px;line-height:1.45}.card-body{padding:14px 16px}.table-wrap{overflow:auto}
        table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:9px 10px;border-bottom:1px solid #e9eef4;text-align:left;vertical-align:top}th{background:#f5f8fc;color:#596b84;font-size:9.5px;text-transform:uppercase;letter-spacing:.035em}td.num{text-align:right;font-variant-numeric:tabular-nums}.tag{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:800}.tag.clear{background:#fff0f1;color:#a32532}.tag.preserve{background:#edf9f2;color:#17623c}.tag.review{background:#fff7e7;color:#855b00}.tag.reset_counter{background:#eef5ff;color:#285f9f}
        .order{padding:11px;border:1px solid #d9e4ef;border-radius:8px;background:#f7faff;font-size:10px;line-height:1.65;word-break:break-word}.steps{display:grid;grid-template-columns:1fr 1fr;gap:12px}.step{padding:13px;border:1px solid var(--line);border-radius:9px;background:#fbfcfe}.step-title{font-size:13px;font-weight:800}.step-text{margin:4px 0 12px;color:var(--muted);font-size:11px;line-height:1.5}label{display:block;margin:8px 0 5px;font-size:10px;font-weight:800}input[type="text"]{width:100%;min-height:40px;padding:8px 10px;border:1px solid #cfdbe8;border-radius:6px;background:#fff;color:#17243a;font-size:12px}.check{display:flex;align-items:flex-start;gap:8px;margin:10px 0 12px;color:#4c5d74;font-size:10.5px;line-height:1.45}.check input{margin-top:2px}.small{color:var(--muted);font-size:10px;line-height:1.45}.locked{padding:12px;border:1px solid #ead59e;border-radius:8px;background:#fff9e8;color:#745100;font-size:11px;line-height:1.5}
        @media(max-width:1280px){.audit-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:1050px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.steps{grid-template-columns:1fr}}@media(max-width:650px){.grid,.audit-grid{grid-template-columns:1fr}.topbar{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <div class="kicker">Fresh Production Start · {{ config('et_erp_release.release', 'ERP') }} FK Resolution Preview</div>
            <h1>Day-Zero Database Reset</h1>
            <div class="sub">Live-schema safety audit for the approved fresh-production plan. Foreign-key blockers, nullable neutralization candidates, exact cycle edges, child-before-parent delete order and counter restart columns are inspected while destructive execution remains locked.</div>
        </div>
        <a class="btn" href="{{ url('/system/health') }}" onclick="if(history.length>1){event.preventDefault();history.back();}">← Back</a>
    </div>

    @if(session('reset_success'))<div class="notice success">{{ session('reset_success') }}</div>@endif
    @if(session('reset_error'))<div class="notice">{{ session('reset_error') }}</div>@endif
    @if($errors->any())
        <div class="notice"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="notice warn">
        <strong>No delete or FK neutralization can run from this safety-preview build.</strong> Zero REVIEW tables is necessary but not sufficient. Unresolved foreign-key blockers, dependency cycles and unrecognized counter restart columns also block any future execution release.
    </div>

    <div class="grid">
        <div class="metric"><div class="metric-label">Rows marked clear</div><div class="metric-value">{{ number_format((int)$plan['rows_to_clear']) }}</div><div class="metric-sub">Known UAT/business/runtime rows</div></div>
        <div class="metric"><div class="metric-label">Clear tables</div><div class="metric-value">{{ number_format((int)$plan['clear_tables']) }}</div><div class="metric-sub">Would be emptied after approval</div></div>
        <div class="metric"><div class="metric-label">Counter tables</div><div class="metric-value">{{ number_format((int)$plan['counter_tables']) }}</div><div class="metric-sub">Would restart numbering</div></div>
        <div class="metric"><div class="metric-label">Preserved tables</div><div class="metric-value">{{ number_format((int)$plan['preserved_tables']) }}</div><div class="metric-sub">Security/system foundation</div></div>
        <div class="metric"><div class="metric-label">Needs review</div><div class="metric-value">{{ number_format((int)$plan['review_tables']) }}</div><div class="metric-sub">Must remain zero</div></div>
    </div>

    @if(!empty($plan['warnings']))
        <div class="notice"><strong>Schema/count warnings detected.</strong> Execution remains blocked. @foreach($plan['warnings'] as $warning)<div class="small"><strong>{{ $warning['table'] }}</strong> — {{ $warning['reason'] }}</div>@endforeach</div>
    @endif

    <div class="card">
        <div class="card-head"><div class="card-title">Execution safety audit</div><div class="card-note">Read-only runtime inspection. This section does not delete, update, truncate, neutralize or reset any table.</div></div>
        <div class="card-body">
            <div class="audit-grid">
                <div class="audit"><div class="audit-label">FK relationships</div><div class="audit-value">{{ number_format((int)$plan['fk_relationships']) }}</div></div>
                <div class="audit"><div class="audit-label">Raw FK blockers</div><div class="audit-value {{ (int)$plan['raw_fk_blockers'] === 0 ? 'good' : 'bad' }}">{{ number_format((int)$plan['raw_fk_blockers']) }}</div></div>
                <div class="audit"><div class="audit-label">Unresolved blockers</div><div class="audit-value {{ (int)$plan['fk_blockers'] === 0 ? 'good' : 'bad' }}">{{ number_format((int)$plan['fk_blockers']) }}</div></div>
                <div class="audit"><div class="audit-label">Dependency cycles</div><div class="audit-value {{ (int)$plan['dependency_cycles'] === 0 ? 'good' : 'bad' }}">{{ number_format((int)$plan['dependency_cycles']) }}</div></div>
                <div class="audit"><div class="audit-label">Dependency plan</div><div class="audit-value {{ $plan['dependency_plan_ready'] ? 'good' : 'bad' }}">{{ $plan['dependency_plan_ready'] ? 'PASS' : 'BLOCKED' }}</div></div>
                <div class="audit"><div class="audit-label">Counter reset plan</div><div class="audit-value {{ $plan['counter_reset_ready'] ? 'good' : 'bad' }}">{{ $plan['counter_reset_ready'] ? 'PASS' : 'BLOCKED' }}</div></div>
                <div class="audit"><div class="audit-label">Fresh backup validation</div><div class="audit-value {{ $backupReady ? 'good' : 'bad' }}">{{ $backupReady ? 'PASS' : 'REQUIRED' }}</div></div>
            </div>

            @if(!empty($plan['dependency_warnings']))
                <div class="notice" style="margin:12px 0 0"><strong>Dependency audit warning.</strong> @foreach($plan['dependency_warnings'] as $warning)<div class="small">{{ $warning }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['raw_fk_blocker_items']))
                <div class="notice info" style="margin:12px 0 0"><strong>Raw preserved-to-clear FK relationships.</strong> @foreach($plan['raw_fk_blocker_items'] as $blocker)<div class="small"><strong>{{ $blocker['child_table'] }}.{{ $blocker['child_column'] }}</strong> → {{ $blocker['parent_table'] }}.{{ $blocker['parent_column'] }} · {{ $blocker['reason'] }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['neutralization_preview']))
                <div class="notice {{ empty($plan['neutralization_warnings']) ? 'success' : 'warn' }}" style="margin:12px 0 0"><strong>Nullable FK neutralization preview.</strong> @foreach($plan['neutralization_preview'] as $item)<div class="small"><strong>{{ $item['child_table'] }}.{{ $item['child_column'] }}</strong> → {{ $item['parent_table'] }}.{{ $item['parent_column'] }} · nullable={{ ($item['nullable'] ?? null) === true ? 'YES' : (($item['nullable'] ?? null) === false ? 'NO' : 'UNKNOWN') }} · {{ strtoupper($item['status'] ?? 'UNKNOWN') }} · {{ $item['operation'] }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['neutralization_warnings']))
                <div class="notice" style="margin:12px 0 0"><strong>FK neutralization warning.</strong> @foreach($plan['neutralization_warnings'] as $warning)<div class="small"><strong>{{ $warning['table'] }}.{{ $warning['column'] }}</strong> · {{ $warning['reason'] }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['fk_blocker_items']))
                <div class="notice" style="margin:12px 0 0"><strong>Unresolved foreign-key blockers found.</strong> @foreach($plan['fk_blocker_items'] as $blocker)<div class="small"><strong>{{ $blocker['child_table'] }}.{{ $blocker['child_column'] }}</strong> → {{ $blocker['parent_table'] }}.{{ $blocker['parent_column'] }} · {{ $blocker['reason'] }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['dependency_cycle_edges']))
                <div class="notice {{ (int)$plan['dependency_cycles'] === 0 ? 'success' : '' }}" style="margin:12px 0 0"><strong>Dependency cycle edge audit.</strong> @foreach($plan['dependency_cycle_edges'] as $edge)<div class="small"><strong>{{ $edge['child_table'] }}.{{ $edge['child_column'] }}</strong> → {{ $edge['parent_table'] }}.{{ $edge['parent_column'] }} · nullable={{ ($edge['nullable'] ?? null) === true ? 'YES' : (($edge['nullable'] ?? null) === false ? 'NO' : 'UNKNOWN') }} · breakable={{ !empty($edge['can_break_with_null']) ? 'YES' : 'NO' }} · {{ $edge['operation'] }}</div>@endforeach</div>
            @endif

            @if(!empty($plan['dependency_cycle_tables']))
                <div class="notice" style="margin:12px 0 0"><strong>Dependency cycle remains.</strong> <span class="small">{{ implode(', ', $plan['dependency_cycle_tables']) }}</span></div>
            @elseif(!empty($plan['dependency_cycle_edges']))
                <div class="notice success" style="margin:12px 0 0"><strong>Dependency cycles resolved in preview.</strong> <span class="small">Only schema-proven nullable cycle edges were removed from the effective ordering graph. No live data was changed.</span></div>
            @endif

            <div class="step-title" style="margin-top:14px">Safe child-before-parent delete order preview</div>
            <div class="step-text">Every table currently classified CLEAR must appear exactly once here before a destructive release can be considered.</div>
            <div class="order">{{ $plan['delete_order'] ? implode(' → ', $plan['delete_order']) : 'No safe delete order is currently available.' }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Counter reset preview</div><div class="card-note">Shows the exact live counter columns recognized for a future restart. No counter value is changed in this release.</div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Table</th><th style="text-align:right">Rows</th><th>Detected Columns</th><th>Proposed Restart Values</th><th>Status</th></tr></thead>
                <tbody>
                @forelse($plan['counter_reset_preview'] as $counter)
                    <tr>
                        <td><strong>{{ $counter['table'] }}</strong></td>
                        <td class="num">{{ $counter['rows'] === null ? '—' : number_format((int)$counter['rows']) }}</td>
                        <td>{{ implode(', ', $counter['columns']) }}</td>
                        <td>
                            @if(!empty($counter['proposed_updates']))
                                @foreach($counter['proposed_updates'] as $column => $value)<span class="tag reset_counter">{{ $column }}={{ $value }}</span> @endforeach
                            @else
                                <span class="tag review">No recognized restart column</span>
                            @endif
                        </td>
                        <td><span class="tag {{ $counter['ready'] ? 'preserve' : 'review' }}">{{ $counter['ready'] ? 'READY' : 'BLOCKED' }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5">No counter tables detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if(!empty($plan['counter_reset_warnings']))
            <div class="card-body"><div class="notice" style="margin:0"><strong>Counter reset warning.</strong> @foreach($plan['counter_reset_warnings'] as $warning)<div class="small">{{ $warning }}</div>@endforeach</div></div>
        @endif
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Live Day-Zero plan</div><div class="card-note">Generated from the actual connected database at page load. CLEAR and RESET COUNTER remain candidates only; no mutation occurs in this release.</div></div>
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
                    <div class="step-text">Creates a compressed JSON backup of every live table, including preserved tables. A fresh validated backup remains mandatory before any future destructive release.</div>
                    <form method="POST" action="{{ route('system.production-data-reset.backup') }}">@csrf<button class="btn primary" type="submit">Download Full Database Backup</button></form>
                    @if($backupReady)<div class="notice info" style="margin:10px 0 0">Fresh backup validated in this session: <strong>{{ $backupFilename }}</strong></div>@endif
                </div>

                <div class="step">
                    <div class="step-title">2. Destructive reset — LOCKED</div>
                    <div class="step-text">A later authorization release may enable execution only after zero REVIEW tables, zero unresolved FK blockers, no dependency cycles, a complete delete order, a valid counter reset plan and a fresh full backup.</div>
                    <div class="locked"><strong>Execution enabled:</strong> NO<br><strong>Safety preview:</strong> {{ $plan['safety_preview_ready'] ? 'PASS' : 'BLOCKED' }}<br><strong>Required phrase:</strong> {{ $plan['confirmation'] }}<br><strong>Current REVIEW tables:</strong> {{ number_format((int)$plan['review_tables']) }}<br><strong>Raw FK blockers:</strong> {{ number_format((int)$plan['raw_fk_blockers']) }}<br><strong>Unresolved FK blockers:</strong> {{ number_format((int)$plan['fk_blockers']) }}<br><strong>Dependency cycles:</strong> {{ number_format((int)$plan['dependency_cycles']) }}</div>
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
