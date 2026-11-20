<?php

namespace App\Http\Controllers\Install;

use App\Install\EnvironmentWriter;
use App\Install\InstallationLock;
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
    public function index(Request $request): View
    {
        $root = realpath(base_path());
        $public = realpath(public_path());
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $extensions = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'mbstring', 'openssl', 'pcre', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml'];

        $checks = [
            'PHP 8.3 or later' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'Required PHP extensions' => count(array_filter($extensions, 'extension_loaded')) === count($extensions),
            'HTTPS request' => $request->isSecure(),
            'Document root serves public/' => $root !== false && $public !== false && $documentRoot !== false && $documentRoot === $public && $root !== $documentRoot,
            'Private application storage is writable' => is_writable(storage_path()) && is_writable(storage_path('app/private')),
            'Framework cache is writable' => is_writable(base_path('bootstrap/cache')),
            'Unique application key is initialized' => is_string(config('app.key')) && config('app.key') !== '',
        ];

        return view('install.index', [
            'checks' => $checks,
            'ready' => ! in_array(false, $checks, true),
        ]);
    }

    public function store(Request $request, EnvironmentWriter $environment, RecoveryCodeGenerator $codes): RedirectResponse
    {
        $validated = $request->validate([
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

        $root = realpath(base_path());
        $public = realpath(public_path());
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if (! $request->isSecure() || $root === false || $public === false || $documentRoot !== $public || $root === $documentRoot) {
            throw ValidationException::withMessages([
                'installer' => 'This host does not meet the required HTTPS and private document-root boundary.',
            ]);
        }

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

            DB::purge('mysql');
            DB::connection('mysql')->select('SELECT 1');

            $migrationExitCode = Artisan::call('migrate', ['--force' => true]);
            if ($migrationExitCode !== 0) {
                throw new RuntimeException('The database migration step did not complete.');
            }

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
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'installer' => 'Configuration could not be verified. Check the database and SMTP settings, then retry. Secret values are not displayed.',
            ]);
        }

        return redirect()->route('install.verify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'primary_code' => ['required', 'digits:6'],
            'recovery_code' => ['required', 'digits:6'],
        ]);

        $pending = $request->session()->get('installer.pending_admin');
        if (! is_array($pending)
            || ! isset($pending['started_at'], $pending['attempts'], $pending['primary_code_hash'], $pending['recovery_code_hash'])
            || time() - (int) $pending['started_at'] > 300
            || (int) $pending['attempts'] >= 5) {
            $request->session()->forget('installer.pending_admin');
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

        $request->session()->forget('installer.pending_admin');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('install.success.handoff', Crypt::encryptString(json_encode([
            'password' => $password,
            'expires_at' => time() + 900,
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
