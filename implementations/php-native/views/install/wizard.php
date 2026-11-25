<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$step = is_string($step ?? null) ? $step : 'setup';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$notice = is_string($notice ?? null) ? $notice : '';
$checks = is_array($checks ?? null) ? $checks : [];
$keyFileHint = is_string($keyFileHint ?? null) ? $keyFileHint : 'INSTALLER-ACCESS-KEY.txt';
$primaryEmail = is_string($primaryEmail ?? null) ? $primaryEmail : '';
$recoveryEmail = is_string($recoveryEmail ?? null) ? $recoveryEmail : '';
$expiresAt = is_string($expiresAt ?? null) ? $expiresAt : '';
$preflightOk = true;
foreach ($checks as $check) { if (!is_array($check) || !($check['ok'] ?? false)) { $preflightOk = false; } }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $step === 'verify' ? 'Verify administrator emails' : 'Secure installation' ?> · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--danger:#8b3535;--ok:#315c42}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(900px,calc(100% - 36px));margin:auto}.top{padding:24px 0;border-bottom:1px solid var(--line)}.brand{font:600 21px Georgia,serif;letter-spacing:.06em;color:var(--ink);text-decoration:none}.intro{padding:42px 0 18px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,50px)/1.08 Georgia,serif;margin:10px 0}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,30px);margin:18px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field.full{grid-column:1/-1}.field label{font-size:11px;font-weight:700;color:var(--muted)}.field input,.field select{width:100%;min-width:0;padding:12px;border:1px solid #d8d3ca;background:white;color:var(--ink);font:inherit}.field input:focus,.field select:focus{outline:2px solid var(--gold);outline-offset:1px}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;border:0;padding:13px 18px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;cursor:pointer}.button:disabled{opacity:.45;cursor:not-allowed}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;margin:14px 0}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:var(--ok);padding:12px 14px;margin:14px 0}.checks{display:grid;gap:9px;margin:14px 0}.check{display:flex;justify-content:space-between;gap:14px;border-bottom:1px solid var(--line);padding:8px 0;font-size:12px}.check strong{font-weight:700}.ok{color:var(--ok)}.bad{color:var(--danger)}.help{font-size:12px;color:var(--muted);margin:12px 0}.security{background:#f6f1e8;border-left:3px solid var(--gold);padding:13px 16px;font-size:12px;color:#594d3d;margin:16px 0}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:18px}.code-input{letter-spacing:.35em;font:600 24px ui-monospace,SFMono-Regular,Consolas,monospace}.footer{padding:20px 0 50px;color:var(--muted);font-size:11px}@media(max-width:600px){.grid{grid-template-columns:1fr}.field.full{grid-column:auto}.check{align-items:flex-start}}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="/"><?= $escape($brandName) ?></a></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Private deployment · Browser installer</div><h1><?= $step === 'verify' ? 'Verify both administrator addresses' : 'Set up your platform' ?></h1><p class="muted"><?= $step === 'verify' ? 'A verification code was sent separately to each address. Both must be verified before the first Super Admin account is activated.' : 'Configure the native PHP application, database and SMTP delivery. The installer runs migrations and reference-data setup, then provisions the first Super Admin only after both email addresses are verified.' ?></p></section>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>

