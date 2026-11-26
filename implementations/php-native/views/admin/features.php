<?php
declare(strict_types=1);
$brandName=is_string($brandName??null)&&$brandName!==''?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$notice=is_string($notice??null)?$notice:'';
$features=is_array($features??null)?$features:[];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Platform features · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.top{background:var(--navy);color:#f5f1e8;padding:20px clamp(18px,5vw,64px);display:flex;justify-content:space-between;gap:20px}.brand{font:22px Georgia,serif;letter-spacing:.1em}.top a{color:#e7d6bc;text-decoration:none;font-size:12px}.wrap{max-width:1200px;margin:auto;padding:38px 20px 70px}h1{font:400 clamp(34px,5vw,48px)/1.1 Georgia,serif;margin:8px 0 10px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:#92734d;font-weight:700}.muted{color:var(--muted)}.intro{max-width:820px;margin-bottom:28px}.notice,.error{padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.feature{background:var(--panel);border:1px solid var(--line);padding:20px;margin:14px 0}.feature-head{display:flex;justify-content:space-between;gap:20px;align-items:start}.key{font:12px ui-monospace,monospace;color:#8a673d}.feature h2{font:22px Georgia,serif;font-weight:400;margin:4px 0}.meta{font-size:12px;color:var(--muted)}.state{font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.state.on{color:#315e3d}.state.off{color:#8b3535}.form-grid{display:grid;grid-template-columns:1.1fr 2fr 1.2fr 1.2fr;gap:10px;margin-top:18px}.field{display:grid;gap:5px}.field label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted)}.field input,.field select{min-width:0;width:100%;padding:10px;border:1px solid #d8d3ca;background:white;color:var(--ink);font:13px inherit}.button{border:0;background:var(--navy);color:white;padding:11px 16px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;margin-top:12px}.button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid var(--gold);outline-offset:2px}.locked{border-left:3px solid var(--gold);padding-left:12px;color:var(--muted);font-size:12px}.blocker{font-size:12px;color:#8b3535;margin-top:8px}.deps{font-size:11px;color:var(--muted);margin-top:8px}@media(max-width:800px){.form-grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.feature-head{flex-direction:column}.form-grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style></head>
<body><header class="top"><div class="brand"><?= $escape($brandName) ?><span style="color:#d4b183">.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><div class="eyebrow">Deployment control plane / Super Admin</div><h1>Platform features</h1>
<div class="intro muted">Feature switches are deployment controls, not proof that a capability is operationally safe. Every activation re-evaluates its dependency graph and independent approval gates. Hard safeguards are immutable. Each change requires your current password, a fresh one-time authenticator code, and a reason.</div>
<?php if($error!==''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if($notice!==''): ?><div class="notice" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<?php foreach($features as $feature): ?>
<section class="feature">
<div class="feature-head"><div><div class="key"><?= $escape($feature['key']??'') ?></div><h2><?= $escape(ucwords(str_replace(['-','_'], ' ',(string)($feature['module']??'')))) ?></h2><div class="meta">Risk: <?= $escape($feature['risk']??'') ?> · Effective: <span class="state <?= !empty($feature['effective'])?'on':'off' ?>"><?= !empty($feature['effective'])?'Available':'Blocked / inactive' ?></span></div></div><div class="state <?= !empty($feature['enabled'])?'on':'off' ?>">Stored: <?= !empty($feature['enabled'])?'Enabled':'Disabled' ?></div></div>
<?php if(!empty($feature['dependencies'])): ?><div class="deps">Dependencies: <?= $escape(implode(', ',$feature['dependencies'])) ?></div><?php endif; ?>
<?php if(!empty($feature['hard_safeguard'])): ?><p class="locked">Hard safeguard — cannot be overridden through the feature registry.</p>
<?php else: ?>
<?php if(!empty($feature['blocker'])): ?><p class="blocker"><?= $escape($feature['blocker']) ?></p><?php endif; ?>
<form method="post" action="<?= $escape('/admin/features/'.rawurlencode((string)$feature['key'])) ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="form-grid">
<div class="field"><label>Desired state</label><select name="enabled" required><option value="1" <?= !empty($feature['enabled'])?'selected':'' ?>>Enabled</option><option value="0" <?= empty($feature['enabled'])?'selected':'' ?>>Disabled</option></select></div>
<div class="field"><label>Operational reason</label><input name="reason" minlength="20" maxlength="500" required placeholder="Explain why this change is required"></div>
<div class="field"><label>Current password</label><input type="password" name="password" maxlength="200" autocomplete="current-password" required></div>
<div class="field"><label>Authenticator code</label><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></div>
</div><button class="button" type="submit">Verify and save</button>
</form>
<?php endif; ?>
</section>
<?php endforeach; ?>
</main></body></html>
