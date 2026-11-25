<?php
declare(strict_types=1);

/**
 * Public front controller. This file must be the only PHP entrypoint exposed
 * by the web server. Never place secrets, logs, uploads or application source
 * beneath this directory.
 */
$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use Talyvane\Http\NativeInstallerController;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;
use Talyvane\Installer\InstallerLock;
use Talyvane\Installer\PrivatePathResolver;

try {
    $request = Request::fromGlobals();
    if (str_starts_with($request->path,'/install')) {
        (new NativeInstallerController($root))->handle($request)->send();
        exit;
    }

    $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $accountHome = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
    if ($accountHome === '' && $documentRoot !== '' && basename(rtrim($documentRoot,DIRECTORY_SEPARATOR)) === 'public_html') {
        $accountHome = dirname($documentRoot);
    }
    $configPath = getenv('TALYVANE_CONFIG');
    if (!is_string($configPath) || $configPath === '' || !is_file($configPath)) {
        $paths = PrivatePathResolver::resolve($documentRoot,$accountHome);
        $configPath = $paths['config'] . '/app.php';
        putenv('TALYVANE_CONFIG=' . $configPath);
        $_ENV['TALYVANE_CONFIG'] = $configPath;
    }
    if (is_link($configPath) || !is_file($configPath) || !is_readable($configPath)) {
        Response::redirect('/install')->send();
        exit;
    }
    $configReal = realpath($configPath);
    $publicReal = realpath($documentRoot);
    $applicationReal = realpath($root);
    if ($configReal === false || $publicReal === false || $applicationReal === false ||
        $configReal === $publicReal || str_starts_with($configReal,$publicReal . DIRECTORY_SEPARATOR) ||
        $applicationReal === $publicReal || str_starts_with($applicationReal,$publicReal . DIRECTORY_SEPARATOR) ||
        (fileperms($configReal) & 0077) !== 0) {
        throw new RuntimeException('Application code and configuration must remain private and outside the public document root.');
    }
    $privateConfig = require $configReal;
    if (!is_array($privateConfig)) {
        throw new RuntimeException('Private application configuration is invalid.');
    }
    $configDirectory = $privateConfig['paths']['config'] ?? dirname($configReal);
    if (!is_string($configDirectory) || $configDirectory === '' || is_link($configDirectory)) {
        throw new RuntimeException('Private installation directory is invalid.');
    }
    $configDirectoryReal = realpath($configDirectory);
    if ($configDirectoryReal === false || $configDirectoryReal === $publicReal ||
        str_starts_with($configDirectoryReal,$publicReal . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private installation directory cannot be inside the public document root.');
    }
    $installationLock = new InstallerLock(rtrim($configDirectoryReal,DIRECTORY_SEPARATOR) . '/INSTALLATION-LOCK.json');
    if (!$installationLock->isLocked()) {
        Response::redirect('/install')->send();
        exit;
    }

    Talyvane\Bootstrap::kernel($root)->handle($request)->send();
} catch (Throwable $error) {
    $reference = bin2hex(random_bytes(8));
    $logDirectory = null;
    $configPath = getenv('TALYVANE_CONFIG');
    if (is_string($configPath) && $configPath !== '' && is_file($configPath) && is_readable($configPath) && !is_link($configPath)) {
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
        error_log(sprintf('[talyvane:%s] exception=%s',$reference,$error::class));
    }
    Response::html(
        '<!doctype html><html lang="en"><meta charset="utf-8"><title>Service unavailable</title><h1>We could not complete that request.</h1><p>Reference: ' .
        htmlspecialchars($reference,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') .
        '</p></html>',
        500
    )->send();
}