<?php if ($step === 'setup'): ?>
<section class="panel">
<div class="eyebrow">Environment preflight</div><h2>Server requirements</h2>
<div class="checks"><?php foreach ($checks as $label=>$check): ?><div class="check"><span><?= $escape($check['detail'] ?? $label) ?></span><strong class="<?= ($check['ok']??false) ? 'ok' : 'bad' ?>"><?= ($check['ok']??false) ? 'Ready' : 'Required' ?></strong></div><?php endforeach; ?></div>
<div class="security"><strong>Installer key protection.</strong> The file <?= $escape($keyFileHint) ?> is stored outside the public document root in your account's private .talyvane directory. Retrieve it using cPanel File Manager. Never move it into public_html or share it in a chat, ticket or screenshot. It is not displayed in this browser.</div>
<form method="post" action="/install" autocomplete="off">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="eyebrow">Private setup key</div>
<div class="grid">
<div class="field full"><label for="setup_key">Enter the key from <?= $escape($keyFileHint) ?></label><input id="setup_key" name="setup_key" type="password" required minlength="43" maxlength="43" autocomplete="off"></div>
</div>
<div class="eyebrow" style="margin-top:24px">Application</div>
<div class="grid">
<div class="field"><label for="app_name">Application name</label><input id="app_name" name="app_name" maxlength="120" value="Talyvane" required></div>
<div class="field"><label for="app_url">Canonical HTTPS URL</label><input id="app_url" name="app_url" type="url" placeholder="https://example.com" required></div>
</div>
<div class="eyebrow" style="margin-top:24px">MySQL database</div>
<div class="grid">
<div class="field"><label for="db_host">Database host</label><input id="db_host" name="db_host" value="localhost" maxlength="255" required></div>
<div class="field"><label for="db_port">Database port</label><input id="db_port" name="db_port" type="number" min="1" max="65535" value="3306" required></div>
<div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" maxlength="64" required></div>
<div class="field"><label for="db_user">Database username</label><input id="db_user" name="db_user" maxlength="128" required autocomplete="username"></div>
<div class="field full"><label for="db_password">Database password</label><input id="db_password" name="db_password" type="password" maxlength="1024" required autocomplete="new-password"></div>
</div>
<div class="eyebrow" style="margin-top:24px">SMTP email delivery</div>
<div class="grid">
<div class="field"><label for="mail_host">SMTP host</label><input id="mail_host" name="mail_host" maxlength="253" required></div>
<div class="field"><label for="mail_port">SMTP port</label><input id="mail_port" name="mail_port" type="number" min="1" max="65535" value="587" required></div>
<div class="field"><label for="mail_encryption">Encryption</label><select id="mail_encryption" name="mail_encryption"><option value="tls">STARTTLS</option><option value="ssl">Implicit TLS</option></select></div>
<div class="field"><label for="mail_username">SMTP username</label><input id="mail_username" name="mail_username" maxlength="254" required autocomplete="username"></div>
<div class="field full"><label for="mail_password">SMTP password</label><input id="mail_password" name="mail_password" type="password" maxlength="1024" required autocomplete="new-password"></div>
<div class="field"><label for="mail_from_email">Sender email</label><input id="mail_from_email" name="mail_from_email" type="email" maxlength="254" required></div>
<div class="field"><label for="mail_from_name">Sender display name</label><input id="mail_from_name" name="mail_from_name" maxlength="120" value="Talyvane" required></div>
</div>
<div class="eyebrow" style="margin-top:24px">First Super Admin</div>
<div class="grid">
<div class="field"><label for="admin_email">Primary administrator email</label><input id="admin_email" name="admin_email" type="email" maxlength="254" required autocomplete="email"></div>
<div class="field"><label for="recovery_email">Independent recovery email</label><input id="recovery_email" name="recovery_email" type="email" maxlength="254" required autocomplete="email"></div>
</div>
<p class="help">The two addresses must be distinct and accessible. The installer will send separate one-time codes to both. The initial administrator password and offline recovery key are generated only after successful verification and displayed once.</p>
<label class="check"><span>I confirm this is a dedicated empty MySQL database and that the SMTP account is authorized to send from the configured sender address.</span><input type="checkbox" name="installation_confirmed" value="1" required></label>
<div class="actions"><button class="button" type="submit" <?= $preflightOk ? '' : 'disabled' ?>>Test services and send verification codes</button></div>
</form>
</section>
<?php else: ?>
<section class="panel">
<div class="eyebrow">Dual-address verification</div><h2>One code per address</h2>
<p class="muted">Primary: <strong><?= $escape($primaryEmail) ?></strong><br>Recovery: <strong><?= $escape($recoveryEmail) ?></strong><br>Challenge expiry: <?= $escape($expiresAt) ?> UTC</p>
<form method="post" action="/install/verify">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="grid">
<div class="field"><label for="primary_code">Primary email code</label><input class="code-input" id="primary_code" name="primary_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code"></div>
<div class="field"><label for="recovery_code">Recovery email code</label><input class="code-input" id="recovery_code" name="recovery_code" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code"></div>
</div>
<div class="actions"><button class="button" type="submit">Verify and activate Super Admin</button></div>
</form>
<div class="security"><strong>Security note.</strong> Verification codes expire after 10 minutes. The overall bootstrap challenge expires after 30 minutes. Failed verification attempts are rate-limited.</div>
<form method="post" action="/install/resend">
<input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
<div class="field"><label for="resend_setup_key">Private setup key (required only to resend)</label><input id="resend_setup_key" name="setup_key" type="password" minlength="43" maxlength="43" autocomplete="off"></div>
<div class="actions"><button class="button" type="submit">Resend both codes</button></div>
</form>
</section>
<?php endif; ?>
<footer class="footer">No production data is modified by this installer. All installer state and configuration remain outside the public document root.</footer>
</main>
</body>
</html>
