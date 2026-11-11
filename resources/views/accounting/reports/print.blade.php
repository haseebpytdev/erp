<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $report['title'] }}</title>
<style>
@page{size:A4 portrait;margin:0}
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#fff;color:#101828;font-family:Arial,Helvetica,sans-serif;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
body{padding:7mm}
a{color:#0b63ce}
table{width:100%;border-collapse:collapse}
.table-link{color:#0b63ce;text-decoration:none}.muted-copy{color:#667085;font-size:11px}.report-paper{width:100%;max-width:none;margin:0 auto}.empty-state{padding:24px;text-align:center}
.print-toolbar{display:flex;justify-content:flex-end;gap:8px;margin:0 0 10px}.print-toolbar button,.print-toolbar a{border:1px solid #cbd5e1;background:#fff;padding:8px 12px;border-radius:6px;font-weight:700;text-decoration:none;color:#0f274b;cursor:pointer}
@media print{
  html,body{width:100%;height:auto;margin:0!important;padding:0!important}
  body{padding:6mm!important}
  .no-print{display:none!important}
  a{color:#111!important;text-decoration:none!important}
}
</style>
</head>
<body>
<div class="no-print print-toolbar">
    <button onclick="window.print()">Print</button>
    <a href="{{ route('accounting.reports.pdf',request()->query()) }}">Download Clean PDF</a>
</div>
@include('accounting.reports.partials.report-table',['report'=>$report])
@if($autoPrint??false)<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script>@endif
</body>
</html>
