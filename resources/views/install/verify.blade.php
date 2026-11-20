<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Verify installer email addresses</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="#">TALYVANE<span>®</span></a>
    <p class="eyebrow">INSTALLATION VERIFICATION</p><h1>Verify<br><em>ownership.</em></h1>
    <p class="admin-muted">Enter the separate six-digit codes sent to {{ $adminEmail }} and {{ $recoveryEmail }}. Codes expire after five minutes.</p>
    @if ($errors->any())<div role="alert" class="form-errors">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('install.verify.submit', [], false) }}" autocomplete="off">
        @csrf
        <label>Primary administrator email code<input name="primary_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
        <label>Recovery email code<input name="recovery_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
        <button class="button button-dark admin-submit" type="submit">Verify and complete installation</button>
    </form>
</main></body></html>
