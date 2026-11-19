<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Secure administrator account</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
    <p class="eyebrow">SECURITY REQUIREMENT</p><h1>Secure your<br><em>account.</em></h1>
    <p class="admin-muted">Set up an authenticator app before accessing the administration workspace. This secret is shown only during enrollment.</p>
    @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first('code') }}</div>@endif
    <label>Authenticator secret</label><div class="mfa-secret">{{ $secret }}</div>
    <p class="field-help">Add a time-based one-time password (TOTP) account manually in your authenticator app. Account: {{ auth()->user()->email }} · Issuer: Talyvane · 30-second interval.</p>
    <label>Provisioning URI</label><textarea class="mfa-uri" rows="3" readonly>{{ $provisioningUri }}</textarea>
    <form method="POST" action="{{ route('admin.mfa.setup.confirm') }}">
        @csrf
        <label for="code">Six-digit authenticator code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        <button class="button button-dark admin-submit" type="submit">Enable MFA ↗</button>
    </form>
</main></body></html>
