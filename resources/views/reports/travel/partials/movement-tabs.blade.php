<style>
.et-sidebar-report-section{display:flex!important;flex-direction:column!important;align-items:stretch!important;gap:6px!important;margin-top:12px!important}
.et-sidebar-report-section .et-sidebar-section-heading{display:block!important;line-height:1.2!important}
.et-sidebar-report-section a{display:block!important;width:100%!important}
.et-arrival-tabs{display:flex;gap:7px;align-items:center;overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch}
body.et-ui-professional .et-arrival-tabs a{white-space:nowrap;background:#ffffff;padding:7px 12px;border:1px solid #e2e8f0;border-radius:6px;color:#172033;text-decoration:none;font-size:10px;font-weight:700;transition:background-color .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease}
body.et-ui-professional .et-arrival-tabs a:not(.active):hover{background:#f5f7fa;border-color:#cbd5e1;color:#172033;box-shadow:0 1px 3px rgba(15,45,91,.08);cursor:pointer}
body.et-ui-professional .et-arrival-tabs a.active{background:#ffffff;border-color:#2563eb;color:#2563eb;box-shadow:0 1px 3px rgba(37,99,235,.10)}
body.et-ui-professional .et-arrival-tabs a.active:hover{background:#ffffff;border-color:#2563eb;color:#2563eb;box-shadow:0 1px 3px rgba(37,99,235,.10)}
.et-movement-pagination{display:flex;align-items:center;justify-content:flex-start;min-height:34px;overflow-x:auto}
.et-movement-pagination nav{display:flex;align-items:center;gap:3px}
.et-movement-pagination svg,.et-arrival-report .pagination svg,.et-makkah-report .pagination svg{width:16px!important;height:16px!important;max-width:16px;max-height:16px}
.et-movement-pagination a,.et-movement-pagination span{line-height:1.2}
@media print{.et-arrival-tabs{display:none!important}.et-movement-pagination{overflow:visible}}
</style>
<nav class="et-arrival-card et-arrival-tabs" aria-label="Movement Reports">
 @foreach($movements as $movementKey=>$movementLabel)<a class="{{ $movementKey===$report?'active':'' }}" href="{{ route('travel-reports.group-umrah.'.$movementKey) }}">{{ $movementKey==='arrival'?'Arrival':$movementLabel }}</a>@endforeach
</nav>
