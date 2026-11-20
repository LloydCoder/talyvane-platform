<?php

namespace App\Http\Controllers\Install;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InstallSuccessController
{
    public function show(Request $request, \App\Install\InstallationDirectoryRemover $remover): Response
    {
        $handoff = $request->session()->pull('install.success.handoff');
        if (! is_string($handoff)) {
            abort(404);
        }

        try {
            $credentials = json_decode(Crypt::decryptString($handoff), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            abort(404);
        }

        if (! is_array($credentials)
            || ! is_string($credentials['password'] ?? null)
            || ! is_int($credentials['expires_at'] ?? null)
            || $credentials['expires_at'] < time()) {
            abort(410);
        }

        $loginPath = route('admin.login', [], false);
        $loginUrl = rtrim((string) config('app.url'), '/').$loginPath;
        $installerRemoved = ($credentials['installer_removed'] ?? false) === true;

        $response = response()->view('install.success', [
            'loginUrl' => $loginUrl,
            'initialPassword' => $credentials['password'],
            'installerRemoved' => $installerRemoved,
        ]);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
