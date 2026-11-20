<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Change initial password</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
    <p class="eyebrow">REQUIRED FIRST-LOGIN SECURITY</p><h1>Change your<br><em>password.</em></h1>
    <p class="admin-muted">Replace the one-time installation password before continuing. You must then enroll or verify administrator MFA.</p>
    @if ($errors->any())<div role="alert" class="form-errors">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('admin.first-login.password.update') }}" autocomplete="off">
        @csrf
        <label>New password<input type="password" name="password" minlength="14" maxlength="200" required autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="password_confirmation" minlength="14" maxlength="200" required autocomplete="new-password"></label>
        <button class="button button-dark admin-submit" type="submit">Replace password and continue</button>
    </form>
</main></body></html>
