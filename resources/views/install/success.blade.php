<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Talyvane installation complete</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="#">TALYVANE<span>®</span></a>
    <p class="eyebrow">INSTALLATION VERIFIED</p><h1>You're<br><em>ready.</em></h1>
    <p class="admin-muted">The database migrations, Super Admin provisioning and installation-lock checks completed successfully.</p>
    <h2>Super Admin sign-in</h2>
    <p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>
    <h2>One-time initial password</h2>
    <p class="install-secret"><code>{{ $initialPassword }}</code></p>
    <p class="admin-muted">Copy this password into a secure password manager now. This page will not show it again. The first sign-in requires you to replace it and enroll MFA before accessing administration.</p>
    @if ($installerRemoved)
        <p role="status">The private installer directory was automatically removed.</p>
    @else
        <div role="alert" class="form-errors"><p><strong>Delete the installer directory immediately.</strong> Automatic removal was not possible. In cPanel File Manager, delete the entire <code>install/</code> directory from the private Talyvane application root. Installer routes are already blocked by the durable installation lock.</p></div>
    @endif
    <p class="admin-muted">For security, the password is handed off once and cannot be retrieved again. If it is lost, use the supported account recovery process.</p>
</main></body>
</html>
