<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Install Talyvane</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="admin-auth-body">
<main class="admin-auth-card" style="max-width:760px">
    <a class="wordmark" href="#">TALYVANE<span>®</span></a>
    <p class="eyebrow">FIRST-TIME SETUP</p>
    <h1>Install<br><em>Talyvane.</em></h1>
    <p class="admin-muted">This guided installer configures this deployment, verifies the database and email transport, and provisions its single Super Admin. Keep this page private.</p>

    @if ($errors->any())
        <div role="alert" class="form-errors">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <h2>Hosting preflight</h2>
    <ul class="install-checks">
        @foreach ($checks as $label => $passed)
            <li><span>{{ $label }}</span><strong>{{ $passed ? 'PASS' : 'BLOCKED' }}</strong></li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('install.store', [], false) }}" autocomplete="off">
        @csrf
        <h2>Private installer access</h2>
        <p class="admin-muted">In cPanel File Manager, open the private application directory, then open <code>storage/app/private/INSTALLER-ACCESS-KEY.txt</code>. Copy its contents into the field below. This key is deliberately never sent to the browser until you enter it. Do not share it or place it in a URL.</p>
        <label>Private installer access key<input type="password" name="installer_token" required minlength="43" maxlength="43" pattern="[A-Za-z0-9_-]{43}" autocomplete="off"></label>
        <h2>Application</h2>
        <label>Deployment name<input name="app_name" required maxlength="80" value="{{ old('app_name', 'Talyvane') }}"></label>
        <label>Canonical HTTPS URL<input type="url" name="app_url" required placeholder="https://agency.example.com" value="{{ old('app_url') }}"></label>

        <p class="admin-muted"><strong>Database safety:</strong> use a new, empty database. Do not point first-time installation at another live application's database. A partially completed Talyvane migration can be resumed safely.</p>

    <h2>Database (MySQL / MariaDB)</h2>
        <label>Database host<input name="db_host" required maxlength="255" value="{{ old('db_host', 'localhost') }}"></label>
        <label>Database port<input type="number" name="db_port" required min="1" max="65535" value="{{ old('db_port', 3306) }}"></label>
        <label>Database name<input name="db_database" required maxlength="64" value="{{ old('db_database') }}"></label>
        <label>Database username<input name="db_username" required maxlength="128" value="{{ old('db_username') }}"></label>
        <label>Database password<input type="password" name="db_password" required maxlength="512" autocomplete="new-password"></label>

        <h2>SMTP email verification</h2>
        <label>SMTP security<select name="mail_scheme" required><option value="smtp">STARTTLS / SMTP</option><option value="smtps">Implicit TLS / SMTPS</option></select></label>
        <label>SMTP host<input name="mail_host" required maxlength="255" value="{{ old('mail_host') }}"></label>
        <label>SMTP port<input type="number" name="mail_port" required min="1" max="65535" value="{{ old('mail_port', 587) }}"></label>
        <label>SMTP username<input name="mail_username" required maxlength="254" value="{{ old('mail_username') }}"></label>
        <label>SMTP password<input type="password" name="mail_password" required maxlength="512" autocomplete="new-password"></label>
        <label>From email<input type="email" name="mail_from_address" required maxlength="254" value="{{ old('mail_from_address') }}"></label>
        <label>From name<input name="mail_from_name" required maxlength="120" value="{{ old('mail_from_name', 'Talyvane') }}"></label>

        <h2>Initial Super Admin</h2>
        <label>Full name<input name="admin_name" required maxlength="120" value="{{ old('admin_name') }}"></label>
        <label>Administrator email<input type="email" name="admin_email" required maxlength="254" value="{{ old('admin_email') }}"></label>
        <label>Separate recovery email<input type="email" name="recovery_email" required maxlength="254" value="{{ old('recovery_email') }}"></label>
        <p class="admin-muted">Both email addresses must receive and confirm separate one-time codes. The initial password is generated only after verification.</p>

        <button class="button button-dark admin-submit" type="submit" @disabled(! $ready)>Verify configuration and continue</button>
        @unless ($ready)<p class="admin-muted">Resolve every blocked preflight item before continuing.</p>@endunless
    </form>
</main>
</body>
</html>
