<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111513">
    <meta name="description" content="{{ $brand['tagline'] ?? 'Discover a more considered standard.' }}">
    <title>{{ $brand['brand_name'] ?? 'Talyvane' }} — A more considered standard</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="announcement">A new standard for independent agency operations</div>
    <header class="site-header">
        <a class="wordmark" href="{{ route('home') }}" aria-label="Talyvane home">@if (!empty($brand['logo_path']))
                <img class="wordmark-image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($brand['logo_path']) }}" alt="{{ $brand['brand_name'] ?? 'Brand logo' }}">
            @else
                {{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span>
            @endif</a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <a href="{{ route('destinations.index') }}">Destinations</a>
            <a href="{{ route('companions.index') }}">Profiles</a>
            <a href="#capabilities">Capabilities</a>
            <a href="#approach">Our approach</a>
        </nav>
        <a class="header-link" href="{{ route('contact') }}">Enquire <span aria-hidden="true">↗</span></a>
    </header>

    <main>
        <section class="hero">
            <div class="hero-copy">
                <p class="eyebrow"><span></span> THE ART OF OPERATING WELL</p>
                <h1>A more considered<br><em>standard</em> of agency<br>operations.</h1>
                <p class="hero-intro">A refined digital foundation for premium agencies. Discover, coordinate and manage every detail through one beautifully considered platform.</p>
                <div class="hero-actions">
                    <a class="button button-light" href="{{ route('destinations.index') }}">Explore destinations <span aria-hidden="true">↗</span></a>
                    <a class="text-link" href="#approach">Explore our approach <span aria-hidden="true">↓</span></a>
                </div>
                <div class="hero-note"><span class="note-line"></span><span>BUILT FOR INDEPENDENT OPERATORS</span></div>
            </div>
            <div class="hero-art" aria-label="Abstract architectural composition">
                <div class="art-frame">
                    <img class="hero-photo" src="{{ config('stock_photography.hero.url') }}" alt="{{ config('stock_photography.hero.alt') }}" fetchpriority="high" decoding="async">
                    <div class="art-image-wash"></div>
                    <div class="art-caption"><span>01 / 03</span><span>PRIVATE HOSPITALITY</span></div>
                </div>
                <div class="art-side-label">{{ strtoupper($brand['brand_name'] ?? 'Talyvane') }} — PRIVATE BY DESIGN</div>
            </div>
        </section>

        <section class="intro-band" id="platform">
            <p class="eyebrow eyebrow-dark">A PLATFORM, NOT A TEMPLATE</p>
            <div class="intro-grid">
                <h2>Every detail,<br><em>in its place.</em></h2>
                <div>
                    <p class="intro-text">Talyvane brings public presence and private operations into a single, coherent environment. Each deployment is independently branded, configured and managed around the needs of its operator.</p>
                    <a class="dark-link" href="#capabilities">See what is possible <span aria-hidden="true">↗</span></a>
                </div>
            </div>
        </section>

        <section class="editorial-gallery" aria-label="A considered world of hospitality">
            <div class="editorial-gallery-heading">
                <p class="eyebrow eyebrow-dark">THE SETTING MATTERS</p>
                <h2>Considered spaces.<br><em>Memorable places.</em></h2>
            </div>
            <div class="editorial-gallery-grid">
                @foreach (config('stock_photography.gallery') as $photo)
                    <figure class="editorial-image {{ $loop->first ? 'editorial-image-tall' : '' }}">
                        <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" loading="lazy" decoding="async">
                        <figcaption><span>{{ $photo['number'] }}</span> {{ $photo['caption'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
            <p class="editorial-note">Illustrative hospitality photography. Destination-specific imagery and profile photography are managed separately.</p>
        </section>

        <section class="capabilities" id="capabilities">
            <div class="section-heading">
                <div><p class="eyebrow">THE PLATFORM</p><h2>Designed around<br><em>the whole operation.</em></h2></div>
                <p>Purposeful tools for the moments your clients see — and the work they never need to.</p>
            </div>
            <div class="capability-grid">
                <article class="capability-card"><span class="card-number">01</span><div class="card-mark">✳</div><h3>Discover</h3><p>An elegant public experience with curated discovery, destination pages and carefully managed profiles.</p><a href="#approach" aria-label="Discover more">↗</a></article>
                <article class="capability-card"><span class="card-number">02</span><div class="card-mark">⌁</div><h3>Coordinate</h3><p>Booking workflows that adapt to your operation, from personal review to rules-led automation.</p><a href="#approach" aria-label="Coordinate more">↗</a></article>
                <article class="capability-card"><span class="card-number">03</span><div class="card-mark">◈</div><h3>Operate</h3><p>One considered workspace for client relationships, communications, payments and reporting.</p><a href="#approach" aria-label="Operate more">↗</a></article>
            </div>
        </section>

        <section class="statement" id="approach">
            <p class="eyebrow">INDEPENDENT BY DESIGN</p>
            <h2>Your identity.<br><em>Your operation.</em><br>One connected platform.</h2>
            <p>Distinctive branding, configurable workflows and isolated customer deployments — with a reusable foundation built to evolve.</p>
            <a class="button button-outline" href="{{ route('contact') }}">Make an enquiry <span aria-hidden="true">↗</span></a>
        </section>
    </main>

    <footer class="site-footer">
        <a class="wordmark" href="{{ route('home') }}">
            @if (!empty($brand['logo_path']))
                <img class="wordmark-image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($brand['logo_path']) }}" alt="{{ $brand['brand_name'] ?? 'Brand logo' }}">
            @else
                {{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span>
            @endif
        </a>
        <p>{{ $brand['tagline'] ?? '' }}</p>
        @if ($pages->isNotEmpty())
            <nav class="footer-pages" aria-label="Information">
                @foreach ($pages as $page)
                    <a href="{{ route('pages.show', $page->slug) }}">{{ $page->title }}</a>
                @endforeach
            </nav>
        @endif
        <div class="footer-contact">
            @if (!empty($brand['contact_email'])) <a href="mailto:{{ $brand['contact_email'] }}">{{ $brand['contact_email'] }}</a> @endif
            @if (!empty($brand['contact_phone'])) <a href="tel:{{ preg_replace('/[^0-9+]/', '', $brand['contact_phone']) }}">{{ $brand['contact_phone'] }}</a> @endif
            @if (!empty($brand['contact_website'])) <a href="{{ $brand['contact_website'] }}" target="_blank" rel="noopener noreferrer">Website ↗</a> @endif
        </div>
        <span>© {{ date('Y') }} {{ $brand['brand_name'] ?? 'Talyvane' }}. All rights reserved.</span>
    </footer>
</body>
</html>
