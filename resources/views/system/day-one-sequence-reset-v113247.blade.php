<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Day-One Numbering Finalization · Easy Ticket ERP</title>
    <style>
        :root{--bg:#f3f6fa;--card:#fff;--text:#17243a;--muted:#687a92;--line:#dfe7f0;--blue:#1769d2;--green:#158754;--red:#bb2d3b;--amber:#8a5a00}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{width:min(1450px,calc(100% - 28px));margin:18px auto 40px}.topbar,.card{background:var(--card);border:1px solid var(--line);border-radius:12px}.topbar{padding:16px 18px;margin-bottom:14px}.kicker{color:var(--blue);font-size:12px;font-weight:900;letter-spacing:.05em;text-transform:uppercase}h1{margin:4px 0 0;font-size:25px}.sub{margin-top:5px;color:var(--muted);font-size:13px;line-height:1.45}.notice{margin-bottom:14px;padding:13px 15px;border:1px solid #cfe0f7;border-radius:10px;background:#eff6ff;color:#285a97;font-size:12px;line-height:1.55}.notice.success{border-color:#bfe3cf;background:#edf9f2;color:#17623c}.notice.warn{border-color:#ead59e;background:#fff9e8;color:#745100}.notice.danger{border-color:#efc3c8;background:#fff0f1;color:#7e1d28}.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:14px}.metric{padding:14px;background:#fff;border:1px solid var(--line);border-radius:10px}.metric-label{color:#66778f;font-size:10px;font-weight:900;text-transform:uppercase}.metric-value{margin-top:7px;font-size:21px;font-weight:900}.metric-sub{margin-top:4px;color:var(--muted);font-size:10.5px}.good{color:var(--green)}.bad{color:var(--red)}.card{margin-bottom:14px;overflow:hidden}.card-head{padding:14px 16px;border-bottom:1px solid #e8edf3}.card-title{font-size:16px;font-weight:900}.card-note{margin-top:3px;color:var(--muted);font-size:11px;line-height:1.45}.card-body{padding:14px 16px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:11px}th,td{padding:9px 10px;border-bottom:1px solid #e9eef4;text-align:left;vertical-align:top}th{background:#f5f8fc;color:#596b84;font-size:9.5px;text-transform:uppercase}.tag{display:inline-flex;padding:3px 7px;border-radius:999px;background:#eef5ff;color:#285f9f;font-size:9px;font-weight:900}.sequence-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}.sequence{padding:11px;border:1px solid var(--line);border-radius:8px;background:#fbfcfe}.sequence strong{display:block;font-size:11px}.sequence code{display:block;margin-top:4px;color:#1769d2;font-size:12px;font-weight:800}.formbox{padding:14px;border:1px solid var(--line);border-radius:9px;background:#fbfcfe}label{display:block;margin:8px 0 5px;font-size:11px;font-weight:900}input[type=text]{width:100%;min-height:42px;padding:8px 10px;border:1px solid #cfdbe8;border-radius:6px}.check{display:flex;gap:8px;align-items:flex-start;margin:12px 0;font-size:11px;line-height:1.45}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 14px;border:0;border-radius:7px;background:var(--blue);color:#fff;font-weight:900;cursor:pointer}.btn:disabled{opacity:.45;cursor:not-allowed}@media(max-width:1000px){.grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sequence-grid{grid-template-columns:1fr 1fr}}@media(max-width:650px){.grid,.sequence-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
@php($year = now()->format('Y'))
<div class="page">
    <div class="topbar">
        <div class="kicker">Fresh Production · Day-One Numbering Finalization</div>
        <h1>Restart Production Document Numbers From 1000</h1>
        <div class="sub">This one-time step does not delete business rows. It only normalizes approved native sequence counters and database identity/auto-increment state after the completed Day-Zero reset.</div>
    </div>

    @if(session('reset_success'))<div class="notice success">{{ session('reset_success') }}</div>@endif
    @if(session('reset_error'))<div class="notice danger">{{ session('reset_error') }}</div>@endif
    @if($errors->any())<div class="notice danger"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="notice warn"><strong>Keep staff stopped until this finishes.</strong> The service will refuse to run if any Day-Zero CLEAR table already contains a new production row.</div>

    <div class="grid">
        <div class="metric"><div class="metric-label">Day-Zero completed</div><div class="metric-value {{ $plan['day_zero_completed'] ? 'good' : 'bad' }}">{{ $plan['day_zero_completed'] ? 'YES' : 'NO' }}</div></div>
        <div class="metric"><div class="metric-label">Business CLEAR tables empty</div><div class="metric-value {{ (int)$plan['empty_business_clear_tables'] === (int)$plan['business_clear_tables'] ? 'good' : 'bad' }}">{{ number_format((int)$plan['empty_business_clear_tables']) }} / {{ number_format((int)$plan['business_clear_tables']) }}</div></div>
        <div class="metric"><div class="metric-label">Identity targets</div><div class="metric-value">{{ number_format((int)$plan['identity_target_count']) }}</div><div class="metric-sub">Will restart next inserted ID at 1000</div></div>
        <div class="metric"><div class="metric-label">Counter tables</div><div class="metric-value">{{ number_format((int)$plan['counter_tables']) }}</div><div class="metric-sub">Native numbering counters</div></div>
        <div class="metric"><div class="metric-label">Ready</div><div class="metric-value {{ $plan['ready'] ? 'good' : 'bad' }}">{{ $plan['ready'] ? 'PASS' : 'BLOCKED' }}</div></div>
    </div>

    @if(!empty($plan['blockers']))<div class="notice danger"><strong>Sequence reset blockers:</strong>@foreach($plan['blockers'] as $item)<div>{{ $item }}</div>@endforeach</div>@endif
    @if(!empty($plan['warnings']))<div class="notice danger"><strong>Sequence reset warnings:</strong>@foreach($plan['warnings'] as $item)<div>{{ $item }}</div>@endforeach</div>@endif

    <div class="notice">
        <strong>Runtime telemetry excluded from business-data gate:</strong>
        @foreach($plan['runtime_telemetry'] as $telemetry)
            <div>{{ $telemetry['table'] }} — {{ !$telemetry['present'] ? 'not present' : ($telemetry['rows'] === null ? 'row count unavailable' : number_format((int)$telemetry['rows']).' row(s)') }}</div>
        @endforeach
        <div>These rows are generated by normal post-reset login/audit activity. They are not production booking or accounting transactions, are not deleted, and their identities are not reseeded.</div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Expected Day-One document numbering</div><div class="card-note">The first new production document should use sequence 1000. Prefix/year rules remain unchanged.</div></div>
        <div class="card-body">
            <div class="sequence-grid">
                <div class="sequence"><strong>Booking</strong><code>BK-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Native Sales Invoice</strong><code>SI-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Group Umrah Sales Invoice</strong><code>ET-SI-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Client / Group Voucher</strong><code>ET-UV-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Receipt Voucher</strong><code>RV-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Payment Voucher</strong><code>PV-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Expense Voucher</strong><code>EV-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Contra Voucher</strong><code>CV-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Customer Advance Receipt</strong><code>CAR-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Supplier Advance Payment</strong><code>SAP-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Advance Adjustment</strong><code>AA-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Supplier Costing</strong><code>SC-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Native Journal</strong><code>JV-{{ $year }}-1000</code></div>
                <div class="sequence"><strong>Voucher Posting Reference</strong><code>CVPOST-{{ now()->format('Ymd') }}-1000</code></div>
                <div class="sequence"><strong>Adjustment Posting Reference</strong><code>AAPOST-{{ now()->format('Ymd') }}-1000</code></div>
                <div class="sequence"><strong>Supplier Cost Posting Reference</strong><code>SCPOST-{{ now()->format('Ymd') }}-1000</code></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Identity / auto-increment targets</div><div class="card-note">Only empty business Day-Zero CLEAR tables with database-managed identities are included.</div></div>
        <div class="table-wrap"><table><thead><tr><th>Table</th><th>Identity Column</th><th>Driver</th><th>Next Value</th></tr></thead><tbody>
        @forelse($plan['identity_targets'] as $target)
            <tr><td><strong>{{ $target['table'] }}</strong></td><td>{{ $target['column'] }}</td><td>{{ $target['driver'] }}</td><td><span class="tag">1000</span></td></tr>
        @empty
            <tr><td colspan="4">No identity targets detected.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">Native counter normalization</div><div class="card-note">These are the same counter values already approved by the Day-Zero planner and are normalized again before Day 1.</div></div>
        <div class="table-wrap"><table><thead><tr><th>Table</th><th>Rows</th><th>Proposed Values</th><th>Status</th></tr></thead><tbody>
        @forelse($plan['counter_preview'] as $counter)
            <tr><td><strong>{{ $counter['table'] }}</strong></td><td>{{ number_format((int)$counter['rows']) }}</td><td>@foreach((array)$counter['proposed_updates'] as $column=>$value)<span class="tag">{{ $column }}={{ $value }}</span> @endforeach</td><td>{{ !empty($counter['ready']) ? 'READY' : 'BLOCKED' }}</td></tr>
        @empty
            <tr><td colspan="4">No native counter tables detected.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div class="card">
        <div class="card-head"><div class="card-title">One-time Day-One authorization</div><div class="card-note">This action is permanently locked after successful completion.</div></div>
        <div class="card-body">
            @if($plan['completed'])
                <div class="notice success" style="margin:0"><strong>Day-One numbering is permanently finalized.</strong> Completed {{ $plan['completed']['completed_at'] ?? 'unknown' }} by {{ $plan['completed']['actor_name'] ?? 'unknown' }}.</div>
            @elseif($plan['ready'])
                <div class="formbox">
                    <form method="POST" action="{{ route('system.production-data-reset.execute') }}" onsubmit="return confirm('FINAL CHECK: Reset approved Day-One document identities and counters so new numbering starts from 1000?');">
                        @csrf
                        <label for="confirmation">Type exactly: {{ $plan['confirmation'] }}</label>
                        <input id="confirmation" type="text" name="confirmation" value="{{ old('confirmation') }}" autocomplete="off">
                        <label class="check"><input type="checkbox" name="acknowledge" value="1"><span>I confirm no staff have entered new production business data since the Day-Zero reset.</span></label>
                        <button class="btn" type="submit">FINALIZE DAY-ONE NUMBERS FROM 1000</button>
                    </form>
                </div>
            @else
                <div class="notice danger" style="margin:0"><strong>Day-One numbering is blocked.</strong> Resolve all blockers before attempting sequence normalization.</div>
            @endif
        </div>
    </div>
</div>
</body>
</html>
