@php
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

<div class="et-reg-shell et-register-server-shell" data-et-register-workspace="ERP-11.3.239" data-register-key="{{ $config['key'] }}">
    <section class="et-reg-header et-register-server-header">
        <div class="et-reg-title-block">
            <small class="et-commercial-reference-kicker">{{ $config['kicker'] }}</small>
            <h1 class="et-reg-title">{{ $config['title'] }}</h1>
            <p class="text-muted">{{ $config['subtitle'] }}</p>
        </div>
        @if($createHref !== '')
            <div><a class="et-reg-primary-action" href="{{ $createHref }}">+ {{ $config['key'] === 'bookings' ? 'New Booking' : 'New Supplier Cost' }}</a></div>
        @endif
    </section>

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

    <section class="et-booking-ref-filter-card" data-register-filter-card>
        <div class="et-booking-ref-filter-head">
            <div class="et-booking-ref-filter-title"><span aria-hidden="true">⌕</span><strong>Search &amp; Filter</strong></div>
            <div class="et-booking-ref-quick">
                <span>Quick Filters:</span>
                @foreach($config['quick'] as $quick)
                    <button type="button" data-quick="{{ $quick[0] }}" @class(['active' => $quick[0] === 'all'])>{{ $quick[1] }}</button>
                @endforeach
            </div>
        </div>

        <div class="et-booking-ref-filter-grid">
            <label>Search<input type="search" data-filter="search" placeholder="Search register"></label>
            <label>{{ $config['field2_label'] }}
                <select data-filter="group">
                    <option value="">{{ $config['field2_all'] }}</option>
                    @foreach($groups as $group)
                        <option value="{{ mb_strtolower($group) }}">{{ $group }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ $config['field3_label'] }}
                <select data-filter="secondary">
                    <option value="">{{ $config['field3_all'] }}</option>
                    @foreach($secondaryOptions as $secondary)
                        <option value="{{ mb_strtolower($secondary) }}">{{ $secondary }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ $config['key'] === 'bookings' ? 'Travel Date From' : 'Date From' }}<input type="date" data-filter="from"></label>
            <label>{{ $config['key'] === 'bookings' ? 'Travel Date To' : 'Date To' }}<input type="date" data-filter="to"></label>
            <label>Status
                <select data-filter="status">
                    <option value="">All Statuses</option>
                    @foreach($config['quick'] as $quick)
                        @if($quick[0] !== 'all')<option value="{{ $quick[0] }}">{{ $quick[1] }}</option>@endif
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
            <div class="et-booking-ref-table-title"><span aria-hidden="true">▦</span><strong>{{ $config['table_title'] }} (<span data-register-count>{{ count($rows) }}</span>)</strong></div>
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
                        <th class="et-booking-action-head">Action</th>
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
                                    @if(str_contains(mb_strtolower($header), 'status'))
                                        <span class="et-register-status et-register-status-{{ $row['status'] }}">{{ $row['status_label'] }}</span>
                                    @else
                                        {!! $row['cells'][$cellIndex]['html'] ?? '' !!}
                                    @endif
                                </td>
                            @endforeach
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
                        </tr>
                    @empty
                        <tr data-register-empty><td colspan="{{ count($headers) + 2 }}">No records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="et-booking-ref-table-footer">
            <div class="et-booking-ref-range" data-register-range>Showing {{ count($rows) ? 1 : 0 }}–{{ min(15, count($rows)) }} of {{ count($rows) }}</div>
            <div class="et-booking-ref-pagination" data-register-pagination></div>
        </div>
    </section>

    <section class="et-booking-ref-insights">
        <article class="et-booking-ref-insight-card">
            <div class="et-booking-ref-insight-head"><strong>Quick Workflow</strong><span>Current lifecycle</span></div>
            <div class="et-register-workflow-list">
                @foreach($config['workflow'] as $index => $step)
                    <div class="et-register-workflow-step"><span>{{ $index + 1 }}</span><div><strong>{{ $step[0] }}</strong><small>{{ $step[1] }}</small></div></div>
                @endforeach
            </div>
        </article>

        <article class="et-booking-ref-insight-card">
            <div class="et-booking-ref-insight-head"><strong>{{ $config['breakdown_title'] }}</strong><span>{{ count($rows) }} records</span></div>
            <div class="et-register-breakdown">
                <div class="et-register-donut" style="background:conic-gradient({{ implode(',', $gradientStops) }})"><span>{{ count($rows) }}</span></div>
                <div class="et-register-breakdown-list">
                    @forelse($breakdown as $index => $item)
                        <div><i style="background:{{ $palette[$index % count($palette)] }}"></i><span>{{ $item['label'] }}</span><strong>{{ $item['count'] }}</strong></div>
                    @empty
                        <small>No data available</small>
                    @endforelse
                </div>
            </div>
        </article>

        <article class="et-booking-ref-insight-card">
            <div class="et-booking-ref-insight-head"><strong>Recent Activity</strong><span>Latest rows</span></div>
            <div class="et-register-activity-list">
                @forelse(array_slice($rows, 0, 5) as $row)
                    <div class="et-register-activity"><span>•</span><div><strong>{{ $row['id'] }}</strong><small>{{ $row['status_label'] }}{{ $row['date_label'] ? ' · '.$row['date_label'] : '' }}</small></div></div>
                @empty
                    <small>No recent activity.</small>
                @endforelse
            </div>
        </article>
    </section>
</div>
