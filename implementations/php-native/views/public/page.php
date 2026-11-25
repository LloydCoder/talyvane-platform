<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $escape($metaTitle) ?> · <?= $escape($brand['brand_name']) ?></title>
<meta name="description" content="<?= $escape($metaDescription) ?>">
</head>
<body>
<header><a href="/"><?= $escape($brand['brand_name']) ?></a></header>
<main>
<article>
<h1><?= $escape($title) ?></h1>
<div class="cms-content"><?= nl2br($escape($content), false) ?></div>
</article>
</main>
</body>
</html>
