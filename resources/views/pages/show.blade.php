<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="description" content="{{ $page->seo_description ?: $page->excerpt }}"><title>{{ $page->seo_title ?: $page->title }} — {{ $brand['brand_name'] ?? 'Talyvane' }}</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body>
    <header class="site-header"><a class="wordmark" href="{{ route('home') }}">{{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span></a><a class="header-link" href="{{ route('home') }}">Home ↗</a></header>
    <main class="page-content"><p class="eyebrow">{{ $brand['brand_name'] ?? 'TALYVANE' }}</p><h1>{{ $page->title }}</h1>@if ($page->excerpt)<p class="page-excerpt">{{ $page->excerpt }}</p>@endif<div class="page-body">{!! nl2br(e($page->body)) !!}</div></main>
    <footer class="site-footer"><a href="{{ route('home') }}">Return to {{ $brand['brand_name'] ?? 'Talyvane' }} ↗</a><span>© {{ date('Y') }} {{ $brand['brand_name'] ?? 'Talyvane' }}</span></footer>
</body></html>
