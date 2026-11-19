<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if (! $user->isPlatformAdministrator()) {
            return $next($request);
        }

        $sessionVersion = filter_var($request->session()->get('admin_session_version'), FILTER_VALIDATE_INT);

        if ($sessionVersion === false || $sessionVersion !== (int) $user->admin_session_version) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors([
                'email' => 'Your administrator session expired after a security change. Sign in again.',
            ]);
        }

        return $next($request);
    }
}
