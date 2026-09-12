@php
    $fmt = static fn ($value) => number_format((int) $value);
    $actionClass = static fn ($action) => match ($action) {
        'clear' => 'danger',
        'reset_counter' => 'warning',
        'preserve' => 'success',
        default => 'secondary',
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Day-Zero Database Reset</title>
    <style>
        :root{color-scheme:light;--ink:#15233a;--muted:#607491;--line:#d7e1ed;--panel:#fff;--bg:#f5f8fc;--danger:#bf3443;--danger-bg:#fff0f1;--success:#16804f;--success-bg:#edf9f3;--warning:#9a6700;--warning-bg:#fff8e6;--secondary:#5c6678;--secondary-bg:#f1f3f6;--blue:#185fb6;--blue-bg:#edf5ff}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.45 Arial,Helvetica,sans-serif}.wrap{max-width:1800px;margin:0 auto;padding:20px}.panel{background:var(--panel);border:1px solid var(--line);border-radius:16px;box-shadow:0 8px 24px rgba(25,48,78,.06);margin-bottom:18px;overflow:hidden}.head{padding:24px}.head h1{margin:0 0 8px;font-size:30px}.head p{margin:0;color:var(--muted)}.banner{margin-top:18px;padding:14px 16px;border:1px solid #fac2c8;border-radius:12px;background:var(--danger-bg);color:#7f1f2a;font-weight:700}.metrics{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:14px;margin-top:20px}.metric{border:1px solid var(--line);border-radius:12px;padding:16px;background:#fff}.metric strong{display:block;font-size:29px;margin-bottom:6px}.metric span{color:var(--muted)}.section-title{padding:20px 22px 0}.section-title h2{margin:0 0 5px;font-size:21px}.section-title p{margin:0;color:var(--muted)}.audit-grid{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:14px;padding:20px 22px}.audit-card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fbfcfe}.audit-card b{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;margin-bottom:10px}.audit-card strong{font-size:24px}.ok{color:var(--success)}.bad{color:var(--danger)}.warn{color:var(--warning)}.notice{margin:0 22px 16px;padding:16px 18px;border-radius:12px;border:1px solid var(--line);background:#fbfcfe}.notice.danger{border-color:#fac2c8;background:var(--danger-bg)}.notice.success{border-color:#b8e6cf;background:var(--success-bg)}.notice.warning{border-color:#f0d38b;background:var(--warning-bg)}.notice.info{border-color:#bfd8f7;background:var(--blue-bg)}.notice h3{margin:0 0 7px;font-size:18px}.notice p{margin:3px 0;color:var(--muted)}.mono{font-family:Consolas,Monaco,monospace;font-size:13px}.table-wrap{overflow:auto;padding:16px 22px 22px}table{width:100%;border-collapse:collapse;min-width:980px}th,td{padding:11px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{background:#f7f9fc;font-size:12px;text-transform:uppercase;color:var(--muted);position:sticky;top:0}.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase}.badge-danger{background:var(--danger-bg);color:var(--danger)}.badge-success{background:var(--success-bg);color:var(--success)}.badge-warning{background:var(--warning-bg);color:var(--warning)}.badge-secondary{background:var(--secondary-bg);color:var(--secondary)}.actions{display:flex;gap:12px;flex-wrap:wrap;padding:20px 22px 24px}.btn{display:inline-block;border:0;border-radius:10px;padding:11px 15px;font-weight:700;text-decoration:none;cursor:pointer}.btn-primary{background:#1558a6;color:#fff}.btn-danger{background:#ad2635;color:#fff}.btn[disabled]{opacity:.45;cursor:not-allowed}.input{width:100%;max-width:440px;padding:11px 12px;border:1px solid var(--line);border-radius:9px;background:#f7f9fc}.small{font-size:13px;color:var(--muted)}ul.compact{margin:8px 0 0;padding-left:18px}ul.compact li{margin:4px 0}@media(max-width:1100px){.metrics{grid-template-columns:repeat(2,1fr)}.audit-grid{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>
<div class="wrap">
    <section class="panel">
        <div class="head">
            <h1>Day-Zero Database Reset</h1>
            <p>ERP-11.3.245 FK Resolution Preview. Runtime inspection only; destructive execution remains locked.</p>
            <div class="banner">No delete, update, truncate, counter reset or FK neutralization can run from this preview build.</div>

            <div class="metrics">
                <div class="metric"><strong>{{ $fmt($plan['rows_to_clear'] ?? 0) }}</strong><span>Known UAT/business/runtime rows</span></div>
                <div class="metric"><strong>{{ $fmt($plan['clear_tables'] ?? 0) }}</strong><span>Would be emptied after approval</span></div>
                <div class="metric"><strong>{{ $fmt($plan['counter_tables'] ?? 0) }}</strong><span>Would restart numbering</span></div>
                <div class="metric"><strong>{{ $fmt($plan['preserved_tables'] ?? 0) }}</strong><span>Security/system foundation</span></div>
                <div class="metric"><strong>{{ $fmt($plan['review_tables'] ?? 0) }}</strong><span>Must remain zero</span></div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="section-title">
            <h2>Execution safety audit</h2>
            <p>Read-only runtime inspection. This section does not delete, update, truncate, neutralize or reset any table.</p>
        </div>

        <div class="audit-grid">
            <div class="audit-card"><b>FK relationships</b><strong>{{ $fmt($plan['fk_relationships'] ?? 0) }}</strong></div>
            <div class="audit-card"><b>Raw FK blockers</b><strong class="{{ ($plan['raw_fk_blockers'] ?? 0) > 0 ? 'warn' : 'ok' }}">{{ $fmt($plan['raw_fk_blockers'] ?? 0) }}</strong></div>
            <div class="audit-card"><b>Unresolved blockers</b><strong class="{{ ($plan['fk_blockers'] ?? 0) > 0 ? 'bad' : 'ok' }}">{{ $fmt($plan['fk_blockers'] ?? 0) }}</strong></div>
            <div class="audit-card"><b>Dependency cycles</b><strong class="{{ ($plan['dependency_cycles'] ?? 0) > 0 ? 'bad' : 'ok' }}">{{ $fmt($plan['dependency_cycles'] ?? 0) }}</strong></div>
            <div class="audit-card"><b>Dependency plan</b><strong class="{{ !empty($plan['dependency_plan_ready']) ? 'ok' : 'bad' }}">{{ !empty($plan['dependency_plan_ready']) ? 'READY' : 'BLOCKED' }}</strong></div>
            <div class="audit-card"><b>Counter reset plan</b><strong class="{{ !empty($plan['counter_reset_ready']) ? 'ok' : 'bad' }}">{{ !empty($plan['counter_reset_ready']) ? 'PASS' : 'BLOCKED' }}</strong></div>
        </div>

        @if(!empty($plan['raw_fk_blocker_items']))
            <div class="notice info">
                <h3>Raw preserved-to-clear FK relationships</h3>
                @foreach($plan['raw_fk_blocker_items'] as $item)
                    <p><strong>{{ $item['child_table'] }}.{{ $item['child_column'] }}</strong> → {{ $item['parent_table'] }}.{{ $item['parent_column'] }}</p>
                @endforeach
            </div>
        @endif

        @if(!empty($plan['neutralization_preview']))
            <div class="notice {{ empty($plan['neutralization_warnings']) ? 'success' : 'warning' }}">
                <h3>Nullable FK neutralization preview</h3>
                @foreach($plan['neutralization_preview'] as $item)
                    <p>
                        <strong>{{ $item['child_table'] }}.{{ $item['child_column'] }}</strong> → {{ $item['parent_table'] }}.{{ $item['parent_column'] }}
                        · nullable={{ ($item['nullable'] ?? null) === true ? 'YES' : (($item['nullable'] ?? null) === false ? 'NO' : 'UNKNOWN') }}
                        · status={{ strtoupper($item['status'] ?? 'UNKNOWN') }}
                        · {{ $item['operation'] ?? '' }}
                    </p>
                @endforeach
            </div>
        @endif

        @if(!empty($plan['fk_blocker_items']))
            <div class="notice danger">
                <h3>Unresolved foreign-key blockers remain.</h3>
                @foreach($plan['fk_blocker_items'] as $item)
                    <p><strong>{{ $item['child_table'] }}.{{ $item['child_column'] }}</strong> → {{ $item['parent_table'] }}.{{ $item['parent_column'] }} · preserved/non-clear table still references a CLEAR parent.</p>
                @endforeach
            </div>
        @endif

        @if(!empty($plan['neutralization_warnings']))
            <div class="notice danger">
                <h3>FK neutralization warnings</h3>
                @foreach($plan['neutralization_warnings'] as $item)
                    <p><strong>{{ $item['table'] }}.{{ $item['column'] }}</strong> · {{ $item['reason'] }}</p>
                @endforeach
            </div>
        @endif

        @if(!empty($plan['dependency_cycle_edges']))
            <div class="notice {{ ($plan['dependency_cycles'] ?? 0) > 0 ? 'danger' : 'success' }}">
                <h3>Dependency cycle edge audit</h3>
                @foreach($plan['dependency_cycle_edges'] as $edge)
                    <p>
                        <strong>{{ $edge['child_table'] }}.{{ $edge['child_column'] }}</strong> → {{ $edge['parent_table'] }}.{{ $edge['parent_column'] }}
                        · nullable={{ ($edge['nullable'] ?? null) === true ? 'YES' : (($edge['nullable'] ?? null) === false ? 'NO' : 'UNKNOWN') }}
                        · breakable={{ !empty($edge['can_break_with_null']) ? 'YES' : 'NO' }}
                        · {{ $edge['operation'] ?? '' }}
                    </p>
                @endforeach
            </div>
        @endif

        @if(!empty($plan['dependency_cycle_tables']))
            <div class="notice danger"><h3>Dependency cycle remains.</h3><p>{{ implode(', ', $plan['dependency_cycle_tables']) }}</p></div>
        @elseif(!empty($plan['dependency_cycle_edges']))
            <div class="notice success"><h3>Dependency cycles resolved in preview.</h3><p>Only schema-proven nullable cycle edges were removed from the effective ordering graph. No live data was changed.</p></div>
        @endif

        <div class="section-title">
            <h2>Safe child-before-parent delete order preview</h2>
            <p>Every table currently classified CLEAR must appear exactly once here before a destructive release can be considered.</p>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th>Table</th></tr></thead>
                <tbody>
                @forelse(($plan['delete_order'] ?? []) as $index => $table)
                    <tr><td>{{ $index + 1 }}</td><td class="mono">{{ $table }}</td></tr>
                @empty
                    <tr><td colspan="2">No safe delete order is currently available.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-title"><h2>Live table classification</h2><p>Every connected table is classified at runtime. Anything unknown fails closed to REVIEW.</p></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Table</th><th>Action</th><th>Rows</th><th>Reason</th></tr></thead>
                <tbody>
                @foreach(($plan['items'] ?? []) as $item)
                    <tr>
                        <td class="mono">{{ $item['table'] }}</td>
                        <td><span class="badge badge-{{ $actionClass($item['action']) }}">{{ $item['action_label'] }}</span></td>
                        <td>{{ $item['rows'] === null ? 'UNKNOWN' : $fmt($item['rows']) }}</td>
                        <td>{{ $item['reason'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-title"><h2>Counter reset preview</h2><p>Only recognized numbering columns may be reset in a future authorized destructive release.</p></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Table</th><th>Recognized columns</th><th>Preview</th></tr></thead>
                <tbody>
                @forelse(($plan['counter_reset_preview'] ?? []) as $item)
                    <tr><td class="mono">{{ $item['table'] }}</td><td>{{ implode(', ', $item['columns']) }}</td><td>{{ $item['preview'] }}</td></tr>
                @empty
                    <tr><td colspan="3">No counter-reset table is currently classified.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel">
        <div class="section-title"><h2>Backup and destructive gate</h2><p>A fresh full database backup is required, but this release cannot execute Day-Zero deletion.</p></div>
        <div class="actions">
            <form method="post" action="{{ route('system.production-data-reset.backup') }}">@csrf<button class="btn btn-primary" type="submit">Download Fresh Full Backup</button></form>
        </div>
        <div style="padding:0 22px 24px">
            <p><strong>Execution enabled:</strong> NO</p>
            <p><strong>Confirmation phrase:</strong> <span class="mono">{{ $plan['confirmation'] ?? 'RESET ERP TO DAY ZERO' }}</span></p>
            <p><strong>Unresolved FK blockers:</strong> {{ $fmt($plan['fk_blockers'] ?? 0) }}</p>
            <p><strong>Dependency cycles:</strong> {{ $fmt($plan['dependency_cycles'] ?? 0) }}</p>
            <p><strong>Needs review:</strong> {{ $fmt($plan['review_tables'] ?? 0) }}</p>
            <form method="post" action="{{ route('system.production-data-reset.execute') }}">
                @csrf
                <input class="input" type="text" name="confirmation" value="{{ old('confirmation') }}" disabled>
                <button class="btn btn-danger" type="submit" disabled>Day-Zero Reset Locked</button>
            </form>
            <p class="small">The historical ProductionDataResetService completion lock is not reopened. ERP-11.3.245 remains preview-only.</p>
        </div>
    </section>
</div>
</body>
</html>
