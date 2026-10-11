<style>
.et-sidebar-report-section{display:flex!important;flex-direction:column!important;align-items:stretch!important;gap:6px!important;margin-top:12px!important}
.et-sidebar-report-section .et-sidebar-section-heading{display:block!important;line-height:1.2!important}
.et-sidebar-report-section a{display:block!important;width:100%!important}
.et-movement-tabs-card{background:#fff;border:1px solid #dfe7f0;border-radius:8px;padding:14px;margin-bottom:12px}
.et-movement-tabs{display:flex;gap:7px;align-items:center;overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch}
.et-movement-tabs a{display:inline-block;white-space:nowrap;padding:7px 10px;border:1px solid #d7e1ec;border-radius:6px;color:#315273;text-decoration:none;font-size:10px;font-weight:700;line-height:1.2}
.et-movement-tabs a.active{background:#1769d2;border-color:#1769d2;color:#fff}
.et-movement-pagination{display:flex;align-items:center;justify-content:flex-start;min-height:34px;overflow-x:auto}
.et-movement-pagination nav{display:flex;align-items:center;gap:3px}
.et-movement-pagination svg,.et-arrival-report .pagination svg,.et-makkah-report .pagination svg{width:16px!important;height:16px!important;max-width:16px;max-height:16px}
.et-movement-pagination a,.et-movement-pagination span{line-height:1.2}
@media print{.et-movement-tabs{display:none!important}.et-movement-pagination{overflow:visible}}
</style>
<nav class="et-movement-tabs-card et-movement-tabs" aria-label="Movement Reports">
 @foreach($movements as $movementKey=>$movementLabel)<a class="{{ $movementKey===$report?'active':'' }}" href="{{ route('travel-reports.group-umrah.'.$movementKey) }}">{{ $movementKey==='arrival'?'Arrival':$movementLabel }}</a>@endforeach
</nav>
