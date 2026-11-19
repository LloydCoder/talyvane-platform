<?php

namespace App\Console\Commands;

use App\Models\SuperAdminRecoveryGuardian;
use App\Models\User;
use App\Services\SuperAdminBreakGlassService;
use App\Services\TotpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class ClearSuperAdminBreakGlass extends Command
{
    protected $signature = 'talyvane:super-admin:clear-break-glass';

    protected $description = 'Remove assigned break-glass guardians using current Super Admin password and MFA';

    public function handle(SuperAdminBreakGlassService $breakGlass, TotpService $totp): int
    {
        $control = DB::table('platform_control')->where('id', 1)->first();
        $superAdmin = $control?->super_admin_user_id ? User::query()->find($control->super_admin_user_id) : null;

        if (! $superAdmin || $superAdmin->role !== 'super_admin' || ! $superAdmin->is_active
            || ! $superAdmin->mfa_enabled_at || ! $superAdmin->mfa_secret) {
            $this->error('Clearing break-glass guardians requires the active designated Super Admin with MFA.');

            return self::FAILURE;
        }

        if (config('mail.default') !== 'smtp' || blank(config('mail.mailers.smtp.host'))) {
            $this->error('Clearing break-glass guardians requires configured SMTP delivery for security notifications.');

            return self::FAILURE;
        }

        if (! SuperAdminRecoveryGuardian::query()->exists()) {
            $this->error('No break-glass guardian assignment is configured.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Current Super Admin password');
        $totpCode = (string) $this->secret('Current authenticator code');

        if (! Hash::check($password, $superAdmin->password)
            || $totp->verify($superAdmin->mfa_secret, $totpCode, $superAdmin->mfa_last_used_step) === null) {
            $this->error('The supplied Super Admin credentials could not be verified.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->ask('Reason for clearing break-glass guardians (minimum 20 characters)'));

        if (strlen($reason) < 20 || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
            $this->error('A reason between 20 and 500 characters without control characters is required.');

            return self::FAILURE;
        }

        try {
            $breakGlass->clear($superAdmin->id, $password, $totpCode, $reason);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Break-glass guardian assignments were not cleared. Verify the database and retry.');

            return self::FAILURE;
        }

        $this->info('Break-glass guardian assignments cleared. The recovery path is unavailable until two new Owners are configured.');

        return self::SUCCESS;
    }
}
