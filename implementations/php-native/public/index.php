<?php
declare(strict_types=1);

/**
 * Public front controller. This file must be the only PHP entrypoint exposed
 * by the web server. Never place secrets, logs, uploads or application source
 * beneath this directory.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;

try {
    $kernel = Talyvane\Bootstrap::kernel($root);
    $kernel->handle(Request::fromGlobals())->send();
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(8));
    $logDirectory = null;
    $configPath = getenv('TALYVANE_CONFIG');
    if (is_string($configPath) && $configPath !== '' && is_file($configPath) && is_readable($configPath)) {
        try {
            $privateConfig = require $configPath;
            if (is_array($privateConfig)) {
                $candidate = $privateConfig['paths']['logs'] ?? $privateConfig['logs_path'] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    $logDirectory = $candidate;
                }
            }
        } catch (Throwable) {
            $logDirectory = null;
        }
    }
    if (is_string($logDirectory)) {
        PrivateErrorLogger::record($error,$logDirectory,(string) ($_SERVER['DOCUMENT_ROOT'] ?? $root . '/public'),$reference);
    } else {
        error_log(sprintf('[talyvane:%s] exception=%s', $reference, $error::class));
    }
    Response::html(
        '<!doctype html><html lang="en"><meta charset="utf-8"><title>Service unavailable</title><h1>We could not complete that request.</h1><p>Reference: ' .
        htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') .
        '</p></html>',
        500
    )->send();
}
