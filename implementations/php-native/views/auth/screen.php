<?php
declare(strict_types=1);

$screen = is_string($screen ?? null) ? $screen : 'login';
$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$csrf = is_string($csrf ?? null) ? $csrf : '';
$error = is_string($error ?? null) ? $error : '';
$email = is_string($email ?? null) ? $email : '';
$secret = is_string($secret ?? null) ? $secret : '';
$recoveryCodes = is_array($recoveryCodes ?? null) ? $recoveryCodes : [];
$roles = is_array($roles ?? null) ? $roles : [];
$titles = [
    'login' => ['Administrator access', 'A private workspace for the people responsible for the agency.'],
    'mfa' => ['Verify your identity', 'Enter the current code from your authenticator, or use one recovery code.'],
    'first_login' => ['Secure your account', 'Replace the initial password before administrator access can be granted.'],
    'mfa_setup' => ['Set up two-factor authentication', 'Add this account to an authenticator app, then confirm a current six-digit code.'],
    'recovery_codes' => ['Save your recovery codes', 'These codes are shown once. Store them somewhere private and separate from this device.'],
    'dashboard' => ['Control room', 'Your administrator identity is verified. Operational workspaces are being connected in this release.'],
];
[$heading, $description] = $titles[$screen] ?? $titles['login'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $escape($heading) ?> · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--gold:#bd9560;--gold-dark:#88643a;--night:#142235;--danger:#8b3535}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--paper);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;grid-template-columns:minmax(260px,.85fr) 1.15fr}
aside{background:var(--night);color:#f5f1e8;padding:clamp(28px,5vw,76px);display:flex;flex-direction:column;justify-content:space-between;min-height:100vh;position:relative;overflow:hidden}
aside:before{content:"";position:absolute;width:520px;height:520px;border:1px solid rgba(189,149,96,.25);border-radius:50%;right:-280px;top:12%;box-shadow:0 0 0 42px rgba(189,149,96,.035),0 0 0 90px rgba(189,149,96,.025)}
.brand{font-family:Georgia,serif;font-size:27px;letter-spacing:.12em;position:relative}.brand span{color:#d4b183}.aside-copy{position:relative;max-width:430px;margin:auto 0}.eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:10px;color:#d2b184;font-weight:700}.aside-copy h2{font:clamp(32px,4vw,52px)/1.08 Georgia,serif;font-weight:400;margin:18px 0}.aside-copy p{color:#b7c0cb;line-height:1.8;font-size:14px}.aside-foot{color:#8895a5;font-size:11px;position:relative;letter-spacing:.04em}
main{display:grid;place-items:center;padding:clamp(24px,6vw,88px)}.panel{width:min(100%,480px)}.panel h1{font:clamp(34px,4vw,46px)/1.08 Georgia,serif;font-weight:400;letter-spacing:-.025em;margin:12px 0}.intro{color:var(--muted);line-height:1.75;font-size:14px;margin:0 0 30px}.rule{height:1px;background:var(--line);margin:28px 0}.field{display:grid;gap:8px;margin:18px 0}.field label{font-size:12px;font-weight:700;letter-spacing:.04em}.field input{width:100%;border:1px solid #d9d5cc;border-radius:3px;background:#fff;padding:14px 15px;font:inherit;color:var(--ink);outline:none}.field input:focus{border-color:var(--gold-dark);box-shadow:0 0 0 3px rgba(189,149,96,.17)}.field input::placeholder{color:#a1a8b0}.hint{font-size:12px;color:var(--muted);line-height:1.6}.error{background:#fff4f2;border:1px solid #e9c9c4;color:var(--danger);padding:12px 14px;border-radius:3px;font-size:13px;line-height:1.5;margin:0 0 18px}.button{width:100%;border:0;border-radius:3px;background:var(--night);color:#fff;padding:15px 18px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;font-weight:700;cursor:pointer;margin-top:8px}.button:hover{background:#263b54}.button:focus-visible,a:focus-visible{outline:3px solid var(--gold);outline-offset:3px}.code{font:13px ui-monospace,SFMono-Regular,Consolas,monospace;letter-spacing:.08em;overflow-wrap:anywhere;background:#f0ede6;border:1px solid var(--line);padding:15px;border-radius:3px}.codes{display:grid;grid-template-columns:1fr 1fr;gap:9px}.codes code{padding:10px;background:#f0ede6;border:1px solid var(--line);font:12px ui-monospace,monospace}.role-list{display:flex;gap:8px;flex-wrap:wrap}.role-list span{font-size:11px;padding:7px 10px;border:1px solid var(--line);color:var(--muted);border-radius:2px}.footer{color:#8993a0;font-size:11px;margin-top:28px;line-height:1.7}
@media(max-width:760px){body{grid-template-columns:1fr}aside{min-height:auto;padding:24px 26px 28px}aside:before{width:280px;height:280px;right:-180px;top:-150px}.aside-copy{display:none}.aside-foot{margin-top:22px}main{padding:44px 24px 56px}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body>
<aside>
  <div class="brand"><?= $escape($brandName) ?><span>.</span></div>
  <div class="aside-copy">
    <div class="eyebrow">Private agency operations</div>
    <h2>Clarity in every detail. Confidence in every decision.</h2>
    <p>Access is limited to verified, authorised team members. Sensitive workspaces require additional identity checks and are recorded in the security audit trail.</p>
  </div>
  <div class="aside-foot">SECURE ACCESS · SESSION PROTECTED · AUDIT ENABLED</div>
</aside>
<main>
<section class="panel" aria-labelledby="page-title">
  <div class="eyebrow">Talyvane / Administration</div>
  <h1 id="page-title"><?= $escape($heading) ?></h1>
  <p class="intro"><?= $escape($description) ?></p>
  <?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

  <?php if ($screen === 'login'): ?>
  <form method="post" action="/admin/login" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
    <div class="field"><label for="email">Work email</label><input id="email" name="email" type="email" inputmode="email" autocomplete="username" maxlength="254" required value="<?= $escape($email) ?>" placeholder="you@agency.com"></div>
    <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required placeholder="Enter your password"></div>
    <button class="button" type="submit">Continue securely</button>
  </form>
  <?php elseif ($screen === 'mfa'): ?>
  <form method="post" action="/admin/mfa">
    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
    <div class="field"><label for="code">Authenticator code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000"></div>
    <div class="rule"></div>
    <div class="field"><label for="recovery_code">Or use a recovery code</label><input id="recovery_code" name="recovery_code" autocomplete="off" maxlength="20" placeholder="20-character recovery code"></div>
    <button class="button" type="submit">Verify and enter</button>
  </form>
  <?php elseif ($screen === 'first_login'): ?>
  <form method="post" action="/admin/first-login">
    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
    <div class="field"><label for="current_password">Initial password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" maxlength="1024" required></div>
    <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required><span class="hint">Use at least 14 characters. Do not reuse the initial password.</span></div>
    <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="1024" required></div>
    <button class="button" type="submit">Replace initial password</button>
  </form>
  <?php elseif ($screen === 'mfa_setup'): ?>
  <p class="hint">In your authenticator app, add a time-based one-time password account and enter this secret manually.</p>
  <div class="code" aria-label="Authenticator setup secret"><?= $escape($secret) ?></div>
  <form method="post" action="/admin/mfa/setup">
    <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
    <div class="field"><label for="code">Six-digit confirmation code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="000000"></div>
    <button class="button" type="submit">Enable two-factor authentication</button>
  </form>
  <?php elseif ($screen === 'recovery_codes'): ?>
  <div class="codes"><?php foreach ($recoveryCodes as $code): ?><code><?= $escape($code) ?></code><?php endforeach; ?></div>
  <p class="hint">Each code can be used once. Do not share these codes. This screen will not show them again.</p>
  <a class="button" href="/admin" style="display:block;text-align:center;text-decoration:none">Continue to control room</a>
  <?php elseif ($screen === 'dashboard'): ?>
  <div class="role-list"><?php foreach ($roles as $role): ?><span><?= $escape($role) ?></span><?php endforeach; ?></div>
  <div class="rule"></div>
  <p class="hint">Authentication and administrator MFA are complete. Operational workspaces are exposed only to the roles and workflows that have passed their authorization gates.</p>
  <?php if (array_intersect($roles, ['super_admin','owner','admin']) !== []): ?>
  <a class="button" href="/admin/content" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Open content workspace</a>
  <a class="button" href="/admin/destinations" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Manage destinations</a>
  <a class="button" href="/admin/navigation" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Manage navigation</a>
  <a class="button" href="/admin/enquiries" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Open enquiry inbox</a>
  <a class="button" href="/admin/companions" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Review companion profiles</a>
  <a class="button" href="/admin/profile-media" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Review profile media</a>
  <a class="button" href="/admin/model-agency" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Manage model-agency applications</a>
  <a class="button" href="/admin/bookings" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Manage bookings</a>
  <a class="button" href="/admin/safeguarding/reports" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Submit confidential safeguarding report</a>
  <a class="button" href="/admin/clients" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Manage client memberships</a>
  <a class="button" href="/admin/privacy/requests" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Review privacy requests</a>
  <?php if (in_array('verifier', $roles, true)): ?><a class="button" href="/admin/model-agency/verifications" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Open verifier queue</a><?php endif; ?>
  <?php endif; ?>
  <?php if (in_array('safeguarding', $roles, true)): ?><a class="button" href="/admin/safeguarding/cases" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Open assigned safeguarding cases</a><?php endif; ?>
  <?php if (array_intersect($roles, ['super_admin','owner']) !== []): ?><a class="button" href="/admin/safeguarding/governance" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Safeguarding governance register</a><?php endif; ?>
  <?php if (in_array('super_admin', $roles, true)): ?><a class="button" href="/admin/brand" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Configure brand identity</a><a class="button" href="/admin/audit" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">View security audit trail</a><a class="button" href="/admin/markets" style="display:block;text-align:center;text-decoration:none;margin-bottom:10px">Review market compliance</a><?php endif; ?>
  <form method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="button" type="submit">Sign out securely</button></form>
  <?php endif; ?>
  <div class="footer">Access attempts are rate-limited. Administrator sessions expire after inactivity and are revoked when account security state changes.</div>
</section>
</main>
</body>
</html>
