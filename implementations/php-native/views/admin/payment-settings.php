<?php
declare(strict_types=1);
$brandName=is_string($brandName??null)&&$brandName!==''?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$notice=is_string($notice??null)?$notice:'';
$settings=is_array($settings??null)?$settings:[];
$labels=[
 'payments_enabled'=>['Global payments','Master configuration switch.'],
 'crypto_enabled'=>['Cryptocurrency','Crypto receiving methods and chain integrations.'],
 'cards_enabled'=>['Card payments','Card processor configuration.'],
 'manual_enabled'=>['Manual payments','Manually verified payment methods.'],
 'booking_payments_enabled'=>['Booking payments','Payments associated with booking invoices.'],
 'membership_payments_enabled'=>['Membership payments','Payments associated with memberships.'],
];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Payment settings · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.top{background:var(--navy);color:#f5f1e8;padding:20px clamp(18px,5vw,64px);display:flex;justify-content:space-between;gap:20px}.brand{font:22px Georgia,serif;letter-spacing:.1em}.top a{color:#e7d6bc;text-decoration:none;font-size:12px}.wrap{max-width:920px;margin:auto;padding:38px 20px 70px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:#92734d;font-weight:700}h1{font:400 clamp(34px,5vw,48px)/1.1 Georgia,serif;margin:8px 0 10px}h2{font:24px Georgia,serif;font-weight:400}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:18px 0}.notice,.error{padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.setting{display:flex;justify-content:space-between;gap:20px;padding:17px 0;border-bottom:1px solid var(--line)}.setting:last-child{border:0}.setting strong{display:block;font-size:13px}.setting small{display:block;color:var(--muted);font-size:12px;margin-top:3px}.setting input{width:20px;height:20px;accent-color:var(--navy)}.button{border:0;background:var(--navy);color:white;padding:12px 18px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;margin-top:20px}.warning{border-left:3px solid var(--gold);padding:12px 14px;background:#f8f5ee;color:#5e503a;font-size:12px;margin:18px 0}@media(max-width:540px){.top{align-items:flex-start;flex-direction:column}.setting{align-items:flex-start}}
</style></head>
<body><header class="top"><div class="brand"><?= $escape($brandName) ?><span style="color:#d4b183">.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><div class="eyebrow">Deployment configuration / Super Admin</div><h1>Payment settings</h1>
<p class="muted">These flags describe which payment capabilities the deployment is configured to support. They do not connect a provider, verify a transfer, create a ledger entry, or enable settlement.</p>
<?php if($error!==''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if($notice!==''): ?><div class="notice" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<section class="panel"><h2>Payment capabilities</h2>
<form method="post" action="/admin/payment-settings"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<?php foreach($labels as $key=>$label): ?><label class="setting"><span><strong><?= $escape($label[0]) ?></strong><small><?= $escape($label[1]) ?></small></span><input type="checkbox" name="<?= $escape($key) ?>" value="1" <?= !empty($settings[$key])?'checked':'' ?> aria-label="<?= $escape($label[0]) ?>"></label><?php endforeach; ?>
<div class="warning">Live checkout, provider webhooks, crypto observation, custody, refunds and settlements remain disabled until their independent feature gates, provider credentials, legal approvals and operational controls are complete. Changing these settings alone cannot enable money movement.</div>
<button class="button" type="submit">Save payment settings</button>
</form></section>
</main></body></html>
