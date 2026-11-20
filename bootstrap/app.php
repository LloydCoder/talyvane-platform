<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Http\Middleware\EnsureAdminSessionCurrent;
use App\Http\Middleware\EnsurePlatformFeatureEnabled;
use App\Http\Middleware\EnsureInstallationState;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'admin.mfa' => EnsureAdminMfa::class,
            'admin.session-current' => EnsureAdminSessionCurrent::class,
            'feature' => EnsurePlatformFeatureEnabled::class,
        ]);
        $middleware->web(prepend: [EnsureInstallationState::class]);
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('admin') || $request->is('admin/*') ? '/admin/login' : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'secret_value',
            'password',
            'password_confirmation',
            'current_password',
            'db_password',
            'mail_password',
            'installer_token',
            'primary_code',
            'recovery_code',
            'recovery_key',
            'code',
        ]);
    })
    ->create();
