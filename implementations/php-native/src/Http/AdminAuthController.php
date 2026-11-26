<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

/**
 * Browser-facing administrator identity lifecycle.
 * No privileged workspace is exposed until password and MFA gates are complete.
 */
final class AdminAuthController
{
    public function __construct(
        private AuthenticationService $authentication,
        private PasswordChangeService $passwords,
        private ContentManagementService $content,
        private ViewRenderer $views
    ) {}

    public function loginForm(Request $request): Response
    {
        SessionManager::start();
        return $this->page('login');
    }

    public function login(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('login', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }

        $email = $this->field($request, 'email');
        $password = $this->field($request, 'password');
        $decision = $this->authentication->verifyPassword($email, $password, 'admin', $request->ipAddress);

        return match ($decision['status']) {
            'first_login_required' => $this->beginPasswordReplacement($decision),
            'mfa_setup_required' => $this->beginMfaSetup($decision),
            'mfa_required' => $this->beginMfaChallenge($decision),
            'rate_limited' => $this->page('login', ['email'=>$email,'error'=>'Too many attempts. Wait before trying again.'], 429),
            default => $this->page('login', ['email'=>$email,'error'=>'The email or password could not be verified.'], 422),
        };
    }

    public function mfaForm(Request $request): Response
    {
        SessionManager::start();
        if (!$this->validPending('_admin_mfa_pending')) {
            unset($_SESSION['_admin_mfa_pending']);
            return Response::redirect('/admin/login');
        }
        return $this->page('mfa');
    }

    public function mfa(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('mfa', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        if (!$this->validPending('_admin_mfa_pending')) {
            unset($_SESSION['_admin_mfa_pending']);
            return Response::redirect('/admin/login');
        }

        $recoveryCode = strtoupper(trim($this->field($request, 'recovery_code')));
        $verified = $recoveryCode !== ''
            ? $this->authentication->completeAdminRecoveryCode($recoveryCode, $request->requestId)
            : $this->authentication->completeAdminMfa($this->field($request, 'code'));

        return $verified
            ? Response::redirect('/admin')
            : $this->page('mfa', ['error'=>'The code was not accepted. Check it and try again.'], 422);
    }

    public function firstLoginForm(Request $request): Response
    {
        SessionManager::start();
        if (!$this->validPending('_admin_password_change_pending')) {
            unset($_SESSION['_admin_password_change_pending']);
            return Response::redirect('/admin/login');
        }
        return $this->page('first_login');
    }

    public function firstLogin(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('first_login', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        if (!$this->validPending('_admin_password_change_pending')) {
            unset($_SESSION['_admin_password_change_pending']);
            return Response::redirect('/admin/login');
        }

        $newPassword = $this->field($request, 'new_password');
        if (!hash_equals($newPassword, $this->field($request, 'confirm_password'))) {
            return $this->page('first_login', ['error'=>'The new password entries do not match.'], 422);
        }

        try {
            $secret = $this->authentication->completeFirstLoginPasswordChange(
                $this->passwords,
                $this->field($request, 'current_password'),
                $newPassword,
                $request->requestId
            );
            $_SESSION['_admin_mfa_setup_secret'] = $secret;
            return Response::redirect('/admin/mfa/setup');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page('first_login', ['error'=>$error->getMessage()], 422);
        }
    }

    public function mfaSetupForm(Request $request): Response
    {
        SessionManager::start();
        if (!$this->validPending('_admin_mfa_setup_pending') || !is_string($_SESSION['_admin_mfa_setup_secret'] ?? null)) {
            return Response::redirect('/admin/login');
        }
        return $this->page('mfa_setup', ['secret'=>$_SESSION['_admin_mfa_setup_secret']]);
    }

    public function mfaSetup(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('mfa_setup', ['secret'=>(string) ($_SESSION['_admin_mfa_setup_secret'] ?? ''),'error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        if (!$this->validPending('_admin_mfa_setup_pending') || !is_string($_SESSION['_admin_mfa_setup_secret'] ?? null)) {
            return Response::redirect('/admin/login');
        }

        try {
            $codes = $this->authentication->completeAdminMfaSetup($this->field($request, 'code'), $request->requestId);
            unset($_SESSION['_admin_mfa_setup_secret']);
            $_SESSION['_admin_recovery_codes_once'] = $codes;
            return Response::redirect('/admin/recovery-codes');
        } catch (DomainException $error) {
            return $this->page('mfa_setup', ['secret'=>(string) $_SESSION['_admin_mfa_setup_secret'],'error'=>$error->getMessage()], 422);
        }
    }

    public function recoveryCodes(Request $request): Response
    {
        SessionManager::start();
        $codes = $_SESSION['_admin_recovery_codes_once'] ?? null;
        if (!is_array($codes) || $codes === []) {
            return Response::redirect('/admin');
        }
        unset($_SESSION['_admin_recovery_codes_once']);
        return $this->page('recovery_codes', ['recoveryCodes'=>$codes]);
    }

    public function dashboard(Request $request): Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) {
            return Response::redirect('/admin/login');
        }
        if (!Authorization::allows($principal, 'dashboard.view') && !($principal->hasRole('finance') && Authorization::allows($principal, 'model_settlements.view', true))) {
            return $this->page('dashboard', ['error'=>'This account does not have permission to open the general dashboard.'], 403);
        }
        return $this->page('dashboard', ['roles'=>$principal->roles]);
    }

    public function logout(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('dashboard', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        $this->authentication->logout();
        return Response::redirect('/admin/login');
    }

    /** @param array<string,mixed> $decision */
    private function beginPasswordReplacement(array $decision): Response
    {
        $this->authentication->beginFirstLoginPasswordChange($decision);
        return Response::redirect('/admin/first-login');
    }

    /** @param array<string,mixed> $decision */
    private function beginMfaSetup(array $decision): Response
    {
        $secret = $this->authentication->beginAdminMfaSetup($decision);
        $_SESSION['_admin_mfa_setup_secret'] = $secret;
        return Response::redirect('/admin/mfa/setup');
    }

    /** @param array<string,mixed> $decision */
    private function beginMfaChallenge(array $decision): Response
    {
        $this->authentication->beginAdminMfaChallenge($decision);
        return Response::redirect('/admin/mfa');
    }

    private function validPending(string $key): bool
    {
        $pending = $_SESSION[$key] ?? null;
        return is_array($pending)
            && isset($pending['user_id'], $pending['session_version'], $pending['expires_at'])
            && is_string($pending['user_id'])
            && is_int($pending['session_version'])
            && is_int($pending['expires_at'])
            && $pending['expires_at'] >= time();
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /** @param array<string,mixed> $data */
    private function page(string $screen, array $data = [], int $status = 200): Response
    {
        $payload = $data + [
            'screen'=>$screen,
            'brandName'=>$this->content->publicBrand()['brand_name'],
            'csrf'=>Csrf::issue(),
            'error'=>'',
            'email'=>'',
            'secret'=>'',
            'recoveryCodes'=>[],
            'roles'=>[],
        ];
        $body = $this->views->render('auth/screen', $payload);
        return new Response($body, $status, [
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache',
            'expires'=>'0',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
