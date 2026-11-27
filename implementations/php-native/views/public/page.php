<?php
declare(strict_types=1);

$navigation = is_array($navigation ?? null) ? $navigation : [];
$footerNavigation = is_array($footerNavigation ?? null) ? $footerNavigation : [];
$robots = in_array($robots ?? 'index,follow', ['index,follow','noindex,nofollow'], true)
    ? $robots
    : 'index,follow';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#17233b">
<title><?= $escape($metaTitle) ?> · <?= $escape($brand['brand_name']) ?></title>
<meta name="description" content="<?= $escape($metaDescription) ?>">
<meta name="robots" content="<?= $escape($robots) ?>">
<link rel="canonical" href="<?= $escape($canonicalUrl ?? '') ?>">
<style>
:root{color-scheme:light;--ink:#17233b;--muted:#687386;--paper:#f7f5f0;--line:#e6e2d9;--accent:#c58b43;--serif:Georgia,"Times New Roman",serif;--sans:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.75 var(--sans);-webkit-font-smoothing:antialiased}a{color:inherit}a:focus-visible{outline:3px solid var(--accent);outline-offset:4px}.shell{width:min(1160px,calc(100% - 48px));margin-inline:auto}.masthead{padding:26px 0;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:24px}.primary-nav{display:flex;align-items:center;gap:clamp(14px,2.5vw,30px);flex-wrap:wrap}.primary-nav a{color:var(--muted);font-size:.72rem;font-weight:700;letter-spacing:.08em;text-decoration:none;text-transform:uppercase}.primary-nav a:hover{color:var(--ink)}.wordmark{font:600 1.25rem/1.1 var(--serif);letter-spacing:.02em;text-decoration:none}.mark{display:inline-block;width:8px;height:8px;margin-right:11px;border-radius:50%;background:var(--accent);vertical-align:middle}.crumb{margin:0 0 32px;color:var(--muted);font-size:.76rem;letter-spacing:.12em;text-transform:uppercase}.crumb a{text-decoration:none}.page{max-width:780px;margin:clamp(68px,10vw,120px) auto clamp(100px,14vw,160px)}.eyebrow{margin:0 0 20px;color:#95652e;font-size:.72rem;font-weight:700;letter-spacing:.2em;text-transform:uppercase}.page h1{margin:0 0 42px;font:400 clamp(3rem,7vw,6rem)/1.02 var(--serif);letter-spacing:-.05em}.rule{width:72px;height:1px;margin-bottom:38px;background:var(--accent)}.cms-content{color:#3e4a5d;font-size:1.08rem;white-space:pre-line;overflow-wrap:anywhere}.footer{padding:28px 0 36px;border-top:1px solid var(--line);color:var(--muted);font-size:.8rem;display:flex;justify-content:space-between;gap:20px;align-items:center;flex-wrap:wrap}.footer-nav{display:flex;gap:18px;flex-wrap:wrap}.footer-nav a{color:var(--muted);text-decoration:none}.footer-nav a:hover{color:var(--ink)}@media(max-width:700px){.shell{width:min(100% - 36px,560px)}.masthead{padding:20px 0;align-items:flex-start;flex-direction:column}.primary-nav{gap:14px}.primary-nav a{font-size:.65rem}.page{margin:68px auto 100px}.page h1{font-size:clamp(2.8rem,14vw,4.8rem)}.cms-content{font-size:1rem}}
</style>
</head>
<body>
<header class="shell masthead"><a class="wordmark" href="/"><span class="mark" aria-hidden="true"></span><?= $escape($brand['brand_name']) ?></a><?php if ($navigation !== []): ?><nav class="primary-nav" aria-label="Primary navigation"><?php foreach ($navigation as $item): ?><a href="<?= $escape($item['target_value'] ?? '/') ?>"><?= $escape($item['label'] ?? '') ?></a><?php endforeach; ?></nav><?php endif; ?></header>
<main class="shell">
<article class="page">
<p class="crumb"><a href="/">Home</a> / Information</p>
<p class="eyebrow"><?= $escape($brand['brand_name']) ?></p>
<h1><?= $escape($title) ?></h1>
<div class="rule" aria-hidden="true"></div>
<div class="cms-content"><?= $escape($content) ?></div>
</article>
</main>
<footer class="shell footer"><?= $escape($brand['brand_name']) ?><?php if ($footerNavigation !== []): ?><nav class="footer-nav" aria-label="Footer navigation"><?php foreach ($footerNavigation as $item): ?><a href="<?= $escape($item['target_value'] ?? '/') ?>"><?= $escape($item['label'] ?? '') ?></a><?php endforeach; ?></nav><?php endif; ?></footer>
</body>
</html>
