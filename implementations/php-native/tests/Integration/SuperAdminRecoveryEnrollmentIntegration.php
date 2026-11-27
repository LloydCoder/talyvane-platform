<?php
declare(strict_types=1);

use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Application\Identity\SuperAdminRecoveryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\SecretBox;
use Talyvane\Security\Totp;

function runSuperAdminRecoveryEnrollmentIntegration(PDO $pdo): int
{
    $assertions=0;
    $assert=static function(bool $ok,string $message) use (&$assertions): void {
        $assertions++;
        if (!$ok) throw new RuntimeException('Super Admin recovery enrollment integration failed: '.$message);
    };

    $recovery=$pdo->query('SELECT super_admin_user_id FROM super_admin_recovery WHERE singleton_id=1')->fetch();
    if (!is_array($recovery)) throw new RuntimeException('Super Admin recovery fixture is missing.');
    $userId=(string)$recovery['super_admin_user_id'];
    $primaryEmail=(string)$pdo->query('SELECT email FROM users WHERE id='.$pdo->quote($userId))->fetchColumn();
    $password='enrollment-super-admin-password';
    $pdo->prepare(
        'UPDATE users SET password_hash=:password,admin_session_version=20,remember_token_hash=:remember,updated_at=UTC_TIMESTAMP() WHERE id=:id'
    )->execute([
        'id'=>$userId,'password'=>password_hash($password,PASSWORD_DEFAULT),
        'remember'=>hash('sha256','recovery-enrollment-remember-token'),
    ]);

    $appKey=str_repeat('R',32);
    $secretBox=new SecretBox($appKey);
    $totpSecret=Totp::generateSecret(20);
    $encrypted=$secretBox->encrypt($totpSecret,'totp:'.$userId);
    $pdo->prepare(
        'UPDATE admin_mfa_credentials SET encrypted_totp_secret=:secret,encryption_key_version="test-key",
         enabled_at=UTC_TIMESTAMP(),last_accepted_counter=-1,updated_at=UTC_TIMESTAMP() WHERE user_id=:user'
    )->execute(['secret'=>$encrypted,'user'=>$userId]);
    $pdo->prepare(
        'UPDATE super_admin_recovery SET recovery_email="",offline_recovery_key_hash="",recovery_version=1,updated_at=UTC_TIMESTAMP()
         WHERE singleton_id=1'
    )->execute();

    $mailer=new SuperAdminRecoveryTestMailer();
    $audit=new AuditLogger($pdo);
    $limiter=new RateLimiter($pdo,hash_hmac('sha256','talyvane-rate-limiter-v1',$appKey,true));
    $emailVerification=new EmailVerificationService(
        $pdo,$mailer,$limiter,$audit,hash_hmac('sha256','talyvane-email-verification-v1',$appKey,true)
    );
    $service=new SuperAdminRecoveryService($pdo,$mailer,$audit,$emailVerification,$secretBox);
    $context=$service->recoveryContactContext();
    $assert($context['user_id']===$userId && $context['primary_email']===$primaryEmail && $context['recovery_email']===null,
        'legacy Super Admin without a recovery record can be identified for enrollment');

    $recoveryEmail='recovery-enroll@example.test';
    $emailVerification->issue($userId,$primaryEmail,'admin_primary');
    $emailVerification->issue($userId,$recoveryEmail,'admin_recovery');
    $assert(!$emailVerification->bothAdminAddressesVerified($userId,$primaryEmail,$recoveryEmail),
        'a historical verified challenge cannot satisfy a newly issued recovery enrollment');
    preg_match('/Your verification code is: ([0-9]{6})/',$mailer->messages[0]['body'],$primaryMatch);
    preg_match('/Your verification code is: ([0-9]{6})/',$mailer->messages[1]['body'],$recoveryMatch);
    $assert(isset($primaryMatch[1],$recoveryMatch[1]),'fresh verification codes are sent to both distinct addresses');
    $assert($emailVerification->verify($userId,$primaryEmail,'admin_primary',$primaryMatch[1]) &&
        $emailVerification->verify($userId,$recoveryEmail,'admin_recovery',$recoveryMatch[1]),
        'fresh primary and recovery email codes are verified');
    $assert($emailVerification->bothAdminAddressesVerified($userId,$primaryEmail,$recoveryEmail),
        'both current email challenges are required and verified before enrollment');

    $enrollReason='Enroll verified offline recovery contacts';
    $enrollKey=$service->enrollRecovery($password,Totp::codeAt($totpSecret,time()),$recoveryEmail,$enrollReason,bin2hex(random_bytes(16)));
    $recoveryRow=$pdo->query('SELECT recovery_email,offline_recovery_key_hash,recovery_version FROM super_admin_recovery WHERE singleton_id=1')->fetch();
    $userRow=$pdo->query('SELECT admin_session_version,remember_token_hash FROM users WHERE id='.$pdo->quote($userId))->fetch();
    $assert(strlen($enrollKey)===43 && is_array($recoveryRow) && $recoveryRow['recovery_email']===$recoveryEmail &&
        password_verify($enrollKey,$recoveryRow['offline_recovery_key_hash']) && (int)$recoveryRow['recovery_version']===2,
        'enrollment stores the verified recovery address and only the replacement offline-key hash');
    $assert(is_array($userRow) && (int)$userRow['admin_session_version']===21 && $userRow['remember_token_hash']===null,
        'enrollment invalidates all prior administrator sessions');
    $enrollNotices=array_slice($mailer->messages,2);
    $assert(array_column($enrollNotices,'recipient')===[$primaryEmail,$recoveryEmail] &&
        !str_contains($enrollNotices[0]['body'],$enrollKey) && !str_contains($enrollNotices[1]['body'],$enrollKey),
        'both verified addresses receive enrollment notices without the offline key');

    $newRecoveryEmail='recovery-rotated@example.test';
    $emailVerification->issue($userId,$primaryEmail,'admin_primary');
    $emailVerification->issue($userId,$newRecoveryEmail,'admin_recovery');
    $assert(!$emailVerification->bothAdminAddressesVerified($userId,$primaryEmail,$newRecoveryEmail),
        'contact rotation requires fresh challenges and does not reuse previous email proof');
    preg_match('/Your verification code is: ([0-9]{6})/',$mailer->messages[4]['body'],$primaryRotationMatch);
    preg_match('/Your verification code is: ([0-9]{6})/',$mailer->messages[5]['body'],$recoveryRotationMatch);
    $assert(isset($primaryRotationMatch[1],$recoveryRotationMatch[1]) &&
        $emailVerification->verify($userId,$primaryEmail,'admin_primary',$primaryRotationMatch[1]) &&
        $emailVerification->verify($userId,$newRecoveryEmail,'admin_recovery',$recoveryRotationMatch[1]),
        'both new contact-rotation email challenges are verified');
    $rotateKey=$service->rotateRecoveryContacts(
        $password,Totp::codeAt($totpSecret,time()+30),$newRecoveryEmail,
        'Rotate verified Super Admin recovery contacts',bin2hex(random_bytes(16))
    );
    $rotated=$pdo->query('SELECT recovery_email,offline_recovery_key_hash,recovery_version FROM super_admin_recovery WHERE singleton_id=1')->fetch();
    $rotatedUser=$pdo->query('SELECT admin_session_version FROM users WHERE id='.$pdo->quote($userId))->fetchColumn();
    $assert(strlen($rotateKey)===43 && is_array($rotated) && $rotated['recovery_email']===$newRecoveryEmail &&
        password_verify($rotateKey,$rotated['offline_recovery_key_hash']) && (int)$rotated['recovery_version']===3,
        'contact rotation replaces the recovery address and offline key atomically');
    $assert((int)$rotatedUser===22 &&
        (int)$pdo->query('SELECT COUNT(*) FROM admin_mfa_credentials WHERE user_id='.$pdo->quote($userId))->fetchColumn()===1,
        'contact rotation invalidates sessions while preserving the authenticator factor');
    $rotateNotices=array_slice($mailer->messages,6);
    $assert(array_column($rotateNotices,'recipient')===[$primaryEmail,$recoveryEmail,$newRecoveryEmail] &&
        !str_contains($rotateNotices[0]['body'],$rotateKey),
        'primary, old recovery and new recovery addresses receive the rotation notice');
    $events=$pdo->query(
        'SELECT COUNT(*) FROM super_admin_recovery_events WHERE actor_user_id='.$pdo->quote($userId).
        ' AND event_key IN ("recovery_credentials_enrolled","recovery_contacts_rotated")'
    )->fetchColumn();
    $assert((int)$events===2,'enrollment and contact rotation are recorded as separate recovery events');

    return $assertions;
}
