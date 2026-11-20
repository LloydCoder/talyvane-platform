<?php

declare(strict_types=1);

$privateRootCandidate = __DIR__.'/../../talyvane-app';
$privateApplicationRoot = realpath($privateRootCandidate);
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
$expectedPublicRoot = $privateApplicationRoot === false ? '' : $privateApplicationRoot.'/public';
$resolvedPublicRoot = $expectedPublicRoot === '' ? false : realpath($expectedPublicRoot);
$frontController = $expectedPublicRoot === '' ? '' : $expectedPublicRoot.'/index.php';
$documentRootInsideApplication = $privateApplicationRoot !== false
    && str_starts_with($documentRoot === false ? '' : $documentRoot, $privateApplicationRoot.DIRECTORY_SEPARATOR);
$documentRootIsExpectedPublicRoot = $documentRoot !== false && $documentRoot === $expectedPublicRoot;

if ($privateApplicationRoot === false
    || $documentRoot === false
    || $privateApplicationRoot === $documentRoot
    || str_starts_with($privateApplicationRoot, $documentRoot.DIRECTORY_SEPARATOR)
    || ($documentRootInsideApplication && ! $documentRootIsExpectedPublicRoot)
    || is_link($privateRootCandidate)
    || is_link($expectedPublicRoot)
    || $resolvedPublicRoot !== $expectedPublicRoot
    || ! is_file($frontController)
    || is_link($frontController)
    || realpath($frontController) !== $frontController
    || ! is_readable($frontController)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Frame-Options: DENY');
    exit('Talyvane is not configured safely. Verify the private application directory and document root.');
}

require $frontController;
