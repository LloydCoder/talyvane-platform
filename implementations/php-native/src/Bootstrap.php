<?php
declare(strict_types=1);

namespace Talyvane;

use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Application\Identity\UserRegistrationService;
use Talyvane\Http\AdminAuthController;
use Talyvane\Http\AdminBrandController;
use Talyvane\Http\AdminNavigationController;
use Talyvane\Http\AdminEnquiryController;
use Talyvane\Http\PortalAuthController;
use Talyvane\Http\PublicEnquiryController;
use Talyvane\Http\AdminContentController;
use Talyvane\Http\PublicSiteController;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Http\ViewRenderer;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\SecretBox;

final class Bootstrap
{
    public static function kernel(string $root): object
    {
        $configPath = getenv('TALYVANE_CONFIG');
        if (!is_string($configPath) || $configPath === '' || !is_file($configPath) || !is_readable($configPath)) {
            throw new \RuntimeException('Private configuration is not available. Run the secure installer.');
        }
        $config = require $configPath;
        if (!is_array($config) || !isset($config['app_key']) || !is_string($config['app_key']) || strlen($config['app_key']) < 32) {
            throw new \RuntimeException('Private application configuration is invalid.');
        }
        if (!isset($config['database']) || !is_array($config['database'])) {
            throw new \RuntimeException('Database configuration is missing.');
        }

        $pdo = ConnectionFactory::mysql($config['database']);
        $views = new ViewRenderer($root . DIRECTORY_SEPARATOR . 'views');

        $audit = new AuditLogger($pdo);
        $rateLimiter = new RateLimiter(
            $pdo,
            hash_hmac('sha256', 'talyvane-rate-limiter-v1', $config['app_key'])
        );
        $mfa = new MfaService($pdo, new SecretBox($config['app_key']), $audit);
        $authentication = new AuthenticationService($pdo, $rateLimiter, $mfa);
        $passwords = new PasswordChangeService($pdo, $rateLimiter, $audit);

        $emailVerification = null;
        $registration = null;\n        $recovery = null;
        if (isset($config['mail']['smtp']) && is_array($config['mail']['smtp'])) {
            try {
                $mailer = new SmtpMailer($config['mail']['smtp']);
                $emailVerification = new EmailVerificationService(
                    $pdo,
                    $mailer,
                    $rateLimiter,
                    $audit,
                    hash_hmac('sha256', 'talyvane-email-verification-v1', $config['app_key'])
                );
                $registration = new UserRegistrationService($pdo, $emailVerification, $rateLimiter, $audit);\n                $recovery = new AccountRecoveryService($pdo, $emailVerification, $rateLimiter, $audit);
            } catch (\InvalidArgumentException) {
                // Mail-dependent account creation stays unavailable; the public site and existing accounts remain usable.
            }
        }

        $public = new PublicSiteController($pdo, $views);
        $contentManagement = new ContentManagementService($pdo, $audit);
        $adminAuth = new AdminAuthController($authentication, $passwords, $contentManagement, $views);
        $adminContent = new AdminContentController($pdo, $authentication, $contentManagement, $views);
        $adminBrand = new AdminBrandController($authentication, $contentManagement, $views);
        $adminNavigation = new AdminNavigationController($pdo, $authentication, $contentManagement, $views);
        $enquiries = new EnquiryService($pdo, $rateLimiter, $audit);
        $brandName = (string) ($contentManagement->publicBrand()['brand_name'] ?? 'Talyvane');
        $publicEnquiries = new PublicEnquiryController($enquiries, $contentManagement, $views, $brandName);
        $adminEnquiries = new AdminEnquiryController($enquiries, $authentication, $views, $brandName);
        $portalAuth = new PortalAuthController($pdo, $authentication, $contentManagement, $views, $registration, $emailVerification, $recovery);
        $router = new Router();

        $router->add('GET', '/', static fn(Request $request): Response => $public->home($request));
        $router->add('GET', '/contact', static fn(Request $request): Response => $publicEnquiries->create($request));
        $router->add('POST', '/enquiries', static fn(Request $request): Response => $publicEnquiries->store($request));
        $router->add('GET', '/pages/{slug}', static fn(Request $request, array $params): Response => $public->page($request, $params));
        $router->add('GET', '/privacy', static fn(Request $request): Response => $public->page($request, ['slug'=>'privacy']));
        $router->add('GET', '/terms', static fn(Request $request): Response => $public->page($request, ['slug'=>'terms']));

        $router->add('GET', '/portal/register', static fn(Request $request): Response => $portalAuth->registerForm($request));
        $router->add('POST', '/portal/register', static fn(Request $request): Response => $portalAuth->register($request));
        $router->add('GET', '/portal/verify', static fn(Request $request): Response => $portalAuth->verifyForm($request));
        $router->add('POST', '/portal/verify', static fn(Request $request): Response => $portalAuth->verify($request));\n        $router->add('POST', '/portal/verify/resend', static fn(Request $request): Response => $portalAuth->resendVerification($request));\n        $router->add('GET', '/portal/recovery', static fn(Request $request): Response => $portalAuth->recoveryForm($request));\n        $router->add('POST', '/portal/recovery', static fn(Request $request): Response => $portalAuth->requestRecovery($request));\n        $router->add('POST', '/portal/recovery/complete', static fn(Request $request): Response => $portalAuth->completeRecovery($request));
        $router->add('GET', '/portal/login', static fn(Request $request): Response => $portalAuth->loginForm($request));
        $router->add('POST', '/portal/login', static fn(Request $request): Response => $portalAuth->login($request));
        $router->add('GET', '/portal', static fn(Request $request): Response => $portalAuth->dashboard($request));
        $router->add('POST', '/portal/logout', static fn(Request $request): Response => $portalAuth->logout($request));

        $router->add('GET', '/admin/login', static fn(Request $request): Response => $adminAuth->loginForm($request));
        $router->add('POST', '/admin/login', static fn(Request $request): Response => $adminAuth->login($request));
        $router->add('GET', '/admin/first-login', static fn(Request $request): Response => $adminAuth->firstLoginForm($request));
        $router->add('POST', '/admin/first-login', static fn(Request $request): Response => $adminAuth->firstLogin($request));
        $router->add('GET', '/admin/mfa', static fn(Request $request): Response => $adminAuth->mfaForm($request));
        $router->add('POST', '/admin/mfa', static fn(Request $request): Response => $adminAuth->mfa($request));
        $router->add('GET', '/admin/mfa/setup', static fn(Request $request): Response => $adminAuth->mfaSetupForm($request));
        $router->add('POST', '/admin/mfa/setup', static fn(Request $request): Response => $adminAuth->mfaSetup($request));
        $router->add('GET', '/admin/recovery-codes', static fn(Request $request): Response => $adminAuth->recoveryCodes($request));
        $router->add('GET', '/admin', static fn(Request $request): Response => $adminAuth->dashboard($request));
        $router->add('POST', '/admin/logout', static fn(Request $request): Response => $adminAuth->logout($request));
        $router->add('GET', '/admin/brand', static fn(Request $request): Response => $adminBrand->show($request));
        $router->add('POST', '/admin/brand', static fn(Request $request): Response => $adminBrand->update($request));
        $router->add('GET', '/admin/enquiries', static fn(Request $request): Response => $adminEnquiries->index($request));
        $router->add('POST', '/admin/enquiries/{reference}', static fn(Request $request, array $params): Response => $adminEnquiries->update($request, $params));
        $router->add('GET', '/admin/navigation', static fn(Request $request): Response => $adminNavigation->index($request));
        $router->add('POST', '/admin/navigation', static fn(Request $request, array $params): Response => $adminNavigation->save($request));
        $router->add('POST', '/admin/navigation/{id}', static fn(Request $request, array $params): Response => $adminNavigation->save($request, $params));
        $router->add('GET', '/admin/content', static fn(Request $request): Response => $adminContent->index($request));
        $router->add('GET', '/admin/content/new', static fn(Request $request): Response => $adminContent->create($request));
        $router->add('POST', '/admin/content', static fn(Request $request, array $params): Response => $adminContent->save($request));
        $router->add('GET', '/admin/content/{id}', static fn(Request $request, array $params): Response => $adminContent->edit($request, $params));
        $router->add('POST', '/admin/content/{id}', static fn(Request $request, array $params): Response => $adminContent->save($request, $params));
        $router->add('POST', '/admin/content/{id}/publish', static fn(Request $request, array $params): Response => $adminContent->publish($request, $params));

        return new class($router) {
            public function __construct(private Router $router) {}
            public function handle(Request $request): Response { return $this->router->dispatch($request); }
        };
    }
}
