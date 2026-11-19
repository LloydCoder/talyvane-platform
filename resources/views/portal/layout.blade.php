@inject('brandSettings', 'App\Services\BrandSettings')
@php($brand = $brandSettings->publicBrand())
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>@yield('title', 'Account') — {{ $brand['brand_name'] ?? 'Talyvane' }}</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body">
    <main class="admin-auth-card">
        <a class="wordmark" href="{{ route('home') }}">{{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span></a>
        @yield('content')
    </main>
</body>
</html>
