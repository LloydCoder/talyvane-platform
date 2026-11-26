<?php
declare(strict_types=1);

/**
 * Explicit cPanel cron entry point for encrypted booking and safeguarding mail.
 * Example: php /path/to/private/implementations/php-native/bin/dispatch-notifications.php --limit=25
 * Requires TALYVANE_CONFIG to reference the installer-generated owner-only config.
 */

use Talyvane\Application\Bookings\BookingNotificationDispatcher;
use Talyvane\Application\Safeguarding\SafeguardingNotificationDispatcher;
use Talyvane\Application\Identity\PortalPrivacyNotificationDispatcher;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Security\SecretBox;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

$options = getopt('', ['limit::','json','help']);
if (array_key_exists('help',$options)) {
    fwrite(STDOUT,"Usage: php bin/dispatch-notifications.php [--limit=25] [--json]\n");
    exit(0);
}
$limitValue = $options['limit'] ?? '25';
if (!is_string($limitValue) || !ctype_digit($limitValue) || (int)$limitValue < 1 || (int)$limitValue > 100) {
    fwrite(STDERR,"Invalid batch limit; use an integer from 1 to 100.\n");
    exit(2);
}

$configPath = getenv('TALYVANE_CONFIG');
if (!is_string($configPath) || $configPath === '' || is_link($configPath) ||
    !is_file($configPath) || !is_readable($configPath)) {
    fwrite(STDERR,"Private application configuration is unavailable.\n");
    exit(2);
}
$realConfig = realpath($configPath);
$mode = $realConfig === false ? false : fileperms($realConfig);
if ($realConfig === false || $mode === false || ($mode & 0077) !== 0) {
    fwrite(STDERR,"Private application configuration must be canonical and owner-only.\n");
    exit(2);
}
$config = require $realConfig;
if (!is_array($config) || !is_string($config['app_key'] ?? null) || strlen($config['app_key']) < 32 ||
    !is_array($config['database'] ?? null)) {
    fwrite(STDERR,"Private application configuration is invalid.\n");
    exit(2);
}
$documentRootInput = $config['paths']['document_root'] ?? null;
$documentRoot = is_string($documentRootInput) && $documentRootInput !== '' && !is_link($documentRootInput) && is_dir($documentRootInput)
    ? realpath($documentRootInput)
    : false;
if ($documentRoot === false || str_starts_with($realConfig . DIRECTORY_SEPARATOR, rtrim($documentRoot,DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR,"Private configuration must be outside the public document root.\\n");
    exit(2);
}

$mailConfig = is_array($config['mail']['smtp'] ?? null)
    ? $config['mail']['smtp']
    : (is_array($config['mail'] ?? null) ? $config['mail'] : null);
if (!is_array($mailConfig)) {
    fwrite(STDERR,"SMTP delivery is not configured.\n");
    exit(2);
}

try {
    $pdo = ConnectionFactory::mysql($config['database']);
    $mailer = new SmtpMailer($mailConfig);
    $box = new SecretBox($config['app_key']);
    $booking = (new BookingNotificationDispatcher($pdo,$box,$mailer))->dispatchBatch((int)$limitValue);
    $safeguarding = (new SafeguardingNotificationDispatcher($pdo,$box,$mailer))->dispatchBatch((int)$limitValue);
    $privacy = (new PortalPrivacyNotificationDispatcher($pdo,$box,$mailer))->dispatchBatch((int)$limitValue);
    $result = [
        'status'=>'ok',
        'booking'=>$booking,
        'safeguarding'=>$safeguarding,
        'privacy'=>$privacy,
        'processed'=>$booking['claimed'] + $safeguarding['claimed'] + $privacy['claimed'],
        'delivered'=>$booking['delivered'] + $safeguarding['delivered'] + $privacy['delivered'],
        'retried'=>$booking['retried'] + $safeguarding['retried'] + $privacy['retried'],
        'dead_lettered'=>$booking['dead_lettered'] + $safeguarding['dead_lettered'] + $privacy['dead_lettered'],
        'checked_at_utc'=>gmdate('Y-m-d\\TH:i:s\\Z'),
    ];
    if (array_key_exists('json',$options)) {
        fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    } else {
        fwrite(STDOUT,sprintf(
            "Notifications: %d claimed, %d delivered, %d retried, %d dead-lettered.\n",
            $result['processed'],$result['delivered'],$result['retried'],$result['dead_lettered']
        ));
    }
    exit($result['dead_lettered'] > 0 ? 1 : 0);
} catch (Throwable $error) {
    // Never emit exception messages: SMTP/DB drivers can include addresses or configuration details.
    fwrite(STDERR,"Notification dispatch failed (" . $error::class . "). Inspect private server logs.\n");
    exit(1);
}
