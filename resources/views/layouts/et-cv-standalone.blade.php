<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Client Voucher') · {{ config('erp.product_name', 'Easy Ticket Travel ERP') }}</title>
    <link rel="stylesheet" href="/assets/app.css?v={{ config('erp.version') }}">
    <style>
        :root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f3f7fb;color:#17243a;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.etcv-standalone-shell{min-height:100vh}.etcv-standalone-header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.5rem;background:#0b2340;color:#fff;border-bottom:4px solid #1769e0}.etcv-brand{display:flex;align-items:center;gap:.75rem}.etcv-brand-mark{display:flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:10px;background:#fff;color:#d72d2d;font-weight:900;font-size:1.1rem}.etcv-brand-name{font-size:1.05rem;font-weight:800}.etcv-brand-sub{font-size:.72rem;opacity:.78;margin-top:.12rem}.etcv-standalone-main{width:100%;padding:1rem 0 2rem}.etcv-standalone-main>.content{width:100%}@media(max-width:640px){.etcv-standalone-header{padding:.85rem 1rem}.etcv-brand-name{font-size:.95rem}}
    </style>
    @stack('styles')
</head>
<body class="erp-body et-cv-standalone">
<div class="etcv-standalone-shell">
    <header class="etcv-standalone-header" aria-label="Easy Ticket client voucher editor">
        <div class="etcv-brand"><div class="etcv-brand-mark">ET</div><div><div class="etcv-brand-name">Easy Ticket</div><div class="etcv-brand-sub">Client Voucher Editor</div></div></div>
        <div aria-hidden="true"></div>
    </header>
    <main class="etcv-standalone-main"><section class="content">@yield('content')</section></main>
</div>
@stack('scripts')
</body>
</html>
