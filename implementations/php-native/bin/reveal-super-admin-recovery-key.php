<?php
declare(strict_types=1);

/**
 * One-time CLI-only handoff. Never expose this script through the web server.
 * Run from the private native application root using cPanel Terminal/SSH.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Installer\OneTimeRecoveryKeyHandoff;
use Talyvane\Security\SecretBox;

$homeValue = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
$home = is_string($homeValue) && $homeValue !== '' ? realpath($homeValue) : false;
if ($home === false || !is_dir($home)) {
    fwrite(STDERR,"Private cPanel account home could not be resolved.\n");
    exit(1);
}

$configCandidate = getenv('TALYVANE_CONFIG');
if (!is_string($configCandidate) || $configCandidate === '') {
    $matches = glob($home . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' .
        DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php') ?: [];
    if (count($matches) !== 1) {
        fwrite(STDERR,"Set TALYVANE_CONFIG to the private config/app.php path for exactly one Talyvane installation.\n");
        exit(1);
    }
    $configCandidate = $matches[0];
}
if (is_link($configCandidate)) {
    fwrite(STDERR,"Private application configuration must not be a symlink.\n");
    exit(1);
}
$configPath = realpath($configCandidate);
$privatePrefix = $home . DIRECTORY_SEPARATOR . '.talyvane' . DIRECTORY_SEPARATOR . 'talyvane' . DIRECTORY_SEPARATOR;
if ($configPath === false || !str_starts_with($configPath,$privatePrefix) ||
    basename($configPath) !== 'app.php' || basename(dirname($configPath)) !== 'config' ||
    !is_file($configPath) || !is_readable($configPath) || (fileperms($configPath) & 0077) !== 0) {
    fwrite(STDERR,"Private application configuration is invalid or has unsafe permissions.\n");
    exit(1);
}

$config = require $configPath;
if (!is_array($config) || !is_string($config['app_key'] ?? null) ||
    strlen($config['app_key']) < 32 || !is_array($config['paths'] ?? null) ||
    !is_string($config['paths']['document_root'] ?? null) ||
    realpath($config['paths']['config'] ?? '') !== dirname($configPath)) {
    fwrite(STDERR,"Private application configuration is incomplete.\n");
    exit(1);
}

try {
    $handoff = new OneTimeRecoveryKeyHandoff(
        dirname($configPath) . DIRECTORY_SEPARATOR . 'INSTALLER-RECOVERY-HANDOFF.json',
        $config['paths']['document_root'],
        new SecretBox($config['app_key'])
    );
    $key = $handoff->consume();
    fwrite(STDOUT,$key . PHP_EOL);
    exit(0);
} catch (Throwable) {
    fwrite(STDERR,"The one-time offline recovery-key handoff is unavailable, expired or already consumed.\n");
    exit(1);
}
