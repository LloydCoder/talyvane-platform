<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$items = is_array($items ?? null) ? $items : [];
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
$csrf = is_string($csrf ?? null) ? $csrf : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Saved companions · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(1080px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:18px}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.06em}.nav{display:flex;gap:16px;font-size:12px;color:var(--muted)}.intro{padding:42px 0 20px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,52px)/1.08 Georgia,serif;margin:10px 0}.muted{color:var(--muted)}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:24px 0 70px}.card{background:var(--panel);border:1px solid var(--line);padding:22px}.card h2{font:400 24px Georgia,serif;margin:0 0 8px}.card p{color:var(--muted);font-size:12px}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;border:0;padding:10px 14px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.quiet{background:transparent;border:1px solid var(--line);color:var(--muted)}.error,.notice{padding:12px 14px;margin:14px 0}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.empty{background:var(--panel);border:1px dashed #d2cbbf;padding:30px;color:var(--muted);margin:24px 0 70px}.actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.footer{padding:0 0 50px;color:var(--muted);font-size:12px}@media(max-width:480px){.top{flex-direction:column}.button{width:100%;text-align:center}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/portal"><?= $escape($brandName) ?></a><nav class="nav"><a href="/portal">Account</a><a href="/portal/bookings">Bookings</a></nav></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Client workspace · Private shortlist</div><h1>Saved companions</h1><p class="muted">Your shortlist is private to your account. A profile appears here only while it remains publicly discoverable under current eligibility, market and consent controls. Saving a profile does not grant access to private or members-only media.</p></section>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<?php if ($items === []): ?><div class="empty">You have no currently available saved profiles. Explore approved destinations to discover profiles.</div><?php else: ?>
<div class="grid"><?php foreach ($items as $item): ?><article class="card"><h2><?= $escape($item['display_name'] ?? '') ?></h2><p><?= $escape($item['destination_name'] ?? '') ?> · <?= $escape($item['experience_category'] ?? 'Profile') ?></p><p><?= $escape($item['biography'] ?? '') ?></p><div class="actions"><a class="button" href="/profiles/<?= $escape($item['slug'] ?? '') ?>">View profile</a><form method="post" action="/portal/favorites/<?= $escape($item['slug'] ?? '') ?>/remove"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="button quiet" type="submit">Remove</button></form></div></article><?php endforeach; ?></div>
<?php endif; ?>
<footer class="footer">Shortlist entries are not reservations, memberships or entitlement grants.</footer>
</main>
</body>
</html>
