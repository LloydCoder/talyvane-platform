<?php
declare(strict_types=1);

/**
 * Explicit cPanel cron entry point for encrypted booking, safeguarding and privacy-outcome mail.
 * Example: php /path/to/private/implementations/php-native/bin/dispatch-notifications.php --limit=25
 * Requires TALYVANE_CONFIG and TALYVANE_PUBLIC_ROOT to identify the owner-only private config and canonical public document root.
 */

use Talyvane\Application\Bookings\BookingNotificationDispatcher;
use Talyvane\Application\Safeguarding\SafeguardingNotificationDispatcher;
use Talyvane\Application\Identity\PortalPrivacyNotificationDispatcher;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Security\SecretBox;
use Talyvane\Installer\PrivateCliConfig;

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
$publicRoot = getenv('TALYVANE_PUBLIC_ROOT');
if (!is_string($configPath) || $configPath === '' || !is_string($publicRoot) || $publicRoot === '') {
    fwrite(STDERR,"Set TALYVANE_CONFIG and TALYVANE_PUBLIC_ROOT to the private config file and public document root.\n");
    exit(2);
}
try {
    $config = PrivateCliConfig::load($configPath,$publicRoot);
} catch (Throwable) {
    fwrite(STDERR,"Private CLI configuration must be valid, owner-only and outside the public document root.\n");
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
