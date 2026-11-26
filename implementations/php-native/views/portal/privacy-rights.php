<?php
declare(strict_types=1);
$history=is_array($history??null)?$history:[];
$notice=is_string($notice??null)?$notice:'';
$error=is_string($error??null)?$error:'';
$pendingRequestId=is_string($pendingRequestId??null)?$pendingRequestId:null;
$brandName=is_string($brandName??null)?$brandName:'Talyvane';
$csrf=is_string($csrf??null)?$csrf:'';
$mailAvailable=(bool)($mailAvailable??false);
$escape=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Privacy rights · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--night:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.65 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(900px,calc(100% - 36px));margin:auto}.top{padding:22px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between}.top a{color:var(--ink);text-decoration:none}.brand{font:600 20px Georgia,serif;letter-spacing:.08em}h1{font:400 clamp(34px,5vw,52px)/1.05 Georgia,serif;margin:44px 0 12px}.intro{color:var(--muted);max-width:740px}.panel{background:var(--panel);border:1px solid var(--line);padding:22px;margin:20px 0}.panel h2{font:400 25px Georgia,serif;margin:0 0 8px}.notice,.error{padding:12px 14px;margin:18px 0}.notice{background:#f1f5ef;border:1px solid #d8e4d1;color:#36543b}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.field{display:grid;gap:7px;margin:14px 0}.field label{font-weight:650}.field textarea,.field input{width:100%;padding:12px;border:1px solid #d9d5cc;background:#fff;font:inherit}.button{border:0;background:var(--night);color:#fff;padding:12px 16px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}.muted{color:var(--muted);font-size:12px}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}.table th{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}.tag{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/"><?= $escape($brandName) ?></a><a href="/portal">Account</a></header>
<main class="shell">
<h1>Your privacy rights.</h1>
<p class="intro">You can submit a verified request to correct information or ask for account erasure. We verify requests using a one-time code sent to your verified account email. Requests enter a restricted review queue; they are not automatically fulfilled.</p>
<?php if($notice!==''):?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif;?>
<?php if($error!==''):?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif;?>
<?php if($pendingRequestId!==null):?>
<section class="panel">
<h2>Verify your request</h2>
<p>Enter the eight-digit code sent to your verified email. The code expires in 10 minutes and can be used once.</p>
<form method="post" action="/portal/privacy/verify">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_id" value="<?= $escape($pendingRequestId) ?>">
<div class="field"><label for="code">Eight-digit verification code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" required></div>
<button class="button" type="submit">Verify privacy request</button>
</form>
</section>
<?php endif;?>
<section class="panel">
<h2>Request a correction</h2>
<p>Describe the information you believe is inaccurate or incomplete. Do not include passwords, identity-document numbers, payment credentials or unnecessary sensitive details.</p>
<?php if($mailAvailable):?>
<form method="post" action="/portal/privacy/requests">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_type" value="correction">
<div class="field"><label for="summary">What needs correcting?</label><textarea id="summary" name="summary" rows="4" maxlength="2000" required></textarea></div>
<button class="button" type="submit">Request a correction</button>
</form>
<?php else:?><p class="muted">Email delivery is not configured. Contact the agency through its published privacy contact route.</p><?php endif;?>
</section>
<section class="panel">
<h2>Request account erasure</h2>
<p>Erasure requests require human review. Active bookings, financial or safeguarding records, legal holds, statutory retention and backup expiry may affect what can be erased and when. Submitting a request does not immediately delete your account or records.</p>
<?php if($mailAvailable):?>
<form method="post" action="/portal/privacy/requests">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<input type="hidden" name="request_type" value="erasure">
<button class="button" type="submit">Request account erasure</button>
</form>
<?php endif;?>
</section>
<section class="panel">
<h2>Request history</h2>
<?php if($history===[]):?><p class="muted">You have not submitted a privacy-rights request.</p><?php else:?>
<table class="table"><thead><tr><th>Type</th><th>Status</th><th>Submitted</th><th>Decision</th></tr></thead><tbody>
<?php foreach($history as $item):?><tr>
<td><?= $escape($item['request_type']??'') ?></td>
<td><span class="tag"><?= $escape($item['status']??'') ?></span></td>
<td><?= $escape($item['created_at']??'') ?></td>
<td><?= $escape($item['decision_reason']??'Awaiting review') ?></td>
</tr><?php endforeach;?>
</tbody></table>
<?php endif;?>
</section>
<p class="muted">Your existing <a href="/portal/privacy/export">account data export</a> remains available separately. Requests are audited and limited to the signed-in account.</p>
</main>
</body>
</html>
