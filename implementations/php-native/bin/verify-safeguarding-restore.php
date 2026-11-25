<?php
declare(strict_types=1);

use Talyvane\Application\Safeguarding\SafeguardingCryptoService;
use Talyvane\Application\Safeguarding\SafeguardingRestoreVerificationService;
use Talyvane\Application\Safeguarding\VaultTransitSafeguardingCaseKeyProvider;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\ConnectionFactory;
use Talyvane\Infrastructure\Logging\PrivateErrorLogger;
use Talyvane\Security\Principal;

$root=dirname(__DIR__);
require $root.'/bootstrap.php';
$configPath=getenv('TALYVANE_CONFIG');
if (!is_string($configPath)||$configPath===''||is_link($configPath)||!is_file($configPath)||!is_readable($configPath)) {
    fwrite(STDERR,"Set TALYVANE_CONFIG to the absolute path of the private app.php configuration.\n");exit(64);
}
$real=realpath($configPath);
if ($real===false||(fileperms($real)&0077)!==0) { fwrite(STDERR,"Private application configuration must be canonical and owner-only.\n");exit(64); }
$config=require $real;
$vault=$config['safeguarding']['vault']??null;
if (!is_array($config)||!is_array($config['database']??null)||!is_array($config['paths']??null)||
    !is_string($config['paths']['logs']??null)||!is_array($vault)||($vault['enabled']??false)!==true) {
    fwrite(STDERR,"A complete private configuration with Vault Transit enabled is required.\n");exit(78);
}
try {
    $pdo=ConnectionFactory::mysql($config['database']);
    $actorId=(string)getenv('TALYVANE_RESTORE_VERIFIED_BY');
    $actorQuery=$pdo->prepare('SELECT r.role_key FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.id=:id AND u.status="active" AND r.role_key IN ("owner","super_admin") LIMIT 1');
    $actorQuery->execute(['id'=>$actorId]);$role=$actorQuery->fetchColumn();
    if (!is_string($role)) { throw new RuntimeException('Restore verifier must be an active Owner or Super Admin.'); }
    $provider=new VaultTransitSafeguardingCaseKeyProvider($vault);
    $service=new SafeguardingRestoreVerificationService($pdo,new SafeguardingCryptoService($provider),new AuditLogger($pdo));
    $result=$service->verify(new Principal($actorId,[$role],true),
        (string)getenv('TALYVANE_RESTORE_BACKUP_REFERENCE'),
        (string)getenv('TALYVANE_RESTORE_MANIFEST_SHA256'),
        (string)getenv('TALYVANE_CASE_RETENTION_POLICY_VERSION'),
        bin2hex(random_bytes(16))
    );
    fwrite(STDOUT,sprintf("Safeguarding restore verified: active_keys=%d orphan_keys_destroyed=%d\n",$result['active_keys_verified'],$result['orphan_keys_destroyed']));
} catch (Throwable $error) {
    PrivateErrorLogger::record($error,$config['paths']['logs'],(string)($config['paths']['public']??$root.'/public'),bin2hex(random_bytes(8)));
    fwrite(STDERR,"Safeguarding restore verification failed. Inspect the private application error log.\n");
    exit(1);
}
