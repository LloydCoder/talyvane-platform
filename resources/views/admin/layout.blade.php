<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Administration') — Talyvane</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="admin-body">
    <aside class="admin-sidebar">
        <a class="wordmark" href="{{ route('admin.dashboard') }}">TALYVANE<span>®</span></a>
        <p class="sidebar-caption">WORKSPACE</p>
        <a class="sidebar-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Overview</a>
        <a class="sidebar-link {{ request()->routeIs('admin.branding.*') ? 'active' : '' }}" href="{{ route('admin.branding.edit') }}">Brand & identity</a>
        <a class="sidebar-link {{ request()->routeIs('admin.destinations.*') ? 'active' : '' }}" href="{{ route('admin.destinations.index') }}">Destinations</a>
        <div class="sidebar-bottom">
            <span>{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('admin.logout') }}">@csrf <button type="submit">Sign out ↗</button></form>
        </div>
    </aside>
    <main class="admin-main">
        <header class="admin-topbar"><span>ADMINISTRATION / @yield('section', 'OVERVIEW')</span><span>{{ auth()->user()->role }}</span></header>
        @if (session('status')) <div class="form-success" role="status">{{ session('status') }}</div> @endif
        @yield('content')
    </main>
</body>
</html>
