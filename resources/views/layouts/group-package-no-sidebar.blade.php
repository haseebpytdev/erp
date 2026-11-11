<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Travel ERP') · {{ config('erp.product_name') }}</title>
    <link rel="stylesheet" href="/assets/app.css?v={{ config('erp.version') }}">
    @stack('styles')
    <style>
        /* Same native shell contract, with the sidebar column omitted server-side. */
        .et-group-package-no-sidebar-shell{display:block!important;width:100%!important;min-width:0!important}
        .et-group-package-no-sidebar-shell>.main{width:100%!important;min-width:0!important;margin:0!important}
        .et-group-package-no-sidebar-shell>.main>.content{width:calc(100% - 32px)!important;max-width:1280px!important;min-width:0!important;margin:0 auto!important;padding:10px 0 24px!important;box-sizing:border-box!important}
    </style>
</head>
<body class="erp-body">
<div class="app-shell et-group-package-no-sidebar-shell">
    <main class="main">
        <header class="topbar">
            <div>
                <div class="top-title">@yield('page_title', 'Dashboard')</div>
                <div class="top-meta">
                    {{ $erpCompany?->name ?? 'Company not configured' }}
                    @if($erpBranch) · {{ auth()->user()->primaryBranch()?->name ?? $erpBranch->name }} @endif
                </div>
            </div>
            <div class="top-actions">
                <div class="user-chip">
                    <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
                    <div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->roleLabel() }}</small></div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn btn-ghost" type="submit">Sign out</button>
                </form>
            </div>
        </header>
        <section class="content">
            @if(session('status'))<div class="alert success">{{ session('status') }}</div>@endif
            @if($errors->any())
                <div class="alert danger"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @yield('content')
        </section>
    </main>
</div>
@stack('scripts')
</body>
</html>
