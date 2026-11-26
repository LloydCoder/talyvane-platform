<?php
declare(strict_types=1);
$brandName=is_string($brandName??null)&&$brandName!==''?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$notice=is_string($notice??null)?$notice:'';
$settings=is_array($settings??null)?$settings:[];
$currencies=is_array($currencies??null)?$currencies:[];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Globalization · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.top{background:var(--navy);color:#f5f1e8;padding:20px clamp(18px,5vw,64px);display:flex;justify-content:space-between;gap:20px}.brand{font:22px Georgia,serif;letter-spacing:.1em}.top a{color:#e7d6bc;text-decoration:none;font-size:12px}.wrap{max-width:1120px;margin:auto;padding:38px 20px 70px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:#92734d;font-weight:700}h1{font:400 clamp(34px,5vw,48px)/1.1 Georgia,serif;margin:8px 0 10px}h2{font:24px Georgia,serif;font-weight:400;margin:0 0 14px}.muted{color:var(--muted)}.intro{max-width:820px;margin-bottom:28px}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:16px 0}.notice,.error{padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;min-width:0;padding:11px;border:1px solid #d8d3ca;background:white;color:var(--ink);font:13px ui-sans-serif,system-ui,sans-serif}.button{border:0;background:var(--navy);color:white;padding:11px 16px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;margin-top:14px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:720px}th,td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line);vertical-align:middle}th{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}td{font-size:12px}.mono{font:11px ui-monospace,monospace}.toggle{display:flex;align-items:center;gap:8px}.toggle select{padding:8px;border:1px solid #d8d3ca;background:white}.inline-form{display:grid;grid-template-columns:1fr 1.5fr auto;gap:8px;align-items:end}.help{font-size:12px;color:var(--muted);margin-top:10px}@media(max-width:760px){.grid{grid-template-columns:1fr}.inline-form{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style></head>
<body><header class="top"><div class="brand"><?= $escape($brandName) ?><span style="color:#d4b183">.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><div class="eyebrow">Deployment configuration / Super Admin</div><h1>Globalization</h1>
<p class="intro muted">Set deployment-wide reporting currency, default locale and IANA time zone. Currency availability is controlled against the pinned ISO 4217 reference dataset. Disabling the current reporting currency is blocked until another reporting currency is selected.</p>
<?php if($error!==''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if($notice!==''): ?><div class="notice" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<section class="panel"><h2>Deployment defaults</h2>
<form method="post" action="/admin/globalization/defaults">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label for="base_currency_code">Reporting currency</label><select id="base_currency_code" name="base_currency_code"><option value="">Not configured</option><?php foreach($currencies as $currency): if(!(bool)$currency['enabled']) continue; ?><option value="<?= $escape($currency['code']) ?>" <?= ($settings['base_currency_code']??'')===$currency['code']?'selected':'' ?>><?= $escape($currency['code'].' — '.$currency['name']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="default_locale">Default locale (BCP 47)</label><input id="default_locale" name="default_locale" maxlength="35" required value="<?= $escape($settings['default_locale']??'en') ?>" placeholder="en-GB"></div>
<div class="field"><label for="default_timezone">Default time zone (IANA)</label><input id="default_timezone" name="default_timezone" maxlength="64" required value="<?= $escape($settings['default_timezone']??'UTC') ?>" placeholder="Europe/London"></div>
</div>
<div class="field" style="margin-top:14px"><label for="defaults_reason">Change reason</label><input id="defaults_reason" name="reason" minlength="20" maxlength="500" required placeholder="Explain why deployment defaults are changing"></div>
<button class="button" type="submit">Save globalization defaults</button>
</form>
<p class="help">Defaults do not silently convert existing prices or change a destination's explicit locale or time zone. FX conversion and tax remain disabled until approved providers and rules exist.</p>
</section>
<section class="panel"><h2>ISO 4217 currency availability</h2><div class="table-wrap"><table><thead><tr><th>Code</th><th>Currency</th><th>Precision</th><th>Dataset</th><th>Availability</th><th>Update</th></tr></thead><tbody>
<?php foreach($currencies as $currency): ?><tr>
<td class="mono"><?= $escape($currency['code']) ?></td><td><?= $escape($currency['name']) ?> <?= $escape($currency['symbol']??'') ?></td><td><?= $escape($currency['minor_units']) ?> decimal places</td><td class="mono"><?= $escape($currency['dataset_version']) ?></td><td><?= !empty($currency['enabled'])?'Enabled':'Disabled' ?><?= ($settings['base_currency_code']??null)===$currency['code']?' · Reporting currency':'' ?></td>
<td><form class="inline-form" method="post" action="<?= $escape('/admin/globalization/currencies/'.rawurlencode((string)$currency['code']).'/toggle') ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<select name="enabled" aria-label="Currency availability"><option value="1" <?= !empty($currency['enabled'])?'selected':'' ?>>Enabled</option><option value="0" <?= empty($currency['enabled'])?'selected':'' ?>>Disabled</option></select>
<input name="reason" minlength="20" maxlength="500" required placeholder="Reason for change">
<button class="button" type="submit" style="margin:0">Save</button></form></td>
</tr><?php endforeach; ?>
</tbody></table></div></section>
</main></body></html>
