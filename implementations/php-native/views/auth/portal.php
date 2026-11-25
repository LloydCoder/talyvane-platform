<?php
declare(strict_types=1);

$screen = is_string($screen ?? null) ? $screen : 'login';
$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
$email = is_string($email ?? null) ? $email : '';
$accountType = is_string($accountType ?? null) ? $accountType : '';
$titles = [
 'login'=>['Your private account','Sign in to your verified Talyvane account.'],
 'register'=>['Create an account','Choose the account type that matches how you use the platform.'],
 'verify'=>['Verify your email','Enter the six-digit code sent to your email address.'],
 'recovery_request'=>['Recover your account','We will send a recovery code if an active, verified account matches your email.'],
 'recovery_complete'=>['Set a new password','Enter the recovery code and choose a new password.'],
 'dashboard'=>[$accountType === 'companion' ? 'Companion workspace' : 'Client workspace','Your account is verified. Additional features appear as their workflows are enabled.'],
];
[$heading,$description] = $titles[$screen] ?? $titles['login'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $escape($heading) ?> · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--night:#142235;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--paper);color:var(--ink);font:16px/1.6 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;place-items:center;padding:24px}.panel{width:min(100%,520px);background:var(--panel);padding:clamp(28px,6vw,56px);border:1px solid var(--line);box-shadow:0 18px 60px rgba(24,38,56,.06)}.brand{font:600 20px Georgia,serif;letter-spacing:.1em}.eyebrow{margin:38px 0 10px;color:#88643a;font-size:10px;font-weight:700;letter-spacing:.2em;text-transform:uppercase}h1{font:400 clamp(32px,6vw,48px)/1.08 Georgia,serif;letter-spacing:-.03em;margin:0 0 12px}.intro{color:var(--muted);font-size:14px;margin:0 0 26px}.field{display:grid;gap:7px;margin:16px 0}.field label{font-size:12px;font-weight:700}.field input,.field select{width:100%;padding:13px 14px;border:1px solid #d9d5cc;border-radius:3px;background:#fff;color:var(--ink);font:inherit}.field input:focus,.field select:focus{outline:3px solid rgba(189,149,96,.2);border-color:#88643a}.button{width:100%;border:0;background:var(--night);color:white;padding:15px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;cursor:pointer;margin-top:10px}.error,.notice{padding:12px 14px;margin:0 0 16px;font-size:13px}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger)}.notice{background:#f1f5ef;border:1px solid #d8e4d1;color:#36543b}.links{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:22px;font-size:13px}.links a{color:#70522e}.hint{color:var(--muted);font-size:12px}.footer{margin-top:28px;color:#8993a0;font-size:11px}
</style>
</head>
<body><main class="panel">
<div class="brand"><?= $escape($brandName) ?></div>
<p class="eyebrow">Private account access</p><h1><?= $escape($heading) ?></h1><p class="intro"><?= $escape($description) ?></p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
<?php if ($screen === 'register'): ?>
<form method="post" action="/portal/register" autocomplete="on">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="account_type">Account type</label><select id="account_type" name="account_type" required><option value="client">Client</option><option value="companion">Companion</option></select></div>
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($email) ?>"></div>
<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required><span class="hint">Use at least 14 characters. Email verification is required before sign-in.</span></div>
<button class="button" type="submit">Create account</button>
</form>
<div class="links"><a href="/portal/login">Already registered? Sign in</a></div>
<?php elseif ($screen === 'verify'): ?>
<form method="post" action="/portal/verify">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($email) ?>"></div>
<div class="field"><label for="code">Six-digit verification code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
<button class="button" type="submit">Verify email</button>
</form>
<form method="post" action="/portal/verify/resend"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="email" value="<?= $escape($email) ?>"><button class="button" type="submit">Resend verification code</button></form>
<div class="links"><a href="/portal/register">Create an account</a><a href="/portal/login">Sign in</a></div>
<?php elseif ($screen === 'recovery_request'): ?>
<form method="post" action="/portal/recovery"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($email) ?>"></div><button class="button" type="submit">Send recovery code</button></form>
<div class="links"><a href="/portal/login">Return to sign in</a></div>
<?php elseif ($screen === 'recovery_complete'): ?>
<form method="post" action="/portal/recovery/complete"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($email) ?>"></div><div class="field"><label for="code">Six-digit recovery code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div><div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required></div><div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required></div><button class="button" type="submit">Reset password</button></form>
<div class="links"><a href="/portal/login">Return to sign in</a></div>
<?php elseif ($screen === 'dashboard'): ?>
<p class="notice">Signed in as a <?= $escape($accountType) ?> account.</p>
<p class="hint">This portal is intentionally separate from administration and from the other account type. Identity and session checks are active; profile, booking and account-management features are being connected as separate workflows.</p>
<form method="post" action="/portal/logout"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="button" type="submit">Sign out securely</button></form>
<?php else: ?>
<form method="post" action="/portal/login">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" maxlength="254" required value="<?= $escape($email) ?>"></div>
<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required></div>
<button class="button" type="submit">Sign in securely</button>
</form><div class="links"><a href="/portal/register">Create an account</a><a href="/portal/verify">Verify your email</a><a href="/portal/recovery">Forgot password?</a></div>
<?php endif; ?>
<div class="footer">Access is rate-limited. Never send identity documents, payment details or passwords by email.</div>
</main></body></html>
