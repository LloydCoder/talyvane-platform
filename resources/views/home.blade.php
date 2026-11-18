<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#111513">
    <meta name="description" content="Talyvane brings considered design and disciplined operations to premium agency businesses.">
    <title>{{ $brand['brand_name'] ?? 'Talyvane' }} — A more considered standard</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <div class="announcement">A new standard for independent agency operations</div>
    <header class="site-header">
        <a class="wordmark" href="{{ route('home') }}" aria-label="Talyvane home">{{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span></a>
        <nav class="desktop-nav" aria-label="Main navigation">
            <a href="#platform">The platform</a>
            <a href="#capabilities">Capabilities</a>
            <a href="#approach">Our approach</a>
        </nav>
        <a class="header-link" href="mailto:{{ $brand['contact_email'] ?? 'hello@tinlance.com' }}">Enquire <span aria-hidden="true">↗</span></a>
    </header>

    <main>
        <section class="hero">
            <div class="hero-copy">
                <p class="eyebrow"><span></span> THE ART OF OPERATING WELL</p>
                <h1>A more considered<br><em>standard</em> of agency<br>operations.</h1>
                <p class="hero-intro">A refined digital foundation for premium agencies. Discover, coordinate and manage every detail through one beautifully considered platform.</p>
                <div class="hero-actions">
                    <a class="button button-light" href="#platform">Discover the platform <span aria-hidden="true">↗</span></a>
                    <a class="text-link" href="#approach">Explore our approach <span aria-hidden="true">↓</span></a>
                </div>
                <div class="hero-note"><span class="note-line"></span><span>BUILT FOR INDEPENDENT OPERATORS</span></div>
            </div>
            <div class="hero-art" aria-label="Abstract architectural composition">
                <div class="art-frame">
                    <div class="art-orbit orbit-one"></div>
                    <div class="art-orbit orbit-two"></div>
                    <div class="art-core"></div>
                    <div class="art-caption"><span>01 / 03</span><span>FORM MEETS FUNCTION</span></div>
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
            <a class="button button-outline" href="mailto:{{ $brand['contact_email'] ?? 'hello@tinlance.com' }}">Discuss a deployment <span aria-hidden="true">↗</span></a>
        </section>
    </main>

    <footer class="site-footer">
        <a class="wordmark" href="{{ route('home') }}">{{ strtoupper($brand['brand_name'] ?? 'Talyvane') }}<span>®</span></a>
        <p>{{ $brand['tagline'] ?? 'Independent operations. Considered design.' }}</p>
        <span>© {{ date('Y') }} Talyvane. All rights reserved.</span>
    </footer>
</body>
</html>
