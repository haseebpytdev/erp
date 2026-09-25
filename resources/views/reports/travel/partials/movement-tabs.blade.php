<style>
.et-sidebar-report-section{display:flex!important;flex-direction:column!important;align-items:stretch!important;gap:6px!important;margin-top:12px!important}
.et-sidebar-report-section .et-sidebar-section-heading{display:block!important;line-height:1.2!important}
.et-sidebar-report-section a{display:block!important;width:100%!important}
</style>
<nav class="et-arrival-card et-arrival-tabs" aria-label="Movement Reports">
 @foreach($movements as $movementKey=>$movementLabel)<a class="{{ $movementKey===$report?'active':'' }}" href="{{ route('travel-reports.group-umrah.'.$movementKey) }}">{{ $movementKey==='arrival'?'Arrival':$movementLabel }}</a>@endforeach
</nav>
