<?php
declare(strict_types=1);
$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$saved = ($saved ?? false) === true;
$items = is_array($items ?? null) ? $items : [];
$assignees = is_array($assignees ?? null) ? $assignees : [];
$statuses = ['new'=>'New','in_progress'=>'In progress','responded'=>'Responded','closed'=>'Closed','spam'=>'Spam'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Enquiries · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--navy:#142235}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,sans-serif}.top{height:74px;background:var(--navy);color:#f5f1e8;display:flex;align-items:center;justify-content:space-between;padding:0 clamp(20px,5vw,72px)}.brand{font:23px Georgia,serif;letter-spacing:.12em}.brand span{color:#d4b183}.top a{color:#e7d6bc;text-decoration:none;font-size:12px;letter-spacing:.08em}.wrap{max-width:1240px;margin:0 auto;padding:clamp(28px,5vw,62px) 22px}.eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:10px;color:#9a774e;font-weight:700}.heading{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:28px}.heading h1{font:clamp(36px,5vw,52px)/1.05 Georgia,serif;font-weight:400;margin:12px 0 0}.muted{color:var(--muted);font-size:13px;line-height:1.7}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(16px,3vw,26px);box-shadow:0 14px 40px rgba(23,35,51,.04);margin-bottom:16px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:#8b3535;padding:12px 14px;margin-bottom:18px;font-size:13px}.success{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39;padding:12px 14px;margin-bottom:18px;font-size:13px}.item{display:grid;grid-template-columns:minmax(0,1fr) minmax(220px,300px);gap:24px;align-items:start}.reference{font-size:10px;letter-spacing:.13em;color:#9a774e;font-weight:700}.name{font:22px Georgia,serif;margin:7px 0}.details{font-size:13px;line-height:1.8;color:var(--muted);overflow-wrap:anywhere}.message{white-space:pre-wrap;color:var(--ink);font-size:13px;line-height:1.75;margin-top:14px;overflow-wrap:anywhere}.field{display:grid;gap:7px;margin:8px 0}.field label{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.field select,.field input{width:100%;border:1px solid #d9d5cc;background:white;border-radius:2px;padding:11px;color:var(--ink);font:13px ui-sans-serif,system-ui,sans-serif}.button{border:0;background:var(--navy);color:white;padding:12px 16px;font-size:10px;letter-spacing:.1em;text-transform:uppercase;font-weight:700;cursor:pointer}.button:focus-visible,a:focus-visible,select:focus-visible,input:focus-visible{outline:3px solid var(--gold);outline-offset:3px}.empty{padding:44px 20px;text-align:center}.empty strong{font:25px Georgia,serif;font-weight:400}.empty p{color:var(--muted);font-size:13px}@media(max-width:760px){.top{height:62px}.heading{align-items:start;flex-direction:column}.item{grid-template-columns:1fr}.panel{padding:17px}}
</style></head>
<body><header class="top"><div class="brand"><?= $escape($brandName) ?><span>.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><a href="/admin" class="muted" style="display:inline-block;text-decoration:none;margin-bottom:20px">← Back to control room</a>
<div class="heading"><div><div class="eyebrow">Customer relations / Inbox</div><h1>Enquiries</h1></div><div class="muted"><?= count($items) ?> most recent enquiries · Private administrative workspace</div></div>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if ($saved): ?><div class="success" role="status">Enquiry updated and audit event recorded.</div><?php endif; ?>
<?php if ($items === []): ?><section class="panel empty"><strong>Your inbox is clear.</strong><p>New public enquiries will appear here.</p></section><?php else: ?>
<?php foreach ($items as $item): ?><section class="panel"><div class="item"><div>
<div class="reference"><?= $escape($item['reference'] ?? '') ?> · <?= $escape($item['created_at'] ?? '') ?></div>
<div class="name"><?= $escape($item['name'] ?? '') ?></div>
<div class="details"><?= $escape($item['email'] ?? '') ?><?php if (!empty($item['phone'])): ?> · <?= $escape($item['phone']) ?><?php endif; ?><?php if (!empty($item['city'])): ?><br><?= $escape($item['city']) ?><?php endif; ?></div>
<div class="message"><strong><?= $escape($item['subject'] ?? '') ?></strong>
<?= $escape($item['message'] ?? '') ?></div>
</div><div><div class="field"><label>Pipeline status</label><form method="post" action="<?= $escape('/admin/enquiries/' . rawurlencode((string) ($item['reference'] ?? ''))) ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<select name="status"><?php foreach ($statuses as $key=>$label): ?><option value="<?= $escape($key) ?>" <?= ($item['status'] ?? '') === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select>
<div class="field"><label>Change reason</label><input name="reason" minlength="8" maxlength="500" required placeholder="Reason for status change"></div>
<button class="button" type="submit">Save status</button></form></div>
<form method="post" action="<?= $escape('/admin/enquiries/' . rawurlencode((string) ($item['reference'] ?? '')) . '/assign') ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label>Assigned administrator</label><select name="assignee_id"><option value="">Unassigned</option><?php foreach ($assignees as $assignee): ?><option value="<?= $escape($assignee['id']) ?>" <?= ($item['assigned_to'] ?? '') === $assignee['id'] ? 'selected' : '' ?>><?= $escape($assignee['email']) ?> (<?= $escape($assignee['role_key']) ?>)</option><?php endforeach; ?></select></div>
<div class="field"><label>Assignment reason</label><input name="reason" minlength="8" maxlength="500" required placeholder="Reason for assignment"></div>
<button class="button" type="submit">Save assignment</button>
</form>
<div class="muted" style="font-size:11px">Responded: <?= $escape($item['responded_at'] ?? '—') ?><br>Closed: <?= $escape($item['closed_at'] ?? '—') ?></div></div></div></section><?php endforeach; ?>
<?php endif; ?>
</main></body></html>
