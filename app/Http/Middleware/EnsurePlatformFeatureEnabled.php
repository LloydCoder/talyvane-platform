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
        $features = app(FeatureRegistry::class);

        if (! $features->isEnabled($feature)) {
            abort(404);
        }

        if (! $features->isOperationallyEnabled($feature)) {
            abort(503, 'Feature activation prerequisites are not satisfied.');
        }

        return $next($request);
    }
}
