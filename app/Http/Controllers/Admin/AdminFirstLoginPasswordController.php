<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AdminFirstLoginPasswordController
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.first-login-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
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

        $pending = $this->pendingUser($request);
        if (! $pending) {
            return redirect()->route('admin.login');
        }

        $user = DB::transaction(function () use ($pending, $validated): User {
            $locked = User::query()->lockForUpdate()->find($pending->id);

            if (! $locked
                || $locked->role !== 'super_admin'
                || ! $locked->is_active
                || ! $locked->must_change_password_at
                || (int) $locked->admin_session_version !== (int) $pending->admin_session_version) {
                throw ValidationException::withMessages(['password' => 'The first-login password-change session is no longer valid. Sign in again.']);
            }

            $locked->forceFill([
                'password' => $validated['password'],
                'must_change_password_at' => null,
                'admin_session_version' => (int) $locked->admin_session_version + 1,
                'remember_token' => Str::random(60),
            ])->save();

            return $locked;
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user->mfa_enabled_at && $user->mfa_secret) {
            $request->session()->put('pending_admin_mfa_user_id', $user->id);
            $request->session()->put('pending_admin_mfa_session_version', (int) $user->admin_session_version);
            $request->session()->put('pending_admin_mfa_remember', false);

            return redirect()->route('admin.mfa.challenge');
        }

        Auth::login($user);
        $request->session()->put('admin_session_version', (int) $user->admin_session_version);

        return redirect()->route('admin.mfa.setup');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('pending_admin_password_change_user_id');
        $version = filter_var($request->session()->get('pending_admin_password_change_session_version'), FILTER_VALIDATE_INT);

        $user = $id
            ? User::query()->whereKey($id)->where('role', 'super_admin')->where('is_active', true)->whereNotNull('must_change_password_at')->first()
            : null;

        if (! $user || $version === false || $version !== (int) $user->admin_session_version || ! $user->isDesignatedDeploymentSuperAdmin()) {
            $request->session()->forget(['pending_admin_password_change_user_id', 'pending_admin_password_change_session_version']);

            return null;
        }

        return $user;
    }
}
