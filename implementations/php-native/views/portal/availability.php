<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$profile = is_array($profile ?? null) ? $profile : null;
$items = is_array($items ?? null) ? $items : [];
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$weekdays = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$today = gmdate('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Availability · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(1100px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:18px}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.06em}.nav{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--muted)}.intro{padding:42px 0 18px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,52px)/1.08 Georgia,serif;margin:10px 0}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:18px 0}.form-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field label{font-size:11px;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;min-width:0;padding:11px;border:1px solid #d8d3ca;background:white;font:inherit;color:var(--ink)}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;border:0;padding:11px 16px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.quiet{background:transparent;color:var(--muted);border:1px solid var(--line)}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39;padding:12px 14px;margin:14px 0}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:700px}th,td{text-align:left;vertical-align:middle;padding:12px 10px;border-bottom:1px solid var(--line)}th{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}td{font-size:12px}.mono{font:11px ui-monospace,SFMono-Regular,Consolas,monospace}.empty{padding:26px;text-align:center;color:var(--muted)}.remove{padding:8px 11px;background:transparent;border:1px solid #d7b9b2;color:#843d34;font-size:10px;font-weight:700;text-transform:uppercase;cursor:pointer}.help{font-size:12px;color:var(--muted);margin:12px 0 0}.footer{padding:10px 0 50px;color:var(--muted);font-size:12px}@media(max-width:760px){.form-grid{grid-template-columns:1fr 1fr}}@media(max-width:480px){.form-grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/portal"><?= $escape($brandName) ?></a><nav class="nav"><a href="/portal">Account</a><a href="/portal/companion/bookings">Booking requests</a></nav></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Companion workspace · Availability</div><h1>Your weekly schedule</h1><p class="muted">Manage the local time windows when you may receive booking requests. Times use your profile destination's IANA time zone, not your device's time zone. Changes do not confirm or alter existing bookings.</p></section>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<?php if ($profile === null): ?>
<section class="panel"><h2>No companion profile is linked</h2><p class="muted">Availability management becomes available after your agency links a companion profile to this account.</p></section>
<?php else: ?>
<section class="panel">
<div class="eyebrow">Profile schedule</div><h2><?= $escape($profile['display_name'] ?? '') ?></h2>
<p class="muted"><?= $escape($profile['destination_name'] ?? '') ?> · <strong><?= $escape($profile['time_zone'] ?? '') ?></strong></p>
<form method="post" action="/portal/companion/availability">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="profile_id" value="<?= $escape($profile['id'] ?? '') ?>">
<div class="form-grid">
<div class="field"><label for="weekday">Weekday</label><select id="weekday" name="weekday" required><?php foreach ($weekdays as $day => $label): ?><option value="<?= $escape($day) ?>"><?= $escape($label) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="local_start">Available from (local time)</label><input id="local_start" name="local_start" type="time" required></div>
<div class="field"><label for="local_end">Available until (local time)</label><input id="local_end" name="local_end" type="time" required></div>
<div class="field"><label for="effective_from">Effective from</label><input id="effective_from" name="effective_from" type="date" value="<?= $escape($today) ?>" required></div>
<div class="field"><label for="effective_until">Effective until (optional)</label><input id="effective_until" name="effective_until" type="date" min="<?= $escape($today) ?>"></div>
</div>
<p class="help">Windows must be within one local day and cannot overlap another active window for the same weekday and effective period. Saving an existing weekday/start-date window updates its end time.</p>
<div class="actions"><button class="button" type="submit">Save availability window</button><a class="button quiet" href="/portal">Return to account</a></div>
</form>
</section>
<section class="panel" aria-label="Saved availability windows">
<div class="eyebrow">Current schedule</div><h2>Saved windows</h2>
<?php if ($items === []): ?><div class="empty">No weekly availability windows have been saved yet.</div><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Weekday</th><th>Local time</th><th>Effective dates</th><th>Time zone</th><th>Action</th></tr></thead><tbody>
<?php foreach ($items as $item): $day=(int)($item['weekday']??-1); ?>
<tr><td><strong><?= $escape($weekdays[$day] ?? 'Unknown') ?></strong></td><td class="mono"><?= $escape(substr((string)($item['local_start']??''),0,5)) ?>–<?= $escape(substr((string)($item['local_end']??''),0,5)) ?></td><td><?= $escape($item['effective_from']??'') ?> to <?= $escape($item['effective_until']??'No end date') ?></td><td class="mono"><?= $escape($item['time_zone']??'') ?></td><td><form method="post" action="/portal/companion/availability/<?= $escape($item['id']??'') ?>/delete" onsubmit="return confirm('Remove this availability window?')"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="profile_id" value="<?= $escape($profile['id']??'') ?>"><button class="remove" type="submit">Remove</button></form></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
</section>
<?php endif; ?>
<footer class="footer">Availability is a scheduling preference, not a guarantee of booking acceptance. You retain control over individual booking requests.</footer>
</main>
</body>
</html>
