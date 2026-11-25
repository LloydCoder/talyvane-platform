<?php
declare(strict_types=1);

namespace Talyvane;

use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Http\AdminAuthController;
use Talyvane\Http\AdminBrandController;
use Talyvane\Http\AdminNavigationController;
use Talyvane\Http\AdminContentController;
use Talyvane\Http\PublicSiteController;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Http\ViewRenderer;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\ConnectionFactory;
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

        $public = new PublicSiteController($pdo, $views);
        $contentManagement = new ContentManagementService($pdo, $audit);
        $adminAuth = new AdminAuthController($authentication, $passwords, $contentManagement, $views);
        $adminContent = new AdminContentController($pdo, $authentication, $contentManagement, $views);
        $adminBrand = new AdminBrandController($authentication, $contentManagement, $views);
        $adminNavigation = new AdminNavigationController($pdo, $authentication, $contentManagement, $views);
        $router = new Router();

        $router->add('GET', '/', static fn(Request $request): Response => $public->home($request));
        $router->add('GET', '/pages/{slug}', static fn(Request $request, array $params): Response => $public->page($request, $params));

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
