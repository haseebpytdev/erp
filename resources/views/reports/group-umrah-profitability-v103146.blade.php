@extends($erpLayout)

@section($erpTitleSection, 'Booking Profitability')

@section($erpContentSection)
@php
    $fmt = fn ($value) => number_format((float) $value, 2);
@endphp

<style>
#et-profitability{
    --p-blue:#1769d2;
    --p-green:#118a55;
    --p-red:#bd3d3d;
    --p-text:#18263b;
    --p-muted:#6f7d90;
    --p-line:#dfe7f0;
    width:100%;
    color:var(--p-text);
}
#et-profitability *{box-sizing:border-box}
#et-profitability .p-top{
    display:flex;align-items:flex-start;justify-content:space-between;
    gap:14px;margin-bottom:14px
}
#et-profitability h1{margin:0;font-size:27px;line-height:1.15}
#et-profitability .p-sub{margin-top:4px;color:var(--p-muted);font-size:12px}
#et-profitability .p-permission{
    margin-top:6px;font-size:9px;color:#7c899a
}
#et-profitability .p-actions{display:flex;gap:7px;flex-wrap:wrap}
#et-profitability .p-btn{
    display:inline-flex;align-items:center;justify-content:center;
    height:34px;padding:0 11px;border:1px solid #cfdbe8;border-radius:6px;
    background:#fff;color:#33445c;text-decoration:none;font-size:10px;font-weight:800
}
#et-profitability .p-btn-blue{background:var(--p-blue);border-color:var(--p-blue);color:#fff}
#et-profitability .p-card{
    background:#fff;border:1px solid var(--p-line);border-radius:10px;
    overflow:hidden;margin-bottom:12px
}
#et-profitability .p-card-head{
    padding:11px 14px;border-bottom:1px solid #e8edf3;
    display:flex;justify-content:space-between;gap:10px;align-items:center
}
#et-profitability .p-card-title{font-size:15px;font-weight:850}
#et-profitability .p-card-note{font-size:10px;color:var(--p-muted)}
#et-profitability .p-card-body{padding:12px 14px}
#et-profitability .p-search{
    display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:7px
}
#et-profitability .p-input{
    width:100%;height:35px;border:1px solid #d6e0eb;border-radius:6px;
    padding:7px 9px;font:inherit;font-size:11px;outline:none
}
#et-profitability .p-grid{
    display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px
}
#et-profitability .p-stat{
    border:1px solid #e1e8f0;border-radius:8px;background:#fbfcfe;padding:10px
}
#et-profitability .p-label{
    color:#768499;font-size:8.5px;text-transform:uppercase;font-weight:800
}
#et-profitability .p-value{
    margin-top:5px;color:#17243a;font-size:16px;font-weight:850;line-height:1.15
}
#et-profitability .p-value.good{color:var(--p-green)}
#et-profitability .p-value.bad{color:var(--p-red)}
#et-profitability .p-meta{
    margin-top:4px;color:#758397;font-size:9px
}
#et-profitability .p-detail-head{
    display:flex;justify-content:space-between;gap:12px;align-items:flex-start;
    padding:12px 14px;border-bottom:1px solid #e8edf3
}
#et-profitability .p-ref{font-size:17px;font-weight:850}
#et-profitability .p-name{margin-top:3px;color:#667589;font-size:10px}
#et-profitability .p-pill{
    display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;
    background:#fff4d9;color:#8a6412;font-size:8.5px;font-weight:850
}
#et-profitability .p-warning{
    margin-top:10px;border:1px solid #e8dba9;border-radius:7px;background:#fff9e7;
    padding:9px 10px;color:#6f5a1a;font-size:9.5px;line-height:1.45
}
#et-profitability .p-table-wrap{overflow:auto}
#et-profitability table{width:100%;border-collapse:collapse;min-width:1040px}
#et-profitability th,#et-profitability td{
    border-bottom:1px solid #edf1f5;padding:8px 7px;font-size:9.5px;text-align:left;
    vertical-align:middle;white-space:nowrap
}
#et-profitability th{
    color:#78869a;font-size:8px;text-transform:uppercase;letter-spacing:.03em;
    background:#fafbfd
}
#et-profitability td.num,#et-profitability th.num{text-align:right}
#et-profitability .p-booking{
    color:#1769d2;font-weight:850;text-decoration:none
}
#et-profitability .p-profit{font-weight:850;color:#118a55}
#et-profitability .p-loss{font-weight:850;color:#bd3d3d}
#et-profitability .p-empty{padding:22px;text-align:center;color:#78869a;font-size:11px}
@media(max-width:1150px){
    #et-profitability .p-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:720px){
    #et-profitability .p-top{display:block}
    #et-profitability .p-actions{margin-top:9px}
    #et-profitability .p-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    #et-profitability .p-search{grid-template-columns:1fr}
}
</style>

