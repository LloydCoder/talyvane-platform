<?php

namespace App\Http\Middleware;

use App\Install\InstallationLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsureInstallationState
{
    public function handle(Request $request, Closure $next): Response
    {
        $installerRequest = $request->is('install') || $request->is('install/*');

        try {
            $lock = new InstallationLock(base_path(), (string) config('app.key'));
            $lockExists = $lock->exists();
        } catch (Throwable) {
            abort(503, 'The installation state is unsafe. Contact the deployment administrator.');
        }

        if (! config('talyvane.installer.enforce')) {
            if ($lockExists && ! $this->lockMatchesDatabase($lock)) {
                abort(503, 'The installation lock could not be verified. Public application access is disabled.');
            }

            if ($installerRequest && ($lockExists || $this->databaseHasCompletionMarker())) {
                abort(404);
            }

            return $next($request);
        }

        if ($installerRequest) {
            if (! $lockExists) {
                if ($this->databaseHasCompletionMarker()) {
                    abort(503, 'The installation lock is missing or inconsistent. Public installation is disabled.');
                }

                return $next($request);
            }

            if (! $this->lockMatchesDatabase($lock)) {
                abort(503, 'The installation lock could not be verified. Public installation is disabled.');
            }

            if ($request->is('install/success')) {
                return $next($request);
            }

            abort(404);
        }

        if (! $lockExists) {
            if ($this->databaseHasCompletionMarker()) {
                abort(503, 'The installation lock is missing or inconsistent. Public application access is disabled.');
            }

            return redirect()->route('install.index');
        }

        if (! $this->lockMatchesDatabase($lock)) {
            abort(503, 'The installation lock could not be verified. Public application access is disabled.');
        }

        return $next($request);
    }

    private function databaseHasCompletionMarker(): bool
    {
        try {
            $control = DB::table('platform_control')->where('id', 1)->first();

            $controlCompleted = $control !== null && (
                ! empty($control->super_admin_user_id)
                || ! empty($control->installation_id)
                || ! empty($control->installation_lock_hash)
                || ! empty($control->installation_completed_at)
            );

            return $controlCompleted
                || DB::table('users')->where('role', 'super_admin')->exists();
        } catch (Throwable) {
            // A fresh installation has not configured or migrated its database yet.
            return false;
        }
    }

    private function lockMatchesDatabase(InstallationLock $lock): bool
    {
        try {
            $control = DB::table('platform_control')->where('id', 1)->first();

            return $control !== null
                && is_string($control->installation_id)
                && is_string($control->installation_lock_hash)
                && $control->installation_completed_at !== null
                && $lock->verify($control->installation_id, $control->installation_lock_hash);
        } catch (Throwable) {
            return false;
        }
    }
}
