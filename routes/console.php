<?php

use App\Models\User;
use App\Models\SuperAdminRecoveryEvent;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Notifications\SuperAdminRecoveryNotice;
use App\Services\RecoveryCodeGenerator;
use App\Services\TotpService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

Artisan::command('talyvane:about', function () {
    $this->comment('Talyvane platform foundation');
})->purpose('Display Talyvane application information');


Artisan::command('talyvane:super-admin:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid email address is required.');

        return 1;
    }

    if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
        $this->error('An account with that email already exists.');

        return 1;
    }

    $control = DB::table('platform_control')->where('id', 1)->first();

    if (! $control) {
        $this->error('Run the database migrations before provisioning the deployment Super Admin.');

        return 1;
    }

    if ($control->super_admin_user_id !== null || User::query()->where('role', 'super_admin')->exists()) {
        $this->error('A Super Admin is already provisioned for this deployment. Use the documented recovery process.');

        return 1;
    }

    if (config('mail.default') !== 'smtp' || blank(config('mail.mailers.smtp.host'))) {
        $this->error('Configure working SMTP delivery before provisioning a Super Admin.');

        return 1;
    }

    $name = trim((string) $this->ask('Super Admin name'));
    $password = (string) $this->secret('Password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm password');
    $recoveryEmail = strtolower(trim((string) $this->ask('Recovery notification email (must differ from admin email)')));

    $strongPassword = strlen($password) >= 14
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);

    if ($name === '' || ! $strongPassword || ! hash_equals($password, $confirmation)) {
        $this->error('A name, a strong 14-character password, and matching confirmation are required.');

        return 1;
    }

    if (! filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL) || hash_equals($email, $recoveryEmail)) {
        $this->error('A distinct, valid recovery notification email address is required.');

        return 1;
    }

    $codeGenerator = app(RecoveryCodeGenerator::class);
    $primaryCode = $codeGenerator->generate();
    $recoveryCode = $codeGenerator->generate();
    $verificationStartedAt = time();

    Notification::route('mail', $email)->notify(new SuperAdminEmailVerificationCode($primaryCode, 'primary administrator email'));
    Notification::route('mail', $recoveryEmail)->notify(new SuperAdminEmailVerificationCode($recoveryCode, 'recovery notification email'));

    $enteredPrimaryCode = (string) $this->secret('Verification code sent to '.$email);
    $enteredRecoveryCode = (string) $this->secret('Verification code sent to '.$recoveryEmail);

    if (time() - $verificationStartedAt > 300
        || ! hash_equals($primaryCode, $enteredPrimaryCode)
        || ! hash_equals($recoveryCode, $enteredRecoveryCode)) {
        $this->error('Notification address verification failed or expired. No administrator account was created.');

        return 1;
    }

    $recoveryKey = Str::random(64);

    $superAdmin = DB::transaction(function () use ($name, $email, $password, $recoveryEmail, $recoveryKey): ?User {
        $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();

        if (! $control || $control->super_admin_user_id !== null || User::query()->where('role', 'super_admin')->exists()) {
            return null;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        DB::table('platform_control')->where('id', 1)->update([
            'super_admin_user_id' => $user->id,
            'super_admin_recovery_key_hash' => Hash::make($recoveryKey),
            'super_admin_recovery_key_rotated_at' => now(),
            'super_admin_recovery_email' => $recoveryEmail,
            'super_admin_recovery_email_verified_at' => now(),
            'updated_at' => now(),
        ]);

        SuperAdminRecoveryEvent::query()->create([
            'super_admin_user_id' => $user->id,
            'event_type' => 'bootstrap_recovery_key_issued',
            'reason' => 'Initial deployment Super Admin provisioning',
            'session_version_before' => null,
            'session_version_after' => (int) $user->admin_session_version,
            'metadata' => ['verified_notification_addresses' => 2, 'source' => 'trusted_cli'],
            'occurred_at' => now(),
        ]);

        return $user;
    });

    if (! $superAdmin) {
        $this->error('A Super Admin is already provisioned for this deployment. No account was created.');

        return 1;
    }

    $this->line('Super Admin recovery key (store offline; shown once): '.$recoveryKey);
    $this->info('Deployment Super Admin created. Sign in and complete mandatory MFA setup before administration.');

    return 0;
})->purpose('Provision the single deployment Super Admin and verify both notification addresses');

