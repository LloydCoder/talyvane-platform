<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminLoginController
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $email = strtolower($credentials['email']);
        $key = Str::transliterate(Str::lower($email).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Please try again shortly.',
            ]);
        }

        $user = User::query()
            ->where('email', $email)
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'admin'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'The supplied credentials could not be verified.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        if ($user->mfa_enabled_at && $user->mfa_secret) {
            $request->session()->put('pending_admin_mfa_user_id', $user->id);
            $request->session()->put('pending_admin_mfa_remember', $request->boolean('remember'));

            return redirect()->route('admin.mfa.challenge');
        }

        Auth::login($user, $request->boolean('remember'));

        if (! $user->mfa_enabled_at || ! $user->mfa_secret) {
            return redirect()->route('admin.mfa.setup');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
