<?php

namespace App\Http\Controllers\Install;

use App\Install\EnvironmentWriter;
use App\Install\InstallerAccessToken;
use App\Install\InstallationDirectoryRemover;
use App\Install\InstallationLock;
use App\Install\InstallerPendingState;
use App\Models\SuperAdminRecoveryEvent;
use App\Models\User;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Services\RecoveryCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

final class InstallerController
{
    public function index(Request $request, InstallerAccessToken $accessToken, InstallerPendingState $pendingState): View
    {
        $startedAt = filter_var($request->session()->get('installer.started_at'), FILTER_VALIDATE_INT);
        if ($startedAt === false || $startedAt === null || $startedAt < time() - 900) {
            $pendingNonce = $request->session()->get('installer.pending_nonce');
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            if ($startedAt !== false && $startedAt !== null) {
                $request->session()->invalidate();
            } else {
                $request->session()->regenerate();
            }
            $request->session()->regenerateToken();
            $request->session()->put('installer.started_at', time());
        }

        $accessToken->ensure();
        $checks = $this->preflightChecks($request, $accessToken);

        return view('install.index', [
            'checks' => $checks,
            'ready' => ! in_array(false, $checks, true),
        ]);
    }

    private function preflightChecks(Request $request, InstallerAccessToken $accessToken): array
    {
        $gdInfo = function_exists('gd_info') ? gd_info() : [];
        $root = realpath(base_path());
        $public = realpath(public_path());
        $documentRoot = realpath((string) $request->server('DOCUMENT_ROOT', ''));
        $extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml'];
        $rootIsPrivate = $root !== false && $documentRoot !== false
            && $root !== $documentRoot
            && ! str_starts_with($root, rtrim($documentRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);

        return [
            'PHP 8.3 or later' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'Required PHP extensions' => count(array_filter($extensions, 'extension_loaded')) === count($extensions),
            'GD WebP image support' => ($gdInfo['WebP Support'] ?? false) === true,
            'HTTPS request' => $request->isSecure(),
            'Document root is isolated and serves only public files' => $rootIsPrivate
                && $public !== false
                && $documentRoot === $public
                && $this->documentRootContainsNoPrivateApplicationFiles($documentRoot),
            'Private application storage is writable' => $this->isSafeWritableDirectory('storage/app/private'),
            'Session storage is writable' => $this->isSafeWritableDirectory('storage/framework/sessions'),
            'Framework cache storage is writable' => $this->isSafeWritableDirectory('storage/framework/cache'),
            'Compiled view storage is writable' => $this->isSafeWritableDirectory('storage/framework/views'),
            'Application log storage is writable' => $this->isSafeWritableDirectory('storage/logs'),
            'Framework cache is writable' => $this->isSafeWritableDirectory('bootstrap/cache'),
            'Unique application key is initialized' => is_string(config('app.key')) && config('app.key') !== '',
            'Production environment is enabled' => config('app.env') === 'production',
            'Production debug mode is disabled' => config('app.debug') === false,
            'Encrypted file-backed sessions are configured' => config('session.driver') === 'file'
                && config('session.encrypt') === true
                && config('session.secure') === true
                && config('session.http_only') === true
                && config('session.same_site') === 'lax',
            'File-backed cache and synchronous setup queue are configured' => config('cache.default') === 'file'
                && config('queue.default') === 'sync',
            'Configuration cache is absent' => ! is_file(base_path('bootstrap/cache/config.php')),
            'Private installer directory is present' => is_dir(base_path('install')) && ! is_link(base_path('install')),
            'One-time private installer access key is available' => $accessToken->isAvailable(),
        ];
    }



    private function documentRootContainsNoPrivateApplicationFiles(string $documentRoot): bool
    {
        $forbidden = [
            '.env', '.env.example', '.git', 'app', 'artisan', 'bootstrap',
            'composer.json', 'composer.lock', 'config', 'database', 'install',
            'phpunit.xml', 'resources', 'routes', 'tests', 'vendor',
        ];

        foreach ($forbidden as $entry) {
            if (file_exists($documentRoot.DIRECTORY_SEPARATOR.$entry)
                || is_link($documentRoot.DIRECTORY_SEPARATOR.$entry)) {
                return false;
            }
        }

        $publicStorage = $documentRoot.DIRECTORY_SEPARATOR.'storage';
        if (file_exists($publicStorage) || is_link($publicStorage)) {
            $expectedStorage = realpath(storage_path('app/public'));
            if (! is_link($publicStorage)
                || $expectedStorage === false
                || realpath($publicStorage) !== $expectedStorage) {
                return false;
            }
        }

        return true;
    }

    private function isSafeWritableDirectory(string $relativePath): bool
    {
        $basePath = realpath(base_path());
        if ($basePath === false || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            return false;
        }

        $path = $basePath;
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                return false;
            }

            $path .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($path)) {
                return false;
            }
        }

        return is_dir($path) && realpath($path) === $path && is_writable($path);
    }

    public function verifyForm(Request $request, InstallerPendingState $pendingState): View|RedirectResponse
    {
        $installerStartedAt = filter_var($request->session()->get('installer.started_at'), FILTER_VALIDATE_INT);
        if ($installerStartedAt === false || $installerStartedAt === null || $installerStartedAt < time() - 900) {
            logger()->warning('Talyvane installer session continuity check failed.', [
                'session_id_hash' => hash('sha256', $request->session()->getId()),
                'session_has_started_at' => $request->session()->has('installer.started_at'),
                'session_started_at_type' => get_debug_type($request->session()->get('installer.started_at')),
                'session_driver' => config('session.driver'),
                'session_cookie_present' => $request->cookies->has((string) config('session.cookie')),
                'session_cookie_value_length' => strlen((string) $request->cookie((string) config('session.cookie'), '')),
                'session_file_exists' => is_file(storage_path('framework/sessions/'.$request->session()->getId())),
                'app_key_hash' => hash('sha256', (string) config('app.key')),
                'secure_request' => $request->isSecure(),
            ]);

            $pendingNonce = $request->session()->get('installer.pending_nonce');
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('install.index')->withErrors([
                'installer' => 'The installer session expired. Restart the installation process.',
            ]);
        }

        $pendingNonce = $request->session()->get('installer.pending_nonce');
        if (! is_string($pendingNonce) || ! $pendingState->verify($pendingNonce)) {
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            $request->session()->forget(['installer.pending_admin', 'installer.pending_nonce']);

            return redirect()->route('install.index')->withErrors([
                'installer' => 'The pending installation state expired or is inconsistent. Restart the installation process.',
            ]);
        }

        $pending = $request->session()->get('installer.pending_admin');

        if (! is_array($pending) || ! isset($pending['email'], $pending['recovery_email'], $pending['started_at'])
            || time() - (int) $pending['started_at'] > 300
            || (int) ($pending['attempts'] ?? 0) >= 5) {
            $pendingNonce = $request->session()->get('installer.pending_nonce');
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            $request->session()->forget(['installer.pending_admin', 'installer.pending_nonce']);

            return redirect()->route('install.index')->withErrors([
                'installer' => 'The verification session expired. Restart the installation verification step.',
            ]);
        }

        return view('install.verify', [
            'adminEmail' => $pending['email'],
            'recoveryEmail' => $pending['recovery_email'],
        ]);
    }

    public function store(Request $request, EnvironmentWriter $environment, RecoveryCodeGenerator $codes, InstallerAccessToken $accessToken, InstallerPendingState $pendingState): RedirectResponse
    {
        $validated = $request->validate([
            'installer_token' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]+$/'],
            'app_name' => ['required', 'string', 'max:80'],
            'app_url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'db_username' => ['required', 'string', 'max:128'],
            'db_password' => ['required', 'string', 'max:512'],
            'mail_scheme' => ['required', 'in:smtp,smtps'],
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'between:1,65535'],
            'mail_username' => ['required', 'string', 'max:254'],
            'mail_password' => ['required', 'string', 'max:512'],
            'mail_from_address' => ['required', 'email', 'max:254'],
            'mail_from_name' => ['required', 'string', 'max:120'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:254'],
            'recovery_email' => ['required', 'email', 'different:admin_email', 'max:254'],
        ]);

        $installerStartedAt = filter_var($request->session()->get('installer.started_at'), FILTER_VALIDATE_INT);
        if ($installerStartedAt === false || $installerStartedAt === null || $installerStartedAt < time() - 900) {
            $pendingNonce = $request->session()->get('installer.pending_nonce');
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'installer' => 'The installer session expired. Reload the installer and begin again.',
            ]);
        }

        if (! $accessToken->verify($validated['installer_token'])) {
            throw ValidationException::withMessages([
                'installer_token' => 'The private one-time installer access key is invalid or has already been consumed.',
            ]);
        }

        if (hash_equals(strtolower($validated['admin_email']), strtolower($validated['recovery_email']))) {
            throw ValidationException::withMessages([
                'recovery_email' => 'The recovery email must be different from the Super Admin email.',
            ]);
        }

        $checks = $this->preflightChecks($request, $accessToken);
        if (in_array(false, $checks, true)) {
            $blockedChecks = array_keys(array_filter($checks, static fn (bool $passed): bool => ! $passed));

            throw ValidationException::withMessages([
                'installer' => 'Resolve these hosting preflight checks before continuing: '.implode('; ', $blockedChecks).'.',
            ]);
        }

        $urlParts = parse_url($validated['app_url']);
        $submittedHost = is_array($urlParts) ? ($urlParts['host'] ?? null) : null;
        if (! is_string($submittedHost)
            || isset($urlParts['user'])
            || isset($urlParts['pass'])
            || isset($urlParts['query'])
            || isset($urlParts['fragment'])
            || ! in_array($urlParts['path'] ?? '', ['', '/'], true)
            || ! hash_equals(strtolower($request->getHost()), strtolower($submittedHost))) {
            throw ValidationException::withMessages([
                'app_url' => 'The canonical application URL host must match the HTTPS host serving this installer.',
            ]);
        }

        try {
            $pendingNonce = $pendingState->acquire();
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'installer' => 'Another installation verification is already in progress. Continue that session or wait for it to expire before restarting.',
            ]);
        }

        $stage = 'environment configuration';

        try {
            $environment->update([
                'APP_NAME' => $validated['app_name'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => rtrim($validated['app_url'], '/'),
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $validated['db_host'],
                'DB_PORT' => (string) $validated['db_port'],
                'DB_DATABASE' => $validated['db_database'],
                'DB_USERNAME' => $validated['db_username'],
                'DB_PASSWORD' => $validated['db_password'],
                'MAIL_MAILER' => 'smtp',
                'MAIL_SCHEME' => $validated['mail_scheme'],
                'MAIL_HOST' => $validated['mail_host'],
                'MAIL_PORT' => (string) $validated['mail_port'],
                'MAIL_USERNAME' => $validated['mail_username'],
                'MAIL_PASSWORD' => $validated['mail_password'],
                'MAIL_FROM_ADDRESS' => $validated['mail_from_address'],
                'MAIL_FROM_NAME' => $validated['mail_from_name'],
                'SESSION_DRIVER' => 'file',
                'SESSION_ENCRYPT' => 'true',
                'SESSION_SECURE_COOKIE' => 'true',
                'CACHE_STORE' => 'file',
                'QUEUE_CONNECTION' => 'sync',
                'TALYVANE_INSTALLER_ENFORCE' => 'true',
            ]);

            config([
                'talyvane.installer.enforce' => true,
                'app.env' => 'production',
                'app.debug' => false,
                'session.driver' => 'file',
                'session.encrypt' => true,
                'session.secure' => true,
                'session.http_only' => true,
                'session.same_site' => 'lax',
                'cache.default' => 'file',
                'queue.default' => 'sync',
                'app.name' => $validated['app_name'],
                'app.url' => rtrim($validated['app_url'], '/'),
                'database.default' => 'mysql',
                'database.connections.mysql.host' => $validated['db_host'],
                'database.connections.mysql.port' => (string) $validated['db_port'],
                'database.connections.mysql.database' => $validated['db_database'],
                'database.connections.mysql.username' => $validated['db_username'],
                'database.connections.mysql.password' => $validated['db_password'],
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.scheme' => $validated['mail_scheme'],
                'mail.mailers.smtp.host' => $validated['mail_host'],
                'mail.mailers.smtp.port' => (int) $validated['mail_port'],
                'mail.mailers.smtp.username' => $validated['mail_username'],
                'mail.mailers.smtp.password' => $validated['mail_password'],
                'mail.from.address' => $validated['mail_from_address'],
                'mail.from.name' => $validated['mail_from_name'],
            ]);

            $stage = 'database connection';
            DB::purge('mysql');
            DB::connection('mysql')->select('SELECT 1');

            $stage = 'database preflight';
            $connection = DB::connection('mysql');
            $schema = $connection->getSchemaBuilder();
            $tables = $schema->getTableListing();
            $hasPlatformControl = $schema->hasTable('platform_control');
            $hasMigrations = $schema->hasTable('migrations');

            if ($tables !== []) {
                if (! $hasPlatformControl || ! $hasMigrations) {
                    throw new RuntimeException('The selected database is not empty or an interrupted Talyvane installation.');
                }

                // A pair of familiar table names is not proof that this is a
                // Talyvane database. Require known migration history and reject
                // any table that is not declared by this release's migrations.
                $migrator = app('migrator');
                $migrationPaths = array_merge($migrator->paths(), [database_path('migrations')]);
                $migrationFiles = $migrator->getMigrationFiles($migrationPaths);
                $knownMigrations = array_fill_keys(array_keys($migrationFiles), true);
                $appliedMigrations = $connection->table('migrations')->pluck('migration')->all();

                if ($appliedMigrations === []
                    || array_diff($appliedMigrations, array_keys($knownMigrations)) !== []) {
                    throw new RuntimeException('The selected database does not contain a recognised Talyvane migration history.');
                }

                $knownTables = ['migrations'];
                foreach ($migrationFiles as $migrationFile) {
                    $migrationSource = file_get_contents($migrationFile);
                    if (! is_string($migrationSource)) {
                        throw new RuntimeException('The release migration inventory could not be verified.');
                    }

                    preg_match_all(
                        '/\\bSchema::(?:create|table|dropIfExists)\\s*\\(\\s*[\'\"]([A-Za-z0-9_]+)[\'\"]/',
                        $migrationSource,
                        $matches,
                    );
                    $knownTables = array_merge($knownTables, $matches[1] ?? []);
                }

                $knownTables = array_unique(array_map('strtolower', $knownTables));
                $normalisedTables = array_map(static function (string $table): string {
                    $parts = explode('.', $table);

                    return strtolower((string) end($parts));
                }, $tables);
                $unknownTables = array_diff($normalisedTables, $knownTables);

                if ($unknownTables !== []) {
                    throw new RuntimeException('The selected database contains tables outside the recognised Talyvane schema: '.implode(', ', $unknownTables).'.');
                }
            }

            if ($hasPlatformControl) {
                $existingControl = $connection->table('platform_control')->where('id', 1)->first();
                if (! $existingControl
                    || $existingControl->super_admin_user_id !== null
                    || ! empty($existingControl->installation_id)
                    || ! empty($existingControl->installation_lock_hash)
                    || ! empty($existingControl->installation_completed_at)) {
                    throw new RuntimeException('The selected database already has an administrator or completed installation state.');
                }
            }

            if ($schema->hasTable('users') && $connection->table('users')->exists()) {
                throw new RuntimeException('The selected database already contains user records and cannot be used for first installation.');
            }

            $stage = 'database migrations';
            $migrationExitCode = Artisan::call('migrate', ['--force' => true]);
            if ($migrationExitCode !== 0) {
                throw new RuntimeException('The database migration step did not complete.');
            }

            $stage = 'administrator email verification';
            $primaryCode = $codes->generate();
            $recoveryCode = $codes->generate();

            Notification::route('mail', strtolower($validated['admin_email']))
                ->notify(new SuperAdminEmailVerificationCode($primaryCode, 'primary administrator email'));
            Notification::route('mail', strtolower($validated['recovery_email']))
                ->notify(new SuperAdminEmailVerificationCode($recoveryCode, 'recovery notification email'));

            $request->session()->put('installer.pending_admin', [
                'name' => $validated['admin_name'],
                'email' => strtolower($validated['admin_email']),
                'recovery_email' => strtolower($validated['recovery_email']),
                'primary_code_hash' => Hash::make($primaryCode),
                'recovery_code_hash' => Hash::make($recoveryCode),
                'started_at' => time(),
                'attempts' => 0,
            ]);
            $request->session()->put('installer.pending_nonce', $pendingNonce);
        } catch (Throwable $exception) {
            $pendingState->release($pendingNonce);
            // Database and SMTP exception messages can contain credentials.
            // Log only non-secret classification data; never persist driver diagnostics.
            logger()->warning('Talyvane browser installer configuration step failed.', [
                'stage' => $stage,
                'exception_class' => $exception::class,
                'exception_code' => (string) $exception->getCode(),
            ]);

            $message = 'Configuration could not be verified during the '.$stage.' step. Check the database and SMTP settings, then retry. Secret values and internal diagnostics are not displayed.';
            if (app()->environment('testing')) {
                $message .= ' ['.$exception::class.': '.$exception->getMessage().']';
            }

            throw ValidationException::withMessages([
                'installer' => $message,
            ]);
        }

        return redirect()->route('install.verify');
    }

    public function verify(Request $request, InstallerAccessToken $accessToken, InstallationDirectoryRemover $remover, InstallerPendingState $pendingState): RedirectResponse
    {
        $validated = $request->validate([
            'primary_code' => ['required', 'digits:6'],
            'recovery_code' => ['required', 'digits:6'],
        ]);

        $installerStartedAt = filter_var($request->session()->get('installer.started_at'), FILTER_VALIDATE_INT);
        if ($installerStartedAt === false || $installerStartedAt === null || $installerStartedAt < time() - 900) {
            $pendingNonce = $request->session()->get('installer.pending_nonce');
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'installer' => 'The installer session expired. Restart the installation process.',
            ]);
        }

        $pendingNonce = $request->session()->get('installer.pending_nonce');
        if (! is_string($pendingNonce) || ! $pendingState->verify($pendingNonce)) {
            if (is_string($pendingNonce)) {
                $pendingState->release($pendingNonce);
            }
            $request->session()->forget(['installer.pending_admin', 'installer.pending_nonce']);

            throw ValidationException::withMessages([
                'installer' => 'The pending installation state expired or is inconsistent. Restart the installation process.',
            ]);
        }

        $pending = $request->session()->get('installer.pending_admin');
        if (! is_array($pending)
            || ! isset($pending['started_at'], $pending['attempts'], $pending['primary_code_hash'], $pending['recovery_code_hash'])
            || time() - (int) $pending['started_at'] > 300
            || (int) $pending['attempts'] >= 5) {
            $pendingState->release($pendingNonce);
            $request->session()->forget(['installer.pending_admin', 'installer.pending_nonce']);
            throw ValidationException::withMessages(['installer' => 'The verification session expired. Restart the installation verification step.']);
        }

        if (! Hash::check($validated['primary_code'], $pending['primary_code_hash'])
            || ! Hash::check($validated['recovery_code'], $pending['recovery_code_hash'])) {
            $pending['attempts'] = (int) $pending['attempts'] + 1;
            $request->session()->put('installer.pending_admin', $pending);
            throw ValidationException::withMessages(['installer' => 'One or both verification codes are invalid.']);
        }

        $password = $this->generateInitialPassword();
        $recoveryKey = bin2hex(random_bytes(32));
        $lock = new InstallationLock(base_path(), (string) config('app.key'));
        $lockData = null;

        try {
            DB::transaction(function () use ($pending, $password, $recoveryKey, $lock, &$lockData): void {
                $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();

                if (! $control
                    || $control->super_admin_user_id !== null
                    || User::query()->where('role', 'super_admin')->exists()
                    || User::query()->where('email', $pending['email'])->exists()) {
                    throw new RuntimeException('The deployment administrator state is not eligible for first installation.');
                }

                $user = User::query()->create([
                    'name' => $pending['name'],
                    'email' => $pending['email'],
                    'password' => $password,
                    'role' => 'super_admin',
                    'is_active' => true,
                ]);

                $user->forceFill([
                    'email_verified_at' => now(),
                    'must_change_password_at' => now(),
                ])->save();

                $lockData = $lock->create();
                $occurredAt = now();

                DB::table('platform_control')->where('id', 1)->update([
                    'super_admin_user_id' => $user->id,
                    'super_admin_recovery_key_hash' => Hash::make($recoveryKey),
                    'super_admin_recovery_key_rotated_at' => $occurredAt,
                    'super_admin_recovery_email' => $pending['recovery_email'],
                    'super_admin_recovery_email_verified_at' => $occurredAt,
                    'super_admin_recovery_key_handoff' => Crypt::encryptString($recoveryKey),
                    'super_admin_recovery_key_handoff_expires_at' => $occurredAt->copy()->addDay(),
                    'installation_id' => $lockData['installation_id'],
                    'installation_lock_hash' => $lockData['lock_hash'],
                    'installation_completed_at' => $occurredAt,
                    'updated_at' => $occurredAt,
                ]);

                SuperAdminRecoveryEvent::query()->create([
                    'super_admin_user_id' => $user->id,
                    'event_type' => 'bootstrap_recovery_key_issued',
                    'reason' => 'Initial browser installation Super Admin provisioning',
                    'session_version_before' => null,
                    'session_version_after' => (int) $user->admin_session_version,
                    'metadata' => ['verified_notification_addresses' => 2, 'source' => 'browser_installer'],
                    'occurred_at' => $occurredAt,
                ]);
            });
        } catch (Throwable) {
            if (is_array($lockData)) {
                $lock->removeIfMatches($lockData['installation_id'], $lockData['lock_hash']);
            }

            throw ValidationException::withMessages([
                'installer' => 'Super Admin provisioning could not be completed safely. No credentials were disclosed. Contact the deployment administrator before retrying.',
            ]);
        }

        try {
            $accessTokenRemoved = $accessToken->consume();
        } catch (Throwable) {
            $accessTokenRemoved = false;
        }

        $pendingState->release($pendingNonce);
        $installerDirectoryRemoved = false;
        try {
            $installerDirectoryRemoved = $remover->remove();
        } catch (Throwable) {
            $installerDirectoryRemoved = false;
        }
        // Treat the installer as fully removed only when both the private
        // bootstrap directory and its one-time access-key file are gone.
        $installerRemoved = $accessTokenRemoved && $installerDirectoryRemoved;

        $request->session()->forget(['installer.pending_admin', 'installer.pending_nonce']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('install.success.handoff', Crypt::encryptString(json_encode([
            'password' => $password,
            'expires_at' => time() + 900,
            'access_token_removed' => $accessTokenRemoved,
            'installer_removed' => $installerRemoved,
        ], JSON_THROW_ON_ERROR)));

        return redirect()->route('install.success');
    }

    private function generateInitialPassword(): string
    {
        do {
            $password = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        } while (
            preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1
            || preg_match('/[^A-Za-z0-9]/', $password) !== 1
        );

        return $password;
    }
}
