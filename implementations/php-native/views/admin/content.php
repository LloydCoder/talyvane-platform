<?php
declare(strict_types=1);

$mode = is_string($mode ?? null) ? $mode : 'list';
$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$error = is_string($error ?? null) ? $error : '';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$pages = is_array($pages ?? null) ? $pages : [];
$page = is_array($page ?? null) ? $page : [];
$id = is_string($page['id'] ?? null) ? $page['id'] : '';
$editing = $mode === 'edit';
$action = $id === '' ? '/admin/content' : '/admin/content/' . rawurlencode($id);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $editing ? 'Edit page' : 'Content library' ?> · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--navy:#142235;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.top{height:74px;background:var(--navy);color:#f5f1e8;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,5vw,72px)}.brand{font:23px Georgia,serif;letter-spacing:.12em}.brand span{color:#d4b183}.top a{color:#e7d6bc;text-decoration:none;font-size:12px;letter-spacing:.08em}.wrap{max-width:1120px;margin:0 auto;padding:clamp(28px,5vw,64px) 24px}.eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:10px;color:#9a774e;font-weight:700}.heading{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:30px}.heading h1{font:clamp(34px,4vw,48px)/1.05 Georgia,serif;font-weight:400;margin:12px 0 0}.muted{color:var(--muted);font-size:13px;line-height:1.7}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,32px);box-shadow:0 14px 40px rgba(23,35,51,.04)}.button{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:2px;background:var(--navy);color:white;text-decoration:none;padding:13px 17px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;cursor:pointer}.button.secondary{background:transparent;color:var(--ink);border:1px solid #cfc8bb}.button:focus-visible,a:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:3px solid var(--gold);outline-offset:3px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin-bottom:20px;font-size:13px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:680px}th{text-align:left;color:var(--muted);font-size:10px;letter-spacing:.12em;text-transform:uppercase;padding:13px 12px;border-bottom:1px solid var(--line)}td{padding:16px 12px;border-bottom:1px solid var(--line);font-size:13px;vertical-align:middle}td strong{font-weight:600}td small{display:block;color:var(--muted);margin-top:5px}.status{display:inline-block;border:1px solid var(--line);padding:5px 8px;font-size:10px;letter-spacing:.08em;text-transform:uppercase}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}.field{display:grid;gap:8px;margin:14px 0}.field.full{grid-column:1/-1}.field label{font-size:11px;font-weight:700;letter-spacing:.06em}.field input,.field select,.field textarea{width:100%;border:1px solid #d9d5cc;border-radius:2px;background:white;padding:13px 14px;font:13px/1.6 ui-sans-serif,system-ui,sans-serif;color:var(--ink)}.field textarea{min-height:280px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Consolas,monospace}.field input:focus,.field textarea:focus,.field select:focus{border-color:#9a774e;outline:3px solid rgba(189,149,96,.15)}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px}.actions form{margin:0}.actions input{border:1px solid #d9d5cc;border-radius:2px;padding:12px 14px;font:13px ui-sans-serif,system-ui,sans-serif;color:var(--ink);min-width:min(320px,100%)}.actions label{font-size:11px;font-weight:700;letter-spacing:.06em}.notice{border-left:3px solid var(--gold);background:#f7f3eb;padding:13px 16px;font-size:12px;color:var(--muted);line-height:1.7;margin:20px 0 0}.back{display:inline-block;color:var(--muted);text-decoration:none;font-size:12px;margin-bottom:20px}
@media(max-width:680px){.top{height:62px}.heading{align-items:start;flex-direction:column}.form-grid{grid-template-columns:1fr}.field.full{grid-column:auto}.wrap{padding:30px 16px}.panel{padding:18px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body>
<header class="top"><div class="brand"><?= $escape($brandName) ?><span>.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap">
  <a class="back" href="/admin">← Back to control room</a>
  <div class="heading">
    <div><div class="eyebrow">Publishing / Website</div><h1><?= $editing ? ($id === '' ? 'Create a page' : 'Edit page') : 'Content library' ?></h1></div>
    <?php if (!$editing): ?><a class="button" href="/admin/content/new">Create page</a><?php endif; ?>
  </div>
  <?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

  <?php if (!$editing): ?>
  <section class="panel">
    <p class="muted">Manage public information pages. Draft content is private until an authorised administrator publishes it. Content is rendered as text on the public site; HTML and scripts are not executed.</p>
    <?php if ($pages === []): ?>
      <div class="notice">No pages have been created yet. Create the first page to begin building the site's information architecture.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Page</th><th>Locale</th><th>Status</th><th>Version</th><th>Last updated</th><th></th></tr></thead>
      <tbody><?php foreach ($pages as $item): ?>
      <tr>
        <td><strong><?= $escape($item['title'] ?? '') ?></strong><small>/pages/<?= $escape($item['slug'] ?? '') ?></small></td>
        <td><?= $escape($item['locale'] ?? '') ?></td>
        <td><span class="status"><?= $escape($item['status'] ?? '') ?></span></td>
        <td><?= $escape($item['version'] ?? '') ?></td>
        <td><?= $escape($item['updated_at'] ?? '') ?></td>
        <td><a href="/admin/content/<?= $escape($item['id'] ?? '') ?>" class="button secondary">Edit</a></td>
      </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </section>
  <?php else: ?>
  <section class="panel">
    <p class="muted">Create a versioned content page. Keep copy factual and ensure publication rights and policy approvals are in place before publishing.</p>
    <form method="post" action="<?= $escape($action) ?>">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <div class="form-grid">
        <div class="field"><label for="title">Page title</label><input id="title" name="title" maxlength="190" required value="<?= $escape($page['title'] ?? '') ?>"></div>
        <div class="field"><label for="slug">URL slug</label><input id="slug" name="slug" maxlength="190" pattern="[a-z0-9]+(-[a-z0-9]+)*" required value="<?= $escape($page['slug'] ?? '') ?>"></div>
        <div class="field"><label for="locale">Language / locale</label><input id="locale" name="locale" maxlength="35" pattern="[a-z]{2,3}(-[A-Z]{2})?" required value="<?= $escape($page['locale'] ?? 'en') ?>"></div>
        <div class="field"><label for="meta_title">SEO title</label><input id="meta_title" name="meta_title" maxlength="190" value="<?= $escape($page['meta_title'] ?? '') ?>"></div>
        <div class="field full"><label for="meta_description">SEO description</label><input id="meta_description" name="meta_description" maxlength="320" value="<?= $escape($page['meta_description'] ?? '') ?>"></div>
        <div class="field full"><label for="content">Page content</label><textarea id="content" name="content" maxlength="200000" required><?= $escape($page['content'] ?? '') ?></textarea><span class="muted">Plain text only. Line breaks are preserved. HTML, embeds and scripts are intentionally not interpreted.</span></div>
        <div class="field full"><label for="reason">Change reason</label><input id="reason" name="reason" maxlength="500" minlength="4" required placeholder="Describe why this page is being created or changed"></div>
      </div>
      <div class="actions"><button class="button" type="submit">Save draft</button><a class="button secondary" href="/admin/content">Cancel</a></div>
    </form>
    <?php if ($id !== ''): ?>
    <div class="notice">Current state: <strong><?= $escape($page['status'] ?? 'draft') ?></strong> · Version <?= $escape($page['version'] ?? 1) ?>. Publishing is a separate, reasoned action.</div>
    <?php if (($page['status'] ?? '') !== 'published'): ?>
    <form method="post" action="<?= $escape('/admin/content/' . rawurlencode($id) . '/publish') ?>" class="actions">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <label class="muted" for="publication_reason">Publication reason</label><input id="publication_reason" name="reason" minlength="8" maxlength="500" required placeholder="Explain why this page is approved for publication">
      <button class="button" type="submit">Publish page</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="muted" style="margin-top:22px;font-size:11px">All write operations require an authenticated administrator with MFA. Changes are versioned and recorded in the audit log.</p>
</main>
</body>
</html>
