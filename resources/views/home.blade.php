<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111513">
    <meta name="description" content="{{ $brand['tagline'] ?? 'Exceptional company, thoughtfully arranged.' }}">
    <title>{{ $brand['brand_name'] ?? 'Talyvane' }} — Exceptional company, thoughtfully arranged</title>
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $brand['brand_name'] ?? 'Talyvane' }} — Exceptional company, thoughtfully arranged">
    <meta property="og:description" content="{{ $brand['tagline'] ?? 'A global luxury escort and executive companionship agency.' }}">
    <meta property="og:image" content="{{ config('stock_photography.hero.url') }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="announcement">Private introductions. Exceptional company. A world without compromise.</div>
    <header class="site-header">
        <a class="wordmark" href="{{ route('home') }}" aria-label="Talyvane home">@if (!empty($brand['logo_path']))
                <img class="wordmark-image" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($brand['logo_path']) }}" alt="{{ $brand['brand_name'] ?? 'Brand logo' }}">
            @else
                {{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span>
            @endif</a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <a href="{{ route('destinations.index') }}">Destinations</a>
            <a href="{{ route('companions.index') }}">Companions</a>
            <a href="#capabilities">The experience</a>
            <a href="#approach">Our standards</a>
        </nav>
        <a class="header-link" href="{{ route('contact') }}">Enquire <span aria-hidden="true">↗</span></a>
    </header>

    <main>
        <section class="hero">
            <div class="hero-copy">
                <p class="eyebrow"><span></span> THE ART OF EXCEPTIONAL COMPANY</p>
                <h1>Exceptional company.<br><em>Thoughtfully</em><br>arranged.</h1>
                <p class="hero-intro">A global luxury escort and executive companionship agency for discerning adults. Discover adult-status-reviewed companions, explore international destinations and arrange each introduction through one attentive, discreet point of contact.</p>
                <div class="hero-actions">
                    <a class="button button-light" href="{{ route('companions.index') }}">Discover companions <span aria-hidden="true">↗</span></a>
                    <a class="text-link" href="{{ route('destinations.index') }}">Explore destinations <span aria-hidden="true">↓</span></a>
                </div>
                <div class="hero-note"><span class="note-line"></span><span>A MORE PERSONAL KIND OF LUXURY</span></div>
            </div>
            <div class="hero-art" aria-label="Luxury hospitality photography">
                <div class="art-frame">
                    <img class="hero-photo" src="{{ config('stock_photography.hero.url') }}" srcset="{{ str_replace('w=1600', 'w=900', config('stock_photography.hero.url')) }} 900w, {{ config('stock_photography.hero.url') }} 1600w" sizes="(max-width: 900px) 100vw, 50vw" alt="{{ config('stock_photography.hero.alt') }}" fetchpriority="high" loading="eager" decoding="async">
                    <div class="art-image-wash"></div>
                    <div class="art-caption"><span>01 / 04</span><span>A WORLD OF DISTINCTION</span></div>
                </div>
                <div class="art-side-label">{{ strtoupper($brand['brand_name'] ?? 'Talyvane') }} — PRIVATE BY DESIGN</div>
            </div>
        </section>

        <section class="intro-band" id="platform">
            <p class="eyebrow eyebrow-dark">EXECUTIVE COMPANIONSHIP</p>
            <div class="intro-grid">
                <h2>The pleasure of<br><em>every detail.</em></h2>
                <div>
                    <p class="intro-text">From the first enquiry to the final detail, every interaction is approached with care. A considered selection, attentive coordination and privacy-led service define every introduction.</p>
                    <a class="dark-link" href="#capabilities">Discover the experience <span aria-hidden="true">↗</span></a>
                </div>
            </div>
        </section>

        <section class="editorial-gallery" aria-label="A considered world of hospitality">
            <div class="editorial-gallery-heading">
                <p class="eyebrow eyebrow-dark">A WORLD OF DISTINCTION</p>
                <h2>Remarkable settings.<br><em>Unforgettable moments.</em></h2>
            </div>
            <div class="editorial-gallery-grid">
                @foreach (config('stock_photography.gallery') as $photo)
                    <figure class="editorial-image {{ $loop->first ? 'editorial-image-tall' : '' }}">
                        <img src="{{ $photo['url'] }}" srcset="{{ preg_replace('/w=\\d+/', 'w=640', $photo['url']) }} 640w, {{ $photo['url'] }} 1400w" sizes="(max-width: 640px) 100vw, 45vw" alt="{{ $photo['alt'] }}" loading="lazy" decoding="async">
                        <figcaption><span>{{ $photo['number'] }}</span> {{ $photo['caption'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
            <p class="editorial-note">Illustrative hospitality and travel photography. Destination imagery is selected to reflect each location; companion portraits are authentic, consented profile images.</p>
        </section>

        <section class="capabilities" id="capabilities">
            <div class="section-heading">
                <div><p class="eyebrow">THE TALYVANE DIFFERENCE</p><h2>A higher standard of<br><em>personal attention.</em></h2></div>
                <p>International reach. Personal attention. Thoughtful coordination at every stage.</p>
            </div>
            <div class="capability-grid">
                <article class="capability-card"><span class="card-number">01</span><div class="card-mark">✳</div><h3>Discover</h3><p>Explore carefully presented adult companion profiles, destination context and availability information. Every published profile is subject to adult-status review and recorded publication consent.</p><a href="#approach" aria-label="Discover more">↗</a></article>
                <article class="capability-card"><span class="card-number">02</span><div class="card-mark">⌁</div><h3>Personal coordination</h3><p>One considered point of contact for private enquiries, booking requests and clear confirmation at every step.</p><a href="#approach" aria-label="Coordinate more">↗</a></article>
                <article class="capability-card"><span class="card-number">03</span><div class="card-mark">◈</div><h3>Discretion by design</h3><p>Privacy-conscious communication, careful handling of personal information and a discreet experience from first enquiry to final confirmation.</p><a href="#approach" aria-label="Operate more">↗</a></article>
            </div>
        </section>

        <section class="statement" id="approach">
            <p class="eyebrow">GLOBAL REACH. PERSONAL ATTENTION.</p>
            <h2>Your time.<br><em>Your preferences.</em><br>Considered in every detail.</h2>
            <p>From a private enquiry to a carefully coordinated experience, discover a more considered approach to executive companionship.</p>
            <a class="button button-outline" href="{{ route('contact') }}">Enquire privately <span aria-hidden="true">↗</span></a>
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
        <span class="age-notice">For adults aged 18 and over. All arrangements are subject to availability, informed consent and applicable local law.</span>
    </footer>
</body>
</html>
