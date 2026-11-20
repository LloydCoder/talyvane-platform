<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Recover initial Super Admin password</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
    <p class="eyebrow">INITIAL ACCOUNT RECOVERY</p><h1>Recover<br><em>access.</em></h1>
    <p class="admin-muted">This recovery route is available only before the designated Super Admin has enrolled MFA. Both registered email addresses must be verified again.</p>
    @if ($errors->any())<div role="alert" class="form-errors">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('admin.first-login.recover.send') }}" autocomplete="off">
        @csrf
        <label>Super Admin email<input type="email" name="email" maxlength="254" required autocomplete="username" value="{{ old('email') }}"></label>
        <label>Registered recovery email<input type="email" name="recovery_email" maxlength="254" required autocomplete="email" value="{{ old('recovery_email') }}"></label>
        <button class="button button-dark admin-submit" type="submit">Verify both email addresses</button>
    </form>
    <a class="admin-back" href="{{ route('admin.login') }}">← Return to sign in</a>
</main></body></html>
