<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$saved = ($saved ?? false) === true;
$items = is_array($items ?? null) ? $items : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Navigation · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--navy:#142235;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.top{height:74px;background:var(--navy);color:#f5f1e8;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,5vw,72px)}.brand{font:23px Georgia,serif;letter-spacing:.12em}.brand span{color:#d4b183}.top a{color:#e7d6bc;text-decoration:none;font-size:12px;letter-spacing:.08em}.wrap{max-width:1160px;margin:0 auto;padding:clamp(28px,5vw,64px) 24px}.eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:10px;color:#9a774e;font-weight:700}.heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:30px}.heading h1{font:clamp(34px,4vw,48px)/1.05 Georgia,serif;font-weight:400;margin:12px 0 0}.muted{color:var(--muted);font-size:13px;line-height:1.7}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);box-shadow:0 14px 40px rgba(23,35,51,.04);margin-bottom:22px}.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:2px;background:var(--navy);color:white;text-decoration:none;padding:13px 17px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;cursor:pointer}.button.secondary{background:transparent;color:var(--ink);border:1px solid #cfc8bb}.button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--gold);outline-offset:3px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin-bottom:18px;font-size:13px}.success{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39;padding:12px 14px;margin-bottom:18px;font-size:13px}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:0 14px}.field{display:grid;gap:7px;margin:12px 0}.field label{font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}.field input,.field select{width:100%;border:1px solid #d9d5cc;border-radius:2px;background:white;padding:11px 12px;font:13px ui-sans-serif,system-ui,sans-serif;color:var(--ink)}.field input:focus,.field select:focus{border-color:#9a774e;outline:3px solid rgba(189,149,96,.15)}.field.wide{grid-column:span 2}.field.reason{grid-column:span 3}.check{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);margin:18px 0}.check input{width:16px;height:16px;accent-color:var(--navy)}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.item-head{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:12px}.item-head strong{font:20px Georgia,serif;font-weight:400}.status{border:1px solid var(--line);padding:5px 8px;font-size:10px;text-transform:uppercase;letter-spacing:.08em}
@media(max-width:900px){.form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.field.reason{grid-column:span 2}}@media(max-width:620px){.top{height:62px}.wrap{padding:30px 16px}.heading{align-items:start;flex-direction:column}.form-grid{grid-template-columns:1fr}.field.wide,.field.reason{grid-column:auto}.panel{padding:18px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body>
<header class="top"><div class="brand"><?= $escape($brandName) ?><span>.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap">
  <a href="/admin" class="muted" style="display:inline-block;text-decoration:none;margin-bottom:20px">← Back to control room</a>
  <div class="heading"><div><div class="eyebrow">Publishing / Information architecture</div><h1>Navigation</h1></div><div class="muted">Header and footer · Locale-aware · Versioned by audit</div></div>
  <?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
  <?php if ($saved): ?><div class="success" role="status">Navigation item saved. Public navigation reflects enabled items only.</div><?php endif; ?>
  <section class="panel">
    <div class="item-head"><strong>Add navigation item</strong><span class="status">New</span></div>
    <p class="muted">Create an internal route, published page link or HTTPS external destination. Disabled items remain stored but are not shown publicly.</p>
    <form method="post" action="/admin/navigation">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <div class="form-grid">
        <div class="field"><label for="new_label">Label</label><input id="new_label" name="label" maxlength="120" required></div>
        <div class="field"><label for="new_location">Location</label><select id="new_location" name="location"><option value="header">Header</option><option value="footer">Footer</option></select></div>
        <div class="field"><label for="new_type">Target type</label><select id="new_type" name="target_type"><option value="page">Published page</option><option value="route">Internal route</option><option value="url">External HTTPS URL</option></select></div>
        <div class="field"><label for="new_target">Target</label><input id="new_target" name="target_value" maxlength="500" required placeholder="/pages/about"></div>
        <div class="field"><label for="new_locale">Locale</label><input id="new_locale" name="locale" maxlength="35" pattern="[a-z]{2,3}(-[A-Z]{2})?" value="en" required></div>
        <div class="field"><label for="new_sort">Sort order</label><input id="new_sort" name="sort_order" type="number" min="-10000" max="10000" value="10" required></div>
        <label class="check"><input type="checkbox" name="enabled" value="1" checked> Enabled on public site</label>
        <div class="field reason"><label for="new_reason">Change reason</label><input id="new_reason" name="reason" minlength="8" maxlength="500" required placeholder="Explain why this navigation item is being added"></div>
      </div>
      <div class="actions"><button class="button" type="submit">Add navigation item</button></div>
    </form>
  </section>
  <div class="eyebrow" style="margin:32px 0 14px">Existing items / <?= count($items) ?></div>
  <?php if ($items === []): ?>
    <section class="panel"><p class="muted">No navigation items have been configured. Add the first item above to create a useful public navigation structure.</p></section>
  <?php else: ?>
    <?php foreach ($items as $item): ?>
    <section class="panel">
      <div class="item-head"><strong><?= $escape($item['label'] ?? '') ?></strong><span class="status"><?= !empty($item['enabled']) ? 'Enabled' : 'Disabled' ?></span></div>
      <form method="post" action="<?= $escape('/admin/navigation/' . rawurlencode((string) ($item['id'] ?? ''))) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <div class="form-grid">
          <div class="field"><label>Label</label><input name="label" maxlength="120" required value="<?= $escape($item['label'] ?? '') ?>"></div>
          <div class="field"><label>Location</label><select name="location"><option value="header" <?= ($item['location_key'] ?? '') === 'header' ? 'selected' : '' ?>>Header</option><option value="footer" <?= ($item['location_key'] ?? '') === 'footer' ? 'selected' : '' ?>>Footer</option></select></div>
          <div class="field"><label>Target type</label><select name="target_type"><option value="page" <?= ($item['target_type'] ?? '') === 'page' ? 'selected' : '' ?>>Published page</option><option value="route" <?= ($item['target_type'] ?? '') === 'route' ? 'selected' : '' ?>>Internal route</option><option value="url" <?= ($item['target_type'] ?? '') === 'url' ? 'selected' : '' ?>>External HTTPS URL</option></select></div>
          <div class="field"><label>Target</label><input name="target_value" maxlength="500" required value="<?= $escape($item['target_value'] ?? '') ?>"></div>
          <div class="field"><label>Locale</label><input name="locale" maxlength="35" pattern="[a-z]{2,3}(-[A-Z]{2})?" required value="<?= $escape($item['locale'] ?? 'en') ?>"></div>
          <div class="field"><label>Sort order</label><input name="sort_order" type="number" min="-10000" max="10000" required value="<?= $escape($item['sort_order'] ?? 0) ?>"></div>
          <label class="check"><input type="checkbox" name="enabled" value="1" <?= !empty($item['enabled']) ? 'checked' : '' ?>> Enabled on public site</label>
          <div class="field reason"><label>Change reason</label><input name="reason" minlength="8" maxlength="500" required placeholder="Explain why this item is changing"></div>
        </div>
        <div class="actions"><button class="button secondary" type="submit">Save item</button></div>
      </form>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>
  <p class="muted" style="font-size:11px">All changes require an MFA-verified administrator with CMS permissions. Internal paths and external destinations are validated before publication.</p>
</main>
</body>
</html>