Artisan::command('talyvane:super-admin:enroll-recovery', function () {
    $control = DB::table('platform_control')->where('id', 1)->first();
    $user = $control?->super_admin_user_id ? User::query()->find($control->super_admin_user_id) : null;

    if (! $user || ! $user->is_active || $user->role !== 'super_admin' || ! $user->mfa_enabled_at || ! $user->mfa_secret) {
        $this->error('Recovery enrollment requires the active designated Super Admin with MFA already enabled.');

        return 1;
    }

    if (! empty($control->super_admin_recovery_key_hash)
        && ! empty($control->super_admin_recovery_email_verified_at)
        && ! empty($control->super_admin_recovery_email)) {
        $this->error('Super Admin recovery credentials are already enrolled. Use the recovery command if access is lost.');

        return 1;
    }

    if (config('mail.default') !== 'smtp' || blank(config('mail.mailers.smtp.host'))) {
        $this->error('Configure working SMTP delivery before enrolling Super Admin recovery credentials.');

        return 1;
    }

    $password = (string) $this->secret('Current Super Admin password');
    $totpCode = (string) $this->secret('Current authenticator code');

    if (! Hash::check($password, $user->password)) {
        $this->error('The supplied recovery enrollment credentials could not be verified.');

        return 1;
    }

    $totp = app(TotpService::class);
    $verifiedStep = $totp->verify($user->mfa_secret, $totpCode, $user->mfa_last_used_step);

    if ($verifiedStep === null) {
        $this->error('The supplied recovery enrollment credentials could not be verified.');

        return 1;
    }

    $recoveryEmail = strtolower(trim((string) $this->ask('Recovery notification email (must differ from admin email)')));

    if (! filter_var($recoveryEmail, FILTER_VALIDATE_EMAIL) || hash_equals(strtolower($user->email), $recoveryEmail)) {
        $this->error('A distinct, valid recovery notification email address is required.');

        return 1;
    }

    $codeGenerator = app(RecoveryCodeGenerator::class);
    $primaryCode = $codeGenerator->generate();
    $recoveryCode = $codeGenerator->generate();
    $verificationStartedAt = time();

    Notification::route('mail', $user->email)->notify(new SuperAdminEmailVerificationCode($primaryCode, 'primary administrator email'));
    Notification::route('mail', $recoveryEmail)->notify(new SuperAdminEmailVerificationCode($recoveryCode, 'recovery notification email'));

    $enteredPrimaryCode = (string) $this->secret('Verification code sent to '.$user->email);
    $enteredRecoveryCode = (string) $this->secret('Verification code sent to '.$recoveryEmail);

    if (time() - $verificationStartedAt > 300
        || ! hash_equals($primaryCode, $enteredPrimaryCode)
        || ! hash_equals($recoveryCode, $enteredRecoveryCode)) {
        $this->error('Notification address verification failed or expired. No recovery credentials were enrolled.');

        return 1;
    }

    $recoveryKey = Str::random(64);
    $occurredAt = now();

    try {
        DB::transaction(function () use ($user, $password, $totpCode, $recoveryEmail, $recoveryKey, $occurredAt, $totp): void {
            $lockedControl = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedControl
                || ! $lockedUser
                || (int) $lockedControl->super_admin_user_id !== (int) $lockedUser->id
                || ! Hash::check($password, $lockedUser->password)) {
                throw new \RuntimeException('Super Admin recovery enrollment state changed.');
            }

            $step = $totp->verify($lockedUser->mfa_secret, $totpCode, $lockedUser->mfa_last_used_step);

            if ($step === null) {
                throw new \RuntimeException('The authenticator code was already used or expired.');
            }

            $versionBefore = (int) $lockedUser->admin_session_version;
            $versionAfter = $versionBefore + 1;

            $lockedUser->forceFill([
                'email_verified_at' => $occurredAt,
                'mfa_last_used_step' => $step,
                'admin_session_version' => $versionAfter,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('platform_control')->where('id', 1)->update([
                'super_admin_recovery_key_hash' => Hash::make($recoveryKey),
                'super_admin_recovery_key_rotated_at' => $occurredAt,
                'super_admin_recovery_email' => $recoveryEmail,
                'super_admin_recovery_email_verified_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            $notice = new SuperAdminRecoveryNotice($occurredAt->toDayDateTimeString());
            Notification::route('mail', $lockedUser->email)->notify($notice);
            Notification::route('mail', $recoveryEmail)->notify($notice);

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $lockedUser->id,
                'event_type' => 'recovery_credentials_enrolled',
                'reason' => 'Existing deployment Super Admin enrolled recovery credentials using password and current MFA',
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => ['verified_notification_addresses' => 2, 'source' => 'trusted_cli'],
                'occurred_at' => $occurredAt,
            ]);

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $lockedUser->id,
                'event_type' => 'notification_sent',
                'reason' => null,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => ['notification_addresses_sent' => 2],
                'occurred_at' => $occurredAt,
            ]);
        });
    } catch (Throwable $exception) {
        report($exception);
        $this->error('Recovery enrollment was not committed. Verify database and SMTP availability, then retry.');

        return 1;
    }

    $this->line('Super Admin recovery key (store offline; shown once): '.$recoveryKey);
    $this->info('Recovery credentials enrolled. All prior administrator sessions were invalidated.');

    return 0;
})->purpose('Enroll verified recovery contacts and a one-time offline key for an existing Super Admin');

