<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$items = is_array($items ?? null) ? $items : [];
$countries = is_array($countries ?? null) ? $countries : [];
$currencies = is_array($currencies ?? null) ? $currencies : [];
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
$csrf = is_string($csrf ?? null) ? $csrf : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Destinations · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(1180px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:18px}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.06em}.nav{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted)}.intro{padding:42px 0 20px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,52px)/1.08 Georgia,serif;margin:10px 0}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:18px 0}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field label{font-size:11px;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;min-width:0;padding:11px;border:1px solid #d8d3ca;background:white;font:inherit;color:var(--ink)}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;border:0;padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39;padding:12px 14px;margin:14px 0}.items{display:grid;gap:14px}.item{border:1px solid var(--line);padding:18px;background:#fff}.item-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:14px}.item h2{font:400 22px Georgia,serif;margin:0}.meta{font-size:11px;color:var(--muted)}.pill{display:inline-block;border:1px solid var(--line);padding:3px 8px;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.help{font-size:12px;color:var(--muted);margin:12px 0 0}@media(max-width:850px){.grid{grid-template-columns:1fr 1fr}}@media(max-width:540px){.grid{grid-template-columns:1fr}.top{flex-direction:column}.item-head{flex-direction:column}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/admin"><?= $escape($brandName) ?></a><nav class="nav"><a href="/admin">Control room</a><a href="/admin/content">Content</a><a href="/admin/destination-guides">Destination guides</a><a href="/admin/audit">Audit trail</a></nav></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Administration · Global discovery</div><h1>Destination management</h1><p class="muted">Maintain city and market metadata. Publishing is blocked unless the country and currency datasets are enabled and the market has a current, MFA-reviewed approval. Draft destinations remain private.</p></section>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<section class="panel">
<div class="eyebrow">New destination</div><h2>Create a destination</h2>
<form method="post" action="/admin/destinations">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label for="new_display_name">Display name</label><input id="new_display_name" name="display_name" maxlength="190" required placeholder="London"></div>
<div class="field"><label for="new_slug">URL slug</label><input id="new_slug" name="slug" maxlength="190" required pattern="[a-z0-9]+([-_][a-z0-9]+)*" placeholder="london"></div>
<div class="field"><label for="new_city_name">City</label><input id="new_city_name" name="city_name" maxlength="190" required placeholder="London"></div>
<div class="field"><label for="new_country_code">Country</label><select id="new_country_code" name="country_code" required><option value="">Choose country</option><?php foreach ($countries as $country): ?><option value="<?= $escape($country['alpha2'] ?? '') ?>"><?= $escape($country['name'] ?? '') ?><?= !(bool)($country['enabled']??false) ? ' (disabled)' : '' ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="new_currency_code">Currency</label><select id="new_currency_code" name="currency_code" required><option value="">Choose currency</option><?php foreach ($currencies as $currency): ?><option value="<?= $escape($currency['code'] ?? '') ?>"><?= $escape($currency['code'] ?? '') ?> · <?= $escape($currency['name'] ?? '') ?><?= !(bool)($currency['enabled']??false) ? ' (disabled)' : '' ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="new_locale">Locale</label><input id="new_locale" name="locale" maxlength="35" required placeholder="en-GB" value="en"></div>
<div class="field"><label for="new_time_zone">IANA time zone</label><input id="new_time_zone" name="time_zone" maxlength="64" required placeholder="Europe/London"></div>
<div class="field"><label for="new_status">Publication status</label><select id="new_status" name="status"><option value="draft">Draft</option><option value="published">Published (market approval required)</option></select></div>
</div>
<div class="actions"><button class="button" type="submit">Create destination</button></div>
</form>
</section>
<section class="panel">
<div class="eyebrow">Existing destinations</div><h2>Destination catalogue</h2>
<?php if ($items === []): ?><p class="muted">No destinations have been configured.</p><?php else: ?>
<div class="items">
<?php foreach ($items as $item): ?>
<article class="item">
<div class="item-head"><div><h2><?= $escape($item['display_name'] ?? '') ?></h2><div class="meta"><?= $escape($item['country_name'] ?? '') ?> · <?= $escape($item['slug'] ?? '') ?></div></div><div><span class="pill"><?= $escape($item['status'] ?? '') ?></span> <span class="pill">Market: <?= $escape($item['market_decision'] ?? 'not reviewed') ?></span></div></div>
<form method="post" action="/admin/destinations/<?= $escape($item['id'] ?? '') ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label>Display name</label><input name="display_name" maxlength="190" required value="<?= $escape($item['display_name'] ?? '') ?>"></div>
<div class="field"><label>URL slug</label><input name="slug" maxlength="190" required value="<?= $escape($item['slug'] ?? '') ?>"></div>
<div class="field"><label>City</label><input name="city_name" maxlength="190" required value="<?= $escape($item['city_name'] ?? '') ?>"></div>
<div class="field"><label>Country</label><select name="country_code" required><?php foreach ($countries as $country): ?><option value="<?= $escape($country['alpha2'] ?? '') ?>" <?= ($country['alpha2']??'') === ($item['country_code']??'') ? 'selected' : '' ?>><?= $escape($country['name'] ?? '') ?><?= !(bool)($country['enabled']??false) ? ' (disabled)' : '' ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Currency</label><select name="currency_code" required><?php foreach ($currencies as $currency): ?><option value="<?= $escape($currency['code'] ?? '') ?>" <?= ($currency['code']??'') === ($item['currency_code']??'') ? 'selected' : '' ?>><?= $escape($currency['code'] ?? '') ?> · <?= $escape($currency['name'] ?? '') ?><?= !(bool)($currency['enabled']??false) ? ' (disabled)' : '' ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Locale</label><input name="locale" maxlength="35" required value="<?= $escape($item['locale'] ?? '') ?>"></div>
<div class="field"><label>IANA time zone</label><input name="time_zone" maxlength="64" required value="<?= $escape($item['time_zone'] ?? '') ?>"></div>
<div class="field"><label>Publication status</label><select name="status"><option value="draft" <?= ($item['status']??'') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="published" <?= ($item['status']??'') === 'published' ? 'selected' : '' ?>>Published</option></select></div>
</div>
<div class="actions"><button class="button" type="submit">Save destination</button></div>
</form>
<form method="post" action="/admin/destinations/<?= $escape($item['id'] ?? '') ?>/cover" enctype="multipart/form-data" class="cover-upload">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label>Destination cover image (JPEG, PNG or WebP; max 8 MB)</label><input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp" required></div>
<label class="help"><input type="checkbox" name="rights_confirmed" value="1" required> I confirm I own or hold the necessary copyright, licence, property and likeness permissions for this image and authorize its use as this destination cover.</label>
<div class="actions"><button class="button" type="submit">Submit cover for independent review</button></div>
</form>
<?php if ((bool)($item['has_cover'] ?? false)): ?><form method="post" action="/admin/destination-covers/<?= $escape($item['active_cover_asset_id'] ?? '') ?>/withdraw">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label>Cover withdrawal reason</label><input name="reason" minlength="10" maxlength="500" required placeholder="Rights withdrawn or cover replaced"></div>
<div class="actions"><button class="button" type="submit">Withdraw current cover</button></div>
</form><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<p class="help">Cover images are stored outside the public web root, re-encoded to WebP, and remain private until a different MFA-authorized administrator approves the rights-attested submission.</p>
<?php $pendingCovers = is_array($pendingCovers ?? null) ? $pendingCovers : []; ?>
<?php if ($pendingCovers !== []): ?><div class="items"><h3>Pending cover review</h3>
<?php foreach ($pendingCovers as $cover): ?><article class="item"><div class="item-head"><div><h2><?= $escape($cover['destination_name'] ?? '') ?></h2><div class="meta">Submitted <?= $escape($cover['created_at'] ?? '') ?></div></div><span class="pill">Pending rights review</span></div>
<form method="post" action="/admin/destination-covers/<?= $escape($cover['id'] ?? '') ?>/review">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label>Review decision</label><select name="decision" required><option value="approved">Approve</option><option value="rejected">Reject</option></select></div>
<div class="field"><label>Reason (10–500 characters)</label><input name="reason" minlength="10" maxlength="500" required></div>
<div class="actions"><button class="button" type="submit">Record independent review</button></div>
</form></article><?php endforeach; ?></div><?php endif; ?>
</section>
</main>
</body>
</html>
