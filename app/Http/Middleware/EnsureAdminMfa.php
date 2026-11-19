<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, ['owner', 'admin'], true)) {
            abort(403);
        }

        if (! $user->mfa_enabled_at || ! $user->mfa_secret) {
            return redirect()->route('admin.mfa.setup');
        }

        return $next($request);
    }
}
