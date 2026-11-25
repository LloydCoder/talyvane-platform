<?php
declare(strict_types=1);

$brandName = is_string($brandName ?? null) && $brandName !== '' ? $brandName : 'Talyvane';
$initialPassword = is_string($initialPassword ?? null) ? $initialPassword : '';
$offlineRecoveryKey = is_string($offlineRecoveryKey ?? null) ? $offlineRecoveryKey : '';
$appUrl = is_string($appUrl ?? null) ? $appUrl : '';
$migrationWarnings = is_array($migrationWarnings ?? null) ? $migrationWarnings : [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Installation complete · <?= $escape($brandName) ?></title>
<style>
:root{color-scheme:light;--ink:#182638;--muted:#667487;--paper:#f4f2ed;--panel:#fffefa;--line:#e6e2d9;--navy:#142235;--gold:#bd9560;--ok:#315c42}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:14px/1.6 Inter,ui-sans-serif,system-ui,sans-serif}.shell{width:min(760px,calc(100% - 36px));margin:auto}.top{padding:24px 0;border-bottom:1px solid var(--line)}.brand{font:600 21px Georgia,serif;letter-spacing:.06em;color:var(--ink);text-decoration:none}.intro{padding:42px 0 20px}.eyebrow{font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:#88643a;font-weight:700}h1{font:400 clamp(34px,5vw,50px)/1.08 Georgia,serif;margin:10px 0}.panel{background:var(--panel);border:1px solid var(--line);padding:clamp(18px,3vw,30px);margin:18px 0}.secret{background:#f6f1e8;border:1px solid #e2d4bd;padding:16px;margin:14px 0}.secret label{display:block;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);font-weight:700;margin-bottom:8px}.secret code{display:block;overflow-wrap:anywhere;font:600 15px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace;color:var(--ink);user-select:all}.notice{background:#f0f5ef;border:1px solid #d6e2d3;color:var(--ok);padding:12px 14px}.warning{background:#fff4df;border:1px solid #e4c78d;color:#674717;padding:12px 14px;margin:14px 0}.warning p{margin:6px 0 0}.button{display:inline-block;background:var(--navy);color:white;text-decoration:none;padding:13px 18px;font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;margin-top:18px}.muted{color:var(--muted);font-size:12px}
</style>
</head>
<body>
<header class="shell top"><a class="brand" href="<?= $escape($appUrl) ?>"><?= $escape($brandName) ?></a></header>
<main class="shell">
<section class="intro"><div class="eyebrow">Installation complete</div><h1>Your platform is ready</h1><p class="notice">Both administrator email addresses were verified. The native database schema, reference datasets, first Super Admin account and permanent installer lock have been created.</p>
<?php if ($migrationWarnings !== []): ?><div class="warning" role="alert"><strong>Database capability notice</strong><p>This database account could not create one or more database-level audit immutability triggers. Application-level append-only controls remain active, but direct database updates/deletes are not blocked by those triggers. Keep privileged database access restricted and arrange trigger support before production use.</p></div><?php endif; ?></section>
<section class="panel">
<h2>One-time administrator credentials</h2>
<p>Store these values in a password manager and an approved offline recovery location. They are shown once and cannot be retrieved from the application after this page is closed.</p>
<div class="secret"><label>Initial Super Admin password</label><code><?= $escape($initialPassword) ?></code></div>
<div class="secret"><label>Offline Super Admin recovery key</label><code><?= $escape($offlineRecoveryKey) ?></code></div>
<p class="muted">Store both values in a password manager and an approved offline recovery location before leaving this page. This page is session-bound, HTTPS-only, non-cacheable and shown once. The recovery key cannot be retrieved from the application after this handoff closes.</p>
<p class="muted">The first administrator login requires a password change and MFA enrollment. No terminal or command-line access is required to complete installation.</p>
<a class="button" href="<?= $escape($appUrl) ?>/admin/login">Continue to Super Admin login</a>
</section>
</main>
</body>
</html>
