<?php
declare(strict_types=1);

use Talyvane\Application\Identity\SuperAdminRecoveryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Installer\PrivateCliConfig;

/** CLI-only offline Super Admin authenticator recovery. Never expose through the web server. */
if (PHP_SAPI!=='cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/bootstrap.php';

function recoveryPrompt(string $label): string
{
    fwrite(STDOUT,$label);
    $stateOutput=shell_exec('stty -g 2>/dev/null');
    $state=is_string($stateOutput)?trim($stateOutput):'';
    if ($state==='' || preg_match('/^[0-9a-fA-F:;]+$/D',$state)!==1) {
        throw new RuntimeException('A real interactive terminal is required for hidden credential input.');
    }
    $disabled=shell_exec('stty -echo 2>/dev/null && printf "__TALYVANE_STTY_OK__"');
    if (!is_string($disabled) || !str_contains($disabled,'__TALYVANE_STTY_OK__')) {
        throw new RuntimeException('Terminal input could not be protected from echo.');
    }
    try {
        $value=fgets(STDIN);
    } finally {
        $restored=shell_exec('stty '.escapeshellarg($state).' 2>/dev/null && printf "__TALYVANE_STTY_OK__"');
        fwrite(STDOUT,PHP_EOL);
        if (!is_string($restored) || !str_contains($restored,'__TALYVANE_STTY_OK__')) {
            throw new RuntimeException('Terminal echo could not be restored safely.');
        }
    }
    if (!is_string($value)) throw new RuntimeException('Credential input could not be read.');
    return rtrim($value,"\r\n");
}

try {
    $homeValue=getenv('HOME')?:($_SERVER['HOME']??'');
    $home=is_string($homeValue)&&$homeValue!==''?realpath($homeValue):false;
    if ($home===false || !is_dir($home)) throw new RuntimeException('Private cPanel account home could not be resolved.');

    $configCandidate=getenv('TALYVANE_CONFIG');
    if (!is_string($configCandidate)||$configCandidate==='') {
        $matches=glob($home.DIRECTORY_SEPARATOR.'.talyvane'.DIRECTORY_SEPARATOR.'talyvane'.
            DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'app.php')?:[];
        if (count($matches)!==1) throw new RuntimeException('Set TALYVANE_CONFIG to the private app.php path for exactly one installation.');
        $configCandidate=$matches[0];
    }
    if (is_link($configCandidate)) throw new RuntimeException('Private application configuration must not be a symlink.');
    $configPath=realpath($configCandidate);
    $privatePrefix=$home.DIRECTORY_SEPARATOR.'.talyvane'.DIRECTORY_SEPARATOR.'talyvane'.DIRECTORY_SEPARATOR;
    if ($configPath===false || !str_starts_with($configPath,$privatePrefix) ||
        basename($configPath)!=='app.php' || basename(dirname($configPath))!=='config' ||
        !is_file($configPath)||!is_readable($configPath)||(fileperms($configPath)&0077)!==0) {
        throw new RuntimeException('Private application configuration is invalid or has unsafe permissions.');
    }

    $config=require $configPath;
    if (!is_array($config)||!is_array($config['paths']??null)||
        !is_string($config['paths']['document_root']??null)||
        realpath($config['paths']['config']??'')!==dirname($configPath)) {
        throw new RuntimeException('Private application configuration is incomplete.');
    }
    $config=PrivateCliConfig::load($configPath,$config['paths']['document_root']);
    $pdo=ConnectionFactory::mysql($config['database']);
    $mailer=new SmtpMailer($config['mail']);
    $service=new SuperAdminRecoveryService($pdo,$mailer,new AuditLogger($pdo));

    fwrite(STDOUT,"Talyvane — offline Super Admin authenticator recovery\n");
    fwrite(STDOUT,"This operation rotates the offline key, resets MFA, revokes all sessions and sends notices to both verified administrator addresses.\n");
    $password=recoveryPrompt("Current Super Admin password (hidden): ");
    $offlineKey=recoveryPrompt("Offline recovery key (hidden): ");
    fwrite(STDOUT,"Reason (visible, minimum 20 characters): ");
    $reason=trim((string)fgets(STDIN));
    $newKey=$service->recover($password,$offlineKey,$reason,bin2hex(random_bytes(16)));
    fwrite(STDOUT,"\nRecovery completed. Save this new offline key once, offline and separately from the administrator password:\n\n");
    fwrite(STDOUT,$newKey.PHP_EOL);
    fwrite(STDOUT,"\nThe previous key is invalid. The Super Admin must sign in and enrol a new authenticator before privileged operations.\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR,"\nRecovery was not completed. No credentials were printed. Check the private application configuration, SMTP connectivity and recovery policy, then contact the approved platform operator if necessary.\n");
    exit(1);
}
