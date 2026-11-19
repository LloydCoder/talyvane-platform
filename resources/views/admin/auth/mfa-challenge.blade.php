<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administrator verification</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
    <p class="eyebrow">MULTI-FACTOR AUTHENTICATION</p><h1>Verify<br><em>identity.</em></h1><p class="admin-muted">Enter the current six-digit code from your authenticator app.</p>
    @if ($errors->any())<div class="form-alert" role="alert">{{ $errors->first('code') }}</div>@endif
    <form method="POST" action="{{ route('admin.mfa.challenge.verify') }}">
        @csrf
        <label for="code">Authenticator code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        <button class="button button-dark admin-submit" type="submit">Verify and continue ↗</button>
    </form>
    <details class="mfa-recovery"><summary>Use a recovery code</summary>
        <form method="POST" action="{{ route('admin.mfa.recovery.verify') }}">@csrf
            <label for="recovery_code">Unused recovery code</label><input id="recovery_code" name="recovery_code" maxlength="32" autocomplete="off" required>
            <button class="text-link" type="submit">Verify recovery code</button>
        </form>
    </details>
</main></body></html>
