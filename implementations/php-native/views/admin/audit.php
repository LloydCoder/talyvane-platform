<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) ? $brandName : 'Talyvane';
$items = is_array($items ?? null) ? $items : [];
$filters = is_array($filters ?? null) ? $filters : [];
$error = is_string($error ?? null) ? $error : '';
$page = is_int($page ?? null) ? $page : 1;
$previousUrl = is_string($previousUrl ?? null) ? $previousUrl : null;
$nextUrl = is_string($nextUrl ?? null) ? $nextUrl : null;
$escape = static fn(mixed $value): string => htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$field = static fn(string $key): string => is_string($filters[$key] ?? null) ? $filters[$key] : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Security audit · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.55 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(1240px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:18px}.brand{font:600 22px Georgia,serif;text-decoration:none;color:var(--ink)}.top a:last-child{color:var(--muted);font-size:12px}.intro{padding:42px 0 24px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,54px)/1.08 Georgia,serif;margin:10px 0}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:22px;margin:18px 0}.filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field label{font-size:11px;font-weight:700;color:var(--muted)}.field input{width:100%;min-width:0;padding:11px;border:1px solid #d8d3ca;background:white;font:inherit;color:var(--ink)}.actions{display:flex;gap:10px;align-items:center;margin-top:16px}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;border:0;padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.quiet{background:transparent;color:var(--muted);border:1px solid var(--line)}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin:14px 0}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:920px}th,td{text-align:left;vertical-align:top;padding:12px 10px;border-bottom:1px solid var(--line)}th{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}td{font-size:12px;overflow-wrap:anywhere}.event{font-weight:700;color:var(--ink)}.mono{font:11px ui-monospace,SFMono-Regular,Consolas,monospace}.reason{max-width:260px}.pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:18px}.empty{padding:30px;text-align:center;color:var(--muted)}.notice{font-size:12px;color:var(--muted);margin:12px 0 0}@media(max-width:760px){.filters{grid-template-columns:1fr 1fr}.top{align-items:flex-start}}@media(max-width:480px){.filters{grid-template-columns:1fr}.panel{padding:15px}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/admin"><?= $escape($brandName) ?></a><a href="/admin">Return to control room</a></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Privileged operations · Super Admin</div><h1>Security audit trail</h1><p class="muted">A read-only view of recorded administrative and application events. Sensitive before/after payloads are deliberately excluded from this workspace.</p></section>
<?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<section class="panel" aria-label="Audit filters">
<form method="get" action="/admin/audit">
<div class="filters">
<div class="field"><label for="event_key">Event key</label><input id="event_key" name="event_key" maxlength="190" value="<?= $escape($field('event_key')) ?>"></div>
<div class="field"><label for="target_type">Target type</label><input id="target_type" name="target_type" maxlength="190" value="<?= $escape($field('target_type')) ?>"></div>
<div class="field"><label for="target_id">Target reference</label><input id="target_id" name="target_id" maxlength="190" value="<?= $escape($field('target_id')) ?>"></div>
<div class="field"><label for="actor_user_id">Actor ID</label><input id="actor_user_id" name="actor_user_id" maxlength="190" value="<?= $escape($field('actor_user_id')) ?>"></div>
<div class="field"><label for="from">From (UTC date)</label><input id="from" name="from" type="date" value="<?= $escape($field('from')) ?>"></div>
<div class="field"><label for="to">To (UTC date)</label><input id="to" name="to" type="date" value="<?= $escape($field('to')) ?>"></div>
</div><div class="actions"><button class="button" type="submit">Apply filters</button><a class="button quiet" href="/admin/audit">Clear</a></div>
</form><p class="notice">Results are limited to 50 events per page and ordered newest first. All dates are UTC.</p>
</section>
<section class="panel" aria-label="Audit events">
<div class="table-wrap"><table><thead><tr><th>Occurred (UTC)</th><th>Event</th><th>Actor ID</th><th>Target</th><th>Reason</th><th>Request ID</th><th>Policy</th></tr></thead><tbody>
<?php if ($items === []): ?><tr><td colspan="7" class="empty">No audit events match these filters.</td></tr><?php else: foreach ($items as $item): ?>
<tr><td><?= $escape($item['occurred_at'] ?? '') ?></td><td class="event"><?= $escape($item['event_key'] ?? '') ?></td><td class="mono"><?= $escape($item['actor_user_id'] ?? 'system') ?></td><td><strong><?= $escape($item['target_type'] ?? '') ?></strong><br><span class="mono"><?= $escape($item['target_id'] ?? '') ?></span></td><td class="reason"><?= $escape($item['reason'] ?? '') ?></td><td class="mono"><?= $escape($item['request_id'] ?? '') ?></td><td><?= $escape($item['policy_version'] ?? '') ?></td></tr>
<?php endforeach; endif; ?>
</tbody></table></div>
<nav class="pagination" aria-label="Audit pagination"><span class="muted">Page <?= $escape($page) ?></span><span class="actions"><?php if ($previousUrl !== null): ?><a class="button quiet" href="<?= $escape($previousUrl) ?>">Previous</a><?php endif; ?><?php if ($nextUrl !== null): ?><a class="button" href="<?= $escape($nextUrl) ?>">Next</a><?php endif; ?></span></nav>
</section>
</main>
</body>
</html>
