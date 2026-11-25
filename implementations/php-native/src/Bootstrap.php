<?php
declare(strict_types=1);

namespace Talyvane;

use Talyvane\Application\Companions\CompanionProfilePublicationService;
use Talyvane\Application\Bookings\BookingCommandService;
use Talyvane\Application\Bookings\BookingQueryService;
use Talyvane\Application\Bookings\BookingService;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Application\ModelAgency\ModelAgencyApplicationService;
use Talyvane\Application\ModelAgency\ModelApplicantRightsService;
use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Application\Identity\AccountRecoveryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Application\Identity\UserRegistrationService;
use Talyvane\Http\AdminCompanionController;
use Talyvane\Http\AdminAuthController;
use Talyvane\Http\AdminBrandController;
use Talyvane\Http\AdminAuditController;
use Talyvane\Http\AdminModelAgencyController;
use Talyvane\Http\AdminNavigationController;
use Talyvane\Http\AdminEnquiryController;
use Talyvane\Http\PortalAuthController;
use Talyvane\Http\PublicModelAgencyController;
use Talyvane\Http\PublicModelAgencyRightsController;
use Talyvane\Http\PublicDiscoveryController;
use Talyvane\Http\PublicEnquiryController;
use Talyvane\Http\AdminContentController;
use Talyvane\Http\PortalBookingController;
use Talyvane\Http\PublicSiteController;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Http\ViewRenderer;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Audit\AuditQueryService;
use Talyvane\Infrastructure\Database\BookingPolicyRepository;
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
        $registration = null;
        $recovery = null;
        $modelApplicantRights = null;
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
                $registration = new UserRegistrationService($pdo, $emailVerification, $rateLimiter, $audit);
                $recovery = new AccountRecoveryService($pdo, $emailVerification, $rateLimiter, $audit);
                $modelApplicantRights = new ModelApplicantRightsService($pdo, $mailer, $rateLimiter, $audit, hash_hmac('sha256', 'talyvane-model-applicant-rights-v1', $config['app_key']));
            } catch (\InvalidArgumentException) {
                // Mail-dependent account creation stays unavailable; the public site and existing accounts remain usable.
            }
        }

        $public = new PublicSiteController($pdo, $views);
        $discovery = new DiscoveryService($pdo);
        $publicDiscovery = new PublicDiscoveryController($discovery, $views);
        $contentManagement = new ContentManagementService($pdo, $audit);
        $adminAuth = new AdminAuthController($authentication, $passwords, $contentManagement, $views);
        $adminAudit = new AdminAuditController($authentication, new AuditQueryService($pdo), $views, (string) ($contentManagement->publicBrand()['brand_name'] ?? 'Talyvane'));
        $adminContent = new AdminContentController($pdo, $authentication, $contentManagement, $views);
        $adminBrand = new AdminBrandController($authentication, $contentManagement, $views);
        $adminNavigation = new AdminNavigationController($pdo, $authentication, $contentManagement, $views);
        $enquiries = new EnquiryService($pdo, $rateLimiter, $audit);
        $brandName = (string) ($contentManagement->publicBrand()['brand_name'] ?? 'Talyvane');
        $publicEnquiries = new PublicEnquiryController($enquiries, $contentManagement, $views, $brandName);
        $adminEnquiries = new AdminEnquiryController($enquiries, $authentication, $views, $brandName);
        $companionPublication = new CompanionProfilePublicationService($pdo, $audit);
        $adminCompanions = new AdminCompanionController($authentication, $companionPublication, $views, $brandName);
        $modelApplications = new ModelAgencyApplicationService($pdo, $audit);
        $publicModelAgency = new PublicModelAgencyController($pdo, $modelApplications, $contentManagement, $rateLimiter, $modelApplicantRights, $views);
        $adminModelAgency = new AdminModelAgencyController($authentication, $modelApplications, $views, $brandName);
        $publicModelAgencyRights = new PublicModelAgencyRightsController($modelApplicantRights, $views, $brandName);
        $bookingPolicies = new BookingPolicyRepository($pdo);
        $bookingService = new BookingService($pdo, $bookingPolicies, $audit);
        $bookingCommands = new BookingCommandService($pdo, $bookingPolicies, $audit);
        $bookingQueries = new BookingQueryService($pdo);
        $portalBookings = new PortalBookingController($authentication, $bookingService, $bookingCommands, $bookingQueries, $discovery, $views, $brandName);
        $portalAuth = new PortalAuthController($pdo, $authentication, $contentManagement, $views, $registration, $emailVerification, $recovery);
        $router = new Router();

        $router->add('GET', '/', static fn(Request $request): Response => $public->home($request));
        $router->add('GET', '/contact', static fn(Request $request): Response => $publicEnquiries->create($request));
        $router->add('POST', '/enquiries', static fn(Request $request): Response => $publicEnquiries->store($request));
        $router->add('GET', '/model-agency/apply', static fn(Request $request): Response => $publicModelAgency->form($request));
        $router->add('POST', '/model-agency/apply', static fn(Request $request): Response => $publicModelAgency->submit($request));
        $router->add('GET', '/model-agency/privacy', static fn(Request $request): Response => $publicModelAgencyRights->requestForm($request));
        $router->add('POST', '/model-agency/privacy', static fn(Request $request): Response => $publicModelAgencyRights->request($request));
        $router->add('GET', '/model-agency/privacy/verify', static fn(Request $request): Response => $publicModelAgencyRights->verifyForm($request));
        $router->add('POST', '/model-agency/privacy/verify', static fn(Request $request): Response => $publicModelAgencyRights->verify($request));
        $router->add('POST', '/model-agency/privacy/correct', static fn(Request $request): Response => $publicModelAgencyRights->correct($request));
        $router->add('POST', '/model-agency/privacy/withdraw', static fn(Request $request): Response => $publicModelAgencyRights->withdraw($request));
        $router->add('POST', '/model-agency/privacy/erase', static fn(Request $request): Response => $publicModelAgencyRights->erase($request));
        $router->add('GET', '/destinations', static fn(Request $request): Response => $publicDiscovery->destinations($request));
        $router->add('GET', '/destinations/{slug}', static fn(Request $request, array $params): Response => $publicDiscovery->destination($request, $params));
        $router->add('GET', '/profiles/{slug}', static fn(Request $request, array $params): Response => $publicDiscovery->profile($request, $params));
        $router->add('GET', '/pages/{slug}', static fn(Request $request, array $params): Response => $public->page($request, $params));
        $router->add('GET', '/privacy', static fn(Request $request): Response => $public->page($request, ['slug'=>'privacy']));
        $router->add('GET', '/terms', static fn(Request $request): Response => $public->page($request, ['slug'=>'terms']));

        $router->add('GET', '/portal/register', static fn(Request $request): Response => $portalAuth->registerForm($request));
        $router->add('POST', '/portal/register', static fn(Request $request): Response => $portalAuth->register($request));
        $router->add('GET', '/portal/verify', static fn(Request $request): Response => $portalAuth->verifyForm($request));
        $router->add('POST', '/portal/verify', static fn(Request $request): Response => $portalAuth->verify($request));
        $router->add('POST', '/portal/verify/resend', static fn(Request $request): Response => $portalAuth->resendVerification($request));
        $router->add('GET', '/portal/recovery', static fn(Request $request): Response => $portalAuth->recoveryForm($request));
        $router->add('POST', '/portal/recovery', static fn(Request $request): Response => $portalAuth->requestRecovery($request));
        $router->add('POST', '/portal/recovery/complete', static fn(Request $request): Response => $portalAuth->completeRecovery($request));
        $router->add('GET', '/portal/login', static fn(Request $request): Response => $portalAuth->loginForm($request));
        $router->add('POST', '/portal/login', static fn(Request $request): Response => $portalAuth->login($request));
        $router->add('GET', '/portal', static fn(Request $request): Response => $portalAuth->dashboard($request));
        $router->add('POST', '/portal/logout', static fn(Request $request): Response => $portalAuth->logout($request));
        $router->add('GET', '/portal/bookings', static fn(Request $request): Response => $portalBookings->clientIndex($request));
        $router->add('GET', '/portal/bookings/new', static fn(Request $request): Response => $portalBookings->newForm($request));
        $router->add('POST', '/portal/bookings', static fn(Request $request): Response => $portalBookings->store($request));
        $router->add('POST', '/portal/bookings/{id}/cancel', static fn(Request $request, array $params): Response => $portalBookings->cancel($request, $params));
        $router->add('GET', '/portal/companion/bookings', static fn(Request $request): Response => $portalBookings->companionIndex($request));
        $router->add('POST', '/portal/companion/bookings/{id}/decision', static fn(Request $request, array $params): Response => $portalBookings->companionDecision($request, $params));

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
        $router->add('GET', '/admin/audit', static fn(Request $request): Response => $adminAudit->index($request));
        $router->add('POST', '/admin/logout', static fn(Request $request): Response => $adminAuth->logout($request));
        $router->add('GET', '/admin/brand', static fn(Request $request): Response => $adminBrand->show($request));
        $router->add('POST', '/admin/brand', static fn(Request $request): Response => $adminBrand->update($request));
        $router->add('GET', '/admin/bookings', static fn(Request $request): Response => $portalBookings->adminIndex($request));
        $router->add('POST', '/admin/bookings/{id}/status', static fn(Request $request, array $params): Response => $portalBookings->adminTransition($request, $params));
        $router->add('GET', '/admin/model-agency', static fn(Request $request): Response => $adminModelAgency->index($request));
        $router->add('GET', '/admin/model-agency/verifications', static fn(Request $request): Response => $adminModelAgency->verificationQueue($request));
        $router->add('POST', '/admin/model-agency/{id}/review', static fn(Request $request, array $params): Response => $adminModelAgency->review($request, $params));
        $router->add('POST', '/admin/model-agency/{id}/verify', static fn(Request $request, array $params): Response => $adminModelAgency->verify($request, $params));
        $router->add('POST', '/admin/model-agency/{id}/legal-hold', static fn(Request $request, array $params): Response => $adminModelAgency->legalHold($request, $params));
        $router->add('GET', '/admin/companions', static fn(Request $request): Response => $adminCompanions->index($request));
        $router->add('POST', '/admin/companions/{id}/publish', static fn(Request $request, array $params): Response => $adminCompanions->publish($request, $params));
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
