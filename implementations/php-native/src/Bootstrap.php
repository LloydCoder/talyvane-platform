<?php
declare(strict_types=1);

namespace Talyvane;

use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Http\PublicSiteController;
use Talyvane\Http\ViewRenderer;
use Talyvane\Infrastructure\Database\ConnectionFactory;

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
        $public = new PublicSiteController($pdo, $views);
        $router = new Router();
        $router->add('GET', '/', static fn(Request $request): Response => $public->home($request));
        $router->add('GET', '/pages/{slug}', static fn(Request $request, array $params): Response => $public->page($request, $params));
        return new class($router) {
            public function __construct(private Router $router) {}
            public function handle(Request $request): Response { return $this->router->dispatch($request); }
        };
    }
}
