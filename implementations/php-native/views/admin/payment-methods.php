<?php
declare(strict_types=1);
$brandName=is_string($brandName??null)&&$brandName!==''?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$error=is_string($error??null)?$error:'';
$notice=is_string($notice??null)?$notice:'';
$methods=is_array($methods??null)?$methods:[];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Payment methods · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535}*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.top{background:var(--navy);color:#f5f1e8;padding:20px clamp(18px,5vw,64px);display:flex;justify-content:space-between;gap:20px}.brand{font:22px Georgia,serif;letter-spacing:.1em}.top a{color:#e7d6bc;text-decoration:none;font-size:12px}.wrap{max-width:1150px;margin:auto;padding:38px 20px 70px}.eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:.18em;color:#92734d;font-weight:700}h1{font:400 clamp(34px,5vw,48px)/1.1 Georgia,serif;margin:8px 0 10px}h2{font:24px Georgia,serif;font-weight:400;margin:0 0 14px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,28px);margin:18px 0}.notice,.error{padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:#345b39}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.field{display:grid;gap:5px}.field label{font-size:10px;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;min-width:0;padding:10px;border:1px solid #d8d3ca;background:white;color:var(--ink);font:13px ui-sans-serif,system-ui,sans-serif}.button{border:0;background:var(--navy);color:white;padding:10px 14px;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;cursor:pointer;margin-top:12px}.warning{border-left:3px solid var(--gold);padding:12px 14px;background:#f8f5ee;color:#5e503a;font-size:12px;margin:16px 0}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:980px}th,td{text-align:left;vertical-align:top;padding:12px 10px;border-bottom:1px solid var(--line)}th{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}td{font-size:12px}.mono{font:11px ui-monospace,monospace;overflow-wrap:anywhere}.actions{display:grid;gap:8px;min-width:240px}.actions input{width:100%;padding:8px;border:1px solid #d8d3ca}.actions select{padding:8px;border:1px solid #d8d3ca}.actions form{border-top:1px solid var(--line);padding-top:8px}.state{font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.08em}.state.enabled{color:#315e3d}.state.disabled{color:#8b3535}@media(max-width:700px){.grid{grid-template-columns:1fr}.top{align-items:flex-start;flex-direction:column}}
</style></head>
<body><header class="top"><div class="brand"><?= $escape($brandName) ?><span style="color:#d4b183">.</span></div><a href="/admin">CONTROL ROOM</a></header>
<main class="wrap"><div class="eyebrow">Deployment configuration / Super Admin</div><h1>Crypto receiving methods</h1>
<p class="muted">Receiving addresses are stored as configuration only. They are never private keys or custody credentials. Each method is created disabled, requires separate provider and legal approval references, and remains unusable until global settings and independent feature gates pass.</p>
<?php if($error!==''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if($notice!==''): ?><div class="notice" role="status"><?= $escape($notice) ?></div><?php endif; ?>
<section class="panel"><h2>Add receiving method</h2>
<form method="post" action="/admin/payment-methods"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label for="asset">Asset</label><select id="asset" name="asset" required><option value="BTC">BTC</option><option value="USDT">USDT</option><option value="USDC">USDC</option></select></div>
<div class="field"><label for="network">Network</label><input id="network" name="network" maxlength="80" required placeholder="Bitcoin, Ethereum, Polygon…"></div>
<div class="field"><label for="purpose">Payment purpose</label><select id="purpose" name="purpose" required><option value="booking">Booking</option><option value="membership">Membership</option></select></div>
<div class="field"><label for="address">Receiving address</label><input id="address" name="address" minlength="16" maxlength="500" required></div>
<div class="field"><label for="token_contract">Token contract (USDT/USDC)</label><input id="token_contract" name="token_contract" maxlength="190"></div>
</div><button class="button" type="submit">Save disabled method</button></form></section>
<section class="panel"><h2>Configured receiving methods</h2>
<?php if($methods===[]): ?><p class="muted">No receiving methods have been configured.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Asset/network</th><th>Receiving address</th><th>Purpose</th><th>Status</th><th>Evidence and controls</th></tr></thead><tbody>
<?php foreach($methods as $method): ?><tr>
<td><strong><?= $escape($method['asset_code']??'') ?></strong><div class="muted"><?= $escape($method['network_code']??'') ?></div><?php if(!empty($method['token_contract'])): ?><div class="mono"><?= $escape($method['token_contract']) ?></div><?php endif; ?></td>
<td class="mono"><?= $escape($method['receiving_address']??'') ?></td><td><?= $escape($method['purpose']??'') ?></td>
<td><span class="state <?= ($method['status']??'')==='enabled'?'enabled':'disabled' ?>"><?= $escape($method['status']??'') ?></span></td>
<td><div class="actions">
<?php if(($method['status']??'')!=='enabled'): ?><form method="post" action="<?= $escape('/admin/payment-methods/'.rawurlencode((string)$method['id']).'/review') ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><input name="provider_reference" minlength="8" maxlength="500" required placeholder="Provider approval reference"><input name="legal_reference" minlength="8" maxlength="500" required placeholder="Legal/jurisdiction approval reference"><button class="button" type="submit">Record independent review</button></form><?php endif; ?>
<form method="post" action="<?= $escape('/admin/payment-methods/'.rawurlencode((string)$method['id']).'/toggle') ?>">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><select name="enabled"><option value="0" <?= ($method['status']??'')!=='enabled'?'selected':'' ?>>Disabled</option><option value="1" <?= ($method['status']??'')==='enabled'?'selected':'' ?>>Enabled</option></select><button class="button" type="submit">Save state</button></form>
</div></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="warning">This workspace does not observe chains, verify confirmations, issue invoices, post ledger entries, refund payments, hold funds or initiate transfers. Those capabilities remain blocked pending provider integrations, legal approvals, independent security review and operational reconciliation.</div>
</section></main></body></html>
