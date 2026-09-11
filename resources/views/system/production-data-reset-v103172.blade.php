<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Production Transaction Reset · Easy Ticket ERP</title>
    <style>
        :root{
            --bg:#f3f6fa;
            --card:#fff;
            --text:#17243a;
            --muted:#6b7a90;
            --line:#dfe7f0;
            --blue:#1769d2;
            --green:#158754;
            --red:#bb2d3b;
            --amber:#8a5a00;
        }
        *{box-sizing:border-box}
        body{
            margin:0;
            background:var(--bg);
            color:var(--text);
            font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }
        .page{
            width:min(1480px,calc(100% - 28px));
            margin:18px auto 40px;
        }
        .topbar,.card{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:12px;
        }
        .topbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            padding:16px 18px;
            margin-bottom:14px;
        }
        .kicker{
            color:var(--blue);
            font-size:12px;
            line-height:1.2;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
        }
        h1{
            margin:4px 0 0;
            font-size:25px;
            line-height:1.15;
        }
        .sub{
            margin-top:5px;
            color:var(--muted);
            font-size:13px;
            line-height:1.45;
        }
        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:38px;
            padding:8px 13px;
            border:1px solid #d3deea;
            border-radius:7px;
            background:#fff;
            color:#26364d;
            text-decoration:none;
            font-size:12px;
            font-weight:800;
            cursor:pointer;
        }
        .btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
        .btn.danger{background:var(--red);border-color:var(--red);color:#fff}
        .btn:disabled{opacity:.5;cursor:not-allowed}
        .notice{
            margin-bottom:14px;
            padding:13px 15px;
            border:1px solid #efc3c8;
            border-radius:10px;
            background:#fff0f1;
            color:#7e1d28;
            font-size:13px;
            line-height:1.5;
        }
        .notice.success{
            border-color:#bfe3cf;
            background:#edf9f2;
            color:#17623c;
        }
        .notice.info{
            border-color:#cfe0f7;
            background:#eff6ff;
            color:#285a97;
        }
        .grid4{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:10px;
            margin-bottom:14px;
        }
        .metric{
            padding:14px;
            background:#fff;
            border:1px solid var(--line);
            border-radius:10px;
        }
        .metric-label{
            color:#66778f;
            font-size:10px;
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.04em;
        }
        .metric-value{
            margin-top:7px;
            font-size:22px;
            font-weight:800;
        }
        .metric-sub{
            margin-top:4px;
            color:var(--muted);
            font-size:10.5px;
        }
        .card{margin-bottom:14px;overflow:hidden}
        .card-head{
            padding:14px 16px;
            border-bottom:1px solid #e8edf3;
        }
        .card-title{font-size:16px;font-weight:800}
        .card-note{margin-top:3px;color:var(--muted);font-size:11px;line-height:1.45}
        .card-body{padding:14px 16px}
        .keep-grid{
            display:grid;
            grid-template-columns:repeat(3,minmax(0,1fr));
            gap:8px;
        }
        .keep{
            padding:10px 11px;
            border:1px solid #dfe7f0;
            border-radius:7px;
            background:#fbfcfe;
            font-size:11px;
            line-height:1.4;
        }
        .keep strong{display:block;margin-bottom:2px}
        .table-wrap{overflow:auto}
        table{
            width:100%;
            border-collapse:collapse;
            font-size:11px;
        }
        th,td{
            padding:9px 10px;
            border-bottom:1px solid #e9eef4;
            text-align:left;
            vertical-align:top;
        }
        th{
            background:#f5f8fc;
            color:#596b84;
            font-size:9.5px;
            text-transform:uppercase;
            letter-spacing:.035em;
        }
        td.num{text-align:right;font-variant-numeric:tabular-nums}
        .tag{
            display:inline-flex;
            padding:3px 6px;
            border-radius:999px;
            background:#eef5ff;
            color:#285f9f;
            font-size:9px;
            font-weight:800;
        }
        .tag.delete{background:#fff0f1;color:#a32532}
        .tag.counter{background:#fff7e7;color:#855b00}
        .steps{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:12px;
        }
        .step{
            padding:13px;
            border:1px solid var(--line);
            border-radius:9px;
            background:#fbfcfe;
        }
        .step-title{font-size:13px;font-weight:800}
        .step-text{margin:4px 0 12px;color:var(--muted);font-size:11px;line-height:1.5}
        label{
            display:block;
            margin:8px 0 5px;
            font-size:10px;
            font-weight:800;
        }
        input[type="text"]{
            width:100%;
            min-height:40px;
            padding:8px 10px;
            border:1px solid #cfdbe8;
            border-radius:6px;
            background:#fff;
            color:#17243a;
            font-size:12px;
        }
        .check{
            display:flex;
            align-items:flex-start;
            gap:8px;
            margin:10px 0 12px;
            color:#4c5d74;
            font-size:10.5px;
            line-height:1.45;
        }
        .check input{margin-top:2px}
        .small{color:var(--muted);font-size:10px;line-height:1.45}
        .completed{
            padding:12px;
            border:1px solid #bfe3cf;
            border-radius:8px;
            background:#edf9f2;
            color:#17623c;
            line-height:1.5;
            font-size:11px;
        }
        @media(max-width:900px){
            .grid4{grid-template-columns:repeat(2,minmax(0,1fr))}
            .keep-grid{grid-template-columns:1fr}
            .steps{grid-template-columns:1fr}
            .topbar{align-items:flex-start;flex-direction:column}
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <div class="kicker">System Maintenance</div>
            <h1>Production Transaction Reset</h1>
            <div class="sub">
                One-time cleanup of UAT/test business transactions before staff begin entering real production data.
            </div>
        </div>
        <a class="btn" href="{{ url('/system/health') }}" onclick="if(history.length>1){event.preventDefault();history.back();}">← Back</a>
    </div>

    @if(session('reset_success'))
        <div class="notice success">{{ session('reset_success') }}</div>
    @endif

    @if(session('reset_error'))
        <div class="notice">{{ session('reset_error') }}</div>
    @endif

    @if($errors->any())
        <div class="notice">
            <strong>Please correct the following:</strong>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="notice">
        <strong>This action permanently clears transactional/UAT data.</strong>
        It does not run automatically when the release is uploaded. Review the live table counts below, download the backup, and execute only once when you are ready for staff to start real entries.
    </div>

    <div class="grid4">
        <div class="metric">
            <div class="metric-label">Rows to delete</div>
            <div class="metric-value">{{ number_format((int)$plan['rows_to_delete']) }}</div>
            <div class="metric-sub">Live transactional/test rows detected</div>
        </div>
        <div class="metric">
            <div class="metric-label">Tables to clear</div>
            <div class="metric-value">{{ number_format((int)$plan['tables_to_clear']) }}</div>
            <div class="metric-sub">Full or selective cleanup</div>
        </div>
        <div class="metric">
            <div class="metric-label">Document counters</div>
            <div class="metric-value">{{ number_format((int)$plan['counter_tables']) }}</div>
            <div class="metric-sub">Recognized counters reset where present</div>
        </div>
        <div class="metric">
            <div class="metric-label">Reset state</div>
            <div class="metric-value">{{ $plan['completed'] ? 'LOCKED' : 'READY' }}</div>
            <div class="metric-sub">{{ $plan['completed'] ? 'Already completed once' : 'Requires backup + confirmation' }}</div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-title">Preserved production structure</div>
            <div class="card-note">These categories remain so staff can begin real booking/accounting work immediately after the reset.</div>
        </div>
        <div class="card-body">
            <div class="keep-grid">
                <div class="keep"><strong>Company & Organization</strong>Company profile, branding, branches/offices.</div>
                <div class="keep"><strong>Staff & Security</strong>Users, roles, permissions, sessions/security structure.</div>
                <div class="keep"><strong>Accounting Foundation</strong>Chart of Accounts, account mappings, currencies, financial years.</div>
                <div class="keep"><strong>Travel Masters</strong>Airlines, airports, hotel/travel/product/service masters and lookup data.</div>
                <div class="keep"><strong>Transporter Data</strong>Transporters, routes, vehicles and transport master records are explicitly protected.</div>
                <div class="keep"><strong>System Configuration</strong>Settings, mappings, type/status/lookup tables and migrations.</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="card-title">Live reset plan</div>
            <div class="card-note">The application inspected the current database at runtime. Only the rows listed here are in reset scope.</div>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Action</th>
                    <th>Table</th>
                    <th style="text-align:right">Rows</th>
                    <th>Why</th>
                </tr>
                </thead>
                <tbody>
                @forelse($plan['items'] as $item)
                    <tr>
                        <td>
                            <span class="tag {{ str_starts_with($item['action'],'delete') ? 'delete' : 'counter' }}">
                                {{ $item['action_label'] }}
                            </span>
                        </td>
                        <td><strong>{{ $item['table'] }}</strong></td>
                        <td class="num">{{ number_format((int)$item['rows']) }}</td>
                        <td>{{ $item['reason'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No transactional tables were detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if(!empty($plan['warnings']))
        <div class="card">
            <div class="card-head">
                <div class="card-title">Safe-default warnings</div>
                <div class="card-note">These tables were not auto-cleared because the reset could not positively classify them.</div>
            </div>
            <div class="card-body">
                @foreach($plan['warnings'] as $warning)
                    <div class="small" style="margin-bottom:6px">
                        <strong>{{ $warning['table'] }}</strong> — {{ $warning['reason'] }}
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="card">
        <div class="card-head">
            <div class="card-title">One-time execution</div>
            <div class="card-note">Backup must be generated in this same signed-in session and remains valid for two hours.</div>
        </div>
        <div class="card-body">
            @if($plan['completed'])
                <div class="completed">
                    <strong>Production reset already completed and locked.</strong><br>
                    Completed: {{ $plan['completed']['completed_at'] ?? 'Unknown' }}<br>
                    By: {{ $plan['completed']['actor_name'] ?? 'Unknown' }}<br>
                    Rows cleared: {{ number_format((int)($plan['completed']['rows_deleted'] ?? 0)) }}<br>
                    Tables cleared: {{ number_format((int)($plan['completed']['tables_cleared'] ?? 0)) }}
                    <div style="margin-top:10px">
                        <a class="btn primary" href="{{ route('system.post-reset-financial-cleanup.index') }}">Post-Reset Financial Cleanup</a>
                    </div>
                </div>
            @else
                <div class="steps">
                    <div class="step">
                        <div class="step-title">1. Download transaction backup</div>
                        <div class="step-text">
                            Creates a compressed JSON backup of every table this reset will touch. Keep the downloaded file with your final pre-production backup.
                        </div>
                        <form method="POST" action="{{ route('system.production-data-reset.backup') }}">
                            @csrf
                            <button class="btn primary" type="submit">Download Pre-Reset Backup</button>
                        </form>
                        @if($backupReady)
                            <div class="notice info" style="margin:10px 0 0">
                                Backup ready in this session:
                                <strong>{{ $backupFilename }}</strong>
                            </div>
                        @endif
                    </div>

                    <div class="step">
                        <div class="step-title">2. Clear UAT/test transactions</div>
                        <div class="step-text">
                            This clears the displayed transactional rows, resets recognized document counters, resets auto-increment identities where supported, then writes a one-time completion lock. The server independently verifies that a valid pre-reset backup exists before any delete starts.
                        </div>

                        <form method="POST" action="{{ route('system.production-data-reset.execute') }}">
                            @csrf

                            <label for="confirmation">
                                Type exactly: {{ $plan['confirmation'] }}
                            </label>
                            <input
                                id="confirmation"
                                name="confirmation"
                                type="text"
                                autocomplete="off"
                                value="{{ old('confirmation') }}"
                                placeholder="{{ $plan['confirmation'] }}"
                            >

                            <label class="check">
                                <input
                                    name="acknowledge"
                                    value="1"
                                    type="checkbox"
                                >
                                <span>I understand that bookings, passengers/test operational identities, sales invoices, journals, receipts/payments, vouchers, ticket records and other detected transactions will be permanently removed.</span>
                            </label>

                            <button
                                class="btn danger"
                                type="submit"
                                onclick="return confirm('Final confirmation: clear the displayed UAT/test transactional data now?');"
                            >
                                Reset Production Transactions
                            </button>

                            @if(!$backupReady)
                                <div class="small" style="margin-top:8px;color:#8a5a00">
                                    You can prepare the confirmation now, but the server will refuse the reset until a valid pre-reset backup has been created in this signed-in session.
                                </div>
                            @else
                                <div class="small" style="margin-top:8px;color:#17623c">
                                    Backup is ready in this session. You may complete the confirmation and execute the reset.
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
</body>
</html>
