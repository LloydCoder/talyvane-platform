<?php
declare(strict_types=1);

namespace Talyvane\Http;

final readonly class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = ['content-type' => 'text/html; charset=utf-8'],
    ) {
        foreach ($headers as $name => $value) {
            if (!preg_match('/^[a-zA-Z0-9-]+$/D', (string) $name) || preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException('Invalid response header.');
            }
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            ['content-type' => 'application/json; charset=utf-8', 'cache-control' => 'no-store']
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        if (!str_starts_with($location, '/') || str_starts_with($location, '//') || preg_match('/[\r\n]/', $location)) {
            throw new \InvalidArgumentException('Redirect target must be a safe local path.');
        }
        return new self('', $status, ['location' => $location, 'cache-control' => 'no-store']);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
        echo $this->body;
        exit;
    }
}
