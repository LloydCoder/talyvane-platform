@inject('brandSettings', 'App\\Services\\BrandSettings')
@php($brand = $brandSettings->publicBrand())
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="@yield('robots', 'index,follow')">
    <title>@yield('title', 'Discover') — {{ $brand['brand_name'] ?? 'Talyvane' }}</title>
    <meta name="description" content="@yield('description', $brand['tagline'] ?? 'Discover destinations and profiles.')">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="discovery-body">
    <header class="discovery-header">
        <a class="wordmark" href="{{ route('home') }}" aria-label="{{ $brand['brand_name'] ?? 'Home' }}">
            @if (!empty($brand['logo_path']))
                <img class="wordmark-image" src="{{ asset('storage/' . $brand['logo_path']) }}" alt="{{ $brand['brand_name'] ?? 'Brand logo' }}">
            @else
                {{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span>
            @endif
        </a>
        <nav aria-label="Main navigation"><a href="{{ route('destinations.index') }}">Destinations</a><a href="{{ route('companions.index') }}">Profiles</a><a href="{{ route('home') }}">Home</a><a href="{{ route('contact') }}">Contact</a></nav>
    </header>
    <main class="discovery-main">@yield('content')</main>
    <footer class="discovery-footer">
        <span>© {{ date('Y') }} {{ $brand['brand_name'] ?? 'Talyvane' }}</span>
        <span>@if (!empty($brand['contact_email'])) <a href="mailto:{{ $brand['contact_email'] }}">{{ $brand['contact_email'] }}</a> · @endif Privacy · Consent · Discretion</span>
    </footer>
</body>
</html>
