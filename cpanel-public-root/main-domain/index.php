<?php

declare(strict_types=1);

$privateRootCandidate = __DIR__.'/../talyvane-app';
$privateApplicationRoot = realpath($privateRootCandidate);
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$frontController = $privateApplicationRoot === false ? '' : $privateApplicationRoot.'/public/index.php';

if ($privateApplicationRoot === false
    || $documentRoot === false
    || $privateApplicationRoot === $documentRoot
    || str_starts_with($privateApplicationRoot, $documentRoot.DIRECTORY_SEPARATOR)
    || is_link($privateRootCandidate)
    || ! is_file($frontController)
    || is_link($frontController)
    || ! is_readable($frontController)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Frame-Options: DENY');
    exit('Talyvane is not configured safely. Verify the private application directory and document root.');
}

require $frontController;