Artisan::command('talyvane:super-admin:recover', function () {
    $control = DB::table('platform_control')->where('id', 1)->first();
    $user = $control?->super_admin_user_id ? User::query()->find($control->super_admin_user_id) : null;

    if (! $user || ! $user->is_active || $user->role !== 'super_admin') {
        $this->error('No active designated deployment Super Admin is available for recovery.');

        return 1;
    }

    if (! $user->email_verified_at
        || blank($control->super_admin_recovery_email)
        || ! $control->super_admin_recovery_email_verified_at
        || blank($control->super_admin_recovery_key_hash)
        || hash_equals(strtolower($user->email), strtolower($control->super_admin_recovery_email))) {
        $this->error('Super Admin recovery is unavailable until both notification addresses are verified.');

        return 1;
    }

    if (config('mail.default') !== 'smtp' || blank(config('mail.mailers.smtp.host'))) {
        $this->error('Super Admin recovery requires configured SMTP delivery so both verified addresses can be notified.');

        return 1;
    }

    $password = (string) $this->secret('Current Super Admin password');
    $recoveryKey = (string) $this->secret('Offline deployment recovery key');

    if (! Hash::check($password, $user->password) || ! Hash::check($recoveryKey, $control->super_admin_recovery_key_hash)) {
        SuperAdminRecoveryEvent::query()->create([
            'super_admin_user_id' => $user->id,
            'event_type' => 'recovery_rejected',
            'reason' => 'Credential verification failed',
            'session_version_before' => (int) $user->admin_session_version,
            'session_version_after' => (int) $user->admin_session_version,
            'metadata' => ['source' => 'trusted_cli'],
            'occurred_at' => now(),
        ]);

        $this->error('The supplied recovery credentials could not be verified.');

        return 1;
    }

    $reason = trim((string) $this->ask('Reason for recovery (minimum 20 characters)'));

    if (strlen($reason) < 20 || strlen($reason) > 500) {
        $this->error('A recovery reason between 20 and 500 characters is required.');

        return 1;
    }

    $newRecoveryKey = Str::random(64);
    $occurredAt = now();

    try {
        DB::transaction(function () use ($user, $password, $recoveryKey, $newRecoveryKey, $reason, $occurredAt): void {
            $lockedControl = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if (! $lockedControl
                || ! $lockedUser
                || (int) $lockedControl->super_admin_user_id !== (int) $lockedUser->id
                || ! Hash::check($password, $lockedUser->password)
                || ! Hash::check($recoveryKey, (string) $lockedControl->super_admin_recovery_key_hash)) {
                throw new \RuntimeException('Recovery state changed or credentials are no longer valid.');
            }

            $versionBefore = (int) $lockedUser->admin_session_version;
            $versionAfter = $versionBefore + 1;

            $lockedUser->forceFill([
                'mfa_secret' => null,
                'mfa_enabled_at' => null,
                'mfa_recovery_codes' => null,
                'mfa_last_used_step' => null,
                'admin_session_version' => $versionAfter,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('platform_control')->where('id', 1)->update([
                'super_admin_recovery_key_hash' => Hash::make($newRecoveryKey),
                'super_admin_recovery_key_rotated_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            $notice = new SuperAdminRecoveryNotice($occurredAt->toDayDateTimeString());
            Notification::route('mail', $lockedUser->email)->notify($notice);
            Notification::route('mail', $lockedControl->super_admin_recovery_email)->notify($notice);

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $lockedUser->id,
                'event_type' => 'mfa_recovered',
                'reason' => $reason,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => ['notification_addresses_sent' => 2, 'source' => 'trusted_cli'],
                'occurred_at' => $occurredAt,
            ]);

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $lockedUser->id,
                'event_type' => 'notification_sent',
                'reason' => null,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => ['notification_addresses_sent' => 2],
                'occurred_at' => $occurredAt,
            ]);
        });
    } catch (Throwable $exception) {
        report($exception);
        $this->error('Recovery was not committed. Verify database and SMTP availability, then retry.');

        return 1;
    }

    $this->line('Super Admin recovery key (store offline; shown once): '.$newRecoveryKey);
    $this->info('Super Admin MFA reset and all prior administrator sessions invalidated.');

    return 0;
})->purpose('Recover the designated Super Admin using the password and one-time offline recovery key');

