@php
    $serverMetadata = $serverMetadata ?? [];
    $isSupplierCosting = ($config['key'] ?? '') === 'supplier-costing';
    $coverage = is_array($serverMetadata['coverage'] ?? null) ? $serverMetadata['coverage'] : [];
    $serverMode = (($serverMetadata['filter_mode'] ?? '') === 'server');
    $serverFilters = (array) ($serverMetadata['filters'] ?? []);
    $serverStatus = (string) ($serverFilters['status'] ?? '');
    $metricSpecs = [
        ['key' => 'total', 'label' => $config['total_label'], 'icon' => '▣', 'tone' => 'blue', 'value' => $counts['total']],
        ['key' => 'pending', 'label' => $config['pending_label'], 'icon' => '◷', 'tone' => 'amber', 'value' => $counts['pending']],
        ['key' => 'approved', 'label' => $config['approved_label'], 'icon' => '✓', 'tone' => 'green', 'value' => $counts['approved']],
        ['key' => 'fourth', 'label' => $config['fourth_label'], 'icon' => $config['key'] === 'bookings' ? '×' : '↗', 'tone' => $config['key'] === 'bookings' ? 'red' : 'green', 'value' => $counts['fourth']],
    ];
    $breakdownTotal = max(1, array_sum(array_column($breakdown, 'count')));
    $palette = ['#0d6fe8', '#13a06c', '#f1a528', '#7a67d8', '#e25461'];
    $gradientStops = [];
    $cursor = 0;
    foreach ($breakdown as $index => $item) {
        $next = $cursor + (($item['count'] / $breakdownTotal) * 100);
        $color = $palette[$index % count($palette)];
        $gradientStops[] = $color.' '.round($cursor, 2).'% '.round($next, 2).'%';
        $cursor = $next;
    }
    if ($gradientStops === []) $gradientStops[] = '#dfe6ef 0% 100%';
@endphp

