<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $escape($brand['brand_name']) ?></title>
<meta name="description" content="<?= $escape($brand['tagline']) ?>">
</head>
<body>
<header><a href="/"><?= $escape($brand['brand_name']) ?></a><p><?= $escape($brand['tagline']) ?></p></header>
<main>
<h1><?= $escape($brand['brand_name']) ?></h1>
<?php foreach ($sections as $section): ?>
<section>
<?php if ($section['title'] !== ''): ?><h2><?= $escape($section['title']) ?></h2><?php endif; ?>
<p><?= nl2br($escape($section['content']), false) ?></p>
</section>
<?php endforeach; ?>
</main>
</body>
</html>
