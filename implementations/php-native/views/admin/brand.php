<?php
declare(strict_types=1);

$brand = is_array($brand ?? null) ? $brand : [];
$error = is_string($error ?? null) ? $error : '';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$saved = ($saved ?? false) === true;
$colors = is_array($brand['color_tokens'] ?? null) ? $brand['color_tokens'] : [];
$typography = is_array($brand['typography'] ?? null) ? $brand['typography'] : [];
$contacts = is_array($brand['contact_details'] ?? null) ? $brand['contact_details'] : [];
$social = is_array($brand['social_links'] ?? null) ? $brand['social_links'] : [];
$seo = is_array($brand['seo_defaults'] ?? null) ? $brand['seo_defaults'] : [];
$color = static function (string $key, string $fallback) use ($colors): string {
    $value = $colors[$key] ?? null;
    return is_string($value) && preg_match('/^#[0-9a-f]{6}$/iD', $value) === 1 ? $value : $fallback;
};
$brandName = is_string($brand['brand_name'] ?? null) ? $brand['brand_name'] : 'Talyvane';
$primary = $color('primary','#17233B');
$accent = $color('accent','#C58B43');
$surface = $color('surface','#F7F5F0');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Brand identity · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--navy:#142235;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.top{height:74px;background:var(--navy);color:#f5f1e8;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,5vw,72px)}.brand{font:23px Georgia,serif;letter-spacing:.12em}.brand span{color:#d4b183}.top a{color:#e7d6bc;text-decoration:none;font-size:12px;letter-spacing:.08em}.wrap{max-width:1120px;margin:0 auto;padding:clamp(28px,5vw,64px) 24px}.eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:10px;color:#9a774e;font-weight:700}.heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:30px}.heading h1{font:clamp(34px,4vw,48px)/1.05 Georgia,serif;font-weight:400;margin:12px 0 0}.muted{color:var(--muted);font-size:13px;line-height:1.7}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,32px);box-shadow:0 14px 40px rgba(23,35,51,.04);margin-bottom:22px}.layout{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(260px,.6fr);gap:22px}.field-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.field{display:grid;gap:8px;margin:13px 0}.field.full{grid-column:1/-1}.field label{font-size:11px;font-weight:700;letter-spacing:.06em}.field input,.field select{width:100%;border:1px solid #d9d5cc;border-radius:2px;background:white;padding:12px 13px;font:13px ui-sans-serif,system-ui,sans-serif;color:var(--ink)}.field input[type=color]{height:46px;padding:5px}.field input:focus,.field select:focus{border-color:#9a774e;outline:3px solid rgba(189,149,96,.15)}.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:2px;background:var(--navy);color:white;text-decoration:none;padding:14px 18px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;cursor:pointer}.button:focus-visible,a:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--gold);outline-offset:3px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin-bottom:18px;font-size:13px}.success{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39;padding:12px 14px;margin-bottom:18px;font-size:13px}.preview{border:1px solid var(--line);overflow:hidden;background:white}.preview-top{padding:20px;background:<?= $escape($primary) ?>;color:white;display:flex;align-items:center;justify-content:space-between;gap:12px}.preview-top strong{font:22px Georgia,serif;font-weight:400}.preview-top span{color:<?= $escape($accent) ?>}.preview-body{padding:22px;background:<?= $escape($surface) ?>}.preview-body h2{font:28px Georgia,serif;font-weight:400;margin:0 0 8px}.swatches{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:18px}.swatch{height:38px;border:1px solid rgba(0,0,0,.08);border-radius:2px}.note{border-left:3px solid var(--gold);background:#f7f3eb;padding:13px 15px;font-size:12px;color:var(--muted);line-height:1.7;margin:18px 0}.section-title{font:22px Georgia,serif;font-weight:400;margin:0 0 8px}.divider{height:1px;background:var(--line);margin:24px 0}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:24px}
@media(max-width:800px){.layout{grid-template-columns:1fr}.heading{align-items:start;flex-direction:column}}@media(max-width:600px){.top{height:62px}.wrap{padding:30px 16px}.field-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.panel{padding:18px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body>
<header class="top"><div class="brand"><?= $escape($brandName) ?><span>.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap">
  <a href="/admin" class="muted" style="display:inline-block;text-decoration:none;margin-bottom:20px">← Back to control room</a>
  <div class="heading"><div><div class="eyebrow">Configuration / Identity</div><h1>Brand identity</h1></div><div class="muted">Super Admin only · Version <?= $escape($brand['version'] ?? 0) ?></div></div>
  <?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
  <?php if ($saved): ?><div class="success" role="status">Brand settings saved. Public and administrative surfaces now use the updated identity.</div><?php endif; ?>
  <div class="layout">
    <section class="panel">
      <form method="post" action="/admin/brand">
        <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
        <h2 class="section-title">Identity and palette</h2>
        <p class="muted">These settings define the white-label deployment identity. Changes are versioned and audited.</p>
        <div class="field-grid">
          <div class="field"><label for="brand_name">Brand name</label><input id="brand_name" name="brand_name" maxlength="120" required value="<?= $escape($brandName) ?>"></div>
          <div class="field"><label for="tagline">Tagline</label><input id="tagline" name="tagline" maxlength="190" value="<?= $escape($brand['tagline'] ?? '') ?>"></div>
          <div class="field"><label for="color_primary">Primary color</label><input id="color_primary" name="color_primary" type="color" value="<?= $escape($primary) ?>"></div>
          <div class="field"><label for="color_accent">Accent color</label><input id="color_accent" name="color_accent" type="color" value="<?= $escape($accent) ?>"></div>
          <div class="field"><label for="color_surface">Surface color</label><input id="color_surface" name="color_surface" type="color" value="<?= $escape($surface) ?>"></div>
          <div class="field"><label for="font_heading">Heading font token</label><input id="font_heading" name="font_heading" maxlength="120" value="<?= $escape($typography['heading'] ?? 'Georgia') ?>"></div>
          <div class="field"><label for="font_body">Body font token</label><input id="font_body" name="font_body" maxlength="120" value="<?= $escape($typography['body'] ?? 'Inter') ?>"></div>
        </div>
        <div class="divider"></div>
        <h2 class="section-title">Contact and social</h2>
        <div class="field-grid">
          <div class="field"><label for="contact_email">Public contact email</label><input id="contact_email" name="contact_email" type="email" maxlength="254" value="<?= $escape($contacts['email'] ?? '') ?>"></div>
          <div class="field"><label for="contact_phone">Public contact phone</label><input id="contact_phone" name="contact_phone" maxlength="40" value="<?= $escape($contacts['phone'] ?? '') ?>"></div>
          <div class="field full"><label for="contact_website">Public website URL</label><input id="contact_website" name="contact_website" type="url" maxlength="500" placeholder="https://example.com" value="<?= $escape($contacts['website'] ?? '') ?>"></div>
          <div class="field"><label for="social_instagram">Instagram URL</label><input id="social_instagram" name="social_instagram" type="url" maxlength="500" value="<?= $escape($social['instagram'] ?? '') ?>"></div>
          <div class="field"><label for="social_linkedin">LinkedIn URL</label><input id="social_linkedin" name="social_linkedin" type="url" maxlength="500" value="<?= $escape($social['linkedin'] ?? '') ?>"></div>
          <div class="field"><label for="social_facebook">Facebook URL</label><input id="social_facebook" name="social_facebook" type="url" maxlength="500" value="<?= $escape($social['facebook'] ?? '') ?>"></div>
          <div class="field"><label for="social_x">X URL</label><input id="social_x" name="social_x" type="url" maxlength="500" value="<?= $escape($social['x'] ?? '') ?>"></div>
        </div>
        <div class="divider"></div>
        <h2 class="section-title">Search appearance</h2>
        <div class="field-grid">
          <div class="field"><label for="seo_title_suffix">SEO title suffix</label><input id="seo_title_suffix" name="seo_title_suffix" maxlength="120" value="<?= $escape($seo['title_suffix'] ?? '') ?>"></div>
          <div class="field"><label for="seo_robots">Default robots policy</label><select id="seo_robots" name="seo_robots"><option value="index,follow" <?= ($seo['robots'] ?? 'index,follow') === 'index,follow' ? 'selected' : '' ?>>Index and follow</option><option value="noindex,nofollow" <?= ($seo['robots'] ?? '') === 'noindex,nofollow' ? 'selected' : '' ?>>No index, no follow</option></select></div>
        </div>
        <div class="field full"><label for="reason">Change reason</label><input id="reason" name="reason" minlength="8" maxlength="500" required placeholder="Explain why these brand settings are changing"></div>
        <div class="actions"><button class="button" type="submit">Save brand identity</button></div>
      </form>
    </section>
    <aside>
      <section class="panel">
        <div class="eyebrow">Live preview</div>
        <div class="preview" style="margin-top:14px">
          <div class="preview-top"><strong><?= $escape($brandName) ?><span>.</span></strong><small>MENU</small></div>
          <div class="preview-body"><h2><?= $escape($brand['tagline'] ?? 'Your brand, thoughtfully presented') ?></h2><p class="muted">A preview of the configured color system.</p><div class="swatches"><div class="swatch" style="background:<?= $escape($primary) ?>"></div><div class="swatch" style="background:<?= $escape($accent) ?>"></div><div class="swatch" style="background:<?= $escape($surface) ?>"></div></div></div>
        </div>
        <p class="note">This preview reflects saved values. Public templates currently consume the brand name and tagline; broader token application is being ported incrementally.</p>
      </section>
      <section class="panel">
        <h2 class="section-title">Media identity</h2>
        <p class="muted">Private logo and favicon assets are retained if already configured. New uploads are not exposed here until the private media workflow is available.</p>
        <div class="note"><?= !empty($brand['logo_storage_key']) ? 'A private logo asset is configured.' : 'No logo asset is currently configured.' ?> <?= !empty($brand['favicon_storage_key']) ? 'A private favicon asset is configured.' : 'No favicon asset is currently configured.' ?></div>
      </section>
    </aside>
  </div>
</main>
</body>
</html>
