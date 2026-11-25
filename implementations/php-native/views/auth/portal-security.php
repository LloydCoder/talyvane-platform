<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Account security · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--night:#142235;--gold:#bd9560;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(680px,calc(100% - 36px));margin:0 auto}.top{padding:24px 0;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:18px}.top a{color:var(--ink)}.brand{font:600 21px Georgia,serif;letter-spacing:.06em;text-decoration:none}.intro{padding:42px 0 18px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,48px)/1.08 Georgia,serif;margin:10px 0}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(20px,4vw,36px);margin:18px 0 70px}.field{display:grid;gap:7px;margin:16px 0}.field label{font-size:12px;font-weight:700}.field input{width:100%;padding:13px 14px;border:1px solid #d9d5cc;border-radius:3px;background:#fff;color:var(--ink);font:inherit}.button{width:100%;border:0;background:var(--night);color:white;padding:15px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;margin-top:10px}.error,.notice{padding:12px 14px;margin:0 0 16px;font-size:13px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.notice{background:#f1f5ef;border:1px solid #d8e4d1;color:#36543b}.hint{color:var(--muted);font-size:12px}.links{margin-top:20px;font-size:13px}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/portal"><?= $escape($brandName) ?></a><a href="/portal">Back to account</a></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Account security</div><h1>Change your password</h1><p class="hint">A successful change revokes other active sessions. Your current session is rotated and remains signed in.</p></section>
<section class="panel">
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<form method="post" action="/portal/security" autocomplete="off">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="1024" required></div>
<div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required><span class="hint">Use at least 14 characters and choose a password different from your current one.</span></div>
<div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required></div>
<button class="button" type="submit">Change password securely</button>
</form>
<p class="links"><a href="/portal">Return to your account</a></p>
</section>
</main>
</body>
</html>
