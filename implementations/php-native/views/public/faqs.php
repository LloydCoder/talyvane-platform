<?php
declare(strict_types=1);

$brandName=is_string($brandName??null)?$brandName:'Talyvane';
$faqs=is_array($faqs??null)?$faqs:[];
$groups=[];
foreach($faqs as $faq) {
    $category=is_string($faq['category_key']??null)?$faq['category_key']:'general';
    $groups[$category][]=$faq;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="index,follow"><title>Frequently asked questions · <?= $escape($brandName) ?></title>
<meta name="description" content="Answers to common questions about <?= $escape($brandName) ?>.">
<link rel="canonical" href="<?= $escape($canonicalUrl??'') ?>">
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f5f3ee;--panel:#fffefa;--line:#e5e0d7;--accent:#b88950;--navy:#142235}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.7 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(980px,calc(100% - 40px));margin:auto}.top{padding:24px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:20px}.brand{font:600 22px Georgia,serif;letter-spacing:.08em;text-decoration:none;color:var(--ink)}.top a:last-child{color:var(--muted);font-size:11px;letter-spacing:.1em;text-transform:uppercase}.eyebrow{margin:66px 0 12px;color:#88643a;font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase}h1{font:400 clamp(40px,6vw,64px)/1.05 Georgia,serif;letter-spacing:-.03em;margin:0 0 16px}.intro{max-width:680px;color:var(--muted);margin:0 0 42px}.group{margin:36px 0 52px}.group h2{font:400 26px Georgia,serif;margin:0 0 16px;text-transform:capitalize}.faq{border-top:1px solid var(--line);padding:20px 0}.faq:last-child{border-bottom:1px solid var(--line)}.faq h3{font:600 16px/1.45 Inter,ui-sans-serif,system-ui,sans-serif;margin:0 0 8px}.faq p{margin:0;color:var(--muted);white-space:pre-line}.empty{padding:24px;border:1px dashed #d2cbbf;color:var(--muted)}.foot{padding:24px 0;border-top:1px solid var(--line);font-size:12px;color:var(--muted)}a:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
</style></head><body><header class="shell top"><a class="brand" href="/"><?= $escape($brandName) ?></a><a href="/contact">Contact</a></header>
<main class="shell"><p class="eyebrow">Help centre</p><h1>Frequently asked questions.</h1><p class="intro">Clear answers to common questions. Public answers are reviewed and published by the agency.</p>
<?php if($groups===[]):?><div class="empty">There are no published FAQs in this language yet.</div><?php else:?>
<?php foreach($groups as $category=>$items):?><section class="group" aria-labelledby="faq-<?= $escape($category) ?>"><h2 id="faq-<?= $escape($category) ?>"><?= $escape(str_replace(['-','_'],' ',$category)) ?></h2>
<?php foreach($items as $item):?><article class="faq"><h3><?= $escape($item['question']??'') ?></h3><p><?= $escape($item['answer']??'') ?></p></article><?php endforeach;?></section><?php endforeach;?>
<?php endif;?></main><footer class="shell foot"><?= $escape($brandName) ?></footer></body></html>
