@php
    $filters = $filters ?? [];
    $actions = $actions ?? [];
    $formAction = $formAction ?? null;
@endphp
<style>
.et-report-filter-card{width:100%;background:#fff;border:1px solid #dfe7f0;border-radius:8px;padding:14px;margin-bottom:12px;display:block!important}.et-report-filter-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;align-items:end}.et-report-filter-grid{display:contents}.et-report-filter-field{display:flex;flex-direction:column;gap:4px;font-size:11px;font-weight:700;color:#52627a;min-width:0}.et-report-filter-field input,.et-report-filter-field select{width:100%;box-sizing:border-box;padding:7px;border:1px solid #cdd8e5;border-radius:5px;background:#fff}.et-report-filter-actions{grid-column:1/-1;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.et-report-filter-actions button,.et-report-filter-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:7px 12px;border:1px solid #1769d2;border-radius:5px;background:#1769d2;color:#fff;text-decoration:none;font-size:11px;font-weight:700}.et-report-filter-actions .secondary{background:#fff;color:#315273;border-color:#cdd8e5}@media(max-width:760px){.et-report-filter-form{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:430px){.et-report-filter-form{grid-template-columns:1fr}.et-report-filter-actions>*{flex:1 1 auto}}
</style>
<div class="card et-report-filter-card print-hide">
    <form class="et-report-filter-form" method="get" @if($formAction) action="{{ $formAction }}" @endif>
        <div class="et-report-filter-grid">
            @foreach($filters as $filter)
                <label class="et-report-filter-field">{{ $filter['label'] }}<input type="{{ $filter['type'] ?? 'text' }}" name="{{ $filter['key'] }}" value="{{ request($filter['key']) }}"></label>
            @endforeach
        </div>
        <div class="et-report-filter-actions">
            @foreach($actions as $action)
                @if(($action['type'] ?? 'button') === 'link')
                    <a class="{{ $action['class'] ?? '' }}" href="{{ $action['href'] ?? '#' }}">{{ $action['label'] }}</a>
                @else
                    <button class="{{ $action['class'] ?? '' }}" type="{{ $action['type'] ?? 'button' }}" @if(!empty($action['onclick'])) onclick="{{ $action['onclick'] }}" @endif>{{ $action['label'] }}</button>
                @endif
            @endforeach
        </div>
    </form>
</div>