<div id="et-profitability">
    <div class="p-top">
        <div>
            <h1>Booking Profitability</h1>
            <div class="p-sub">
                Management-only Group Umrah revenue, supplier cost, commission and margin report.
            </div>
            <div class="p-permission">
                Access permission: {{ $permissionKey }}
            </div>
        </div>
        <div class="p-actions">
            <a class="p-btn" href="{{ url('/operations/bookings') }}">Booking Register</a>
            <a class="p-btn p-btn-blue" href="{{ route('reports.group-umrah-profitability.index') }}">Profitability Report</a>
        </div>
    </div>

    @if($selected)
        <section class="p-card">
            <div class="p-detail-head">
                <div>
                    <div class="p-ref">{{ $selected['booking_reference'] }}</div>
                    <div class="p-name">
                        {{ $selected['package_name'] ?: $selected['package_code'] }}
                        · {{ $selected['customer_name'] }}
                        · Vendor: {{ $selected['vendor_name'] }}
                    </div>
                </div>
                <span class="p-pill">{{ $selected['profit_status_label'] }} Profit</span>
            </div>

            <div class="p-card-body">
                <div class="p-grid">
                    <div class="p-stat">
                        <div class="p-label">Customer Revenue</div>
                        <div class="p-value">{{ $selected['currency'] }} {{ $fmt($selected['final_sale']) }}</div>
                        <div class="p-meta">Final customer sale</div>
                    </div>
                    <div class="p-stat">
                        <div class="p-label">Supplier Cost</div>
                        <div class="p-value">{{ $selected['currency'] }} {{ $fmt($selected['supplier_cost']) }}</div>
                        <div class="p-meta">Commercial vendor cost</div>
                    </div>
                    <div class="p-stat">
                        <div class="p-label">Gross Margin</div>
                        <div class="p-value {{ $selected['gross_margin'] >= 0 ? 'good' : 'bad' }}">
                            {{ $selected['currency'] }} {{ $fmt($selected['gross_margin']) }}
                        </div>
                        <div class="p-meta">Revenue − supplier cost</div>
                    </div>
                    <div class="p-stat">
                        <div class="p-label">Agent Commission</div>
                        <div class="p-value">{{ $selected['currency'] }} {{ $fmt($selected['agent_commission']) }}</div>
                        <div class="p-meta">Total booking commission</div>
                    </div>
                    <div class="p-stat">
                        <div class="p-label">Salesperson Commission</div>
                        <div class="p-value">{{ $selected['currency'] }} {{ $fmt($selected['salesperson_commission']) }}</div>
                        <div class="p-meta">Total booking commission</div>
                    </div>
                    <div class="p-stat">
                        <div class="p-label">Forecast Net Profit</div>
                        <div class="p-value {{ $selected['forecast_net_profit'] >= 0 ? 'good' : 'bad' }}">
                            {{ $selected['currency'] }} {{ $fmt($selected['forecast_net_profit']) }}
                        </div>
                        <div class="p-meta">{{ number_format((float)$selected['margin_pct'], 2) }}% margin</div>
                    </div>
                </div>

                <div class="p-warning">
                    <strong>Profit Status: Forecast.</strong>
                    Supplier cost and commissions shown here come from the controlled Group Umrah commercial record.
                    This page intentionally does not expose profitability on the Sales Invoice page.
                    When native Vendor Bill/AP actual-cost posting is positively integrated, this report can add a separate Actual Profit column without changing historical Sales Invoice accounting.
                </div>

                <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:10px">
                    <a class="p-btn" href="{{ route('operations.bookings.group-package-unified.edit', ['booking'=>$selected['booking_id']]) }}">Open Group Umrah</a>
                    @if(data_get($selected, 'invoice.number'))
                        <span class="p-btn" style="cursor:default">
                            Invoice: {{ data_get($selected, 'invoice.number') }}
                            @if(data_get($selected, 'invoice.status'))
                                · {{ ucfirst((string)data_get($selected, 'invoice.status')) }}
                            @endif
                        </span>
                    @endif
                </div>
            </div>
        </section>
    @endif

    <section class="p-card">
        <div class="p-card-head">
            <div>
                <div class="p-card-title">Group Umrah Profitability Register</div>
                <div class="p-card-note">Sensitive management report. Not shown to ordinary invoice-posting staff.</div>
            </div>
        </div>

        <div class="p-card-body">
            <form method="GET" action="{{ route('reports.group-umrah-profitability.index') }}" class="p-search">
                <input
                    class="p-input"
                    type="search"
                    name="q"
                    value="{{ $search }}"
                    placeholder="Search booking reference, package code or package name"
                >
                <button class="p-btn p-btn-blue" type="submit">Search</button>
            </form>
        </div>

        <div class="p-table-wrap">
            @if(count($rows))
                <table>
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>Vendor</th>
                            <th class="num">Pax</th>
                            <th class="num">Revenue</th>
                            <th class="num">Supplier Cost</th>
                            <th class="num">Gross Margin</th>
                            <th class="num">Commission</th>
                            <th class="num">Net Profit</th>
                            <th class="num">Margin %</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        @php
                            $commission = (float)$row['agent_commission'] + (float)$row['salesperson_commission'];
                        @endphp
                        <tr>
                            <td>
                                <a
                                    class="p-booking"
                                    href="{{ route('reports.group-umrah-profitability.show', ['booking'=>$row['booking_id']]) }}"
                                >
                                    {{ $row['booking_reference'] }}
                                </a>
                                <div style="margin-top:2px;color:#7d8999;font-size:8px">
                                    {{ $row['package_code'] }}
                                </div>
                            </td>
                            <td>{{ $row['customer_name'] }}</td>
                            <td>{{ $row['vendor_name'] }}</td>
                            <td class="num">{{ $row['booked_pax'] }}</td>
                            <td class="num">{{ $row['currency'] }} {{ $fmt($row['final_sale']) }}</td>
                            <td class="num">{{ $row['currency'] }} {{ $fmt($row['supplier_cost']) }}</td>
                            <td class="num">{{ $row['currency'] }} {{ $fmt($row['gross_margin']) }}</td>
                            <td class="num">{{ $row['currency'] }} {{ $fmt($commission) }}</td>
                            <td class="num {{ $row['forecast_net_profit'] >= 0 ? 'p-profit' : 'p-loss' }}">
                                {{ $row['currency'] }} {{ $fmt($row['forecast_net_profit']) }}
                            </td>
                            <td class="num">{{ number_format((float)$row['margin_pct'], 2) }}%</td>
                            <td><span class="p-pill">{{ $row['profit_status_label'] }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <div class="p-empty">No Group Umrah profitability records found.</div>
            @endif
        </div>
    </section>
</div>
@endsection
