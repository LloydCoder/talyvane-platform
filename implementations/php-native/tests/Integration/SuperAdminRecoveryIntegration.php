<?php
declare(strict_types=1);

use Talyvane\Application\Identity\SuperAdminRecoveryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Mail\Mailer;

final class SuperAdminRecoveryTestMailer implements Mailer
{
    /** @var list<array{recipient:string,subject:string,body:string}> */
    public array $messages=[];
    public ?int $failAt=null;

    public function send(string $recipient,string $subject,string $textBody): void
    {
        if ($this->failAt!==null && count($this->messages)+1===$this->failAt) {
            throw new RuntimeException('Simulated mail delivery failure.');
        }
        $this->messages[]=['recipient'=>$recipient,'subject'=>$subject,'body'=>$textBody];
    }
}

function runSuperAdminRecoveryIntegration(PDO $pdo): int
{
    $assertions=0;
    $assert=static function(bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) throw new RuntimeException('Super Admin recovery integration failed: '.$message);
    };
    $uuid=static function(): string {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    };
    $userId=$uuid();
    $primary='super-admin-recovery@example.test';
    $recoveryEmail='super-admin-recovery-contact@example.test';
    $password='current-super-admin-password';
    $offlineKey=str_repeat('A',43);
    $now=gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,remember_token_hash,created_at,updated_at)
         VALUES (:id,:email,:password,"active",UTC_TIMESTAMP(),0,7,:remember,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute([
        'id'=>$userId,'email'=>$primary,'password'=>password_hash($password,PASSWORD_DEFAULT),
        'remember'=>hash('sha256','pre-recovery-remember-token'),
    ]);
    $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:user,"super_admin",NULL,UTC_TIMESTAMP())')
        ->execute(['user'=>$userId]);
    $pdo->prepare(
        'INSERT INTO super_admin_recovery (singleton_id,super_admin_user_id,recovery_email,offline_recovery_key_hash,recovery_version,enrolled_at,updated_at)
         VALUES (1,:user,:email,:key_hash,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute(['user'=>$userId,'email'=>$recoveryEmail,'key_hash'=>password_hash($offlineKey,PASSWORD_DEFAULT)]);
    $pdo->prepare(
        'INSERT INTO admin_mfa_credentials (user_id,encrypted_totp_secret,encryption_key_version,enabled_at,last_accepted_counter,created_at,updated_at)
         VALUES (:user,"test-encrypted-secret","test-key-version",UTC_TIMESTAMP(),-1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute(['user'=>$userId]);
    $pdo->prepare(
        'INSERT INTO admin_mfa_recovery_codes (id,user_id,code_hash,used_at,created_at)
         VALUES (:id,:user,:hash,NULL,UTC_TIMESTAMP())'
    )->execute(['id'=>$uuid(),'user'=>$userId,'hash'=>password_hash('old-mfa-recovery-code',PASSWORD_DEFAULT)]);

    $mailer=new SuperAdminRecoveryTestMailer();
    $service=new SuperAdminRecoveryService($pdo,$mailer,new AuditLogger($pdo));
    $reason='Lost authenticator recovery after device replacement';
    $requestId=bin2hex(random_bytes(16));

    for($attempt=0;$attempt<5;$attempt++) {
        try {
            $service->recover($password,str_repeat('B',43),$reason,$requestId);
            $assert(false,'invalid offline keys must be rejected');
        } catch (DomainException) {
            $assert(true,'invalid offline keys must be rejected');
        }
    }
    try {
        $service->recover($password,$offlineKey,$reason,$requestId);
        $assert(false,'the recovery throttle must block a sixth attempt within the window');
    } catch (DomainException) {
        $assert(true,'the recovery throttle must block a sixth attempt within the window');
    }
    $pdo->prepare('DELETE FROM super_admin_recovery_events WHERE actor_user_id=:user')->execute(['user'=>$userId]);

    $newKey=$service->recover($password,$offlineKey,$reason,bin2hex(random_bytes(16)));
    $user=$pdo->prepare('SELECT admin_session_version,remember_token_hash FROM users WHERE id=:id');
    $user->execute(['id'=>$userId]);
    $userRow=$user->fetch();
    $recovery=$pdo->query('SELECT offline_recovery_key_hash,recovery_version FROM super_admin_recovery WHERE singleton_id=1')->fetch();
    $assert(strlen($newKey)===43 && is_array($recovery) && password_verify($newKey,$recovery['offline_recovery_key_hash']) &&
        !password_verify($offlineKey,$recovery['offline_recovery_key_hash']),
        'successful recovery rotates the offline key and stores only its password hash');
    $assert(is_array($userRow) && (int)$userRow['admin_session_version']===8 && $userRow['remember_token_hash']===null,
        'successful recovery invalidates all existing authenticated and remember sessions');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM admin_mfa_credentials WHERE user_id='.$pdo->quote($userId))->fetchColumn()===0 &&
        (int)$pdo->query('SELECT COUNT(*) FROM admin_mfa_recovery_codes WHERE user_id='.$pdo->quote($userId))->fetchColumn()===0,
        'lost authenticator and all old MFA recovery codes are revoked');
    $assert(count($mailer->messages)===2 &&
        array_column($mailer->messages,'recipient')===[$primary,$recoveryEmail] &&
        !str_contains($mailer->messages[0]['body'],$newKey) && !str_contains($mailer->messages[1]['body'],$newKey),
        'both verified administrator addresses receive notices without receiving the new offline key');
    $event=$pdo->query('SELECT event_key,notification_status,session_version_before,session_version_after FROM super_admin_recovery_events WHERE actor_user_id='.$pdo->quote($userId).' ORDER BY created_at DESC LIMIT 1')->fetch();
    $assert(is_array($event) && $event['event_key']==='mfa_recovered' && $event['notification_status']==='sent' &&
        (int)$event['session_version_before']===7 && (int)$event['session_version_after']===8,
        'successful recovery records an attributable event and session-version transition');

    $pdo->prepare(
        'INSERT INTO admin_mfa_credentials (user_id,encrypted_totp_secret,encryption_key_version,enabled_at,last_accepted_counter,created_at,updated_at)
         VALUES (:user,"test-encrypted-secret","test-key-version",UTC_TIMESTAMP(),-1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    )->execute(['user'=>$userId]);
    $pdo->prepare(
        'INSERT INTO admin_mfa_recovery_codes (id,user_id,code_hash,used_at,created_at)
         VALUES (:id,:user,:hash,NULL,UTC_TIMESTAMP())'
    )->execute(['id'=>$uuid(),'user'=>$userId,'hash'=>password_hash('new-mfa-recovery-code',PASSWORD_DEFAULT)]);
    $mailer->failAt=4;
    $versionBefore=(int)$recovery['recovery_version'];
    try {
        $service->recover($password,$newKey,$reason,bin2hex(random_bytes(16)));
        $assert(false,'recovery must fail closed if either notification cannot be delivered');
    } catch (RuntimeException) {
        $assert(true,'recovery must fail closed if either notification cannot be delivered');
    }
    $recoveryAfter=$pdo->query('SELECT offline_recovery_key_hash,recovery_version FROM super_admin_recovery WHERE singleton_id=1')->fetch();
    $versionAfter=(int)$pdo->query('SELECT admin_session_version FROM users WHERE id='.$pdo->quote($userId))->fetchColumn();
    $assert(is_array($recoveryAfter) && (int)$recoveryAfter['recovery_version']===$versionBefore &&
        password_verify($newKey,$recoveryAfter['offline_recovery_key_hash']) && $versionAfter===8,
        'notification failure rolls back recovery-key rotation and session invalidation');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM admin_mfa_credentials WHERE user_id='.$pdo->quote($userId))->fetchColumn()===1,
        'notification failure leaves the current MFA credential intact');
    $failedEvent=$pdo->query('SELECT event_key,notification_status FROM super_admin_recovery_events WHERE actor_user_id='.$pdo->quote($userId).' ORDER BY created_at DESC LIMIT 1')->fetch();
    $assert(is_array($failedEvent) && $failedEvent['event_key']==='recovery_notification_failed' && $failedEvent['notification_status']==='failed',
        'failed notification delivery is recorded without committing recovery state');

    return $assertions;
}
