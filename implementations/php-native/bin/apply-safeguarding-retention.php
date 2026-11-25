<?php
declare(strict_types=1);

use Talyvane\Application\Safeguarding\LocalSafeguardingCaseKeyProvider;
use Talyvane\Application\Safeguarding\SafeguardingCryptoService;
use Talyvane\Application\Safeguarding\SafeguardingRetentionService;
use Talyvane\Application\Safeguarding\VaultTransitSafeguardingCaseKeyProvider;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;

$root=dirname(__DIR__);
require $root.'/bootstrap.php';
$configPath=getenv('TALYVANE_CONFIG');
if (!is_string($configPath)||$configPath===''||is_link($configPath)||!is_file($configPath)||!is_readable($configPath)) {
    fwrite(STDERR,"Set TALYVANE_CONFIG to the absolute path of the private app.php configuration.\n");exit(64);
}
$real=realpath($configPath);
if ($real===false||(fileperms($real)&0077)!==0) { fwrite(STDERR,"Private application configuration must be canonical and owner-only.\n");exit(64); }
$config=require $real;
if (!is_array($config)||!is_array($config['database']??null)||!is_array($config['paths']??null)||!is_string($config['paths']['logs']??null)) {
    fwrite(STDERR,"Private application configuration is incomplete.\n");exit(78);
}
try {
    $pdo=ConnectionFactory::mysql($config['database']);
    $vault=$config['safeguarding']['vault']??null;
    $provider=is_array($vault)&&($vault['enabled']??false)===true
        ? new VaultTransitSafeguardingCaseKeyProvider($vault)
        : new LocalSafeguardingCaseKeyProvider((string)($config['app_key']??''));
    $retention=new SafeguardingRetentionService($pdo,new SafeguardingCryptoService($provider));
    $count=$retention->apply(50);
    fwrite(STDOUT,"Safeguarding retention: cryptographically erased cases=".$count."\n");
} catch (Throwable $error) {
    PrivateErrorLogger::record($error,$config['paths']['logs'],(string)($config['paths']['public']??$root.'/public'),bin2hex(random_bytes(8)));
    fwrite(STDERR,"Safeguarding retention did not complete. Inspect the private application error log.\n");
    exit(1);
}
