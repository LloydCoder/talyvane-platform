<?php
declare(strict_types=1);

use Talyvane\Application\Identity\AccountRecoveryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Application\Identity\UserRegistrationService;
use Talyvane\Application\Companions\CompanionProfilePublicationService;
use Talyvane\Application\Companions\CompanionOnboardingService;
use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Application\Destinations\DestinationManagementService;
use Talyvane\Application\Globalization\MarketPolicyManagementService;
use Talyvane\Application\Bookings\BookingService;
use Talyvane\Application\Bookings\BookingCommandService;
use Talyvane\Application\Bookings\BookingQueryService;
use Talyvane\Infrastructure\Database\BookingPolicyRepository;
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
    for ($attempt = 0; $attempt < 11; $attempt++) {
        $limiter->consume('auth.admin','limited-admin@example.test|127.0.0.2',10,60,900);
    }
    $limited = $auth->verifyPassword('limited-admin@example.test',$password,'admin','127.0.0.2');
    $assert(($limited['status'] ?? '') === 'rate_limited','rate-limited authentication returns a controlled decision without fatal errors');
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

    $clientId = (string) $pdo->query('SELECT UUID()')->fetchColumn();
    $clientPassword = str_repeat('C',24) . '!';
    $clientCreate = $pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,:email,:hash,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $clientCreate->execute(['id'=>$clientId,'email'=>'native-password-client@example.test','hash'=>PasswordHasher::hash($clientPassword)]);
    $clientRole = $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,"client",NULL,UTC_TIMESTAMP())');
    $clientRole->execute(['id'=>$clientId]);
    $passwordChanges = new PasswordChangeService($pdo,$limiter,$audit);
    $newClientPassword = str_repeat('N',24) . '!';
    $newSessionVersion = $passwordChanges->changePortalPassword($clientId,$clientPassword,$newClientPassword,bin2hex(random_bytes(16)));
    $clientState = $pdo->prepare('SELECT password_hash,admin_session_version FROM users WHERE id=:id');
    $clientState->execute(['id'=>$clientId]);
    $clientStateRow = $clientState->fetch();
    $assert($newSessionVersion === 2 && is_array($clientStateRow) && (int) $clientStateRow['admin_session_version'] === 2 &&
        password_verify($newClientPassword,$clientStateRow['password_hash']) && !password_verify($clientPassword,$clientStateRow['password_hash']),
        'client password change updates the hash and revokes other sessions');
    $clientAudit = $pdo->prepare('SELECT event_key FROM audit_events WHERE actor_user_id=:id AND event_key="portal.password.changed"');
    $clientAudit->execute(['id'=>$clientId]);
    $assert($clientAudit->fetchColumn() === 'portal.password.changed','client password change emits an audit event');
    $adminPasswordChangeDenied = false;
    try { $passwordChanges->changePortalPassword($id,$password,$newClientPassword,bin2hex(random_bytes(16))); }
    catch (DomainException) { $adminPasswordChangeDenied = true; }
    $assert($adminPasswordChangeDenied,'portal password self-service cannot change privileged administrator credentials');

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

    $pdo->prepare('INSERT INTO countries (alpha2,alpha3,numeric_code,name,enabled,dataset_version,updated_at) VALUES ("GB","GBR","826","United Kingdom",1,"test-iso-2026",UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,updated_at=UTC_TIMESTAMP()')->execute();
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
    $role->execute(['id'=>$companionId,'role'=>'companion']);
    $role->execute(['id'=>$id,'role'=>'verifier']);
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

    $registrationMailer = new NativeTestMailer();
    $registrationVerification = new EmailVerificationService($pdo,$registrationMailer,$limiter,$audit,str_repeat('V',32));
    $registration = new UserRegistrationService($pdo,$registrationVerification,$limiter,$audit);
    $registeredId = $registration->register('native-registered-client@example.test',$password,'client','127.0.0.1',bin2hex(random_bytes(16)));
    $registrationCode = preg_match('/code is: ([0-9]{6})/', $registrationMailer->messages[0]['body'], $registrationMatch) ? $registrationMatch[1] : '';
    $assert($registrationCode !== '' && $registrationVerification->verify($registeredId,'native-registered-client@example.test','account_primary',$registrationCode),'client registration sends and verifies a primary email challenge');
    $registeredState = $pdo->prepare('SELECT status,email_verified_at FROM users WHERE id = :id');
    $registeredState->execute(['id'=>$registeredId]);
    $registered = $registeredState->fetch();
    $assert(is_array($registered) && $registered['status'] === 'active' && $registered['email_verified_at'] !== null,'client account activates only after email verification');
    $recovery = new AccountRecoveryService($pdo,$registrationVerification,$limiter,$audit);
    $recovery->request('native-bootstrap@example.test');
    $assert(count($registrationMailer->messages) === 1,'public portal password recovery never sends recovery codes to privileged administrator accounts');
    $recovery->request('native-registered-client@example.test');
    $recoveryCode = preg_match('/code is: ([0-9]{6})/', $registrationMailer->messages[1]['body'], $recoveryMatch) ? $recoveryMatch[1] : '';
    $recoveredPassword = str_repeat('R',24) . '!';
    $assert($recoveryCode !== '' && $recovery->complete('native-registered-client@example.test',$recoveryCode,$recoveredPassword,bin2hex(random_bytes(16))),'verified primary email can complete account password recovery');
    $recoveredState = $pdo->prepare('SELECT password_hash,admin_session_version FROM users WHERE id = :id');
    $recoveredState->execute(['id'=>$registeredId]);
    $recovered = $recoveredState->fetch();
    $assert(is_array($recovered) && password_verify($recoveredPassword,(string) $recovered['password_hash']),'account recovery stores the new password as a secure hash');
    $assert(is_array($recovered) && (int) $recovered['admin_session_version'] === 2,'account recovery revokes existing sessions by incrementing the session version');
    $assert(is_array($recovered) && !password_verify($password,(string) $recovered['password_hash']),'recovered account no longer accepts the previous password');
    try { $registration->register('native-forbidden-admin@example.test',$password,'super_admin','127.0.0.1',bin2hex(random_bytes(16))); $assert(false,'public registration cannot create privileged roles'); }
    catch (InvalidArgumentException) { $assert(true,'public registration cannot create privileged roles'); }
    $publication = new CompanionProfilePublicationService($pdo,$audit);
    $publisher = new Principal($id,['super_admin','verifier'],true);
    $publication->publish($publisher,$profileId,'Independent adult verification and current publication consent reviewed',bin2hex(random_bytes(16)));
    $discovery = new DiscoveryService($pdo);
    $publicDestinations = $discovery->destinations();
    $publicProfiles = $discovery->profiles('london');
    $allPublicProfiles = $discovery->profiles(null);
    $profileLookup = $pdo->prepare('SELECT slug FROM companion_profiles WHERE id = :id');
    $profileLookup->execute(['id'=>$profileId]);
    $publicSlug = (string) $profileLookup->fetchColumn();
    $publicProfile = $discovery->profile($publicSlug);
    $favorites = new \Talyvane\Application\Clients\ClientFavoriteService($pdo,$audit,$discovery);
    $clientFavoritePrincipal = new Principal($registeredId,['client'],false);
    $favorites->add($clientFavoritePrincipal,$publicSlug,bin2hex(random_bytes(16)));
    $favoriteRows = $favorites->list($clientFavoritePrincipal);
    $assert(count($favoriteRows) === 1 && $favoriteRows[0]['slug'] === $publicSlug,
        'client can save a currently public profile to a private shortlist');
    $favorites->remove($clientFavoritePrincipal,$publicSlug,bin2hex(random_bytes(16)));
    $assert($favorites->list($clientFavoritePrincipal) === [],
        'client can remove a profile from their private shortlist');
    try { $favorites->add(new Principal($companionId,['companion'],false),$publicSlug,bin2hex(random_bytes(16))); $assert(false,'companion cannot use client favorites'); }
    catch (DomainException) { $assert(true,'companion cannot use client favorites'); }
    $assert(count($publicDestinations) === 1 && $publicDestinations[0]['slug'] === 'london','public discovery lists only destinations in currently approved markets');
    $assert(count($publicProfiles) === 1 && is_array($publicProfile),'public discovery exposes independently verified and consented profiles');
    $assert(count($allPublicProfiles) === 1 && $allPublicProfiles[0]['slug'] === $publicSlug,'global companion discovery exposes only currently verified and consented public profiles');
    $assert(is_array($publicProfile) && $publicProfile['time_zone'] === 'Europe/London',
        'public profile projection includes the destination IANA time zone without undefined-field warnings');
    $assert(is_array($publicProfile) && !array_key_exists('user_id',$publicProfile) && !array_key_exists('verification_reference_hash',$publicProfile),'public profile projection excludes account identifiers and verification evidence');
    $pdo->prepare('INSERT INTO currencies (code,numeric_code,name,symbol,minor_units,enabled,dataset_version,updated_at) VALUES ("GBP","826","Pound sterling","£",2,1,"test-iso-2026",UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,updated_at=UTC_TIMESTAMP()')->execute();
    $destinationManagement = new DestinationManagementService($pdo,$audit);
    $newDestinationId = $destinationManagement->save($publisher,null,[
        'slug'=>'manchester','country_code'=>'GB','city_name'=>'Manchester',
        'display_name'=>'Manchester','currency_code'=>'GBP','locale'=>'en-GB',
        'time_zone'=>'Europe/London','status'=>'published',
    ],bin2hex(random_bytes(16)));
    $managedDestinations = $destinationManagement->list($publisher);
    $manchesterRows = array_values(array_filter($managedDestinations,static fn(array $row): bool => $row['id'] === $newDestinationId));
    $assert(count($manchesterRows) === 1 && $manchesterRows[0]['status'] === 'published' && $manchesterRows[0]['market_decision'] === 'approved',
        'MFA-protected administrator can publish a destination only inside a currently approved market');
    $destinationManagement->save($publisher,$newDestinationId,[
        'slug'=>'manchester','country_code'=>'GB','city_name'=>'Manchester',
        'display_name'=>'Greater Manchester','currency_code'=>'GBP','locale'=>'en-GB',
        'time_zone'=>'Europe/London','status'=>'published',
    ],bin2hex(random_bytes(16)));
    $updatedDestination = $pdo->prepare('SELECT display_name FROM destinations WHERE id = :id');
    $updatedDestination->execute(['id'=>$newDestinationId]);
    $assert($updatedDestination->fetchColumn() === 'Greater Manchester','destination update uses owner-scoped identity and preserves market publication gates');
    $pdo->prepare('INSERT INTO countries (alpha2,alpha3,numeric_code,name,enabled,dataset_version,updated_at) VALUES ("DE","DEU","276","Germany",1,"test-iso-2026",UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,updated_at=UTC_TIMESTAMP()')->execute();
    $pdo->prepare('INSERT INTO currencies (code,numeric_code,name,symbol,minor_units,enabled,dataset_version,updated_at) VALUES ("EUR","978","Euro","€",2,1,"test-iso-2026",UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enabled=1,updated_at=UTC_TIMESTAMP()')->execute();
    try { $destinationManagement->save($publisher,null,['slug'=>'berlin','country_code'=>'DE','city_name'=>'Berlin','display_name'=>'Berlin','currency_code'=>'EUR','locale'=>'de-DE','time_zone'=>'Europe/Berlin','status'=>'published'],bin2hex(random_bytes(16))); $assert(false,'destination cannot publish without market compliance approval'); }
    catch (DomainException) { $assert(true,'destination cannot publish without market compliance approval'); }
    $draftDestinationId = $destinationManagement->save($publisher,null,['slug'=>'berlin','country_code'=>'DE','city_name'=>'Berlin','display_name'=>'Berlin','currency_code'=>'EUR','locale'=>'de-DE','time_zone'=>'Europe/Berlin','status'=>'draft'],bin2hex(random_bytes(16)));
    $draftDestination = $pdo->prepare('SELECT status FROM destinations WHERE id = :id');
    $draftDestination->execute(['id'=>$draftDestinationId]);
    $assert($draftDestination->fetchColumn() === 'draft','destination can be prepared as a private draft before market approval');
    $marketManagement = new MarketPolicyManagementService($pdo,$audit);
    $marketManagement->review($publisher,'DE','approved','de-legal-review-evidence-2026','Jurisdiction-specific legal review and operating-model assessment completed.','2027-11-25',true,bin2hex(random_bytes(16)));
    $destinationManagement->save($publisher,$draftDestinationId,['slug'=>'berlin','country_code'=>'DE','city_name'=>'Berlin','display_name'=>'Berlin','currency_code'=>'EUR','locale'=>'de-DE','time_zone'=>'Europe/Berlin','status'=>'published'],bin2hex(random_bytes(16)));
    $publishedBerlin = $pdo->prepare('SELECT status FROM destinations WHERE id = :id');
    $publishedBerlin->execute(['id'=>$draftDestinationId]);
    $assert($publishedBerlin->fetchColumn() === 'published','destination publication succeeds only after an evidence-backed Super Admin market approval');
    $coverRoot=sys_get_temp_dir().DIRECTORY_SEPARATOR.'talyvane-cover-private-'.bin2hex(random_bytes(6));
    $coverPublic=$coverRoot.DIRECTORY_SEPARATOR.'public';
    $coverPrivate=$coverRoot.DIRECTORY_SEPARATOR.'private';
    mkdir($coverPublic,0755,true);
    $coverStorage=new \Talyvane\Infrastructure\Storage\PrivateMediaStorage($coverPrivate,$coverPublic);
    $coverService=new \Talyvane\Application\Destinations\DestinationCoverService($pdo,$coverStorage,$audit);
    $assert($coverService->pending($principal)===[],'destination cover review queue starts empty');
    $coverDenied=false;
    try { $coverService->pending($clientPrincipal); } catch (DomainException) { $coverDenied=true; }
    $assert($coverDenied,'client cannot inspect destination cover moderation queue');
    $coverFilename=bin2hex(random_bytes(16)).'.webp';
    $coverDirectory=$coverPrivate.DIRECTORY_SEPARATOR.'destination-covers';
    mkdir($coverDirectory,0700,true);
    $coverImage=imagecreatetruecolor(320,320);
    imagefilledrectangle($coverImage,0,0,319,319,imagecolorallocate($coverImage,40,60,80));
    $coverPath=$coverDirectory.DIRECTORY_SEPARATOR.$coverFilename;
    $assert(imagewebp($coverImage,$coverPath,80),'integration fixture creates a sanitized WebP destination cover');
    imagedestroy($coverImage);
    chmod($coverPath,0600);
    $coverKey='destination-covers/'.$coverFilename;
    $coverHash=hash_file('sha256',$coverPath);
    $coverAssetId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO destination_cover_assets
         (id,destination_id,uploaded_by,private_storage_key,mime_type,file_size,sha256,
          rights_declaration_version,rights_statement_hash,rights_confirmed_at,review_status,created_at)
         VALUES (:id,:destination,:uploader,:key,"image/webp",:size,:hash,:version,:statement_hash,UTC_TIMESTAMP(),"pending",UTC_TIMESTAMP())'
    )->execute([
        'id'=>$coverAssetId,'destination'=>$newDestinationId,'uploader'=>$clientId,'key'=>$coverKey,
        'size'=>filesize($coverPath),'hash'=>$coverHash,
        'version'=>\Talyvane\Application\Destinations\DestinationCoverService::RIGHTS_VERSION,
        'statement_hash'=>hash('sha256',\Talyvane\Application\Destinations\DestinationCoverService::RIGHTS_STATEMENT),
    ]);
    $coverSelfApprovalDenied=false;
    try { $coverService->review(new Principal($clientId,['owner'],true),$coverAssetId,'approved','Review attempt from uploader account',bin2hex(random_bytes(16))); }
    catch (DomainException) { $coverSelfApprovalDenied=true; }
    $assert($coverSelfApprovalDenied,'destination cover uploader cannot approve their own asset');
    $coverService->review($principal,$coverAssetId,'approved','Rights evidence and image integrity reviewed by independent administrator',bin2hex(random_bytes(16)));
    $approvedCover=$coverService->publicCover('manchester');
    $coverState=$pdo->prepare('SELECT cover_media_key,rights_declaration_version FROM destinations WHERE id=:id');
    $coverState->execute(['id'=>$newDestinationId]);
    $coverMetadata=$coverState->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($coverMetadata) && $coverMetadata['cover_media_key']===$coverKey &&
        $coverMetadata['rights_declaration_version']===\Talyvane\Application\Destinations\DestinationCoverService::RIGHTS_VERSION,
        'independent approval binds the exact rights-attested private asset to the destination');
    $assert(is_array($approvedCover) && $approvedCover['mime']==='image/webp' &&
        hash_equals($coverHash,hash('sha256',$approvedCover['bytes'])),
        'public destination cover delivery requires a published market-approved destination and verified approved asset');
    $coverService->withdraw($principal,$coverAssetId,'Destination rights owner requested removal of the approved cover image',bin2hex(random_bytes(16)));
    $assert($coverService->publicCover('manchester')===null && !is_file($coverPath),
        'cover withdrawal immediately removes public delivery and deletes private image bytes');
    $coverPointer=$pdo->prepare('SELECT cover_media_key FROM destinations WHERE id=:id');
    $coverPointer->execute(['id'=>$newDestinationId]);
    $assert($coverPointer->fetchColumn()===null,'cover withdrawal clears the destination public-media pointer');
    rmdir($coverDirectory);
    rmdir($coverPrivate);
    rmdir($coverPublic);
    rmdir($coverRoot);
    try { $marketManagement->review(new Principal($registeredId,['client'],false),'DE','rejected','client-evidence-reference','This client must not be allowed to record a compliance decision.','2027-11-25',false,bin2hex(random_bytes(16))); $assert(false,'client cannot record market compliance decisions'); }
    catch (DomainException) { $assert(true,'client cannot record market compliance decisions'); }
    try { $destinationManagement->save(new Principal($registeredId,['client'],false),null,['slug'=>'client-city','country_code'=>'GB','city_name'=>'Leeds','display_name'=>'Leeds','currency_code'=>'GBP','locale'=>'en-GB','time_zone'=>'Europe/London','status'=>'draft'],bin2hex(random_bytes(16))); $assert(false,'client cannot create destinations'); }
    catch (DomainException) { $assert(true,'client cannot create destinations'); }
    $pdo->prepare('UPDATE consent_grants SET withdrawn_at = UTC_TIMESTAMP() WHERE subject_type = "companion_profile" AND subject_id = :profile AND purpose = "profile.publication" AND audience = "public"')->execute(['profile'=>$profileId]);
    $assert($discovery->profile($publicSlug) === null,'public profile immediately disappears when publication consent is withdrawn');
    $pdo->prepare('UPDATE consent_grants SET withdrawn_at = NULL WHERE subject_type = "companion_profile" AND subject_id = :profile AND purpose = "profile.publication" AND audience = "public"')->execute(['profile'=>$profileId]);
    $pdo->prepare('UPDATE companion_profiles SET publication_consent_withdrawn_at = NULL WHERE id = :profile')->execute(['profile'=>$profileId]);
    $localBookingStart = new DateTimeImmutable('tomorrow 10:00:00',new DateTimeZone('Europe/London'));
    $localDate = $localBookingStart->format('Y-m-d');
    $availabilityBytes = random_bytes(16);
    $availabilityBytes[6] = chr((ord($availabilityBytes[6]) & 15) | 64);
    $availabilityBytes[8] = chr((ord($availabilityBytes[8]) & 63) | 128);
    $availabilityHex = bin2hex($availabilityBytes);
    $availabilityId = substr($availabilityHex,0,8).'-'.substr($availabilityHex,8,4).'-'.substr($availabilityHex,12,4).'-'.substr($availabilityHex,16,4).'-'.substr($availabilityHex,20);
    $availabilityInsert = $pdo->prepare('INSERT INTO companion_availability (id,profile_id,weekday,local_start,local_end,time_zone,effective_from,effective_until,enabled,created_at) VALUES (:id,:profile,:weekday,"00:00:00","23:59:59","Europe/London",:date,NULL,1,UTC_TIMESTAMP())');
    $availabilityInsert->execute(['id'=>$availabilityId,'profile'=>$profileId,'weekday'=>(int) $localBookingStart->format('w'),'date'=>$localDate]);
    $availabilityService = new \Talyvane\Application\Companions\CompanionAvailabilityService($pdo,$audit);
    $companionPrincipal = new Principal($companionId,['companion'],false);
    $weekday = ((int) $localBookingStart->format('w') + 1) % 7;
    $availabilityRuleId = $availabilityService->save($companionPrincipal,$profileId,$weekday,'09:00','11:30',$localDate,null,bin2hex(random_bytes(16)));
    $availabilityRows = $availabilityService->listForCompanion($companionPrincipal);
    $savedAvailability = array_values(array_filter($availabilityRows,static fn(array $row): bool => $row['id'] === $availabilityRuleId));
    $assert(count($savedAvailability) === 1 && $savedAvailability[0]['local_start'] === '09:00:00' && $savedAvailability[0]['time_zone'] === 'Europe/London',
        'companion can create a weekly availability window using the profile destination time zone');
    try { $availabilityService->save($companionPrincipal,$profileId,$weekday,'10:00','12:00',$localDate,null,bin2hex(random_bytes(16))); $assert(false,'overlapping companion availability windows are rejected'); }
    catch (DomainException) { $assert(true,'overlapping companion availability windows are rejected'); }
    try { $availabilityService->save(new Principal($registeredId,['companion'],false),$profileId,$weekday,'12:00','13:00',$localDate,null,bin2hex(random_bytes(16))); $assert(false,'a different account cannot manage another companion profile'); }
    catch (DomainException) { $assert(true,'a different account cannot manage another companion profile'); }
    $availabilityService->delete($companionPrincipal,$profileId,$availabilityRuleId,bin2hex(random_bytes(16)));
    $afterAvailabilityDelete = array_values(array_filter($availabilityService->listForCompanion($companionPrincipal),static fn(array $row): bool => $row['id'] === $availabilityRuleId));
    $assert($afterAvailabilityDelete === [],'companion can remove only their own availability window');
    try { $availabilityService->save(new Principal($registeredId,['client'],false),$profileId,$weekday,'12:00','13:00',$localDate,null,bin2hex(random_bytes(16))); $assert(false,'client cannot mutate companion availability'); }
    catch (DomainException) { $assert(true,'client cannot mutate companion availability'); }
    $clientPrincipal = new Principal($registeredId,['client'],false);
    $bookingService = new BookingService($pdo,new BookingPolicyRepository($pdo),$audit);
    $manualBookingId = $bookingService->request($clientPrincipal,$profileId,$localBookingStart->setTimezone(new DateTimeZone('UTC')),$localBookingStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,'Manual booking integration test',bin2hex(random_bytes(16)));
    $manualState = $pdo->prepare('SELECT status,booking_mode,deposit_status FROM bookings WHERE id = :id');
    $manualState->execute(['id'=>$manualBookingId]);
    $manual = $manualState->fetch();
    $assert(is_array($manual) && $manual['status'] === 'requested' && $manual['booking_mode'] === 'manual','booking requests default to manual review unless all automatic gates pass');
    try {
        $bookingService->request($clientPrincipal,$profileId,$localBookingStart->setTimezone(new DateTimeZone('UTC')),$localBookingStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,null,bin2hex(random_bytes(16)));
        $assert(false,'overlapping booking request is rejected');
    } catch (DomainException) {
        $assert(true,'overlapping booking request is rejected');
    }
    $pdo->prepare('UPDATE companion_profiles SET auto_booking_opt_in = 1 WHERE id = :id')->execute(['id'=>$profileId]);
    $bookingRules = json_encode(['automatic_enabled'=>true,'deposit_required'=>false,'minimum_notice_minutes'=>60,'maximum_advance_days'=>90,'minimum_duration_minutes'=>60,'maximum_duration_minutes'=>480],JSON_THROW_ON_ERROR);
    $settings = $pdo->prepare('INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at) VALUES ("booking.rules",:settings,1,:actor,UTC_TIMESTAMP())');
    $settings->execute(['settings'=>$bookingRules,'actor'=>$id]);
    $pdo->prepare('INSERT INTO feature_states (feature_key,enabled,config_json,updated_by,updated_at) VALUES ("bookings.automatic",1,NULL,:actor,UTC_TIMESTAMP())')->execute(['actor'=>$id]);
    $autoStart = $localBookingStart->modify('+2 hours');
    $autoBookingId = $bookingService->request($clientPrincipal,$profileId,$autoStart->setTimezone(new DateTimeZone('UTC')),$autoStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,null,bin2hex(random_bytes(16)));
    $autoState = $pdo->prepare('SELECT status,booking_mode,companion_opt_in_snapshot FROM bookings WHERE id = :id');
    $autoState->execute(['id'=>$autoBookingId]);
    $automatic = $autoState->fetch();
    $assert(is_array($automatic) && $automatic['status'] === 'requested' && $automatic['booking_mode'] === 'manual' && (bool) $automatic['companion_opt_in_snapshot'],'automatic booking remains manual until deployment feature, provider, legal and safeguarding prerequisites are operational');

    $bookingCommands = new BookingCommandService($pdo,new BookingPolicyRepository($pdo),$audit);
    $superAdmin = new Principal($id,['super_admin'],true);
    $companionPrincipal = new Principal($companionId,['companion'],false);
    $bookingCommands->transition($companionPrincipal,$manualBookingId,'accepted','Companion accepted the requested booking',bin2hex(random_bytes(16)));
    $bookingCommands->transition($superAdmin,$manualBookingId,'confirmed','Administrator confirmed after deposit gate review',bin2hex(random_bytes(16)));
    $bookingCommands->transition($clientPrincipal,$manualBookingId,'cancelled','Client requested cancellation after confirmation',bin2hex(random_bytes(16)));
    $cancelledState = $pdo->prepare('SELECT status FROM bookings WHERE id = :id');
    $cancelledState->execute(['id'=>$manualBookingId]);
    $assert($cancelledState->fetchColumn() === 'cancelled','companion acceptance, administrator confirmation and client cancellation follow authorized transitions');

    $pdo->prepare('UPDATE companion_profiles SET auto_booking_opt_in = 0 WHERE id = :id')->execute(['id'=>$profileId]);
    $thirdStart = $localBookingStart->modify('+4 hours');
    $thirdBookingId = $bookingService->request($clientPrincipal,$profileId,$thirdStart->setTimezone(new DateTimeZone('UTC')),$thirdStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,null,bin2hex(random_bytes(16)));
    $bookingCommands->transition($companionPrincipal,$thirdBookingId,'accepted','Companion accepted the reschedule test booking',bin2hex(random_bytes(16)));
    $bookingCommands->transition($superAdmin,$thirdBookingId,'confirmed','Administrator confirmed the reschedule test booking',bin2hex(random_bytes(16)));
    $newStart = $localBookingStart->modify('+6 hours');
    $bookingCommands->reschedule($clientPrincipal,$thirdBookingId,$newStart->setTimezone(new DateTimeZone('UTC')),$newStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),'Client requested a new available time',bin2hex(random_bytes(16)));
    $rescheduled = $pdo->prepare('SELECT starts_at_utc,status FROM bookings WHERE id = :id');
    $rescheduled->execute(['id'=>$thirdBookingId]);
    $rescheduledRow = $rescheduled->fetch();
    $assert(is_array($rescheduledRow) && $rescheduledRow['status'] === 'confirmed' && $rescheduledRow['starts_at_utc'] === $newStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'client rescheduling preserves confirmed state and uses UTC storage');
    $bookingQueries = new BookingQueryService($pdo);
    $clientHistory = $bookingQueries->clientHistory($clientPrincipal);
    $companionHistory = $bookingQueries->companionHistory($companionPrincipal);
    $adminBookingQueue = $bookingQueries->adminQueue($superAdmin);
    $assert(count($clientHistory) === 3 && count($companionHistory) === 3,'client and companion booking histories are scoped to their own identities');
    $assert(count($adminBookingQueue) === 3 && array_key_exists('client_user_id',$adminBookingQueue[0]),'MFA-authorized administrators can inspect the booking operations queue');
    $assert(!array_key_exists('client_user_id',$companionHistory[0]) && !array_key_exists('client_user_id',$clientHistory[0]),'client and companion booking read models omit internal account identifiers');


    return $assertions;
}
