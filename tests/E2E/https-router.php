<?php

declare(strict_types=1);

// The test origin terminates TLS in Caddy. The private PHP development server
// is reachable only through that proxy, so expose the HTTPS request attributes
// Laravel would receive from the production TLS web server.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($documentRoot === false) {
    http_response_code(500);
    exit('The customer package document root is unavailable.');
}

if (is_string($path) && $path !== '/' && ! str_contains($path, '..')) {
    $staticFile = $documentRoot.DIRECTORY_SEPARATOR.ltrim($path, '/');
    if (is_file($staticFile)) {
        return false;
    }
}

require $documentRoot.DIRECTORY_SEPARATOR.'index.php';
