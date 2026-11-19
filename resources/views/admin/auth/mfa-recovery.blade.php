<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Save recovery codes</title><link rel="stylesheet" href="{{ asset('css/app.css') }}"></head>
<body class="admin-auth-body"><main class="admin-auth-card">
    <a class="wordmark" href="{{ route('home') }}">TALYVANE<span>®</span></a>
    <p class="eyebrow">SAVE THESE ONCE</p><h1>Recovery<br><em>codes.</em></h1><p class="admin-muted">Store these codes in a secure password manager or offline vault. Each code can be used once. They will not be shown again.</p>
    <ul class="mfa-recovery-codes">@foreach ($codes as $code)<li>{{ $code }}</li>@endforeach</ul>
    <a class="button button-dark admin-submit" href="{{ route('admin.dashboard') }}">Continue to administration ↗</a>
</main></body></html>
