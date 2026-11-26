<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class PortalSecurityController
{
    public function __construct(
        private AuthenticationService $authentication,
        private PasswordChangeService $passwords,
        private ViewRenderer $views,
        private string $brandName,
        private ?\Talyvane\Application\Identity\PortalEmailChangeService $emailChanges = null
    ) {}

    public function index(Request $request): Response
    {
        SessionManager::start();
        if (!$this->portalPrincipal() instanceof Principal) {
            return Response::redirect('/portal/login');
        }
        return $this->page();
    }

    public function changePassword(Request $request): Response
    {
        SessionManager::start();
        $principal = $this->portalPrincipal();
        if (!$principal instanceof Principal) {
            return Response::redirect('/portal/login');
        }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh this page and try again.', '',419);
        }
        $newPassword = $this->field($request,'new_password');
        if (!hash_equals($newPassword,$this->field($request,'confirm_password'))) {
            return $this->page('The new password and confirmation do not match.', '',422);
        }
        try {
            $version = $this->passwords->changePortalPassword(
                $principal->id,
                $this->field($request,'current_password'),
                $newPassword,
                $request->requestId
            );
            SessionManager::rotateAfterAuthentication();
            $_SESSION['session_version'] = $version;
            return $this->page('', 'Your password was changed. Other active sessions have been revoked.');
        } catch (\InvalidArgumentException|\DomainException $error) {
            $status = str_contains(strtolower($error->getMessage()),'rate limited') ? 429 : 422;
            return $this->page(
                $status === 429 ? 'Too many password-change attempts. Wait before trying again.' : 'The password could not be changed. Check the current password and new-password requirements.',
                '',
                $status
            );
        } catch (\Throwable) {
            return $this->page('Password change is temporarily unavailable. Try again later.','',503);
        }
    }

    public function requestEmailChange(Request $request): Response
    {
        SessionManager::start();
        $principal=$this->portalPrincipal();
        if(!$principal instanceof Principal) return Response::redirect('/portal/login');
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('Your session expired. Refresh this page and try again.','',419);
        if($this->emailChanges===null) return $this->page('Email change verification is unavailable because outbound email is not configured.','',503);
        try {
            $this->emailChanges->request($principal,$this->field($request,'new_email'),$this->field($request,'current_password'),$request->requestId);
            return $this->page('','A verification code was sent to the new address. Your account email remains unchanged until verification succeeds.');
        } catch(\DomainException $e) {
            return $this->page('The request could not be accepted. Check your current password, address and request limits.','',422);
        } catch(\InvalidArgumentException) {
            return $this->page('Enter a valid new email address and your current password.','',422);
        } catch(\Throwable) {
            return $this->page('The verification message could not be delivered. No email change was made.','',503);
        }
    }

    public function verifyEmailChange(Request $request): Response
    {
        SessionManager::start();
        $principal=$this->portalPrincipal();
        if(!$principal instanceof Principal) return Response::redirect('/portal/login');
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('Your session expired. Refresh this page and try again.','',419);
        if($this->emailChanges===null) return $this->page('Email change verification is unavailable.','',503);
        try {
            $version=$this->emailChanges->verify($principal,$this->field($request,'code'),$request->requestId);
            SessionManager::rotateAfterAuthentication();
            $_SESSION['session_version']=$version;
            return $this->page('','Your contact email is verified and updated. Other sessions have been revoked.');
        } catch(\Throwable) {
            return $this->page('The verification code could not be accepted. It may be expired, invalid or already used.','',422);
        }
    }

    private function portalPrincipal(): ?Principal
    {
        $principal = $this->authentication->currentPrincipal();
        if (!$principal instanceof Principal) {
            return null;
        }
        $portalRoles = array_values(array_intersect($principal->roles,['client','companion']));
        $adminRoles = array_intersect($principal->roles,['super_admin','owner','admin','finance','verifier','safeguarding']);
        return count($portalRoles) === 1 && $adminRoles === [] ? $principal : null;
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    private function page(string $error='',string $notice='',int $status=200): Response
    {
        $html = $this->views->render('auth/portal-security',[
            'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>$error,'notice'=>$notice,'emailChangeAvailable'=>$this->emailChanges!==null,
        ]);
        $base = Response::html($html,$status);
        $headers = $base->headers;
        $headers['cache-control'] = 'no-store, no-cache, must-revalidate, private';
        $headers['pragma'] = 'no-cache';
        $headers['referrer-policy'] = 'no-referrer';
        $headers['content-security-policy'] = "default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'";
        return new Response($html,$status,$headers);
    }
}
