<?php

namespace App\Http\Controllers\Admin;

use App\Models\SuperAdminRecoveryEvent;
use App\Models\User;
use App\Notifications\SuperAdminEmailVerificationCode;
use App\Services\RecoveryCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

final class AdminInitialPasswordRecoveryController
{
    public function create(): View
    {
        return view('admin.auth.first-login-recover');
    }

    public function send(Request $request, RecoveryCodeGenerator $codes): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'recovery_email' => ['required', 'email', 'different:email', 'max:254'],
        ]);

        $email = strtolower($validated['email']);
        $recoveryEmail = strtolower($validated['recovery_email']);
        $user = User::query()->where('email', $email)->where('role', 'super_admin')->where('is_active', true)->first();
        $control = DB::table('platform_control')->where('id', 1)->first();

        $eligible = ! hash_equals($email, $recoveryEmail)
            && $user !== null
            && $control !== null
            && (int) $control->super_admin_user_id === (int) $user->id
            && $user->isDesignatedDeploymentSuperAdmin()
            && $user->email_verified_at !== null
            && ! $user->mfa_enabled_at
            && blank($user->mfa_secret)
            && ! blank($control->super_admin_recovery_key_hash)
            && ! blank($control->super_admin_recovery_email_verified_at)
            && hash_equals(strtolower((string) $control->super_admin_recovery_email), $recoveryEmail)
            && ! blank($control->installation_completed_at)
            && ! blank($control->installation_lock_hash)
            && config('mail.default') === 'smtp'
            && filled(config('mail.mailers.smtp.host'));

        $primaryCode = (string) random_int(100000, 999999);
        $recoveryCode = (string) random_int(100000, 999999);
        $eligibleUserId = null;

        if ($eligible) {
            try {
                $primaryCode = $codes->generate();
                $recoveryCode = $codes->generate();

                Notification::route('mail', $email)
                    ->notify(new SuperAdminEmailVerificationCode($primaryCode, 'primary administrator email'));
                Notification::route('mail', $recoveryEmail)
                    ->notify(new SuperAdminEmailVerificationCode($recoveryCode, 'recovery notification email'));

                $eligibleUserId = $user->id;
            } catch (Throwable) {
                // Keep the response indistinguishable from an ineligible account.
                $eligibleUserId = null;
            }
        }

        $request->session()->put('admin.initial_password_recovery', [
            'user_id' => $eligibleUserId,
            'email' => $email,
            'recovery_email' => $recoveryEmail,
            'primary_code_hash' => Hash::make($primaryCode),
            'recovery_code_hash' => Hash::make($recoveryCode),
            'started_at' => time(),
            'attempts' => 0,
        ]);

        return redirect()->route('admin.first-login.recover.verify')->with(
            'status',
            'If both addresses match the designated Super Admin record, verification codes have been sent to both.'
        );
    }

    public function verifyForm(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get('admin.initial_password_recovery');

        if (! is_array($pending)
            || ! isset($pending['started_at'], $pending['attempts'])
            || time() - (int) $pending['started_at'] > 300
            || (int) $pending['attempts'] >= 5) {
            $request->session()->forget('admin.initial_password_recovery');

            return redirect()->route('admin.first-login.recover');
        }

        return view('admin.auth.first-login-recover-verify');
    }

    public function complete(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'primary_code' => ['required', 'digits:6'],
            'recovery_code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:14', 'max:200', 'confirmed'],
        ]);

        if (preg_match('/[a-z]/', $validated['password']) !== 1
            || preg_match('/[A-Z]/', $validated['password']) !== 1
            || preg_match('/[0-9]/', $validated['password']) !== 1
            || preg_match('/[^A-Za-z0-9]/', $validated['password']) !== 1) {
            throw ValidationException::withMessages([
                'password' => 'Use at least 14 characters with lowercase, uppercase, number and symbol characters.',
            ]);
        }

        $pending = $request->session()->get('admin.initial_password_recovery');
        if (! is_array($pending)
            || ! isset($pending['started_at'], $pending['attempts'], $pending['primary_code_hash'], $pending['recovery_code_hash'])
            || time() - (int) $pending['started_at'] > 300
            || (int) $pending['attempts'] >= 5) {
            $request->session()->forget('admin.initial_password_recovery');

            throw ValidationException::withMessages(['primary_code' => 'The recovery verification session expired. Start again.']);
        }

        if (! Hash::check($validated['primary_code'], $pending['primary_code_hash'])
            || ! Hash::check($validated['recovery_code'], $pending['recovery_code_hash'])
            || ! is_numeric($pending['user_id'] ?? null)) {
            $pending['attempts'] = (int) $pending['attempts'] + 1;
            $request->session()->put('admin.initial_password_recovery', $pending);

            throw ValidationException::withMessages(['primary_code' => 'The recovery verification could not be completed. Check both codes and try again.']);
        }

        try {
            $user = DB::transaction(function () use ($pending, $validated): User {
                $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();
                $lockedUser = User::query()->lockForUpdate()->find((int) $pending['user_id']);

                if (! $control
                    || ! $lockedUser
                    || (int) $control->super_admin_user_id !== (int) $lockedUser->id
                    || $lockedUser->role !== 'super_admin'
                    || ! $lockedUser->is_active
                    || ! $lockedUser->isDesignatedDeploymentSuperAdmin()
                    || ! $lockedUser->email_verified_at
                    || $lockedUser->mfa_enabled_at
                    || filled($lockedUser->mfa_secret)
                    || blank($control->super_admin_recovery_key_hash)
                    || blank($control->super_admin_recovery_email_verified_at)
                    || ! hash_equals(strtolower((string) $lockedUser->email), $pending['email'])
                    || ! hash_equals(strtolower((string) $control->super_admin_recovery_email), $pending['recovery_email'])
                    || blank($control->installation_completed_at)
                    || blank($control->installation_lock_hash)) {
                    throw new \RuntimeException('The initial Super Admin recovery state is no longer eligible.');
                }

                $versionBefore = (int) $lockedUser->admin_session_version;
                $versionAfter = $versionBefore + 1;
                $occurredAt = now();

                $lockedUser->forceFill([
                    'password' => $validated['password'],
                    'must_change_password_at' => null,
                    'admin_session_version' => $versionAfter,
                    'remember_token' => Str::random(60),
                ])->save();

                SuperAdminRecoveryEvent::query()->create([
                    'super_admin_user_id' => $lockedUser->id,
                    'event_type' => 'initial_password_recovered',
                    'reason' => 'Initial Super Admin password reset after verification of both registered email addresses',
                    'session_version_before' => $versionBefore,
                    'session_version_after' => $versionAfter,
                    'metadata' => ['verified_notification_addresses' => 2, 'source' => 'web_initial_password_recovery'],
                    'occurred_at' => $occurredAt,
                ]);

                return $lockedUser;
            });
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'primary_code' => 'The recovery verification could not be completed. Start again or contact the deployment administrator.',
            ]);
        }

        $request->session()->forget('admin.initial_password_recovery');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('admin_session_version', (int) $user->admin_session_version);

        return redirect()->route('admin.mfa.setup');
    }
}
