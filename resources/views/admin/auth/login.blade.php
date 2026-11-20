<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Administrator sign in — Talyvane</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="admin-auth-body">
    <main class="admin-auth-card">
        <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
        <p class="eyebrow">SECURE ADMINISTRATION</p>
        <h1>Welcome<br><em>back.</em></h1>
        <p class="admin-muted">Sign in with your authorised operator account.</p>
        @if ($errors->any())
            <div class="form-alert" role="alert">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('admin.login.store') }}">
            @csrf
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <label class="remember-row"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            <button class="button button-light admin-submit" type="submit">Sign in <span aria-hidden="true">↗</span></button>
        </form>
        <p class="admin-muted"><a href="{{ route('admin.first-login.recover') }}">Lost the initial Super Admin password?</a></p>
        <a class="admin-back" href="{{ route('home') }}">← Return to public site</a>
    </main>
</body>
</html>