Artisan::command('talyvane:admin:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('A valid email address is required.');

        return;
    }

    if (User::query()->where('email', $email)->exists()) {
        $this->error('An account with that email already exists.');

        return;
    }

    $name = trim((string) $this->ask('Administrator name'));
    $password = (string) $this->secret('Password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm password');

    if ($name === '' || strlen($password) < 14 || ! preg_match('/[A-Za-z]/', $password) || ! preg_match('/[0-9]/', $password) || ! hash_equals($password, $confirmation)) {
        $this->error('Invalid name, password length, or password confirmation.');

        return;
    }

    User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => 'owner',
        'is_active' => true,
    ]);

    $this->info('Initial platform owner created. Store the credentials securely and enable MFA before remote administration.');
})->purpose('Create the first platform owner from a trusted command line');

Artisan::command('talyvane:companion:create {email}', function () {
    $email = strtolower(trim((string) $this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || User::query()->where('email', $email)->exists()) {
        $this->error('A valid, unused email address is required.');

        return;
    }

    $name = trim((string) $this->ask('Companion account name'));
    $password = (string) $this->secret('Temporary password (minimum 14 characters)');
    $confirmation = (string) $this->secret('Confirm temporary password');

    if ($name === '' || strlen($password) < 14 || ! hash_equals($password, $confirmation)) {
        $this->error('Invalid name, password length, or password confirmation.');

        return;
    }

    $user = User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => 'companion',
        'is_active' => true,
    ]);

    event(new Registered($user));

    $this->info('Companion account created. Deliver the temporary password through a separate secure channel. The companion must verify their email before using the portal.');
})->purpose('Provision a companion account from a trusted command line');
