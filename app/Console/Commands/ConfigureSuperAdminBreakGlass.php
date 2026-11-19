<?php

namespace App\Console\Commands;

use App\Models\SuperAdminRecoveryEvent;
use App\Models\SuperAdminRecoveryGuardian;
use App\Models\User;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Services\RecoveryCodeGenerator;
use App\Services\SuperAdminBreakGlassService;
use App\Services\TotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ConfigureSuperAdminBreakGlass extends Command
{
    protected $signature = 'talyvane:super-admin:configure-break-glass';

    protected $description = 'Configure two distinct Owner custodians for deployment Super Admin break-glass recovery';

    public function handle(
        SuperAdminBreakGlassService $breakGlass,
        RecoveryCodeGenerator $codes,
        TotpService $totp,
    ): int {
        $control = DB::table('platform_control')->where('id', 1)->first();
        $superAdmin = $control?->super_admin_user_id ? User::query()->find($control->super_admin_user_id) : null;

        if (! $superAdmin
            || $superAdmin->role !== 'super_admin'
            || ! $superAdmin->is_active
            || ! $superAdmin->email_verified_at
            || ! $superAdmin->mfa_enabled_at
            || ! $superAdmin->mfa_secret
            || blank($control->super_admin_recovery_email)
            || ! $control->super_admin_recovery_email_verified_at
            || blank($control->super_admin_recovery_key_hash)) {
            $this->error('Break-glass setup requires the active designated Super Admin with enrolled MFA and verified recovery contacts.');

            return self::FAILURE;
        }

        if (SuperAdminRecoveryGuardian::query()->exists()) {
            $this->error('Break-glass guardians are already configured. Clear the existing assignment before replacing it.');

            return self::FAILURE;
        }

        if (config('mail.default') !== 'smtp' || blank(config('mail.mailers.smtp.host'))) {
            $this->error('Configure SMTP delivery before setting up break-glass recovery.');

            return self::FAILURE;
        }

        $superAdminPassword = (string) $this->secret('Current Super Admin password');
        $superAdminCode = (string) $this->secret('Current authenticator code');

        if (! Hash::check($superAdminPassword, $superAdmin->password)
            || $totp->verify($superAdmin->mfa_secret, $superAdminCode, $superAdmin->mfa_last_used_step) === null) {
            $this->recordRejected($superAdmin->id, 'Break-glass setup credentials could not be verified.');

            $this->error('The supplied Super Admin credentials could not be verified.');

            return self::FAILURE;
        }

        $emails = [
            strtolower(trim((string) $this->ask('Recovery guardian 1 email'))),
            strtolower(trim((string) $this->ask('Recovery guardian 2 email'))),
        ];

        if (! filter_var($emails[0], FILTER_VALIDATE_EMAIL)
            || ! filter_var($emails[1], FILTER_VALIDATE_EMAIL)
            || hash_equals($emails[0], $emails[1])
            || hash_equals(strtolower($superAdmin->email), $emails[0])
            || hash_equals(strtolower($superAdmin->email), $emails[1])
            || hash_equals(strtolower($control->super_admin_recovery_email), $emails[0])
            || hash_equals(strtolower($control->super_admin_recovery_email), $emails[1])) {
            $this->error('Two distinct, verified Owner addresses separate from both Super Admin notification addresses are required.');

            return self::FAILURE;
        }

        $guardians = [];
        foreach ($emails as $index => $email) {
            $guardian = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if (! $guardian
                || $guardian->role !== 'owner'
                || ! $guardian->is_active
                || ! $guardian->email_verified_at
                || ! $guardian->mfa_enabled_at
                || ! $guardian->mfa_secret) {
                $this->error('Both recovery guardians must be existing, active, email-verified Owners with enrolled MFA.');

                return self::FAILURE;
            }

            $password = (string) $this->secret('Guardian '.($index + 1).' password');
            $totpCode = (string) $this->secret('Guardian '.($index + 1).' authenticator code');

            if (! Hash::check($password, $guardian->password)
                || $totp->verify($guardian->mfa_secret, $totpCode, $guardian->mfa_last_used_step) === null) {
                $this->recordRejected($superAdmin->id, 'Break-glass guardian credentials could not be verified.');

                $this->error('The supplied recovery guardian credentials could not be verified.');

                return self::FAILURE;
            }

            $guardians[] = [
                'user_id' => $guardian->id,
                'email' => $guardian->email,
                'password' => $password,
                'totp_code' => $totpCode,
            ];
        }

        $verificationCodes = [$codes->generate(), $codes->generate()];
        $verificationStartedAt = time();

        try {
            foreach ($emails as $index => $email) {
                Notification::route('mail', $email)->notify(
                    new SuperAdminEmailVerificationCode($verificationCodes[$index], 'break-glass recovery guardian address'),
                );
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Guardian email verification could not be delivered. No break-glass assignment was created.');

            return self::FAILURE;
        }

        $enteredCodes = [
            (string) $this->secret('Verification code sent to '.$emails[0]),
            (string) $this->secret('Verification code sent to '.$emails[1]),
        ];

        if (time() - $verificationStartedAt > 300
            || ! hash_equals($verificationCodes[0], $enteredCodes[0])
            || ! hash_equals($verificationCodes[1], $enteredCodes[1])) {
            $this->recordRejected($superAdmin->id, 'Break-glass guardian email verification failed or expired.');

            $this->error('Guardian email verification failed or expired. No break-glass assignment was created.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->ask('Reason for break-glass configuration (minimum 20 characters)'));

        if (strlen($reason) < 20 || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            $this->error('A reason between 20 and 500 characters without control characters is required.');

            return self::FAILURE;
        }

        try {
            $breakGlass->configure(
                $superAdmin->id,
                $superAdminPassword,
                $superAdminCode,
                $guardians,
                $reason,
                $verificationStartedAt,
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->recordRejected($superAdmin->id, 'Break-glass guardian configuration did not commit.');

            $this->error('Break-glass configuration was not committed. Verify the guardian accounts, database and SMTP delivery, then retry.');

            return self::FAILURE;
        }

        $this->info('Two-owner Super Admin break-glass recovery is configured. All prior administrator sessions were invalidated.');

        return self::SUCCESS;
    }

    private function recordRejected(int $superAdminId, string $reason): void
    {
        SuperAdminRecoveryEvent::query()->create([
            'super_admin_user_id' => $superAdminId,
            'event_type' => 'break_glass_setup_rejected',
            'reason' => $reason,
            'metadata' => ['source' => 'trusted_cli'],
            'occurred_at' => now(),
        ]);
    }
}
