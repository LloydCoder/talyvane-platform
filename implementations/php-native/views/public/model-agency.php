<?php
declare(strict_types=1);
$screen=is_string($screen??null)?$screen:'form';
$brandName=is_string($brandName??null)?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$notice=is_string($notice??null)?$notice:'';
$reference=is_string($reference??null)?$reference:'';
$available=($available??false)===true;
$privacyVersion=is_string($privacyVersion??null)?$privacyVersion:'';
$values=is_array($values??null)?$values:[];
$value=static fn(string $key):string=>is_scalar($values[$key]??null)?(string)$values[$key]:'';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Model representation · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--line:#e6e2d9;--panel:#fffefa;--navy:#142235;--gold:#bd9560}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(850px,calc(100% - 36px));margin:auto}.top{padding:24px 0;border-bottom:1px solid var(--line)}.top a{font:600 22px Georgia,serif;color:var(--ink);text-decoration:none}.eyebrow{margin:58px 0 10px;color:#88643a;font-size:10px;font-weight:700;letter-spacing:.18em;text-transform:uppercase}h1{font:400 clamp(36px,6vw,58px)/1.05 Georgia,serif;margin:0 0 14px}.intro{color:var(--muted);max-width:700px}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(20px,4vw,38px);margin:30px 0 70px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 18px}.field{display:grid;gap:7px;margin:12px 0}.field.wide{grid-column:span 2}.field label{font-size:11px;font-weight:700}.field input,.field textarea{padding:12px 13px;border:1px solid #d8d3ca;background:white;color:var(--ink);font:inherit}.field textarea{min-height:130px}.ack{display:flex;gap:10px;align-items:flex-start;color:var(--muted);font-size:12px;margin:18px 0}.ack input{margin-top:3px}.button{border:0;background:var(--navy);color:white;padding:14px 18px;font-weight:700;font-size:11px;letter-spacing:.1em;text-transform:uppercase;cursor:pointer}.alert{padding:13px 15px;margin:0 0 18px;font-size:13px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:#8b3535}.success{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.hint{color:var(--muted);font-size:12px}.ref{font:600 22px ui-monospace,monospace;letter-spacing:.1em;padding:16px;background:#f2eee6;overflow-wrap:anywhere}@media(max-width:600px){.grid{grid-template-columns:1fr}.field.wide{grid-column:auto}.button{width:100%}}
</style></head><body><header class="shell top"><a href="/"><?= $escape($brandName) ?></a></header><main class="shell"><p class="eyebrow">Independent model representation</p><h1>Apply for representation.</h1><p class="intro">This application belongs to the model-agency programme. It is separate from companionship profiles, customer accounts and booking activity. An adult attestation is only an initial statement; independent eligibility verification is required before acceptance or representation.</p><p class="hint">Already applied? <a href="/model-agency/privacy">Open your model workspace or manage applicant privacy rights</a> using your application reference and email.</p><section class="panel">
<?php if($error!==''):?><div class="alert error" role="alert"><?= $escape($error) ?></div><?php endif;?>
<?php if($screen==='success'):?><div class="alert success" role="status"><?= $escape($notice) ?></div><p class="hint">Keep this application reference. It is required to verify a later privacy-rights request.</p><div class="ref"><?= $escape($reference) ?></div><p><a href="/model-agency/privacy">Manage your application privacy rights</a></p>
<?php elseif(!$available):?><div class="alert error" role="status">Model-agency intake is not currently open. A published Privacy Notice and explicit intake configuration are required before applications can be accepted.</div>
<?php else:?>
<form method="post" action="/model-agency/apply"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><div class="grid">
<div class="field"><label for="applicant_name">Full name</label><input id="applicant_name" name="applicant_name" maxlength="120" autocomplete="name" required value="<?= $escape($value('applicant_name')) ?>"></div>
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= $escape($value('email')) ?>"></div>
<div class="field"><label for="phone">Phone (optional)</label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $escape($value('phone')) ?>"></div>
<div class="field"><label for="country_code">Country code (ISO alpha-2)</label><input id="country_code" name="country_code" maxlength="2" pattern="[A-Za-z]{2}" required value="<?= $escape($value('country_code')) ?>"></div>
<div class="field"><label for="city">City</label><input id="city" name="city" maxlength="120" required value="<?= $escape($value('city')) ?>"></div>
<div class="field"><label for="portfolio_url">Portfolio URL (HTTPS only)</label><input id="portfolio_url" name="portfolio_url" type="url" maxlength="2048" value="<?= $escape($value('portfolio_url')) ?>"></div>
<div class="field wide"><label for="experience_summary">Experience / portfolio summary (optional)</label><textarea id="experience_summary" name="experience_summary" maxlength="8000"><?= $escape($value('experience_summary')) ?></textarea></div></div>
<label class="ack"><input type="checkbox" name="adult_attested" value="1" required><span>I attest that I am an adult. I understand that this statement is not age verification and that independent eligibility verification is required before acceptance.</span></label>
<label class="ack"><input type="checkbox" name="privacy_acknowledged" value="1" required><span>I have read the <a href="/privacy" target="_blank" rel="noopener noreferrer">Privacy Notice</a> and acknowledge this application will be processed under version <?= $escape($privacyVersion) ?>.</span></label>
<button class="button" type="submit">Submit application</button></form>
<?php endif;?></section></main></body></html>
