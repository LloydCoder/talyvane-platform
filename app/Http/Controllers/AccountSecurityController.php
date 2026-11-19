<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class AccountSecurityController extends Controller
{
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(14)->letters()->numbers()],
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
        ])->save();

        $request->session()->regenerate();

        $destination = match ($request->user()->role) {
            'owner', 'admin' => route('admin.dashboard'),
            'companion' => route('companion-portal.dashboard'),
            default => route('portal.dashboard'),
        };

        return redirect($destination)->with('status', 'Your password has been changed.');
    }
}
