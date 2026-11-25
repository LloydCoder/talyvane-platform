<?php
declare(strict_types=1);

use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\PasswordHasher;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;
use Talyvane\Security\SessionManager;
use Talyvane\Security\Totp;

final class NativeTestMailer implements Mailer
{
    public array $messages = [];
    public function send(string $recipient, string $subject, string $textBody): void
    {
        $this->messages[] = ['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
    }
}

function runIdentityIntegration(PDO $pdo): int
{
    $assertions = 0;
    $assert = static function (bool $ok, string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) {
            throw new RuntimeException('Identity integration failed: ' . $message);
        }
    };
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $hex = bin2hex($bytes);
    $id = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    $password = str_repeat('T',24) . '!';
    $create = $pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:hash,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $create->execute(['id'=>$id,'email'=>'native-admin@example.test','hash'=>PasswordHasher::hash($password)]);
    $role = $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,:role,NULL,UTC_TIMESTAMP())');
    $role->execute(['id'=>$id,'role'=>'super_admin']);
    $audit = new AuditLogger($pdo);
    $mfa = new MfaService($pdo,new SecretBox(str_repeat('K',32)),$audit);
    $actor = new Principal($id,['super_admin'],false);
    $secret = $mfa->beginEnrollment($actor);
    $codes = $mfa->confirmEnrollment($actor,Totp::codeAt($secret,time()),bin2hex(random_bytes(16)));
    $assert(count($codes) === 10,'enrollment issues one-time recovery codes');

    $limiter = new RateLimiter($pdo,str_repeat('R',32));
    $auth = new AuthenticationService($pdo,$limiter,$mfa);
    $decision = $auth->verifyPassword('native-admin@example.test',$password,'admin','127.0.0.1');
    $assert(($decision['status'] ?? '') === 'mfa_required','admin password step requires MFA');
    $_SERVER['HTTPS'] = 'on';
    SessionManager::start();
    $auth->beginAdminMfaChallenge($decision);
    $code = Totp::codeAt($secret,(intdiv(time(),30)+1)*30);
    $assert($auth->completeAdminMfa($code),'admin session requires and accepts valid TOTP');
    $assert(!$mfa->verifyChallenge($id,$code),'TOTP replay is rejected');
    $principal = $auth->currentPrincipal();
    $assert($principal instanceof Principal && $principal->mfaVerified,'principal is resolved with verified MFA');
    $assert($mfa->consumeRecoveryCode($id,$codes[0],bin2hex(random_bytes(16))),'recovery code is consumed');
    $assert(!$mfa->consumeRecoveryCode($id,$codes[0],bin2hex(random_bytes(16))),'recovery code cannot be reused');
    $auth->logout();

    $bootstrapIdBytes = random_bytes(16);
    $bootstrapIdBytes[6] = chr((ord($bootstrapIdBytes[6]) & 15) | 64);
    $bootstrapIdBytes[8] = chr((ord($bootstrapIdBytes[8]) & 63) | 128);
    $bootstrapHex = bin2hex($bootstrapIdBytes);
    $bootstrapId = substr($bootstrapHex,0,8).'-'.substr($bootstrapHex,8,4).'-'.substr($bootstrapHex,12,4).'-'.substr($bootstrapHex,16,4).'-'.substr($bootstrapHex,20);
    $pending = $pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:hash,"pending",NULL,1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $pending->execute(['id'=>$bootstrapId,'email'=>'native-bootstrap@example.test','hash'=>PasswordHasher::hash($password)]);
    $mailer = new NativeTestMailer();
    $verification = new EmailVerificationService($pdo,$mailer,$limiter,$audit,str_repeat('E',32));
    $verification->issue($bootstrapId,'native-bootstrap@example.test','admin_primary');
    $verification->issue($bootstrapId,'native-recovery@example.test','admin_recovery');
    $primaryCode = preg_match('/code is: ([0-9]{6})/', $mailer->messages[0]['body'], $primaryMatch) ? $primaryMatch[1] : '';
    $recoveryCode = preg_match('/code is: ([0-9]{6})/', $mailer->messages[1]['body'], $recoveryMatch) ? $recoveryMatch[1] : '';
    $assert($primaryCode !== '' && $recoveryCode !== '' && $mailer->messages[0]['recipient'] !== $mailer->messages[1]['recipient'],'dual-address codes are delivered to distinct email addresses');
    $assert($verification->verify($bootstrapId,'native-bootstrap@example.test','admin_primary',$primaryCode),'primary Super Admin email verification succeeds');
    $assert($verification->verify($bootstrapId,'native-recovery@example.test','admin_recovery',$recoveryCode),'recovery Super Admin email verification succeeds');
    $assert($verification->bothAdminAddressesVerified($bootstrapId,'native-bootstrap@example.test','native-recovery@example.test'),'Super Admin activation requires both verified addresses');
    $offlineKey = str_repeat('O',40);
    $verification->activateSuperAdmin($bootstrapId,'native-bootstrap@example.test','native-recovery@example.test',$offlineKey,bin2hex(random_bytes(16)));
    $bootstrapState = $pdo->prepare('SELECT status,first_login_required,email_verified_at FROM users WHERE id = :id');
    $bootstrapState->execute(['id'=>$bootstrapId]);
    $state = $bootstrapState->fetch();
    $assert(is_array($state) && $state['status'] === 'active' && (bool) $state['first_login_required'] && $state['email_verified_at'] !== null,'Super Admin activates only after dual email verification and must replace initial password');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM super_admin_recovery WHERE singleton_id = 1')->fetchColumn() === 1,'Super Admin offline recovery record is stored once');
    return $assertions;
}
