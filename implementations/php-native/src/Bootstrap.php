<?php
declare(strict_types=1);

namespace Talyvane;

use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;

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
        $router = new Router();
        $router->add('GET', '/', static fn(Request $request): Response => Response::html(
            '<!doctype html><html lang="en"><meta charset="utf-8"><title>Talyvane</title><main><h1>Talyvane</h1><p>Native PHP application workspace initialized.</p></main></html>'
        ));
        return new class($router) {
            public function __construct(private Router $router) {}
            public function handle(Request $request): Response { return $this->router->dispatch($request); }
        };
    }
}
