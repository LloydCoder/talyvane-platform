<?php

namespace App\Services;

use App\Models\SuperAdminRecoveryEvent;
use App\Models\SuperAdminRecoveryGuardian;
use App\Models\User;
use App\Notifications\SuperAdminBreakGlassNotice;
use App\Notifications\SuperAdminRecoveryNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class SuperAdminBreakGlassService
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    /**
     * @param array<int, array{user_id:int,email:string,password:string,totp_code:string}> $guardians
     */
    public function configure(
        int $superAdminId,
        string $superAdminPassword,
        string $superAdminTotp,
        array $guardians,
        string $reason,
        int $verificationStartedAt,
    ): void {
        if (count($guardians) !== 2 || count(array_unique(array_column($guardians, 'user_id'))) !== 2) {
            throw new RuntimeException('Exactly two distinct recovery guardians are required.');
        }

        DB::transaction(function () use ($superAdminId, $superAdminPassword, $superAdminTotp, $guardians, $reason, $verificationStartedAt): void {
            if (time() - $verificationStartedAt > 300) {
                throw new RuntimeException('Guardian email verification expired. Restart break-glass configuration.');
            }

            $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();
            $superAdmin = User::query()->lockForUpdate()->find($superAdminId);

            if (! $control
                || ! $superAdmin
                || (int) $control->super_admin_user_id !== $superAdminId
                || $superAdmin->role !== 'super_admin'
                || ! $superAdmin->is_active
                || ! $superAdmin->email_verified_at
                || ! $superAdmin->mfa_enabled_at
                || ! $superAdmin->mfa_secret
                || blank($control->super_admin_recovery_key_hash)
                || blank($control->super_admin_recovery_email_verified_at)
                || blank($control->super_admin_recovery_email)
                || ! Hash::check($superAdminPassword, $superAdmin->password)) {
                throw new RuntimeException('Designated Super Admin recovery enrollment state could not be verified.');
            }

            if (SuperAdminRecoveryGuardian::query()->exists()) {
                throw new RuntimeException('Break-glass guardians are already configured. Clear the existing assignment before replacing it.');
            }

            $superAdminStep = $this->totp->verify($superAdmin->mfa_secret, $superAdminTotp, $superAdmin->mfa_last_used_step);

            if ($superAdminStep === null) {
                throw new RuntimeException('The Super Admin authenticator code is invalid, expired or already used.');
            }

            $guardianIds = array_map(static fn (array $guardian): int => (int) $guardian['user_id'], $guardians);
            sort($guardianIds);
            $lockedUsers = User::query()->whereIn('id', $guardianIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($guardians as $guardian) {
                $user = $lockedUsers->get((int) $guardian['user_id']);

                if (! $user
                    || $user->role !== 'owner'
                    || ! $user->is_active
                    || ! $user->email_verified_at
                    || ! $user->mfa_enabled_at
                    || ! $user->mfa_secret
                    || ! hash_equals(strtolower($user->email), strtolower($guardian['email']))
                    || ! Hash::check($guardian['password'], $user->password)) {
                    throw new RuntimeException('Both recovery guardians must be distinct, active, verified Owners with valid passwords and MFA.');
                }

                $step = $this->totp->verify($user->mfa_secret, $guardian['totp_code'], $user->mfa_last_used_step);

                if ($step === null) {
                    throw new RuntimeException('A recovery guardian authenticator code is invalid, expired or already used.');
                }

                $user->forceFill(['mfa_last_used_step' => $step])->save();
            }

            $versionBefore = (int) $superAdmin->admin_session_version;
            $versionAfter = $versionBefore + 1;
            $occurredAt = now();

            $superAdmin->forceFill([
                'mfa_last_used_step' => $superAdminStep,
                'admin_session_version' => $versionAfter,
                'remember_token' => Str::random(60),
            ])->save();

            foreach ($guardians as $index => $guardian) {
                SuperAdminRecoveryGuardian::query()->create([
                    'slot' => $index + 1,
                    'user_id' => (int) $guardian['user_id'],
                    'enrolled_at' => $occurredAt,
                ]);
            }

            $notice = new SuperAdminBreakGlassNotice('configured', $occurredAt->toDayDateTimeString());
            foreach (array_unique([
                $superAdmin->email,
                $control->super_admin_recovery_email,
                $guardians[0]['email'],
                $guardians[1]['email'],
            ]) as $address) {
                \Illuminate\Support\Facades\Notification::route('mail', $address)->notify($notice);
            }

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $superAdminId,
                'event_type' => 'break_glass_guardians_configured',
                'reason' => $reason,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => [
                    'guardian_user_ids' => $guardianIds,
                    'guardian_count' => 2,
                    'notification_addresses_sent' => 4,
                    'source' => 'trusted_cli',
                ],
                'occurred_at' => $occurredAt,
            ]);
        });
    }

    /**
     * @param array<int, array{user_id:int,email:string,password:string,totp_code:string}> $guardians
     */
    public function recover(
        array $guardians,
        string $newPassword,
        string $reason,
        int $verificationStartedAt,
    ): string {
        if (count($guardians) !== 2 || count(array_unique(array_column($guardians, 'user_id'))) !== 2) {
            throw new RuntimeException('Exactly two distinct recovery guardians are required.');
        }

        $newRecoveryKey = Str::random(64);
        $guardianIds = array_map(static fn (array $guardian): int => (int) $guardian['user_id'], $guardians);
        sort($guardianIds);
        $occurredAt = now();

        DB::transaction(function () use ($guardians, $guardianIds, $newPassword, $reason, $verificationStartedAt, $newRecoveryKey, $occurredAt): void {
            if (time() - $verificationStartedAt > 300) {
                throw new RuntimeException('Guardian email verification expired. Restart break-glass recovery.');
            }

            $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();
            $superAdmin = $control?->super_admin_user_id
                ? User::query()->lockForUpdate()->find($control->super_admin_user_id)
                : null;

            if (! $control || ! $superAdmin || $superAdmin->role !== 'super_admin' || ! $superAdmin->is_active) {
                throw new RuntimeException('The designated Super Admin is unavailable for break-glass recovery.');
            }

            $assignments = DB::table('super_admin_recovery_guardians')->orderBy('slot')->lockForUpdate()->get();

            if ($assignments->count() !== 2 || $assignments->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all() !== $guardianIds) {
                throw new RuntimeException('The configured two-owner recovery assignment changed. Reconfigure break-glass before retrying.');
            }

            $lockedUsers = User::query()->whereIn('id', $guardianIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($guardians as $guardian) {
                $user = $lockedUsers->get((int) $guardian['user_id']);

                if (! $user
                    || $user->role !== 'owner'
                    || ! $user->is_active
                    || ! $user->email_verified_at
                    || ! $user->mfa_enabled_at
                    || ! $user->mfa_secret
                    || ! hash_equals(strtolower($user->email), strtolower($guardian['email']))
                    || ! Hash::check($guardian['password'], $user->password)) {
                    throw new RuntimeException('Both configured recovery guardians must authenticate independently.');
                }

                $step = $this->totp->verify($user->mfa_secret, $guardian['totp_code'], $user->mfa_last_used_step);

                if ($step === null) {
                    throw new RuntimeException('A recovery guardian authenticator code is invalid, expired or already used.');
                }

                $user->forceFill(['mfa_last_used_step' => $step])->save();
                DB::table('super_admin_recovery_guardians')
                    ->where('user_id', $user->id)
                    ->update(['last_used_at' => $occurredAt, 'updated_at' => $occurredAt]);
            }

            $versionBefore = (int) $superAdmin->admin_session_version;
            $versionAfter = $versionBefore + 1;

            $superAdmin->forceFill([
                'password' => $newPassword,
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
            $addresses = array_unique([
                $superAdmin->email,
                $control->super_admin_recovery_email,
                $guardians[0]['email'],
                $guardians[1]['email'],
            ]);

            foreach ($addresses as $address) {
                \Illuminate\Support\Facades\Notification::route('mail', $address)->notify($notice);
            }

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $superAdmin->id,
                'event_type' => 'break_glass_completed',
                'reason' => $reason,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => [
                    'guardian_user_ids' => $guardianIds,
                    'notification_addresses_sent' => count($addresses),
                    'source' => 'trusted_cli',
                ],
                'occurred_at' => $occurredAt,
            ]);

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $superAdmin->id,
                'event_type' => 'notification_sent',
                'reason' => null,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => [
                    'notification_addresses_sent' => count($addresses),
                    'source' => 'trusted_cli',
                ],
                'occurred_at' => $occurredAt,
            ]);
        });

        return $newRecoveryKey;
    }

    public function clear(
        int $superAdminId,
        string $password,
        string $totpCode,
        string $reason,
    ): void {
        DB::transaction(function () use ($superAdminId, $password, $totpCode, $reason): void {
            $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();
            $superAdmin = User::query()->lockForUpdate()->find($superAdminId);

            if (! $control
                || ! $superAdmin
                || (int) $control->super_admin_user_id !== $superAdminId
                || $superAdmin->role !== 'super_admin'
                || ! $superAdmin->is_active
                || ! $superAdmin->mfa_enabled_at
                || ! $superAdmin->mfa_secret
                || ! Hash::check($password, $superAdmin->password)) {
                throw new RuntimeException('The current Super Admin credentials could not be verified.');
            }

            $step = $this->totp->verify($superAdmin->mfa_secret, $totpCode, $superAdmin->mfa_last_used_step);

            if ($step === null) {
                throw new RuntimeException('The current Super Admin authenticator code is invalid or already used.');
            }

            $guardians = SuperAdminRecoveryGuardian::query()->with('user')->orderBy('slot')->get();
            $addresses = array_unique(array_filter([
                $superAdmin->email,
                $control->super_admin_recovery_email,
                ...$guardians->pluck('user.email')->all(),
            ]));

            $versionBefore = (int) $superAdmin->admin_session_version;
            $versionAfter = $versionBefore + 1;

            DB::table('super_admin_recovery_guardians')->delete();

            $superAdmin->forceFill([
                'mfa_last_used_step' => $step,
                'admin_session_version' => $versionAfter,
                'remember_token' => Str::random(60),
            ])->save();

            $notice = new SuperAdminBreakGlassNotice('cleared', now()->toDayDateTimeString());
            foreach ($addresses as $address) {
                \Illuminate\Support\Facades\Notification::route('mail', $address)->notify($notice);
            }

            SuperAdminRecoveryEvent::query()->create([
                'super_admin_user_id' => $superAdminId,
                'event_type' => 'break_glass_guardians_cleared',
                'reason' => $reason,
                'session_version_before' => $versionBefore,
                'session_version_after' => $versionAfter,
                'metadata' => ['source' => 'trusted_cli'],
                'occurred_at' => now(),
            ]);
        });
    }
}
