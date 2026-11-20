<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\SuperAdminRecoveryEvent;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminMfaController
{
    public function setup(Request $request, TotpService $totp): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->mfa_enabled_at && $user->mfa_secret) {
            return redirect()->route(match ($user->role) { 'finance' => 'admin.model-settlements.index', 'verifier' => 'admin.adult-eligibility.index', 'safeguarding' => 'admin.safeguarding.cases.index', default => 'admin.dashboard' });
        }

        $secret = $request->session()->get('mfa_setup_secret');
        if (! $secret) {
            $secret = $totp->generateSecret();
            $request->session()->put('mfa_setup_secret', $secret);
        }

        return view('admin.auth.mfa-setup', [
            'secret' => $secret,
            'provisioningUri' => $totp->provisioningUri($secret, $user->email, 'Talyvane'),
        ]);
    }

    public function confirmSetup(Request $request, TotpService $totp): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = (string) $request->session()->get('mfa_setup_secret');

        if ($secret === '') {
            throw ValidationException::withMessages(['code' => 'Start MFA setup again to generate a new secret.']);
        }

        $step = $totp->verify($secret, $validated['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid or expired.']);
        }

        $recoveryCodes = collect(range(1, 10))
            ->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();

        $request->user()->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_last_used_step' => $step,
            'mfa_recovery_codes' => array_map(fn ($code) => Hash::make($code), $recoveryCodes),
        ])->save();

        $request->session()->forget('mfa_setup_secret');
        $request->session()->flash('mfa_recovery_codes', $recoveryCodes);

        return redirect()->route('admin.mfa.recovery-codes');
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('mfa_recovery_codes');
        if (! is_array($codes)) {
            return redirect()->route(match ($request->user()->role) { 'finance' => 'admin.model-settlements.index', 'verifier' => 'admin.adult-eligibility.index', 'safeguarding' => 'admin.safeguarding.cases.index', default => 'admin.dashboard' });
        }

        $recoveryKey = null;

        if ($request->user()->isDesignatedDeploymentSuperAdmin()
            && $request->user()->mfa_enabled_at
            && filled($request->user()->mfa_secret)) {
            $recoveryKey = DB::transaction(function (): ?string {
                $control = DB::table('platform_control')->where('id', 1)->lockForUpdate()->first();

                if (! $control || blank($control->super_admin_recovery_key_hash)) {
                    return null;
                }

                $handoff = $control->super_admin_recovery_key_handoff;
                $expiresAt = $control->super_admin_recovery_key_handoff_expires_at;

                if (blank($handoff) || ! $expiresAt || now()->greaterThan($expiresAt)) {
                    $handoffKey = bin2hex(random_bytes(32));
                    $occurredAt = now();

                    DB::table('platform_control')->where('id', 1)->update([
                        'super_admin_recovery_key_hash' => Hash::make($handoffKey),
                        'super_admin_recovery_key_rotated_at' => $occurredAt,
                        'super_admin_recovery_key_handoff' => Crypt::encryptString($handoffKey),
                        'super_admin_recovery_key_handoff_expires_at' => $occurredAt->copy()->addDay(),
                        'updated_at' => $occurredAt,
                    ]);

                    SuperAdminRecoveryEvent::query()->create([
                        'super_admin_user_id' => auth()->id(),
                        'event_type' => 'bootstrap_recovery_key_issued',
                        'reason' => 'Initial offline recovery key handoff was absent or expired before first MFA completion',
                        'session_version_before' => (int) auth()->user()->admin_session_version,
                        'session_version_after' => (int) auth()->user()->admin_session_version,
                        'metadata' => ['source' => 'post_mfa_recovery_handoff_reissue'],
                        'occurred_at' => $occurredAt,
                    ]);

                    $handoff = Crypt::encryptString($handoffKey);
                }

                try {
                    $key = Crypt::decryptString($handoff);
                } catch (\Throwable) {
                    return null;
                }

                DB::table('platform_control')->where('id', 1)->update([
                    'super_admin_recovery_key_handoff' => null,
                    'super_admin_recovery_key_handoff_expires_at' => null,
                    'updated_at' => now(),
                ]);

                return $key;
            });
        }

        $request->session()->forget('mfa_recovery_codes');

        return view('admin.auth.mfa-recovery', ['codes' => $codes, 'recoveryKey' => $recoveryKey]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $this->pendingAdmin($request)) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.mfa-challenge');
    }

    public function verifyChallenge(Request $request, TotpService $totp): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $this->pendingAdmin($request);
        if (! $user) {
            return redirect()->route('admin.login');
        }

        $key = 'admin-mfa:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many verification attempts. Try again shortly.']);
        }

        $step = $totp->verify($user->mfa_secret, $validated['code'], $user->mfa_last_used_step);
        if ($step === null) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid or already used.']);
        }

        $user->forceFill(['mfa_last_used_step' => $step])->save();
        RateLimiter::clear($key);

        return $this->completeLogin($request, $user);
    }

    public function verifyRecovery(Request $request): RedirectResponse
    {
        $validated = $request->validate(['recovery_code' => ['required', 'string', 'max:32']]);
        $user = $this->pendingAdmin($request);
        if (! $user) {
            return redirect()->route('admin.login');
        }

        $key = 'admin-mfa-recovery:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['recovery_code' => 'Too many recovery attempts. Try again shortly.']);
        }

        $codes = $user->mfa_recovery_codes ?? [];
        foreach ($codes as $index => $hash) {
            if (Hash::check(strtoupper($validated['recovery_code']), $hash)) {
                unset($codes[$index]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                RateLimiter::clear($key);

                return $this->completeLogin($request, $user);
            }
        }

        RateLimiter::hit($key, 60);
        throw ValidationException::withMessages(['recovery_code' => 'The recovery code is invalid or has already been used.']);
    }

    private function pendingAdmin(Request $request): ?User
    {
        $id = $request->session()->get('pending_admin_mfa_user_id');
        if (! $id) {
            return null;
        }

        $user = User::query()
            ->whereKey($id)
            ->where('is_active', true)
            ->whereIn('role', ['super_admin', 'owner', 'admin', 'finance', 'verifier', 'safeguarding'])
            ->whereNotNull('mfa_enabled_at')
            ->first();

        $pendingVersion = filter_var($request->session()->get('pending_admin_mfa_session_version'), FILTER_VALIDATE_INT);

        if (! $user
            || ($user->role === 'super_admin' && ! $user->isDesignatedDeploymentSuperAdmin())
            || $pendingVersion === false
            || $pendingVersion !== (int) $user->admin_session_version) {
            $request->session()->forget(['pending_admin_mfa_user_id', 'pending_admin_mfa_remember', 'pending_admin_mfa_session_version']);

            return null;
        }

        return $user;
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        $remember = (bool) $request->session()->pull('pending_admin_mfa_remember', false);
        $request->session()->forget(['pending_admin_mfa_user_id', 'pending_admin_mfa_session_version']);
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('admin_session_version', (int) $user->admin_session_version);

        $destination = route(match ($user->role) { 'finance' => 'admin.model-settlements.index', 'verifier' => 'admin.adult-eligibility.index', 'safeguarding' => 'admin.safeguarding.cases.index', default => 'admin.dashboard' });

        return in_array($user->role, ['finance', 'verifier', 'safeguarding'], true)
            ? redirect($destination)
            : redirect()->intended($destination);
    }
}
