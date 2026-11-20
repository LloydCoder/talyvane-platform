<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Verify Super Admin recovery</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
    <p class="eyebrow">DUAL-EMAIL VERIFICATION</p><h1>Set a new<br><em>password.</em></h1>
    @if (session('status'))<p class="admin-muted">{{ session('status') }}</p>@endif
    @if ($errors->any())<div role="alert" class="form-errors">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('admin.first-login.recover.complete') }}" autocomplete="off">
        @csrf
        <label>Code sent to the Super Admin email<input name="primary_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
        <label>Code sent to the recovery email<input name="recovery_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></label>
        <label>New password<input type="password" name="password" minlength="14" maxlength="200" required autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="password_confirmation" minlength="14" maxlength="200" required autocomplete="new-password"></label>
        <button class="button button-dark admin-submit" type="submit">Recover account and continue to MFA</button>
    </form>
</main></body></html>
