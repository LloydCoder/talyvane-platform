<?php
declare(strict_types=1);

$seoDefaults = is_array($brand['seo_defaults'] ?? null) ? $brand['seo_defaults'] : [];
$titleSuffix = is_string($seoDefaults['title_suffix'] ?? null) ? $seoDefaults['title_suffix'] : '';
$homeTitle = (string) ($brand['brand_name'] ?? 'Talyvane') . $titleSuffix;
$homeDescription = (string) ($brand['tagline'] ?? '');
$robots = in_array($seoDefaults['robots'] ?? 'index,follow', ['index,follow','noindex,nofollow'], true)
    ? $seoDefaults['robots']
    : 'index,follow';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#17233b">
<title><?= $escape($homeTitle) ?></title>
<meta name="robots" content="<?= $escape($robots) ?>">
<meta name="description" content="<?= $escape($homeDescription) ?>">
<style>
:root{color-scheme:light;--ink:#17233b;--muted:#687386;--paper:#f7f5f0;--white:#fff;--line:#e6e2d9;--accent:#c58b43;--serif:Georgia,"Times New Roman",serif;--sans:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.65 var(--sans);-webkit-font-smoothing:antialiased}a{color:inherit}a:focus-visible{outline:3px solid var(--accent);outline-offset:4px}.shell{width:min(1160px,calc(100% - 48px));margin-inline:auto}.masthead{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:26px 0;border-bottom:1px solid var(--line)}.wordmark{font:600 1.25rem/1.1 var(--serif);letter-spacing:.02em;text-decoration:none}.mark{display:inline-block;width:8px;height:8px;margin-right:11px;border-radius:50%;background:var(--accent);vertical-align:middle}.masthead-note{color:var(--muted);font-size:.76rem;letter-spacing:.16em;text-transform:uppercase}.hero{position:relative;display:grid;grid-template-columns:minmax(0,1.3fr) minmax(240px,.7fr);gap:clamp(32px,7vw,100px);align-items:center;padding:clamp(76px,12vw,150px) 0 clamp(72px,10vw,120px);border-bottom:1px solid var(--line)}.eyebrow{margin:0 0 22px;color:#95652e;font-size:.72rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase}.hero h1{max-width:760px;margin:0;font:400 clamp(3.3rem,7.5vw,7rem)/.98 var(--serif);letter-spacing:-.055em}.hero h1 em{color:#95652e;font-weight:400}.hero-copy{max-width:370px;margin:0 0 28px;color:var(--muted);font-size:1.03rem}.hero-side{align-self:end;padding-bottom:8px}.link-arrow{display:inline-flex;align-items:center;gap:16px;padding:12px 0;border-bottom:1px solid var(--accent);font-size:.78rem;font-weight:700;letter-spacing:.11em;text-decoration:none;text-transform:uppercase}.link-arrow span{color:#95652e;font-size:1.2rem}.section-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 64px;padding:20px 0 76px}.section{padding:38px 0;border-bottom:1px solid var(--line)}.section-index{display:block;margin-bottom:16px;color:#95652e;font-size:.7rem;letter-spacing:.16em}.section h2{margin:0 0 12px;font:400 clamp(1.65rem,3vw,2.35rem)/1.15 var(--serif);letter-spacing:-.025em}.section p{margin:0;color:var(--muted);white-space:pre-line}.footer{display:flex;justify-content:space-between;gap:24px;padding:28px 0 36px;border-top:1px solid var(--line);color:var(--muted);font-size:.8rem}.footer p{margin:0}@media(max-width:700px){.shell{width:min(100% - 36px,560px)}.masthead{padding:20px 0}.masthead-note{max-width:120px;text-align:right;font-size:.64rem}.hero{grid-template-columns:1fr;gap:36px;padding:76px 0 64px}.hero h1{font-size:clamp(3.2rem,15vw,5.5rem)}.hero-side{max-width:440px}.section-list{grid-template-columns:1fr;padding-bottom:52px}.section{padding:30px 0}.footer{flex-direction:column;gap:8px}}@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*{animation:none!important;transition:none!important}}
</style>
</head>
<body>
<header class="shell masthead">
<a class="wordmark" href="/"><span class="mark" aria-hidden="true"></span><?= $escape($brand['brand_name']) ?></a>
<span class="masthead-note">Considered service. Personal by design.</span>
</header>
<main class="shell">
<section class="hero" aria-labelledby="page-title">
<div>
<p class="eyebrow">A more considered experience</p>
<h1 id="page-title">Exceptional, <em>by nature.</em></h1>
</div>
<div class="hero-side">
<p class="hero-copy"><?= $escape($brand['tagline']) ?></p>
<a class="link-arrow" href="#about">Discover our approach <span aria-hidden="true">↗</span></a>
</div>
</section>
<?php if ($sections !== []): ?>
<div class="section-list" id="about">
<?php foreach ($sections as $index => $section): ?>
<section class="section">
<span class="section-index"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
<?php if ($section['title'] !== ''): ?><h2><?= $escape($section['title']) ?></h2><?php endif; ?>
<p><?= $escape($section['content']) ?></p>
</section>
<?php endforeach; ?>
</div>
<?php endif; ?>
</main>
<footer class="shell footer">
<p><?= $escape($brand['brand_name']) ?></p>
<p>Private, considered, and always personal.</p>
</footer>
</body>
</html>