<div class="et-reg-shell et-register-server-shell" data-et-register-workspace="ERP-11.3.239" data-register-key="{{ $config['key'] }}" @if(($serverMetadata['filter_mode'] ?? '') === 'server') data-et-register-filter-mode="server" @endif>
    <section class="et-reg-header et-page-header et-register-server-header">
        <div class="et-reg-title-block">
            <small class="et-page-kicker et-commercial-reference-kicker">{{ $config['kicker'] }}</small>
            <h1 class="et-reg-title et-page-title">{{ $config['title'] }}</h1>
            <p class="text-muted et-page-subtitle">{{ $config['subtitle'] }}</p>
        </div>
        @if($createHref !== '')
            <div><a class="et-reg-primary-action et-btn primary" href="{{ $createHref }}">+ {{ $config['key'] === 'bookings' ? 'New Booking' : 'New Supplier Cost' }}</a></div>
        @endif
    </section>

    @if($isSupplierCosting)
        <style>
            .et-supplier-costing-strip{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin:0 0 14px}
            .et-supplier-costing-status-card{min-height:88px;padding:12px 14px;border:1px solid #dfe6ef;border-radius:10px;background:#fff;box-shadow:0 2px 8px rgba(31,51,73,.05)}
            .et-supplier-costing-status-card strong{display:block;font-size:24px;line-height:1.05;color:#172b4d;font-variant-numeric:tabular-nums}
            .et-supplier-costing-status-card span{display:block;margin-top:7px;color:#40536c;font-size:11px;font-weight:800}
            .et-supplier-costing-queue-tools{display:flex;gap:10px;align-items:end;padding:10px 14px;border-bottom:1px solid #edf1f5}
            .et-supplier-costing-queue-tools label{flex:1}
            .et-supplier-costing-queue-tools input{width:100%;height:38px;padding:8px 10px;border:1px solid #d4dce6;border-radius:7px}
            .et-supplier-costing-queue .et-reg-table th,.et-supplier-costing-queue .et-reg-table td{padding:8px 10px;font-size:12px}
            .et-supplier-costing-queue .et-reg-table th:nth-last-child(3),.et-supplier-costing-queue .et-reg-table td:nth-last-child(3){text-align:center}
            .et-supplier-costing-queue .et-reg-table th:nth-last-child(2),.et-supplier-costing-queue .et-reg-table td:nth-last-child(2){text-align:right;font-variant-numeric:tabular-nums}
            .et-supplier-costing-queue-state{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef3f8;font-size:11px;font-weight:800;white-space:nowrap}
            .et-supplier-costing-booking-link{color:#0d6fe8;font-weight:700;text-decoration:none;white-space:nowrap}
            .et-supplier-costing-booking-link:hover{color:#095fc9;text-decoration:underline}
            @media(max-width:1180px){.et-supplier-costing-strip{grid-template-columns:repeat(3,minmax(0,1fr))}}
            @media(max-width:650px){.et-supplier-costing-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.et-supplier-costing-queue-tools{align-items:stretch;flex-direction:column}.et-supplier-costing-queue-tools label{flex:auto}}
            @media(max-width:360px){.et-supplier-costing-strip{grid-template-columns:1fr}}
        </style>
        <section class="et-supplier-costing-strip" aria-label="Supplier Costing status summary">
            @foreach(($coverage['cards'] ?? []) as $card)
                <article class="et-supplier-costing-status-card"><strong>{{ $card['count'] }}</strong><span>{{ $card['label'] }}</span></article>
            @endforeach
        </section>
    @else
    <section class="et-booking-ref-kpis" aria-label="Register summary">
        @foreach($metricSpecs as $metric)
            @php($caption = $metricCaptions[$metric['key']] ?? ['badge' => '0%', 'caption' => '', 'tone' => 'flat'])
            <article class="et-booking-ref-kpi et-booking-ref-kpi-{{ $metric['tone'] }}">
                <div class="et-booking-ref-kpi-icon" aria-hidden="true">{{ $metric['icon'] }}</div>
                <div class="et-booking-ref-kpi-main">
                    <div class="et-booking-ref-kpi-label">{{ $metric['label'] }}</div>
                    <div class="et-booking-ref-kpi-line">
                        <strong>{{ $metric['value'] }}</strong>
                        <span class="et-booking-ref-trend et-booking-ref-trend-{{ $caption['tone'] }}">{{ $caption['badge'] }}</span>
                    </div>
                    <div class="et-booking-ref-kpi-caption">{{ $caption['caption'] }}</div>
                </div>
            </article>
        @endforeach
    </section>
    @endif

    <section class="et-booking-ref-filter-card" data-register-filter-card>
        <div class="et-booking-ref-filter-head">
            <div class="et-booking-ref-filter-title"><span aria-hidden="true">⌕</span><strong>Search &amp; Filter</strong></div>
            <div class="et-booking-ref-quick">
                <span>Quick Filters:</span>
                @foreach($config['quick'] as $quick)
                    <button type="button" data-quick="{{ $quick[0] }}" @class(['active' => $serverMode ? (($serverStatus === '' && $quick[0] === 'all') || $serverStatus === $quick[0]) : $quick[0] === 'all'])>{{ $quick[1] }}</button>
                @endforeach
            </div>
        </div>

        <div class="et-booking-ref-filter-grid">
            <label>Search<input type="search" data-filter="search" placeholder="Search register" @if($serverMode) value="{{ $serverFilters['q'] ?? '' }}" @endif></label>
            <label>{{ $config['field2_label'] }}
                <select data-filter="group">
                    <option value="">{{ $config['field2_all'] }}</option>
                    @if(($serverMetadata['filter_mode'] ?? '') === 'server')
                        @foreach(($serverMetadata['supplier_options'] ?? []) as $supplier)<option value="{{ $supplier['id'] }}" @selected((int) ($serverFilters['supplier_id'] ?? 0) === (int) $supplier['id'])>{{ $supplier['label'] }}</option>@endforeach
                    @else
                        @foreach($groups as $group)<option value="{{ mb_strtolower($group) }}">{{ $group }}</option>@endforeach
                    @endif
                </select>
            </label>
            <label>{{ $config['field3_label'] }}
                <select data-filter="secondary">
                    <option value="">{{ $config['field3_all'] }}</option>
                    @foreach(($serverMetadata['product_options'] ?? $secondaryOptions) as $secondary)
                        <option value="{{ $serverMode ? $secondary : mb_strtolower($secondary) }}" @if($serverMode) @selected(($serverFilters['product'] ?? '') === $secondary) @endif>{{ $secondary }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ $config['key'] === 'bookings' ? 'Travel Date From' : 'Date From' }}<input type="date" data-filter="from" @if($serverMode) value="{{ $serverFilters['date_from'] ?? '' }}" @endif></label>
            <label>{{ $config['key'] === 'bookings' ? 'Travel Date To' : 'Date To' }}<input type="date" data-filter="to" @if($serverMode) value="{{ $serverFilters['date_to'] ?? '' }}" @endif></label>
            <label>Status
                <select data-filter="status">
                    <option value="">All Statuses</option>
                    @foreach($config['quick'] as $quick)
                        @if($quick[0] !== 'all')<option value="{{ $quick[0] }}" @if($serverMode) @selected($serverStatus === $quick[0]) @endif>{{ $quick[1] }}</option>@endif
                    @endforeach
                </select>
            </label>
        </div>

        <div class="et-booking-ref-filter-actions">
            <button type="button" class="et-booking-ref-apply" data-register-apply><span aria-hidden="true">⌕</span> Apply Filter</button>
            <button type="button" class="et-booking-ref-reset" data-register-reset><span aria-hidden="true">↻</span> Reset</button>
        </div>
    </section>

    <section class="et-booking-ref-register-card" data-register-table-card>
        <div class="et-booking-ref-table-head">
            <div class="et-booking-ref-table-title"><span aria-hidden="true">▦</span><strong>{{ $config['table_title'] }} (<span data-register-count>{{ $serverMetadata['total'] ?? count($rows) }}</span>)</strong></div>
            <div class="et-booking-export" data-register-export>
                <button type="button" class="et-booking-export-button" data-register-export-toggle>⇩ Export ▾</button>
                <div class="et-booking-export-menu">
                    <button type="button" data-register-export-visible>Export visible CSV</button>
                    <button type="button" data-register-export-selected>Export selected CSV</button>
                </div>
            </div>
        </div>

        <div class="et-booking-ref-table-scroll">
            <table class="et-reg-table et-register-server-table">
                <thead>
                    <tr>
                        <th class="et-booking-select-cell"><input type="checkbox" data-register-select-all aria-label="Select all visible rows"></th>
                        @foreach($headers as $header)<th>{{ $header }}</th>@endforeach
                        @if(!$isSupplierCosting)<th class="et-booking-action-head">Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr data-et-register-row
                            data-status="{{ $row['status'] }}"
                            data-group="{{ mb_strtolower($row['group']) }}"
                            data-secondary="{{ mb_strtolower($row['secondary']) }}"
                            data-date="{{ $row['date'] }}"
                            data-search="{{ $row['search'] }}"
                            data-row-id="{{ $row['id'] }}">
                            <td class="et-booking-select-cell"><input type="checkbox" data-register-row-select aria-label="Select {{ $row['id'] }}"></td>
                            @foreach($headers as $cellIndex => $header)
                                <td>
                                    @if($isSupplierCosting && mb_strtolower(trim($header)) === 'booking' && $row['href'] !== '')
                                        <a class="et-supplier-costing-booking-link" href="{{ $row['href'] }}">{!! $row['cells'][$cellIndex]['html'] ?? '' !!}</a>
                                    @else
                                        {!! $row['cells'][$cellIndex]['html'] ?? '' !!}
                                    @endif
                                </td>
                            @endforeach
                            @if(!$isSupplierCosting)
                                <td class="et-booking-action-cell">
                                    <div class="et-booking-row-menu">
                                        <button type="button" class="et-booking-row-menu-button" data-register-row-menu aria-label="Row actions">⋮</button>
                                        <div class="et-booking-row-menu-panel">
                                            @if($row['href'] !== '')
                                                <a href="{{ $row['href'] }}">{{ $config['action_text'] }}</a>
                                            @else
                                                <span>No action available</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr data-register-empty><td colspan="{{ count($headers) + ($isSupplierCosting ? 1 : 2) }}">No records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="et-booking-ref-table-footer">
            <div class="et-booking-ref-range" data-register-range>Showing {{ $serverMetadata['from'] ?? (count($rows) ? 1 : 0) }}–{{ $serverMetadata['to'] ?? min(15, count($rows)) }} of {{ $serverMetadata['total'] ?? count($rows) }}</div>
            <div class="et-booking-ref-pagination" data-register-pagination></div>
        </div>
    </section>

    @if($nativePaginatorHtml !== '')
        <div class="et-register-native-pagination" aria-label="Server result pages">{!! $nativePaginatorHtml !!}</div>
    @endif

    @if(!$isSupplierCosting)<section class="et-booking-ref-insights">
        <article class="et-booking-ref-insight-card et-booking-ref-workflow">
            <div class="et-booking-ref-insight-head"><div><span aria-hidden="true">⇄</span><strong>Quick Workflow</strong></div><small>Current lifecycle</small></div>
            <div class="et-booking-ref-workflow-track">
                @foreach($config['workflow'] as $index => $step)
                    <div class="et-booking-ref-workflow-step {{ $index === 0 ? 'active' : '' }}"><b>{{ $index + 1 }}</b><strong>{{ $step[0] }}</strong><small>{{ $step[1] }}</small></div>
                @endforeach
            </div>
        </article>

                <article class="et-booking-ref-insight-card">
                    <div class="et-booking-ref-insight-head"><div><span aria-hidden="true">◔</span><strong>{{ $config['breakdown_title'] }}</strong></div><small>{{ $serverMetadata['total'] ?? count($rows) }} records</small></div>
            <div class="et-booking-ref-type-body">
                <div class="et-booking-ref-donut" style="background:conic-gradient({{ implode(',', $gradientStops) }})"><span></span></div>
                <div class="et-booking-ref-type-legend">
                    @forelse($breakdown as $index => $item)
                        <div><i style="background:{{ $palette[$index % count($palette)] }}"></i><span>{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></div>
                    @empty
                        <small>No data available</small>
                    @endforelse
                </div>
            </div>
        </article>

        <article class="et-booking-ref-insight-card">
            <div class="et-booking-ref-insight-head"><div><span aria-hidden="true">◷</span><strong>Recent Activity</strong></div><small>Latest rows</small></div>
            <div class="et-booking-ref-activity-list">
                @forelse(($serverMetadata['recent'] ?? array_slice($rows, 0, 5)) as $row)
                    <div class="et-booking-ref-activity-row"><i class="blue"></i><div><strong>{{ $row['id'] }}</strong><small>{{ $row['status_label'] }}</small></div><time>{{ $row['date_label'] }}</time></div>
                @empty
                    <small>No recent activity.</small>
                @endforelse
            </div>
        </article>
    </section>@endif

    @if($isSupplierCosting && $coverage !== [])
        <section class="et-booking-ref-register-card et-supplier-costing-queue" data-et-supplier-costing-coverage style="margin-top:18px">
            <div class="et-booking-ref-table-head"><div class="et-booking-ref-table-title"><span aria-hidden="true">◈</span><strong>Booking Cost Queue</strong></div><small>Read-only coverage projection · operational supplier actions</small></div>
            <form class="et-supplier-costing-queue-tools" method="get" action="{{ url()->current() }}">
                @foreach(request()->except(['coverage_q','coverage_page']) as $key => $value)
                    @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
                @endforeach
                <label style="flex:1"><span style="display:block;font-size:12px;color:#6b7788;margin-bottom:4px">Find Booking</span><input type="search" name="coverage_q" value="{{ $coverage['search'] ?? '' }}" placeholder="Booking reference" style="width:100%"></label>
                <button type="submit" class="et-btn primary">Search</button>
                @if(($coverage['search'] ?? '') !== '')<a class="et-btn" href="{{ request()->fullUrlWithQuery(['coverage_q' => null, 'coverage_page' => 1]) }}">Clear</a>@endif
            </form>
            <div style="padding:12px 14px;color:#6b7788;font-size:13px">Coverage scan: eligible bookings {{ $coverage['candidate_from'] ?? 0 }}–{{ $coverage['candidate_to'] ?? 0 }} of {{ $coverage['candidate_total'] ?? 0 }} (page {{ $coverage['coverage_page'] ?? 1 }} of {{ $coverage['coverage_last_page'] ?? 1 }})</div>
            @if(($coverage['queue'] ?? []) !== [])
            <div class="et-booking-ref-table-scroll"><table class="et-reg-table"><thead><tr><th>Booking</th><th>Supplier</th><th>Products</th><th>State</th><th>Issue / Detail</th><th>Items</th><th>Amount</th><th>Action</th></tr></thead><tbody>
                    @foreach($coverage['queue'] as $item)<tr><td>{{ $item['booking_reference'] }}</td><td>{{ $item['supplier_name'] }}</td><td>{{ $item['products'] }}</td><td><span class="et-supplier-costing-queue-state">{{ $item['state_label'] }}</span></td><td>{{ $item['issue'] ?: 'Ready for supplier costing.' }}</td><td>{{ $item['items'] }}</td><td>PKR {{ number_format((float) $item['total'], 2) }}</td><td>@if($item['action'] === 'Create Draft')<form method="POST" action="{{ $item['action_url'] }}" style="display:inline">@csrf<button type="submit" class="et-btn primary">Create Draft</button></form>@else<a href="{{ $item['action_url'] }}">{{ $item['action'] }}</a>@endif</td></tr>@endforeach
                </tbody></table></div>
            @else
                <div style="padding:22px;color:#6b7788">{{ $coverage['empty_message'] ?? 'No booking supplier obligations are ready for costing.' }}</div>
            @endif
            @if(($coverage['coverage_last_page'] ?? 1) > 1)
                <nav aria-label="Booking cost queue pages" style="display:flex;justify-content:space-between;padding:14px">
                    @if(($coverage['coverage_page'] ?? 1) > 1)<a class="et-btn" href="{{ request()->fullUrlWithQuery(['coverage_page' => ($coverage['coverage_page'] - 1)]) }}">Previous</a>@else<span></span>@endif
                    @if(($coverage['coverage_page'] ?? 1) < ($coverage['coverage_last_page'] ?? 1))<a class="et-btn" href="{{ request()->fullUrlWithQuery(['coverage_page' => ($coverage['coverage_page'] + 1)]) }}">Next</a>@endif
                </nav>
            @endif
        </section>
    @endif
</div>
