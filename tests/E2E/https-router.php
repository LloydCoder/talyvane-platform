<?php

declare(strict_types=1);

// The test origin terminates TLS in Caddy. The private PHP development server
// is reachable only through that proxy, so expose the HTTPS request attributes
// Laravel would receive from the production TLS web server.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && $path !== '/' && is_file(__DIR__.'/../../public'.$path)) {
    return false;
}

require __DIR__.'/../../public/index.php';
