<?php
declare(strict_types=1);

namespace Talyvane\Http;

use PDO;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Identity\AccountRecoveryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Application\Identity\UserRegistrationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** Public client and companion identity journeys; privileged identities are never accepted here. */
final class PortalAuthController
{
    public function __construct(
        private PDO $pdo,
        private AuthenticationService $authentication,
        private ContentManagementService $content,
        private ViewRenderer $views,
        private ?UserRegistrationService $registration,
        private ?EmailVerificationService $verification,
        private ?AccountRecoveryService $recovery
    ) {}

    public function registerForm(Request $request): Response
    {
        SessionManager::start();
        return $this->page('register');
    }

    public function register(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('register', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        if ($this->registration === null) {
            return $this->page('register', ['error'=>'Account registration is temporarily unavailable. Please try again later.'], 503);
        }
        $email = strtolower(trim($this->field($request, 'email')));
        $role = $this->field($request, 'account_type');
        try {
            $this->registration->register($email, $this->field($request, 'password'), $role, $request->ipAddress, $request->requestId);
            return $this->page('verify', ['email'=>$email,'notice'=>'A verification code has been sent if the address can receive mail. It expires in 10 minutes.']);
        } catch (\InvalidArgumentException|\DomainException $error) {
            $status = str_contains(strtolower($error->getMessage()), 'rate limited') ? 429 : 422;
            return $this->page('register', ['email'=>$email,'error'=>$status === 429 ? 'Please wait before trying again.' : 'The account could not be created with those details.'], $status);
        } catch (\Throwable) {
            return $this->page('register', ['email'=>$email,'error'=>'Registration is temporarily unavailable. Please try again later.'], 503);
        }
    }

    public function verifyForm(Request $request): Response
    {
        SessionManager::start();
        return $this->page('verify', ['email'=>$this->queryString($request, 'email')]);
    }

    public function verify(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('verify', ['email'=>$this->field($request, 'email'),'error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        if ($this->verification === null) {
            return $this->page('verify', ['error'=>'Email verification is temporarily unavailable.'], 503);
        }
        $email = strtolower(trim($this->field($request, 'email')));
        $code = trim($this->field($request, 'code'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[0-9]{6}$/D', $code)) {
            return $this->page('verify', ['email'=>$email,'error'=>'Enter a valid email address and six-digit code.'], 422);
        }
        $lookup = $this->pdo->prepare('SELECT id FROM users WHERE email = :email AND status = "pending" AND email_verified_at IS NULL LIMIT 1');
        $lookup->execute(['email'=>$email]);
        $userId = $lookup->fetchColumn();
        if (!is_string($userId)) {
            return $this->page('verify', ['email'=>$email,'error'=>'The verification details could not be accepted.'], 422);
        }
        try {
            if (!$this->verification->verify($userId, $email, 'account_primary', $code)) {
                return $this->page('verify', ['email'=>$email,'error'=>'The verification details could not be accepted.'], 422);
            }
            return $this->page('login', ['email'=>$email,'notice'=>'Your email is verified. You can now sign in.']);
        } catch (\Throwable) {
            return $this->page('verify', ['email'=>$email,'error'=>'The verification details could not be accepted.'], 422);
        }
    }

    public function resendVerification(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('verify', ['email'=>$this->field($request, 'email'),'error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        $email = strtolower(trim($this->field($request, 'email')));
        if ($this->registration !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $lookup = $this->pdo->prepare('SELECT id FROM users WHERE email = :email AND status = "pending" AND email_verified_at IS NULL LIMIT 1');
            $lookup->execute(['email'=>$email]);
            $userId = $lookup->fetchColumn();
            if (is_string($userId)) {
                try { $this->registration->resendPrimaryVerification($userId); } catch (\Throwable) {}
            }
        }
        return $this->page('verify', ['email'=>$email,'notice'=>'If this account is awaiting verification, a new code has been sent.']);
    }

    public function recoveryForm(Request $request): Response
    {
        SessionManager::start();
        return $this->page('recovery_request');
    }

    public function requestRecovery(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('recovery_request', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        if ($this->recovery !== null) {
            try { $this->recovery->request($this->field($request, 'email')); } catch (\Throwable) {}
        }
        return $this->page('recovery_complete', ['email'=>strtolower(trim($this->field($request, 'email'))),'notice'=>'If an active account matches that address, a recovery code has been sent.']);
    }

    public function completeRecovery(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('recovery_complete', ['email'=>$this->field($request, 'email'),'error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        $email = strtolower(trim($this->field($request, 'email')));
        $password = $this->field($request, 'new_password');
        if (!hash_equals($password, $this->field($request, 'confirm_password'))) {
            return $this->page('recovery_complete', ['email'=>$email,'error'=>'The new password entries do not match.'], 422);
        }
        if ($this->recovery === null) {
            return $this->page('recovery_complete', ['email'=>$email,'error'=>'Account recovery is temporarily unavailable.'], 503);
        }
        try {
            if (!$this->recovery->complete($email, $this->field($request, 'code'), $password, $request->requestId)) {
                return $this->page('recovery_complete', ['email'=>$email,'error'=>'The recovery details could not be accepted.'], 422);
            }
            return $this->page('login', ['email'=>$email,'notice'=>'Your password has been reset. Sign in with your new password.']);
        } catch (\Throwable) {
            return $this->page('recovery_complete', ['email'=>$email,'error'=>'The recovery details could not be accepted.'], 422);
        }
    }

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
        $email = strtolower(trim($this->field($request, 'email')));
        $decision = $this->authentication->verifyPassword($email, $this->field($request, 'password'), 'portal', $request->ipAddress);
        if (($decision['status'] ?? '') === 'rate_limited') {
            return $this->page('login', ['email'=>$email,'error'=>'Too many attempts. Wait before trying again.'], 429);
        }
        if (($decision['status'] ?? '') !== 'authenticated') {
            return $this->page('login', ['email'=>$email,'error'=>'The email or password could not be verified. Check that your email is verified.'], 422);
        }
        $roles = $decision['roles'] ?? [];
        if (count(array_intersect($roles, ['client','companion'])) !== 1) {
            return $this->page('login', ['email'=>$email,'error'=>'This account cannot use the public portal.'], 403);
        }
        $this->authentication->establishPortalSession($decision);
        return Response::redirect('/portal');
    }

    public function dashboard(Request $request): Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if (!$principal instanceof Principal) {
            return Response::redirect('/portal/login');
        }
        $portalRoles = array_values(array_intersect($principal->roles, ['client','companion']));
        if (count($portalRoles) !== 1 || !Authorization::allows($principal, $portalRoles[0] . '.self.view', false)) {
            return $this->page('login', ['error'=>'This account does not have access to the public portal.'], 403);
        }
        return $this->page('dashboard', ['accountType'=>$portalRoles[0]]);
    }

    public function logout(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('dashboard', ['error'=>'Your session expired. Refresh this page and try again.'], 419);
        }
        $this->authentication->logout();
        return Response::redirect('/portal/login');
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    private function queryString(Request $request, string $name): string
    {
        $value = $request->query[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    /** @param array<string,mixed> $data */
    private function page(string $screen, array $data = [], int $status = 200): Response
    {
        $payload = $data + [
            'screen'=>$screen,
            'brandName'=>$this->content->publicBrand()['brand_name'],
            'csrf'=>Csrf::issue(),
            'error'=>'',
            'notice'=>'',
            'email'=>'',
            'accountType'=>'',
        ];
        return Response::html($this->views->render('auth/portal', $payload), $status);
    }
}
