<?php

namespace App\Http\Middleware;

use App\Services\FeatureRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! app(FeatureRegistry::class)->isEnabled($feature)) {
            abort(404);
        }

        return $next($request);
    }
}
