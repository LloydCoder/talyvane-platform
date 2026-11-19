@inject('brandSettings', 'App\Services\BrandSettings')
@php($brand = $brandSettings->publicBrand())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Administration') — {{ $brand['brand_name'] ?? 'Talyvane' }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="admin-body">
    <aside class="admin-sidebar">
        <a class="wordmark" href="{{ route('admin.dashboard') }}">
            @if (!empty($brand['logo_path']))
                <img class="wordmark-image" src="{{ asset('storage/' . $brand['logo_path']) }}" alt="{{ $brand['brand_name'] ?? 'Brand logo' }}">
            @else
                {{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span>
            @endif
        </a>
        <p class="sidebar-caption">WORKSPACE</p>
        <a class="sidebar-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Overview</a>
        @if (auth()->user()->role === 'super_admin')
            <p class="sidebar-caption">DEPLOYMENT CONTROL</p>
            <a class="sidebar-link {{ request()->routeIs('admin.features.*') ? 'active' : '' }}" href="{{ route('admin.features.index') }}">Feature registry</a>
            <a class="sidebar-link {{ request()->routeIs('admin.branding.*') ? 'active' : '' }}" href="{{ route('admin.branding.edit') }}">Brand & identity</a>
            <a class="sidebar-link {{ request()->routeIs('admin.globalization*') ? 'active' : '' }}" href="{{ route('admin.globalization') }}">Global markets & currency</a>
            <a class="sidebar-link {{ request()->routeIs('admin.destinations.*') ? 'active' : '' }}" href="{{ route('admin.destinations.index') }}">Destinations</a>
            <a class="sidebar-link {{ request()->routeIs('admin.bookings.settings*') ? 'active' : '' }}" href="{{ route('admin.bookings.settings') }}">Booking rules</a>
            <a class="sidebar-link {{ request()->routeIs('admin.payment-*') ? 'active' : '' }}" href="{{ route('admin.payment-settings') }}">Payment settings</a>
            <a class="sidebar-link {{ request()->routeIs('admin.payment-methods*') ? 'active' : '' }}" href="{{ route('admin.payment-methods') }}">Crypto addresses</a>
            <a class="sidebar-link {{ request()->routeIs('admin.pages.*') ? 'active' : '' }}" href="{{ route('admin.pages.index') }}">Site pages</a>
        @endif
        <p class="sidebar-caption">AGENCY OPERATIONS</p>
        <a class="sidebar-link {{ request()->routeIs('admin.profiles.*') ? 'active' : '' }}" href="{{ route('admin.profiles.index') }}">Profiles</a>
        <a class="sidebar-link {{ request()->routeIs('admin.clients.*') ? 'active' : '' }}" href="{{ route('admin.clients.index') }}">Clients & membership</a>
        <a class="sidebar-link {{ request()->routeIs('admin.enquiries.*') ? 'active' : '' }}" href="{{ route('admin.enquiries.index') }}">Enquiries</a>
        @if (\Illuminate\Support\Facades\Schema::hasTable('model_talent_applications'))
            <a class="sidebar-link {{ request()->routeIs('admin.model-applications.*') ? 'active' : '' }}" href="{{ route('admin.model-applications.index') }}">Model applications</a>
        @endif
        @if (\Illuminate\Support\Facades\Schema::hasTable('model_casting_calls') && app(\App\Services\FeatureRegistry::class)->isEnabled('model-agency.casting'))
            <a class="sidebar-link {{ request()->routeIs('admin.model-castings.*') || request()->routeIs('admin.model-casting-submissions.*') ? 'active' : '' }}" href="{{ route('admin.model-castings.index') }}">Model casting</a>
        @endif
        @if (\Illuminate\Support\Facades\Schema::hasTable('model_talent_assignments') && app(\App\Services\FeatureRegistry::class)->isEnabled('model-agency.assignments'))
            <a class="sidebar-link {{ request()->routeIs('admin.model-assignments.*') ? 'active' : '' }}" href="{{ route('admin.model-assignments.index') }}">Model assignments</a>
        @endif
        <a class="sidebar-link {{ request()->routeIs('admin.bookings.index') ? 'active' : '' }}" href="{{ route('admin.bookings.index') }}">Booking requests</a>
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
