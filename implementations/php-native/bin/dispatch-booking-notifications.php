<?php
declare(strict_types=1);

use Talyvane\Application\Bookings\BookingNotificationDispatcher;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Security\SecretBox;

$root=dirname(__DIR__);
require $root.'/bootstrap.php';
$configPath=getenv('TALYVANE_CONFIG');
if(!is_string($configPath) || $configPath==='' || is_link($configPath) || !is_file($configPath) || !is_readable($configPath)) {
    fwrite(STDERR,"Set TALYVANE_CONFIG to the absolute path of the private app.php configuration.\n");
    exit(64);
}
$real=realpath($configPath);
if($real===false || (fileperms($real)&0077)!==0) {
    fwrite(STDERR,"Private application configuration must be canonical and owner-only.\n");
    exit(64);
}
$config=require $real;
if(!is_array($config) || !is_array($config['database']??null) ||
   !is_array($config['mail']['smtp']??null) || !is_string($config['app_key']??null) ||
   !is_array($config['paths']??null) || !is_string($config['paths']['logs']??null)) {
    fwrite(STDERR,"Private application configuration is incomplete.\n");
    exit(78);
}
try {
    $pdo=ConnectionFactory::mysql($config['database']);
    $dispatcher=new BookingNotificationDispatcher(
        $pdo,
        new SecretBox($config['app_key']),
        new SmtpMailer($config['mail']['smtp'])
    );
    $result=$dispatcher->dispatchBatch(25);
    fwrite(STDOUT,sprintf(
        "Booking notifications: claimed=%d delivered=%d retried=%d dead_lettered=%d\n",
        $result['claimed'],$result['delivered'],$result['retried'],$result['dead_lettered']
    ));
    exit($result['dead_lettered']>0?2:0);
} catch(Throwable $error) {
    PrivateErrorLogger::record($error,$config['paths']['logs'],(string)($config['paths']['public']??$root.'/public'),bin2hex(random_bytes(8)));
    fwrite(STDERR,"Booking notification dispatch failed. Inspect the private application error log.\n");
    exit(1);
}
