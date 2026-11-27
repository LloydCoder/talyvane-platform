<?php
declare(strict_types=1);

use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Application\Identity\SuperAdminRecoveryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Mail\SmtpMailer;
use Talyvane\Installer\PrivateCliConfig;
use Talyvane\Security\RateLimiter;
use Talyvane\Security\SecretBox;

if (PHP_SAPI!=='cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/bootstrap.php';

function manageRecoveryStty(array $arguments): ?array
{
    if (!function_exists('proc_open')) return null;
    $process=proc_open(array_merge(['stty'],$arguments),[
        0=>STDIN,1=>['pipe','w'],2=>['pipe','w'],
    ],$pipes);
    if (!is_resource($process)) return null;
    $output=stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status=proc_close($process);
    if (!is_string($output)||!is_string($error)) return null;
    return ['status'=>$status,'output'=>trim($output)];
}

function manageRecoveryHidden(string $label): string
{
    fwrite(STDOUT,$label);
    $state=manageRecoveryStty(['-g']);
    if (!is_array($state)||$state['status']!==0||$state['output']===''||
        preg_match('/^[0-9a-fA-F:;]+$/D',$state['output'])!==1) {
        throw new RuntimeException('A real interactive terminal is required for hidden credential input.');
    }
    $disabled=manageRecoveryStty(['-echo']);
    if (!is_array($disabled)||$disabled['status']!==0) {
        throw new RuntimeException('Terminal input could not be protected from echo.');
    }
    try {
        $value=fgets(STDIN);
    } finally {
        $restored=manageRecoveryStty([$state['output']]);
        fwrite(STDOUT,PHP_EOL);
        if (!is_array($restored)||$restored['status']!==0) {
            throw new RuntimeException('Terminal echo could not be restored safely.');
        }
    }
    if (!is_string($value)) throw new RuntimeException('Credential input could not be read.');
    return rtrim($value,"\r\n");
}

function manageRecoveryVisible(string $label): string
{
    fwrite(STDOUT,$label);
    $value=fgets(STDIN);
    if (!is_string($value)) throw new RuntimeException('Input could not be read.');
    return trim($value);
}

function manageRecoveryVerificationCode(EmailVerificationService $verification,string $userId,string $email,string $purpose): void
{
    $verification->issue($userId,$email,$purpose);
}

try {
    $action=$argv[1]??'';
    if (!in_array($action,['enroll','rotate'],true)) {
        throw new InvalidArgumentException('Choose exactly one action: enroll or rotate.');
    }
    $homeValue=getenv('HOME')?:($_SERVER['HOME']??'');
    $home=is_string($homeValue)&&$homeValue!==''?realpath($homeValue):false;
    if ($home===false||!is_dir($home)) throw new RuntimeException('Private cPanel account home could not be resolved.');
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
    if ($configPath===false||!str_starts_with($configPath,$privatePrefix)||
        basename($configPath)!=='app.php'||basename(dirname($configPath))!=='config'||
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
    $audit=new AuditLogger($pdo);
    $pepper=hash_hmac('sha256','talyvane-email-verification-v1',$config['app_key'],true);
    $rateLimiter=new RateLimiter($pdo,hash_hmac('sha256','talyvane-rate-limiter-v1',$config['app_key'],true));
    $emailVerification=new EmailVerificationService($pdo,$mailer,$rateLimiter,$audit,$pepper);
    $service=new SuperAdminRecoveryService($pdo,$mailer,$audit,$emailVerification,new SecretBox($config['app_key']));
    $context=$service->recoveryContactContext();

    fwrite(STDOUT,"Talyvane — Super Admin recovery ".$action."\n");
    fwrite(STDOUT,"This operation requires fresh verification of both administrator email addresses, the current password and a current authenticator code.\n");
    $password=manageRecoveryHidden("Current Super Admin password (hidden): ");
    $totp=manageRecoveryHidden("Current six-digit authenticator code (hidden): ");
    $startedAt=time();
    $newRecoveryEmail=$action==='enroll'
        ?manageRecoveryVisible("New recovery email address: ")
        :manageRecoveryVisible("Replacement recovery email address: ");
    $newRecoveryEmail=strtolower($newRecoveryEmail);
    if (!filter_var($newRecoveryEmail,FILTER_VALIDATE_EMAIL)||strcasecmp($newRecoveryEmail,$context['primary_email'])===0) {
        throw new InvalidArgumentException('The recovery address must be valid and distinct from the primary administrator address.');
    }
    if ($action==='rotate' && ($context['recovery_email']===null||strcasecmp($newRecoveryEmail,$context['recovery_email'])===0)) {
        throw new InvalidArgumentException('A different, currently enrolled recovery address is required for rotation.');
    }

    manageRecoveryVerificationCode($emailVerification,$context['user_id'],$context['primary_email'],'admin_primary');
    manageRecoveryVerificationCode($emailVerification,$context['user_id'],$newRecoveryEmail,'admin_recovery');
    $primaryCode=manageRecoveryHidden("Verification code sent to primary address (hidden): ");
    $recoveryCode=manageRecoveryHidden("Verification code sent to recovery address (hidden): ");
    if (time()-$startedAt>300) throw new RuntimeException('Email verification window expired. Start the operation again.');
    if (!$emailVerification->verify($context['user_id'],$context['primary_email'],'admin_primary',$primaryCode)||
        !$emailVerification->verify($context['user_id'],$newRecoveryEmail,'admin_recovery',$recoveryCode)) {
        throw new RuntimeException('One or both administrator email verification codes were rejected.');
    }

    $reason=manageRecoveryVisible("Reason (visible, minimum 20 characters): ");
    $requestId=bin2hex(random_bytes(16));
    $newKey=$action==='enroll'
        ?$service->enrollRecovery($password,$totp,$newRecoveryEmail,$reason,$requestId)
        :$service->rotateRecoveryContacts($password,$totp,$newRecoveryEmail,$reason,$requestId);

    fwrite(STDOUT,"\nRecovery ".$action." completed. Save this replacement offline key once, separately from the administrator password:\n\n");
    fwrite(STDOUT,$newKey.PHP_EOL);
    fwrite(STDOUT,"\nThe previous key is invalid. All prior administrator sessions have been invalidated.\n");
    exit(0);
} catch (Throwable) {
    fwrite(STDERR,"\nRecovery management was not completed. No credentials were printed. Verify the private configuration, SMTP service, email codes and administrator state, then retry or contact the approved platform operator.\n");
    exit(1);
}
