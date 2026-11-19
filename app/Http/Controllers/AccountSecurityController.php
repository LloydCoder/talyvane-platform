<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Str;

class AccountSecurityController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(14)->letters()->numbers()],
        ]);

        $user = $request->user();
        $attributes = ['password' => $validated['password']];

        if ($user->isPlatformAdministrator()) {
            $attributes['admin_session_version'] = (int) $user->admin_session_version + 1;
            $attributes['remember_token'] = Str::random(60);
        }

        $user->forceFill($attributes)->save();

        $request->session()->regenerate();
        if ($user->isPlatformAdministrator()) {
            $request->session()->put('admin_session_version', (int) $user->admin_session_version);
        }

        $destination = match ($request->user()->role) {
            'owner', 'admin' => route('admin.dashboard'),
            'companion' => route('companion-portal.dashboard'),
            default => route('portal.dashboard'),
        };

        return redirect($destination)->with('status', 'Your password has been changed.');
    }
}
