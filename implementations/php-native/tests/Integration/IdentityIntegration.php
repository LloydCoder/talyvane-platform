<?php
declare(strict_types=1);

use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Application\Companions\CompanionOnboardingService;
use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Infrastructure\Mail\Mailer;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\PasswordHasher;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;
use Talyvane\Security\SessionManager;
use Talyvane\Security\Totp;
use Talyvane\Installer\SetupKey;

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

    $installerDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'talyvane-installer-' . bin2hex(random_bytes(6));
    mkdir($installerDirectory,0700,true);
    $setupKey = new SetupKey($installerDirectory . DIRECTORY_SEPARATOR . 'installer.key');
    $installerKey = $setupKey->create();
    $mailer = new NativeTestMailer();
    $verification = new EmailVerificationService($pdo,$mailer,$limiter,$audit,str_repeat('E',32));
    $prepared = $verification->prepareSuperAdminBootstrap($setupKey,$installerKey,'native-bootstrap@example.test','native-recovery@example.test');
    $bootstrapId = $prepared['user_id'];
    $primaryCode = preg_match('/code is: ([0-9]{6})/', $mailer->messages[0]['body'], $primaryMatch) ? $primaryMatch[1] : '';
    $recoveryCode = preg_match('/code is: ([0-9]{6})/', $mailer->messages[1]['body'], $recoveryMatch) ? $recoveryMatch[1] : '';
    $assert($primaryCode !== '' && $recoveryCode !== '' && $mailer->messages[0]['recipient'] !== $mailer->messages[1]['recipient'],'dual-address codes are delivered to distinct email addresses');
    $assert($verification->verify($bootstrapId,'native-bootstrap@example.test','admin_primary',$primaryCode),'primary Super Admin email verification succeeds');
    $assert($verification->verify($bootstrapId,'native-recovery@example.test','admin_recovery',$recoveryCode),'recovery Super Admin email verification succeeds');
    $assert($verification->bothAdminAddressesVerified($bootstrapId,'native-bootstrap@example.test','native-recovery@example.test'),'Super Admin activation requires both verified addresses');
    try {
        $verification->activateSuperAdmin($bootstrapId,'native-bootstrap@example.test','native-recovery@example.test',str_repeat('x',43),bin2hex(random_bytes(16)));
        $assert(false,'Super Admin activation rejects invalid installer proof');
    } catch (DomainException) {
        $assert(true,'Super Admin activation rejects invalid installer proof');
    }
    $bootstrapSecrets = $verification->activateSuperAdmin($bootstrapId,'native-bootstrap@example.test','native-recovery@example.test',$prepared['bootstrap_proof'],bin2hex(random_bytes(16)));
    $bootstrapState = $pdo->prepare('SELECT status,first_login_required,email_verified_at,password_hash FROM users WHERE id = :id');
    $bootstrapState->execute(['id'=>$bootstrapId]);
    $state = $bootstrapState->fetch();
    $assert(is_array($state) && $state['status'] === 'active' && (bool) $state['first_login_required'] && $state['email_verified_at'] !== null,'Super Admin activates only after dual email verification and must replace initial password');
    $assert(is_array($state) && password_verify($bootstrapSecrets['initial_password'],(string) $state['password_hash']),'one-time Super Admin password is returned after activation and stored only as a hash');
    $offline = $pdo->query('SELECT offline_recovery_key_hash FROM super_admin_recovery WHERE singleton_id = 1')->fetchColumn();
    $assert(is_string($offline) && password_verify($bootstrapSecrets['offline_recovery_key'],$offline),'offline recovery key is stored only as a password hash');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM super_admin_recovery WHERE singleton_id = 1')->fetchColumn() === 1,'Super Admin offline recovery record is stored once');
    $setupKey->consume();
    rmdir($installerDirectory);

    $bootstrapAuth = new AuthenticationService($pdo,$limiter,$mfa);
    $firstLogin = $bootstrapAuth->verifyPassword('native-bootstrap@example.test',$bootstrapSecrets['initial_password'],'admin','127.0.0.1');
    $assert(($firstLogin['status'] ?? '') === 'first_login_required','new Super Admin must replace the one-time password');
    $bootstrapAuth->beginFirstLoginPasswordChange($firstLogin);
    $replacementPassword = str_repeat('P',24) . '!';
    $passwords = new PasswordChangeService($pdo,$limiter,$audit);
    $setupSecret = $bootstrapAuth->completeFirstLoginPasswordChange($passwords,$bootstrapSecrets['initial_password'],$replacementPassword,bin2hex(random_bytes(16)));
    $setupDecision = $bootstrapAuth->verifyPassword('native-bootstrap@example.test',$replacementPassword,'admin','127.0.0.1');
    $assert(($setupDecision['status'] ?? '') === 'mfa_setup_required','administrator cannot enter the control plane before MFA enrollment');
    $setupCodes = $bootstrapAuth->completeAdminMfaSetup(Totp::codeAt($setupSecret,time()),bin2hex(random_bytes(16)));
    $assert(count($setupCodes) === 10,'mandatory MFA setup returns one-time recovery codes');
    $setupPrincipal = $bootstrapAuth->currentPrincipal();
    $assert($setupPrincipal instanceof Principal && $setupPrincipal->mfaVerified && $setupPrincipal->hasRole('super_admin'),'new Super Admin session is established only after password replacement and MFA');
    $bootstrapAuth->logout();

    $pdo->prepare('INSERT INTO countries (alpha2,alpha3,numeric_code,name,enabled,dataset_version,updated_at) VALUES ("GB","GBR","826","United Kingdom",1,"test-iso-2026",UTC_TIMESTAMP())')->execute();
    $marketInsert = $pdo->prepare('INSERT INTO market_policies (country_code,enabled,decision,reviewer_id,reviewed_at,expires_at,evidence_reference,review_summary,policy_version,updated_at) VALUES ("GB",1,"approved",:reviewer,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY),"legal-review-evidence","Market counsel review completed","market-v1",UTC_TIMESTAMP())');
    $marketInsert->execute(['reviewer'=>$id]);
    $destinationIdBytes = random_bytes(16);
    $destinationIdBytes[6] = chr((ord($destinationIdBytes[6]) & 15) | 64);
    $destinationIdBytes[8] = chr((ord($destinationIdBytes[8]) & 63) | 128);
    $destinationHex = bin2hex($destinationIdBytes);
    $destinationId = substr($destinationHex,0,8).'-'.substr($destinationHex,8,4).'-'.substr($destinationHex,12,4).'-'.substr($destinationHex,16,4).'-'.substr($destinationHex,20);
    $destinationInsert = $pdo->prepare('INSERT INTO destinations (id,slug,country_code,city_name,display_name,currency_code,locale,time_zone,status,created_at,updated_at) VALUES (:id,"london","GB","London","London",NULL,"en-GB","Europe/London","published",UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $destinationInsert->execute(['id'=>$destinationId]);
    $companionBytes = random_bytes(16);
    $companionBytes[6] = chr((ord($companionBytes[6]) & 15) | 64);
    $companionBytes[8] = chr((ord($companionBytes[8]) & 63) | 128);
    $companionHex = bin2hex($companionBytes);
    $companionId = substr($companionHex,0,8).'-'.substr($companionHex,8,4).'-'.substr($companionHex,12,4).'-'.substr($companionHex,16,4).'-'.substr($companionHex,20);
    $companionInsert = $pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,"native-companion@example.test",:hash,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $companionInsert->execute(['id'=>$companionId,'hash'=>PasswordHasher::hash($password)]);
    $role->execute(['user'=>$companionId,'role'=>'companion']);
    $role->execute(['user'=>$id,'role'=>'verifier']);
    $verifier = new Principal($id,['super_admin','verifier'],true);
    $onboarding = new CompanionOnboardingService($pdo,$audit);
    $profileId = $onboarding->createVerifiedProfile($verifier,$companionId,$destinationId,'Verified Talent','Biography supplied by the companion',['en','fr'],'representation',hash('sha256','provider-verification-reference'),true,'consent-v1','consent-evidence-reference-001',bin2hex(random_bytes(16)));
    $profileQuery = $pdo->prepare('SELECT status,adult_eligibility_status,auto_booking_opt_in,adult_verified_by FROM companion_profiles WHERE id = :id');
    $profileQuery->execute(['id'=>$profileId]);
    $profile = $profileQuery->fetch();
    $assert(is_array($profile) && $profile['status'] === 'pending_review' && $profile['adult_eligibility_status'] === 'verified','companion profile requires independent age assurance and remains pending human publication review');
    $assert(is_array($profile) && !(bool) $profile['auto_booking_opt_in'] && $profile['adult_verified_by'] === $id,'companion onboarding never enables automatic booking and records verifier');
    $consentCount = $pdo->prepare('SELECT COUNT(*) FROM consent_grants WHERE subject_type = "companion_profile" AND subject_id = :profile AND purpose = "profile.publication" AND audience = "public" AND granted = 1');
    $consentCount->execute(['profile'=>$profileId]);
    $assert((int) $consentCount->fetchColumn() === 1,'public profile requires explicit versioned publication consent');

    $clientBytes = random_bytes(16);
    $clientBytes[6] = chr((ord($clientBytes[6]) & 15) | 64);
    $clientBytes[8] = chr((ord($clientBytes[8]) & 63) | 128);
    $clientHex = bin2hex($clientBytes);
    $clientId = substr($clientHex,0,8).'-'.substr($clientHex,8,4).'-'.substr($clientHex,12,4).'-'.substr($clientHex,16,4).'-'.substr($clientHex,20);
    $clientInsert = $pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:hash,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $clientInsert->execute(['id'=>$clientId,'email'=>'native-client@example.test','hash'=>PasswordHasher::hash($password)]);
    $clientRole = $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,"client",NULL,UTC_TIMESTAMP())');
    $clientRole->execute(['id'=>$clientId]);
    $clientAdminDecision = $bootstrapAuth->verifyPassword('native-client@example.test',$password,'admin','127.0.0.1');
    $assert(($clientAdminDecision['status'] ?? '') === 'invalid_credentials','client identity cannot authenticate into the administration context');
    $clientDecision = $bootstrapAuth->verifyPassword('native-client@example.test',$password,'portal','127.0.0.1');
    $assert(($clientDecision['status'] ?? '') === 'authenticated','client identity can authenticate into the client portal');
    $bootstrapAuth->establishPortalSession($clientDecision);
    $clientPrincipal = $bootstrapAuth->currentPrincipal();
    $assert($clientPrincipal instanceof Principal && $clientPrincipal->hasRole('client') && !$clientPrincipal->mfaVerified,'client session remains separate from privileged administration MFA');
    $bootstrapAuth->logout();
    return $assertions;
}
