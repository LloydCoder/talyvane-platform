<?php
declare(strict_types=1);

namespace Talyvane\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $quoted = preg_quote($path, '~');
        $pattern = preg_replace_callback('/\\\\\{([a-zA-Z][a-zA-Z0-9_]*)\\\\\}/', static fn(array $m): string => '(?P<' . $m[1] . '>[^/]+)', $quoted);
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => '~^' . $pattern . '/?$~D', 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['pattern'], $request->path, $matches) === 1) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return ($route['handler'])($request, $params);
            }
        }
        return Response::html('Not Found', 404);
    }
}
