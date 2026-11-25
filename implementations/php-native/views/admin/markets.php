<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$items = is_array($items ?? null) ? $items : [];
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$defaultExpiry = gmdate('Y-m-d',time() + 365 * 86400);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Market compliance · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(1180px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:18px}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.06em}.nav{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted)}.intro{padding:42px 0 20px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,52px)/1.08 Georgia,serif;margin:10px 0}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:18px 0}.items{display:grid;gap:16px}.item{background:#fff;border:1px solid var(--line);padding:18px}.item-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.item h2{font:400 23px Georgia,serif;margin:0}.meta{font-size:11px;color:var(--muted)}.pill{display:inline-block;border:1px solid var(--line);padding:3px 8px;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.field{display:grid;gap:6px;margin-top:14px}.field label{font-size:11px;font-weight:700;color:var(--muted)}.field input,.field select,.field textarea{width:100%;min-width:0;padding:11px;border:1px solid #d8d3ca;background:white;font:inherit;color:var(--ink)}.check{display:flex;gap:9px;align-items:flex-start;margin:15px 0;font-size:12px;color:var(--muted)}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;border:0;padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39;padding:12px 14px;margin:14px 0}.help{font-size:12px;color:var(--muted);margin:12px 0 0}@media(max-width:760px){.grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.grid{grid-template-columns:1fr}.top,.item-head{flex-direction:column}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/admin"><?= $escape($brandName) ?></a><nav class="nav"><a href="/admin">Control room</a><a href="/admin/destinations">Destinations</a><a href="/admin/audit">Audit trail</a></nav></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Super Admin · Global compliance</div><h1>Market approval register</h1><p class="muted">Country configuration is not legal permission to operate. Each approval requires a named evidence reference, substantive review findings, an explicit decision and an expiry date. Only a current approval signed by an MFA-protected Super Admin can enable destination publication.</p></section>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<section class="panel"><div class="eyebrow">ISO country register</div><h2>Country market decisions</h2>
<?php if ($items === []): ?><p class="muted">No country reference data has been imported. Load a verified ISO 3166 dataset before recording market approvals.</p><?php else: ?>
<div class="items">
<?php foreach ($items as $item): ?>
<article class="item">
<div class="item-head"><div><h2><?= $escape($item['name'] ?? '') ?></h2><p class="meta"><?= $escape($item['alpha2'] ?? '') ?><?= !empty($item['alpha3']) ? ' · '.$escape($item['alpha3']) : '' ?> · Country record: <?= (bool)($item['country_enabled']??false) ? 'enabled' : 'disabled' ?></p></div><div><span class="pill"><?= $escape($item['decision'] ?? 'not reviewed') ?></span> <span class="pill"><?= (bool)($item['market_enabled']??false) ? 'enabled' : 'disabled' ?></span></div></div>
<?php if (!empty($item['reviewed_at'])): ?><p class="meta">Last reviewed <?= $escape($item['reviewed_at']) ?> · Expires <?= $escape($item['expires_at'] ?? '') ?></p><p class="meta">Evidence: <?= $escape($item['evidence_reference'] ?? '') ?></p><p class="meta"><?= $escape($item['review_summary'] ?? '') ?></p><?php endif; ?>
<form method="post" action="/admin/markets/<?= $escape($item['alpha2'] ?? '') ?>/review">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label>Decision</label><select name="decision" required><option value="approved">Approve</option><option value="rejected">Reject</option></select></div>
<div class="field"><label>Approval expiry (UTC date)</label><input name="expires_at" type="date" min="<?= $escape(gmdate('Y-m-d',time()+86400)) ?>" max="<?= $escape(gmdate('Y-m-d',time()+730*86400)) ?>" value="<?= $escape($defaultExpiry) ?>" required></div>
<div class="field"><label>Evidence reference</label><input name="evidence_reference" maxlength="500" minlength="8" required placeholder="Legal review ID / secure record"></div>
</div>
<div class="field"><label>Review summary</label><textarea name="review_summary" rows="3" minlength="20" maxlength="2000" required placeholder="Describe the jurisdiction-specific legal and operational review, its scope and limitations."></textarea></div>
<label class="check"><input type="checkbox" name="enable_market" value="1"> Enable this market for destination publication and booking after approval</label>
<label class="check"><input type="checkbox" name="review_confirmed" value="1" required> I confirm that the evidence is genuine, current and specific to this operating model and country</label>
<button class="button" type="submit">Record compliance decision</button>
</form>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<p class="help">Approvals expire automatically. Rejected, disabled, missing or expired market policies fail closed. Review history is recorded in the immutable security audit trail.</p>
</section>
</main>
</body>
</html>
