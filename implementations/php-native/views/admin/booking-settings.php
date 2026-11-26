<?php
declare(strict_types=1);
$brandName=is_string($brandName??null)&&$brandName!==''?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$notice=is_string($notice??null)?$notice:'';
$settings=is_array($settings??null)?$settings:[];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Booking settings · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.top{background:var(--navy);color:#f5f1e8;padding:20px clamp(18px,5vw,64px);display:flex;justify-content:space-between;gap:20px}.brand{font:22px Georgia,serif;letter-spacing:.1em}.top a{color:#e7d6bc;text-decoration:none;font-size:12px}.wrap{max-width:900px;margin:auto;padding:38px 20px 70px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:#92734d;font-weight:700}h1{font:400 clamp(34px,5vw,48px)/1.1 Georgia,serif;margin:8px 0 10px}h2{font:24px Georgia,serif;font-weight:400}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:18px 0}.notice,.error{padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;padding:11px;border:1px solid #d8d3ca;background:white;color:var(--ink);font:13px ui-sans-serif,system-ui,sans-serif}.check{display:flex;gap:10px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--line)}.check input{margin-top:4px;width:18px;height:18px;accent-color:var(--navy)}.check strong{display:block;font-size:13px}.check small{display:block;color:var(--muted);font-size:12px}.button{border:0;background:var(--navy);color:white;padding:12px 18px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;margin-top:18px}.warning{border-left:3px solid var(--gold);padding:12px 14px;background:#f8f5ee;color:#5e503a;font-size:12px;margin:16px 0}@media(max-width:600px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style></head>
<body><header class="top"><div class="brand"><?= $escape($brandName) ?><span style="color:#d4b183">.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><div class="eyebrow">Booking operations / Administrator</div><h1>Booking settings</h1>
<p class="muted">Configure booking mode, lead time, booking horizon, deposit policy and client cancellation windows. Automatic booking remains unavailable until its independent feature and market safeguards are approved.</p>
<?php if($error!==''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if($notice!==''): ?><div class="notice" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<section class="panel"><h2>Booking policy</h2>
<form method="post" action="/admin/bookings/settings"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label for="booking_mode">Booking mode</label><select id="booking_mode" name="booking_mode"><option value="manual" <?= ($settings['booking_mode']??'manual')==='manual'?'selected':'' ?>>Manual review</option><option value="automatic" <?= ($settings['booking_mode']??'')==='automatic'?'selected':'' ?>>Automatic</option></select></div>
<div class="field"><label for="minimum_notice_hours">Minimum notice (hours)</label><input id="minimum_notice_hours" name="minimum_notice_hours" type="number" min="0" max="720" required value="<?= $escape($settings['minimum_notice_hours']??12) ?>"></div>
<div class="field"><label for="maximum_advance_days">Maximum advance (days)</label><input id="maximum_advance_days" name="maximum_advance_days" type="number" min="1" max="365" required value="<?= $escape($settings['maximum_advance_days']??90) ?>"></div>
<div class="field"><label for="cancellation_cutoff_hours">Cancellation cutoff (hours)</label><input id="cancellation_cutoff_hours" name="cancellation_cutoff_hours" type="number" min="0" max="720" required value="<?= $escape($settings['cancellation_cutoff_hours']??2) ?>"></div>
</div>
<label class="check"><input type="checkbox" name="auto_confirm_enabled" value="1" <?= !empty($settings['auto_confirm_enabled'])?'checked':'' ?>><span><strong>Allow automatic confirmation</strong><small>Only applies when automatic booking is operationally enabled.</small></span></label>
<label class="check"><input type="checkbox" name="auto_requires_verified_deposit" value="1" <?= !empty($settings['auto_requires_verified_deposit'])?'checked':'' ?>><span><strong>Require verified deposit</strong><small>Bookings cannot be confirmed while a required deposit is unverified.</small></span></label>
<label class="check"><input type="checkbox" name="client_cancellation_enabled" value="1" <?= !empty($settings['client_cancellation_enabled'])?'checked':'' ?>><span><strong>Allow client cancellation</strong><small>Cancellation requests are subject to the configured cutoff and booking state.</small></span></label>
<div class="warning">Changing these settings does not retroactively alter accepted bookings, immutable quotes, or existing payment obligations. Automatic mode is rejected until the bookings.automatic feature gate is operationally enabled.</div>
<button class="button" type="submit">Save booking settings</button></form>
</section></main></body></html>
