<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>@yield('title', 'Discover') — Talyvane</title>
    <meta name="description" content="@yield('description', 'Discover destinations and verified profiles.')">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="discovery-body">
    <header class="discovery-header">
        <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
        <nav aria-label="Main navigation"><a href="{{ route('destinations.index') }}">Destinations</a><a href="{{ route('companions.index') }}">Profiles</a><a href="{{ route('home') }}">Home</a></nav>
    </header>
    <main class="discovery-main">@yield('content')</main>
    <footer class="discovery-footer"><span>© {{ date('Y') }} Talyvane</span><span>Privacy · Consent · Discretion</span></footer>
</body>
</html>
