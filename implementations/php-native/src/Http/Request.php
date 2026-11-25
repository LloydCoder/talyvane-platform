<?php
declare(strict_types=1);

namespace Talyvane\Http;

final readonly class Request
{
    /** @param array<string,string> $headers @param array<string,mixed> $query @param array<string,mixed> $body @param array<string,mixed> $files */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers = [],
        public array $query = [],
        public array $body = [],
        public string $ipAddress = '',
        public string $requestId = '',
        public array $files = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method.');
        }
        $rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $rawPath = parse_url($rawUri, PHP_URL_PATH);
        if (!is_string($rawPath) || strlen($rawPath) > 2048 ||
            preg_match('/%(?:2f|5c|00)/i', $rawPath) === 1 ||
            str_contains($rawPath, "\0") || str_contains($rawPath, '\\')) {
            throw new \InvalidArgumentException('Request path is invalid.');
        }
        $path = rawurldecode($rawPath);
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('Request path traversal is not allowed.');
            }
        }
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('Request path must be absolute.');
        }
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 10_485_760) {
            throw new \LengthException('Request body exceeds the 2 MiB limit.');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $body = $_POST;
        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        if ($contentType === 'application/json') {
            $rawBody = (string) file_get_contents('php://input');
            $decoded = json_decode($rawBody, true, 32);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('JSON request body is invalid.');
            }
            $body = $decoded;
        }
        return new self(
            $method,
            $path,
            $headers,
            $_GET,
            $body,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            bin2hex(random_bytes(16)),
            $_FILES
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
