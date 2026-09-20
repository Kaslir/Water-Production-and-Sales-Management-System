<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AquaTrack</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{margin:0;background:#f4f7fa;color:#173a57;font-family:system-ui,sans-serif}.sidebar{position:fixed;inset:0 auto 0 0;width:248px;background:#8b1e2d;padding:26px 14px;display:flex;flex-direction:column}.brand{color:#fff;font-weight:700;font-size:1.2rem}.nav-heading{font-size:.68rem;letter-spacing:.08em;color:#b5cedd;margin:25px 10px 8px}.side-link{display:flex;gap:11px;align-items:center;color:#d6e6f0;text-decoration:none;border-radius:5px;padding:10px 12px;font-size:.9rem;margin:2px 0}.side-link:hover,.side-link.active{color:#fff;background:#b42332}.side-link i{width:17px}.sidebar-bottom{margin-top:auto;border-top:1px solid #6091ad;padding-top:14px}.profile{display:flex;gap:9px;align-items:center;color:#fff;font-size:.8rem;padding:8px}.avatar{background:#f6d6da;color:#7d2530;width:29px;height:29px;border-radius:50%;display:grid;place-items:center;font-weight:bold;font-size:.7rem}.page{margin-left:248px;min-height:100vh}.topbar{height:78px;background:#fff;border-bottom:1px solid #e4eaf0;display:flex;align-items:center;justify-content:space-between;padding:0 34px}.breadcrumb-label{font-size:.76rem;font-weight:700;color:#7d2530}.page-title{font-weight:700;font-size:1.2rem;margin:3px 0}.top-user{font-size:.8rem;color:#6f7f8e}.content{padding:28px 34px}.metric,.toolbar,.panel{background:#fff;border:1px solid #e4eaf0;border-radius:6px}.metric{padding:15px 16px;min-height:68px;display:flex;align-items:center;justify-content:space-between}.metric-label{font-size:.74rem;font-weight:700}.metric-value{font-size:1.35rem;font-weight:700}.toolbar{padding:14px;display:flex;gap:9px;align-items:center;flex-wrap:wrap}.panel{padding:18px}.asset-card{border:1px solid #e4eaf0;border-radius:5px;padding:13px;min-height:152px}.asset-card h3{font-size:.88rem;font-weight:700;margin:9px 0}.tiny{font-size:.72rem;color:#6f7f8e}.detail-row{font-size:.7rem;border-bottom:1px solid #edf1f4;padding:5px 0;display:flex;justify-content:space-between}.btn-outline-brand{border:2px solid #a51f2e;color:#8f1e2b;font-weight:700;font-size:.7rem;padding:4px;width:100%;margin-top:10px}.map-area{height:520px;position:relative;overflow:hidden;background:repeating-linear-gradient(35deg,#e6ecef 0 34px,#dce5e8 35px 68px)}.map-pin{position:absolute;background:#fff;border-radius:4px;box-shadow:0 2px 6px #7894a477;padding:4px 7px;font-size:.68rem;font-weight:700}.map-card{position:absolute;right:8%;top:21%;width:235px;background:#fff;border:1px solid #dfe5e9;border-radius:5px;padding:13px;box-shadow:0 5px 12px #607b8a2b}.table{font-size:.8rem}.table thead th{font-size:.68rem;text-transform:uppercase;color:#6f7f8e}.form-label{font-size:.78rem;font-weight:700;color:#7d2530}.form-control,.form-select{font-size:.82rem;border-color:#d9e2e9}.btn-brand{background:#b42332;border-color:#b42332;color:#fff}.btn-brand:hover{background:#8f1e2b;color:#fff}
    .text-primary{color:#b42332!important}.btn-primary{background-color:#b42332!important;border-color:#b42332!important}.btn-outline-primary{color:#b42332!important;border-color:#b42332!important}</style>
</head>
<body>
<aside class="sidebar">
    <div class="brand">◜ AquaTrack</div>
    <div class="nav-heading">WATER OPERATIONS</div>
    <nav>
        <a class="side-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="bi bi-grid-1x2"></i>Overview</a>
        <a class="side-link {{ request()->routeIs('production.*') ? 'active' : '' }}" href="{{ route('production.index') }}"><i class="bi bi-droplet"></i>Production</a>
        <a class="side-link {{ request()->routeIs('inventory.*') ? 'active' : '' }}" href="{{ route('inventory.index') }}"><i class="bi bi-box-seam"></i>Inventory</a>
        <a class="side-link {{ request()->routeIs('distribution.*') ? 'active' : '' }}" href="{{ route('distribution.index') }}"><i class="bi bi-truck"></i>Distribution</a>
        <a class="side-link {{ request()->routeIs('sales.*') ? 'active' : '' }}" href="{{ route('sales.index') }}"><i class="bi bi-receipt"></i>Sales</a>
        <a class="side-link {{ request()->routeIs('customers.*') ? 'active' : '' }}" href="{{ route('customers.index') }}"><i class="bi bi-people"></i>Customers</a>
    </nav>
    @if (auth()->user()->hasRole('administrator'))
        <div class="nav-heading">ADMINISTRATION</div>
        <nav><a class="side-link {{ request()->routeIs('users.*') ? 'active' : '' }}" href="{{ route('users.index') }}"><i class="bi bi-person-gear"></i>Users</a></nav>
    @endif
    @if (auth()->user()->hasRole('administrator', 'manager'))
        <div class="nav-heading">INSIGHTS</div>
        <nav>
            <a class="side-link {{ request()->routeIs('analytics.*') ? 'active' : '' }}" href="{{ route('analytics.index') }}"><i class="bi bi-graph-up-arrow"></i>Analytics</a>
            <a class="side-link {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}"><i class="bi bi-file-earmark-bar-graph"></i>Reports</a>
        </nav>
    @endif
    <div class="sidebar-bottom"><div class="profile"><div class="avatar">{{ str(auth()->user()->name)->substr(0, 2)->upper() }}</div><div><strong>{{ auth()->user()->name }}</strong><br><span class="text-white-50">{{ str_replace('_', ' ', auth()->user()->role) }}</span></div></div><form method="post" action="{{ route('logout') }}">@csrf<button class="side-link border-0 bg-transparent w-100 text-start"><i class="bi bi-box-arrow-left"></i>Log out</button></form></div>
</aside>
<section class="page"><header class="topbar"><div><div class="breadcrumb-label">WATER MANAGEMENT</div><div class="page-title">@yield('page-title', 'Overview')</div></div><div class="top-user"><i class="bi bi-bell me-3"></i>{{ now()->format('D, d M Y') }}</div></header><main class="content">@if (session('status'))<div class="alert alert-success border-0 shadow-sm">{{ session('status') }}</div>@endif @if ($errors->any())<div class="alert alert-danger border-0 shadow-sm">{{ $errors->first() }}</div>@endif @yield('content')</main></section>
</body></html>
