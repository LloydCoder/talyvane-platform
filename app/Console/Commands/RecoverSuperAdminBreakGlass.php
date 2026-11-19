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

class RecoverSuperAdminBreakGlass extends Command
{
    protected $signature = 'talyvane:super-admin:break-glass';

    protected $description = 'Reset the designated Super Admin using two independently authenticated Owner guardians';

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
            || blank($control->super_admin_recovery_email)
            || ! $control->super_admin_recovery_email_verified_at
            || blank($control->super_admin_recovery_key_hash)) {
            $this->error('Break-glass recovery requires the designated Super Admin and verified recovery notification contacts.');

            return self::FAILURE;
        }

        $recentFailures = SuperAdminRecoveryEvent::query()
            ->where('super_admin_user_id', $superAdmin->id)
            ->where('event_type', 'break_glass_rejected')
            ->where('occurred_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentFailures >= 5) {
            $this->error('Break-glass recovery is temporarily locked after five rejected attempts. Wait 15 minutes before retrying.');

            return self::FAILURE;
        }

        if (config('mail.default') !== 'smtp' || blank(config('mail.mailers.smtp.host'))) {
            $this->error('Break-glass recovery requires configured SMTP delivery.');

            return self::FAILURE;
        }

        $assignments = SuperAdminRecoveryGuardian::query()->with('user')->orderBy('slot')->get();

        if ($assignments->count() !== 2
            || (int) $assignments[0]->slot !== 1
            || (int) $assignments[1]->slot !== 2
            || $assignments[0]->user_id === $assignments[1]->user_id) {
            $this->error('Two distinct, configured recovery guardians are required. Use the documented setup command before production.');
            return self::FAILURE;
        }

        $guardians = [];
        $guardianUsers = [];
        foreach ($assignments as $index => $assignment) {
            $guardian = $assignment->user;

            if (! $guardian
                || $guardian->role !== 'owner'
                || ! $guardian->is_active
                || ! $guardian->email_verified_at
                || ! $guardian->mfa_enabled_at
                || ! $guardian->mfa_secret) {
                $this->error('Both configured recovery guardians must remain active, verified Owners with enrolled MFA.');

                return self::FAILURE;
            }

            $this->line('Guardian '.($index + 1).' account: '.$guardian->email);
            $password = (string) $this->secret('Guardian '.($index + 1).' password');
            $totpCode = (string) $this->secret('Guardian '.($index + 1).' authenticator code');

            if (! Hash::check($password, $guardian->password)
                || $totp->verify($guardian->mfa_secret, $totpCode, $guardian->mfa_last_used_step) === null) {
                $this->recordRejected($superAdmin->id, 'Break-glass guardian credential verification failed.');

                $this->error('The supplied recovery guardian credentials could not be verified.');

                return self::FAILURE;
            }

            $guardianUsers[] = $guardian;
            $guardians[] = [
                'user_id' => $guardian->id,
                'email' => $guardian->email,
                'password' => $password,
                'totp_code' => $totpCode,
            ];
        }

        if (hash_equals($guardianUsers[0]->mfa_secret, $guardianUsers[1]->mfa_secret)
            || Hash::check($guardians[0]['password'], $guardianUsers[1]->password)
            || Hash::check($guardians[1]['password'], $guardianUsers[0]->password)) {
            $this->recordRejected($superAdmin->id, 'Break-glass guardian credentials are not independent.');

            $this->error('The two recovery guardians must use independent authenticator secrets and different passwords.');

            return self::FAILURE;
        }

        $verificationCodes = [$codes->generate(), $codes->generate()];
        $verificationStartedAt = time();

        try {
            foreach ($guardians as $index => $guardian) {
                Notification::route('mail', $guardian['email'])->notify(
                    new SuperAdminEmailVerificationCode($verificationCodes[$index], 'two-owner break-glass approval'),
                );
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Guardian verification codes could not be delivered. No recovery was performed.');

            return self::FAILURE;
        }

        $enteredCodes = [
            (string) $this->secret('Verification code sent to '.$guardians[0]['email']),
            (string) $this->secret('Verification code sent to '.$guardians[1]['email']),
        ];

        if (time() - $verificationStartedAt > 300
            || ! hash_equals($verificationCodes[0], $enteredCodes[0])
            || ! hash_equals($verificationCodes[1], $enteredCodes[1])) {
            $this->recordRejected($superAdmin->id, 'Break-glass guardian email verification failed or expired.');

            $this->error('Both guardian email verification codes must be valid within five minutes.');

            return self::FAILURE;
        }

        $newPassword = (string) $this->secret('New Super Admin password (minimum 14 characters)');
        $confirmation = (string) $this->secret('Confirm new Super Admin password');
        $strongPassword = strlen($newPassword) >= 14
            && preg_match('/[a-z]/', $newPassword)
            && preg_match('/[A-Z]/', $newPassword)
            && preg_match('/[0-9]/', $newPassword)
            && preg_match('/[^A-Za-z0-9]/', $newPassword);

        if (! $strongPassword || ! hash_equals($newPassword, $confirmation)) {
            $this->error('A strong 14-character password and matching confirmation are required.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->ask('Reason for break-glass recovery (minimum 20 characters)'));

        if (strlen($reason) < 20 || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            $this->error('A reason between 20 and 500 characters without control characters is required.');

            return self::FAILURE;
        }

        try {
            $newRecoveryKey = $breakGlass->recover($guardians, $newPassword, $reason, $verificationStartedAt);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordRejected($superAdmin->id, 'Break-glass authorization or transaction validation failed.');

            $this->error('Break-glass recovery was not committed. Verify both guardian accounts, database and SMTP delivery, then retry.');

            return self::FAILURE;
        }

        $this->line('Super Admin recovery key (store offline; shown once): '.$newRecoveryKey);
        $this->info('Super Admin password reset, MFA cleared and prior sessions invalidated. Sign in and enroll MFA again.');

        return self::SUCCESS;
    }

    private function recordRejected(int $superAdminId, string $reason): void
    {
        SuperAdminRecoveryEvent::query()->create([
            'super_admin_user_id' => $superAdminId,
            'event_type' => 'break_glass_rejected',
            'reason' => $reason,
            'metadata' => ['source' => 'trusted_cli'],
            'occurred_at' => now(),
        ]);
    }
}
