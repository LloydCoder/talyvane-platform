<?php
declare(strict_types=1);

use Talyvane\Application\Identity\AccountRecoveryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\MfaService;
use Talyvane\Application\Identity\PasswordChangeService;
use Talyvane\Application\Identity\UserRegistrationService;
use Talyvane\Application\Companions\CompanionProfilePublicationService;
use Talyvane\Application\Companions\CompanionOnboardingService;
use Talyvane\Application\Companions\CompanionAvailabilityService;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Application\Features\FeatureManagementService;
use Talyvane\Application\Globalization\GlobalizationSettingsService;
use Talyvane\Application\Payments\PaymentSettingsService;
use Talyvane\Application\Payments\PaymentMethodManagementService;
use Talyvane\Application\Bookings\BookingSettingsService;
use Talyvane\Infrastructure\Database\BookingPolicyRepository;
use Talyvane\Infrastructure\Database\FeatureStateRepository;
use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Application\Destinations\DestinationManagementService;
use Talyvane\Application\Globalization\MarketPolicyManagementService;
use Talyvane\Application\Bookings\BookingService;
use Talyvane\Application\Bookings\BookingCommandService;
use Talyvane\Application\Bookings\CalendarFeedTokenService;
use Talyvane\Application\Bookings\BookingQueryService;
use Talyvane\Application\Bookings\BookingNotificationOutbox;
use Talyvane\Application\Bookings\BookingNotificationDispatcher;

use Talyvane\Application\Identity\EmailVerificationService;
use Talyvane\Application\Identity\PortalPrivacyRightsService;
use Talyvane\Application\Identity\PortalPrivacyRightsAdminService;
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

final class NativeFailingBookingMailer implements Mailer
{
    public function send(string $recipient,string $subject,string $textBody): void
    {
        throw new RuntimeException('Synthetic SMTP outage for retry coverage.');
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
    $companionActor=new Principal($companionId,['companion'],false);
    $availabilityService=new CompanionAvailabilityService($pdo,$audit,new FeatureAvailability([],static fn(string $dependency):bool=>false));
    $pdo->prepare('UPDATE companion_profiles SET auto_booking_opt_in=1 WHERE id=:id')->execute(['id'=>$profileId]);
    $availabilityService->setAutomaticBookingPreference($companionActor,$profileId,false,false,bin2hex(random_bytes(16)));
    $autoPreference=$pdo->prepare('SELECT auto_booking_opt_in FROM companion_profiles WHERE id=:id');
    $autoPreference->execute(['id'=>$profileId]);
    $assert(!(bool)$autoPreference->fetchColumn(),'companion can immediately opt out of automatic booking while deployment feature gates are closed');
    $optOutAudit=$pdo->prepare('SELECT event_key FROM audit_events WHERE actor_user_id=:actor AND target_id=:subject AND event_key="companion.automatic_booking.opted_out"');
    $optOutAudit->execute(['actor'=>$companionId,'subject'=>$profileId]);
    $assert($optOutAudit->fetchColumn()==='companion.automatic_booking.opted_out','companion automatic-booking opt-out is audited');
    try {
        $availabilityService->setAutomaticBookingPreference($companionActor,$profileId,true,true,bin2hex(random_bytes(16)));
        $assert(false,'companion cannot opt in while automatic-booking deployment prerequisites are disabled');
    } catch (DomainException) {
        $assert(true,'companion cannot opt in while automatic-booking deployment prerequisites are disabled');
    }

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
    $bookingNotifications = new BookingNotificationOutbox($pdo,new SecretBox(str_repeat('B',32)),str_repeat('F',32));
    $bookingService = new BookingService($pdo,new BookingPolicyRepository($pdo),$audit,$bookingNotifications);
    $manualRules=json_encode(['automatic_enabled'=>false,'auto_confirm_enabled'=>false,'deposit_required'=>false,
        'minimum_notice_minutes'=>60,'maximum_advance_days'=>90,'minimum_duration_minutes'=>60,
        'maximum_duration_minutes'=>480,'client_cancellation_enabled'=>false,'client_cancellation_cutoff_minutes'=>120],JSON_THROW_ON_ERROR);
    $seedManualRules=$pdo->prepare('INSERT INTO deployment_settings (setting_key,setting_json,version,updated_by,updated_at)
        VALUES ("booking.rules",:settings,1,:actor,UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE setting_json=VALUES(setting_json),version=version+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)');
    $seedManualRules->execute(['settings'=>$manualRules,'actor'=>$id]);
    $manualBookingId = $bookingService->request($clientPrincipal,$profileId,$localBookingStart->setTimezone(new DateTimeZone('UTC')),$localBookingStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,'Manual booking integration test',bin2hex(random_bytes(16)));
    $manualState = $pdo->prepare('SELECT status,booking_mode,deposit_status FROM bookings WHERE id = :id');
    $manualState->execute(['id'=>$manualBookingId]);
    $manual = $manualState->fetch();
    $assert(is_array($manual) && $manual['status'] === 'requested' && $manual['booking_mode'] === 'manual','booking requests default to manual review unless all automatic gates pass');
    $bookingOutboxQuery=$pdo->prepare('SELECT id,idempotency_key,recipient_ciphertext,payload_ciphertext,status FROM communication_outbox WHERE related_type="booking" AND related_id=:booking');
    $bookingOutboxQuery->execute(['booking'=>$manualBookingId]);
    $requestNotifications=$bookingOutboxQuery->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($requestNotifications)===1 && $requestNotifications[0]['status']==='pending' &&
        !str_contains($requestNotifications[0]['recipient_ciphertext'],'native-companion@example.test') &&
        !str_contains($requestNotifications[0]['payload_ciphertext'],'TVN-'),
        'booking request creates one pending outbox record with encrypted recipient and payload');
    $bookingNotifications->enqueue($manualBookingId,$companionId,'booking.requested');
    $bookingOutboxCount=$pdo->prepare('SELECT COUNT(*) FROM communication_outbox WHERE related_type="booking" AND related_id=:booking');
    $bookingOutboxCount->execute(['booking'=>$manualBookingId]);
    $assert((int)$bookingOutboxCount->fetchColumn()===1,'booking notification enqueue is idempotent for the same booking, event and recipient');
    try {
        $bookingService->request($clientPrincipal,$profileId,$localBookingStart->setTimezone(new DateTimeZone('UTC')),$localBookingStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,null,bin2hex(random_bytes(16)));
        $assert(false,'overlapping booking request is rejected');
    } catch (DomainException) {
        $assert(true,'overlapping booking request is rejected');
    }
    $pdo->prepare('UPDATE companion_profiles SET auto_booking_opt_in = 1 WHERE id = :id')->execute(['id'=>$profileId]);
    $bookingRules = json_encode(['automatic_enabled'=>true,'auto_confirm_enabled'=>true,'deposit_required'=>false,'minimum_notice_minutes'=>60,'maximum_advance_days'=>90,'minimum_duration_minutes'=>60,'maximum_duration_minutes'=>480,'client_cancellation_enabled'=>true,'client_cancellation_cutoff_minutes'=>0],JSON_THROW_ON_ERROR);
    $settings = $pdo->prepare('UPDATE deployment_settings SET setting_json=:settings,version=version+1,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE setting_key="booking.rules"');
    $settings->execute(['settings'=>$bookingRules,'actor'=>$id]);
    $pdo->prepare('INSERT INTO feature_states (feature_key,enabled,config_json,updated_by,updated_at) VALUES ("bookings.automatic",1,NULL,:actor,UTC_TIMESTAMP())')->execute(['actor'=>$id]);
    $autoStart = $localBookingStart->modify('+2 hours');
    $autoBookingId = $bookingService->request($clientPrincipal,$profileId,$autoStart->setTimezone(new DateTimeZone('UTC')),$autoStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),null,null,bin2hex(random_bytes(16)));
    $autoState = $pdo->prepare('SELECT status,booking_mode,companion_opt_in_snapshot FROM bookings WHERE id = :id');
    $autoState->execute(['id'=>$autoBookingId]);
    $automatic = $autoState->fetch();
    $assert(is_array($automatic) && $automatic['status'] === 'requested' && $automatic['booking_mode'] === 'manual' && (bool) $automatic['companion_opt_in_snapshot'],'automatic booking remains manual until deployment feature, provider, legal and safeguarding prerequisites are operational');

    $bookingCommands = new BookingCommandService($pdo,new BookingPolicyRepository($pdo),$audit,$bookingNotifications);
    $superAdmin = new Principal($id,['super_admin'],true);
    $companionPrincipal = new Principal($companionId,['companion'],false);
    $bookingCommands->transition($companionPrincipal,$manualBookingId,'accepted','Companion accepted the requested booking',bin2hex(random_bytes(16)));
    $bookingCommands->transition($superAdmin,$manualBookingId,'confirmed','Administrator confirmed after deposit gate review',bin2hex(random_bytes(16)));
    $bookingCommands->transition($clientPrincipal,$manualBookingId,'cancelled','Client requested cancellation after confirmation',bin2hex(random_bytes(16)));
    $cancelledState = $pdo->prepare('SELECT status FROM bookings WHERE id = :id');
    $cancelledState->execute(['id'=>$manualBookingId]);
    $assert($cancelledState->fetchColumn() === 'cancelled','companion acceptance, administrator confirmation and client cancellation follow authorized transitions');
    $notificationEvents=$pdo->prepare('SELECT COUNT(*) FROM communication_outbox WHERE related_type="booking" AND related_id=:booking');
    $notificationEvents->execute(['booking'=>$manualBookingId]);
    $assert((int)$notificationEvents->fetchColumn()===5,'companion acceptance, administrator confirmation and client cancellation enqueue the corresponding role-scoped alerts');

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
    $rescheduleNotificationCount=$pdo->prepare('SELECT COUNT(*) FROM communication_outbox WHERE related_type="booking" AND related_id=:booking AND idempotency_key LIKE "%booking.rescheduled%"');
    $rescheduleNotificationCount->execute(['booking'=>$thirdBookingId]);
    $assert((int)$rescheduleNotificationCount->fetchColumn()===1,'client rescheduling notifies the companion without exposing the client note');
    $bookingQueries = new BookingQueryService($pdo);
    try {
        $bookingQueries->clientHistory(new Principal($companionId,['client','admin'],true));
        $assert(false,'mixed portal and privileged roles cannot enter a client-scoped booking read model');
    } catch (DomainException) {
        $assert(true,'mixed portal and privileged roles cannot enter a client-scoped booking read model');
    }
    try {
        $bookingCommands->transition(new Principal($companionId,['client','admin'],true),$autoBookingId,'cancelled','Mixed-role identity must not mutate another booking.',bin2hex(random_bytes(16)));
        $assert(false,'mixed portal and privileged roles cannot use administrator precedence to mutate another booking');
    } catch (DomainException) {
        $assert(true,'mixed portal and privileged roles cannot use administrator precedence to mutate another booking');
    }
    $clientHistory = $bookingQueries->clientHistory($clientPrincipal);
    $companionHistory = $bookingQueries->companionHistory($companionPrincipal);
    $adminBookingQueue = $bookingQueries->adminQueue($superAdmin);
    try {
        $bookingQueries->adminQueue(new Principal($companionId,['admin','companion'],true));
        $assert(false,'mixed companion and administrator roles cannot enter the administrator booking queue');
    } catch (DomainException) {
        $assert(true,'mixed companion and administrator roles cannot enter the administrator booking queue');
    }
    $assert(count($clientHistory) === 3 && count($companionHistory) === 3,'client and companion booking histories are scoped to their own identities');
    $assert(count($adminBookingQueue) === 3 && array_key_exists('client_user_id',$adminBookingQueue[0]),'MFA-authorized administrators can inspect the booking operations queue');
    $assert(!array_key_exists('client_user_id',$companionHistory[0]) && !array_key_exists('client_user_id',$clientHistory[0]),'client and companion booking read models omit internal account identifiers');

    $bookingCalendar = new \Talyvane\Application\Bookings\BookingCalendarService($pdo,str_repeat('C',32));
    $clientCalendarEvents = $bookingCalendar->clientEvents($clientPrincipal);
    $companionCalendarEvents = $bookingCalendar->companionEvents($companionPrincipal);
    $assert(count($clientCalendarEvents) === 2 && count($companionCalendarEvents) === 2,
        'client and companion calendars contain only their own active or recently changed bookings');
    $companionCalendarIcs = $bookingCalendar->toIcalendar($companionPrincipal,$companionCalendarEvents,new DateTimeImmutable('now',new DateTimeZone('UTC')));
    $assert(!str_contains($companionCalendarIcs,'native-client@example.test') && !str_contains($companionCalendarIcs,'client_user_id'),
        'companion iCalendar export excludes client identity and account identifiers');
    $assert(str_contains($companionCalendarIcs,'STATUS:CANCELLED'),
        'calendar export preserves cancellation tombstones for downstream calendar reconciliation');
    $calendarFeedTokens=new CalendarFeedTokenService($pdo,$audit);
    $feedToken=$calendarFeedTokens->issue($clientPrincipal,'Personal calendar',90,bin2hex(random_bytes(16)));
    $storedFeedToken=$pdo->prepare('SELECT token_hash,role_key,expires_at,revoked_at FROM calendar_feed_tokens WHERE id=:id');
    $storedFeedToken->execute(['id'=>$feedToken['id']]);$storedFeedRow=$storedFeedToken->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($storedFeedRow) && $storedFeedRow['token_hash']===hash('sha256',$feedToken['token']) &&
        $storedFeedRow['token_hash']!==$feedToken['token'] && $storedFeedRow['role_key']==='client',
        'calendar feed stores only a one-way token hash bound to the issuing portal role');
    $resolvedFeedPrincipal=$calendarFeedTokens->resolve($feedToken['token']);
    $assert($resolvedFeedPrincipal instanceof Principal && $resolvedFeedPrincipal->id===$clientPrincipal->id &&
        $resolvedFeedPrincipal->hasRole('client'),'valid calendar feed token resolves only to its owning client identity');
    $feedList=$calendarFeedTokens->list($clientPrincipal);
    $assert(count($feedList)===1 && !array_key_exists('token_hash',$feedList[0]) && !array_key_exists('token',$feedList[0]),
        'calendar feed management lists metadata without disclosing token material');
    $wrongFeedOwnerDenied=false;
    try { $calendarFeedTokens->revoke($companionPrincipal,(string)$feedToken['id'],bin2hex(random_bytes(16))); }
    catch(DomainException) { $wrongFeedOwnerDenied=true; }
    $assert($wrongFeedOwnerDenied,'companion cannot revoke a client calendar feed token');
    $calendarFeedTokens->revoke($clientPrincipal,(string)$feedToken['id'],bin2hex(random_bytes(16)));
    $assert($calendarFeedTokens->resolve($feedToken['token'])===null,'revoked calendar feed token stops resolving immediately');
    $secondFeedToken=$calendarFeedTokens->issue($companionPrincipal,'Companion calendar',30,bin2hex(random_bytes(16)));
    $pdo->prepare('UPDATE calendar_feed_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=:id')
        ->execute(['id'=>$secondFeedToken['id']]);
    $assert($calendarFeedTokens->resolve($secondFeedToken['token'])===null,'expired calendar feed token cannot be used');

    $bookingTestMailer=new NativeTestMailer();
    $bookingDispatcher=new BookingNotificationDispatcher($pdo,new SecretBox(str_repeat('B',32)),$bookingTestMailer);
    $dispatchResult=$bookingDispatcher->dispatchBatch(50);
    $assert($dispatchResult['claimed']>=5 && $dispatchResult['delivered']===$dispatchResult['claimed'] &&
        $dispatchResult['retried']===0 && $dispatchResult['dead_lettered']===0,
        'booking notification dispatcher decrypts and delivers queued alerts through the mail adapter');
    $assert(count($bookingTestMailer->messages)===$dispatchResult['delivered'] &&
        !str_contains(implode("\n",array_column($bookingTestMailer->messages,'body')),'Manual booking integration test'),
        'booking emails contain event details but never disclose private client booking notes');
    $bookingNotifications->enqueue($manualBookingId,$registeredId,'booking.rescheduled');
    $failingDispatcher=new BookingNotificationDispatcher($pdo,new SecretBox(str_repeat('B',32)),new NativeFailingBookingMailer());
    $retryResult=$failingDispatcher->dispatchBatch(1);
    $retryState=$pdo->prepare('SELECT status,attempts,last_error_code FROM communication_outbox WHERE related_type="booking" AND related_id=:booking AND idempotency_key LIKE "%booking.rescheduled%" AND recipient_fingerprint=:fingerprint ORDER BY created_at DESC LIMIT 1');
    $retryState->execute(['booking'=>$manualBookingId,'fingerprint'=>hash_hmac('sha256','native-registered-client@example.test',str_repeat('F',32))]);
    $retryRow=$retryState->fetch(PDO::FETCH_ASSOC);
    $assert($retryResult['retried']===1 && is_array($retryRow) && $retryRow['status']==='pending' &&
        (int)$retryRow['attempts']===1 && $retryRow['last_error_code']==='RuntimeException',
        'SMTP failure schedules a bounded retry and records a sanitized delivery attempt');
    $bookingNotifications->enqueue($manualBookingId,$registeredId,'booking.declined');
    $staleOutbox=$pdo->prepare('UPDATE communication_outbox SET status="processing",attempts=8,claim_token=UUID(),claimed_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE) WHERE related_type="booking" AND related_id=:booking AND idempotency_key LIKE "%booking.declined%"');
    $staleOutbox->execute(['booking'=>$manualBookingId]);
    $staleResult=$bookingDispatcher->dispatchBatch(5);
    $staleState=$pdo->prepare('SELECT status,last_error_code FROM communication_outbox WHERE related_type="booking" AND related_id=:booking AND idempotency_key LIKE "%booking.declined%"');
    $staleState->execute(['booking'=>$manualBookingId]);
    $staleRow=$staleState->fetch(PDO::FETCH_ASSOC);
    $assert($staleResult['claimed']===0 && is_array($staleRow) && $staleRow['status']==='failed' &&
        $staleRow['last_error_code']==='retry_limit_after_stale_claim',
        'exhausted stale notification claims are moved to a terminal failed state rather than stranded forever');
    $privacyExports=new \Talyvane\Application\Identity\PortalPrivacyExportService($pdo,$audit,$limiter);
    $clientPrivacyExport=$privacyExports->export($clientPrincipal,bin2hex(random_bytes(16)));
    $companionPrivacyExport=$privacyExports->export($companionPrincipal,bin2hex(random_bytes(16)));
    $assert(($clientPrivacyExport['schema']??'')==='talyvane-portal-data-export-v1' &&
        count($clientPrivacyExport['bookings']??[])===3 &&
        is_array($clientPrivacyExport['memberships']??null) && is_array($clientPrivacyExport['favorites']??null),
        'client privacy export includes only the client account bookings, memberships and shortlist data');
    $assert(($companionPrivacyExport['schema']??'')==='talyvane-portal-data-export-v1' &&
        ($companionPrivacyExport['profile']['display_name']??'')==='Verified Talent' &&
        count($companionPrivacyExport['bookings']??[])===3 &&
        !array_key_exists('client_user_id',$companionPrivacyExport['bookings'][0]??[]),
        'companion privacy export includes own profile, schedule and booking records without client identifiers');
    foreach ([$clientPrivacyExport,$companionPrivacyExport] as $privacyExport) {
        $assert(!array_key_exists('password_hash',$privacyExport['account']) &&
            !array_key_exists('mfa_secret',$privacyExport['account']) &&
            !array_key_exists('offline_recovery_key',$privacyExport['account']),
            'privacy exports never disclose password hashes, MFA secrets or offline recovery material');
    }
    $privilegedExportDenied=false;
    try { $privacyExports->export($principal,bin2hex(random_bytes(16))); } catch (DomainException) { $privilegedExportDenied=true; }
    $assert($privilegedExportDenied,'administrator accounts cannot use the client/companion portal privacy export');
    $privacyExports->export($clientPrincipal,bin2hex(random_bytes(16)));
    $privacyExports->export($clientPrincipal,bin2hex(random_bytes(16)));
    $privacyExportRateLimited=false;
    try { $privacyExports->export($clientPrincipal,bin2hex(random_bytes(16))); }
    catch (\Talyvane\Application\Identity\PortalPrivacyExportRateLimitException) { $privacyExportRateLimited=true; }
    $assert($privacyExportRateLimited,'privacy export requests are rate-limited per portal account');
    $exportAudit=$pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE event_key="portal.privacy.exported" AND actor_user_id IN (:client,:companion)');
    $exportAudit->execute(['client'=>$clientPrincipal->id,'companion'=>$companionPrincipal->id]);
    $assert((int)$exportAudit->fetchColumn()===4,'each successful client/companion privacy export emits a correlated audit event');
    $pdo->exec('UPDATE deployment_settings SET setting_json=JSON_SET(setting_json,\'$.client_cancellation_enabled\',false) WHERE setting_key=\'booking.rules\'');
    $requestedCancellationStart=$localBookingStart->modify('+8 hours');
    $requestedCancellationId=$bookingService->request($clientPrincipal,$profileId,
        $requestedCancellationStart->setTimezone(new DateTimeZone('UTC')),
        $requestedCancellationStart->modify('+60 minutes')->setTimezone(new DateTimeZone('UTC')),
        null,'Request cancellation regression coverage',bin2hex(random_bytes(16)));
    $bookingCommands->transition($clientPrincipal,$requestedCancellationId,'cancelled',
        'Client cancelled a newly submitted booking request',bin2hex(random_bytes(16)));
    $requestedCancellationState=$pdo->prepare('SELECT status FROM bookings WHERE id=:id');
    $requestedCancellationState->execute(['id'=>$requestedCancellationId]);
    $assert($requestedCancellationState->fetchColumn()==='cancelled',
        'client can cancel a newly submitted requested booking before companion review even when confirmed cancellation is disabled');
    $privacyRightsMailer=new NativeTestMailer();
    $portalRights=new PortalPrivacyRightsService($pdo,$privacyRightsMailer,$limiter,$audit,
        new SecretBox(str_repeat('Q',32)),str_repeat('P',32));
    $correctionSummary='Please correct the spelling of my account details.';
    $correctionRequestId=$portalRights->request($clientPrincipal,'correction',$correctionSummary,'127.0.0.1',bin2hex(random_bytes(16)));
    $correctionRowQuery=$pdo->prepare('SELECT status,payload_ciphertext FROM portal_privacy_requests WHERE id=:id AND user_id=:user');
    $correctionRowQuery->execute(['id'=>$correctionRequestId,'user'=>$registeredId]);
    $correctionRow=$correctionRowQuery->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($correctionRow) && $correctionRow['status']==='pending' &&
        !str_contains((string)$correctionRow['payload_ciphertext'],$correctionSummary),
        'client correction request stores its explanation encrypted and starts in pending verification');
    preg_match('/One-time verification code: ([0-9]{8})/',(string)$privacyRightsMailer->messages[0]['body'],$correctionCodeMatch);
    $correctionCode=(string)($correctionCodeMatch[1]??'');
    $wrongCode=$correctionCode==='99999999'?'00000000':'99999999';
    $assert(!$portalRights->verify($clientPrincipal,$correctionRequestId,$wrongCode,'127.0.0.1',bin2hex(random_bytes(16))),
        'incorrect privacy verification codes are rejected without disclosing request existence');
    $assert($portalRights->verify($clientPrincipal,$correctionRequestId,$correctionCode,'127.0.0.1',bin2hex(random_bytes(16))),
        'correct email verification code moves the client correction request into restricted review');
    $correctionHistory=$portalRights->history($clientPrincipal);
    $assert(count($correctionHistory)===1 && $correctionHistory[0]['status']==='in_review',
        'client can see privacy request status without retrieving the encrypted explanation');
    $companionHistoryBefore=$portalRights->history($companionPrincipal);
    $assert($companionHistoryBefore===[],'companion privacy request history is isolated from client requests');
    $erasureRequestId=$portalRights->request($clientPrincipal,'erasure',null,'127.0.0.1',bin2hex(random_bytes(16)));
    preg_match('/One-time verification code: ([0-9]{8})/',(string)$privacyRightsMailer->messages[1]['body'],$erasureCodeMatch);
    $erasureCode=(string)($erasureCodeMatch[1]??'');
    $assert($erasureCode!=='' && $portalRights->verify($clientPrincipal,$erasureRequestId,$erasureCode,'127.0.0.1',bin2hex(random_bytes(16))),
        'client erasure request requires a separate verified one-time code');
    $clientStillActive=$pdo->prepare('SELECT status,email FROM users WHERE id=:id');
    $clientStillActive->execute(['id'=>$registeredId]);
    $clientAfterErasureRequest=$clientStillActive->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($clientAfterErasureRequest) && $clientAfterErasureRequest['status']==='active' &&
        $clientAfterErasureRequest['email']==='native-registered-client@example.test',
        'verified erasure requests enter human review and never erase an account automatically');
    $companionCorrectionId=$portalRights->request($companionPrincipal,'correction','Please correct my companion profile information.','127.0.0.1',bin2hex(random_bytes(16)));
    preg_match('/One-time verification code: ([0-9]{8})/',(string)$privacyRightsMailer->messages[2]['body'],$companionCodeMatch);
    $companionCode=(string)($companionCodeMatch[1]??'');
    $assert($companionCode!=='' && $portalRights->verify($companionPrincipal,$companionCorrectionId,$companionCode,'127.0.0.1',bin2hex(random_bytes(16))),
        'companion can submit and verify an independently scoped correction request');
    $assert(count($portalRights->history($companionPrincipal))===1 && count($portalRights->history($clientPrincipal))===2,
        'client and companion privacy request histories remain account-scoped');
    $portalRightsAdmin=new PortalPrivacyRightsAdminService($pdo,new SecretBox(str_repeat('Q',32)),$audit);
    $adminPrivacyQueue=$portalRightsAdmin->queue($superAdmin,bin2hex(random_bytes(16)));
    $assert(count($adminPrivacyQueue)===3 &&
        in_array($correctionSummary,array_column($adminPrivacyQueue,'correction_summary'),true),
        'MFA-authorized owner/admin review queue can decrypt correction details while preserving account ownership');
    $mfaPrivacyReviewDenied=false;
    try { $portalRightsAdmin->queue(new Principal($id,['super_admin'],false),bin2hex(random_bytes(16))); }
    catch (DomainException) { $mfaPrivacyReviewDenied=true; }
    $assert($mfaPrivacyReviewDenied,'privacy request review requires a verified administrator MFA session');
    $clientPrivacyReviewDenied=false;
    try { $portalRightsAdmin->queue($clientPrincipal,bin2hex(random_bytes(16))); }
    catch (DomainException) { $clientPrivacyReviewDenied=true; }
    $assert($clientPrivacyReviewDenied,'client and companion accounts cannot access the administrator privacy review queue');
    $portalRightsAdmin->review($superAdmin,$erasureRequestId,'deferred',
        'Legal retention and active service records require further assessment.',bin2hex(random_bytes(16)));
    $deferredRequest=$pdo->prepare('SELECT status,decision_reason FROM portal_privacy_requests WHERE id=:id');
    $deferredRequest->execute(['id'=>$erasureRequestId]);
    $deferredRow=$deferredRequest->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($deferredRow) && $deferredRow['status']==='deferred' &&
        str_contains($deferredRow['decision_reason'],'Legal retention'),
        'administrator can defer erasure with a mandatory auditable legal-retention reason');
    $portalRightsAdmin->fulfillCompanionCorrection($superAdmin,$companionCorrectionId,
        'Verified Talent Corrected','Corrected biography supplied and checked by the administrator.',['en','fr-CA'],
        'Applicant identity and requested profile corrections were independently checked.',bin2hex(random_bytes(16)));
    $correctedProfile=$pdo->prepare('SELECT display_name,biography,languages_json,status FROM companion_profiles WHERE user_id=:user');
    $correctedProfile->execute(['user'=>$companionPrincipal->id]);
    $correctedProfileRow=$correctedProfile->fetch(PDO::FETCH_ASSOC);
    $correctedRequest=$pdo->prepare('SELECT status,payload_ciphertext FROM portal_privacy_requests WHERE id=:id');
    $correctedRequest->execute(['id'=>$companionCorrectionId]);
    $correctedRequestRow=$correctedRequest->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($correctedProfileRow) && $correctedProfileRow['display_name']==='Verified Talent Corrected' &&
        $correctedProfileRow['biography']==='Corrected biography supplied and checked by the administrator.' &&
        json_decode((string)$correctedProfileRow['languages_json'],true)===['en','fr-CA'] &&
        $correctedProfileRow['status']==='draft',
        'verified companion correction applies only allowlisted profile fields and suspends public publication for re-review');
    $assert(is_array($correctedRequestRow) && $correctedRequestRow['status']==='fulfilled' &&
        $correctedRequestRow['payload_ciphertext']===null,
        'fulfilled companion correction closes the verified request and clears its encrypted request payload');
    $correctionAudit=$pdo->prepare('SELECT after_json FROM audit_events WHERE event_key="portal.privacy.correction.fulfilled" AND target_id=:id');
    $correctionAudit->execute(['id'=>$companionCorrectionId]);
    $correctionAuditPayload=json_decode((string)$correctionAudit->fetchColumn(),true);
    $assert(is_array($correctionAuditPayload) && ($correctionAuditPayload['fields']??[])===['display_name','biography','languages'],
        'companion correction fulfilment records an auditable field-level event without copying corrected personal values into the audit log');
    $portalRightsAdmin->fulfillClientCorrection($superAdmin,$correctionRequestId,
        'Verified Client Corrected','Client identity correction independently verified against supplied evidence.',bin2hex(random_bytes(16)));
    $correctedClient=$pdo->prepare('SELECT name,email,status FROM users WHERE id=:id');
    $correctedClient->execute(['id'=>$clientPrincipal->id]);
    $correctedClientRow=$correctedClient->fetch(PDO::FETCH_ASSOC);
    $clientCorrectionRequest=$pdo->prepare('SELECT status,payload_ciphertext FROM portal_privacy_requests WHERE id=:id');
    $clientCorrectionRequest->execute(['id'=>$correctionRequestId]);
    $clientCorrectionRequestRow=$clientCorrectionRequest->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($correctedClientRow) && $correctedClientRow['name']==='Verified Client Corrected' &&
        $correctedClientRow['email']==='native-registered-client@example.test' && $correctedClientRow['status']==='active',
        'verified client name correction changes only the allowlisted account name and preserves contact email and account status');
    $assert(is_array($clientCorrectionRequestRow) && $clientCorrectionRequestRow['status']==='fulfilled' &&
        $clientCorrectionRequestRow['payload_ciphertext']===null,
        'fulfilled client correction closes the verified request and clears encrypted request payload');
    $clientCorrectionAudit=$pdo->prepare('SELECT after_json FROM audit_events WHERE event_key="portal.privacy.correction.fulfilled" AND target_id=:id');
    $clientCorrectionAudit->execute(['id'=>$correctionRequestId]);
    $clientCorrectionAuditPayload=json_decode((string)$clientCorrectionAudit->fetchColumn(),true);
    $assert(is_array($clientCorrectionAuditPayload) && ($clientCorrectionAuditPayload['fields']??[])===['name'],
        'client correction audit records the allowlisted field without copying personal values');
    // Restore the pre-existing published fixture for later profile-media integration tests.
    $pdo->prepare('UPDATE companion_profiles SET status="published" WHERE user_id=:user')->execute(['user'=>$companionPrincipal->id]);
    $assert(count($portalRightsAdmin->queue($superAdmin,bin2hex(random_bytes(16))))===1,
        'fulfilled privacy requests leave the active review queue');



    // The feature control plane requires a separate Super Admin step-up and records state changes.
    $featureAdminId=(string)$pdo->query('SELECT UUID()')->fetchColumn();
    $featureAdminCreate=$pdo->prepare('INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at) VALUES (:id,"native-feature-admin@example.test",:hash,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $featureAdminCreate->execute(['id'=>$featureAdminId,'hash'=>PasswordHasher::hash($password)]);
    $role->execute(['id'=>$featureAdminId,'role'=>'super_admin']);
    $featureAdmin=new Principal($featureAdminId,['super_admin'],true);
    $featureSecret=$mfa->beginEnrollment($featureAdmin);
    $mfa->confirmEnrollment($featureAdmin,Totp::codeAt($featureSecret,time()),bin2hex(random_bytes(16)));
    $featureManager=new FeatureManagementService(
        $pdo,new FeatureStateRepository($pdo),$audit,new SecretBox(str_repeat('K',32)),
        static fn(string $dependency):bool=>false
    );
    $featureOutcome=$featureManager->change(
        $featureAdmin,'crm.enquiries',false,
        'Temporarily disable the enquiry module during a controlled incident response.',
        $password,Totp::codeAt($featureSecret,(intdiv(time(),30)+1)*30),bin2hex(random_bytes(16))
    );
    $featureState=(new FeatureStateRepository($pdo))->enabledStates();
    $assert($featureOutcome['changed']===true && ($featureState['crm.enquiries']??true)===false,
        'Super Admin can change a configurable feature after password and one-time TOTP step-up');
    $featureAudit=$pdo->prepare('SELECT event_key FROM audit_events WHERE actor_user_id=:actor AND target_id="crm.enquiries" AND event_key="platform.feature.disabled"');
    $featureAudit->execute(['actor'=>$featureAdminId]);
    $assert($featureAudit->fetchColumn()==='platform.feature.disabled',
        'feature state changes are recorded in the append-only audit log');
    (new FeatureStateRepository($pdo))->set('crm.enquiries',true,null,$id);

    $globalization=new GlobalizationSettingsService($pdo,$audit);
    $usd=$pdo->query('SELECT code FROM currencies WHERE code="USD"')->fetchColumn();
    $assert($usd==='USD','pinned ISO 4217 reference dataset contains USD for globalization settings');
    $globalization->toggleCurrency($featureAdmin,'USD',true,
        'Enable the verified ISO currency for controlled reporting configuration.',bin2hex(random_bytes(16)));
    $globalization->updateDefaults($featureAdmin,'USD','en-GB','Europe/London',
        'Set the deployment reporting currency and regional formatting defaults.',bin2hex(random_bytes(16)));
    $globalSettings=$pdo->query('SELECT base_currency_code,default_locale,default_timezone FROM deployment_globalization_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($globalSettings) && $globalSettings['base_currency_code']==='USD' &&
        $globalSettings['default_locale']==='en-GB' && $globalSettings['default_timezone']==='Europe/London',
        'globalization defaults persist only against an enabled ISO currency and valid BCP 47/IANA values');
    try {
        $globalization->toggleCurrency($featureAdmin,'USD',false,
            'Attempt to disable the active reporting currency during a test.',bin2hex(random_bytes(16)));
        $assert(false,'active reporting currency cannot be disabled');
    } catch (DomainException) {
        $assert(true,'active reporting currency cannot be disabled');
    }
    $globalization->updateDefaults($featureAdmin,null,'en','UTC',
        'Restore neutral deployment locale and time zone after integration verification.',bin2hex(random_bytes(16)));
    $globalization->toggleCurrency($featureAdmin,'USD',false,
        'Disable the temporary ISO currency after the globalization integration test.',bin2hex(random_bytes(16)));
    $globalAudit=$pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE actor_user_id=:actor AND event_key IN ("globalization.defaults.updated","globalization.currency.availability_changed")');
    $globalAudit->execute(['actor'=>$featureAdminId]);

    $paymentSettingsService=new PaymentSettingsService($pdo,$audit);
    $paymentSettingsService->update($featureAdmin,[
        'payments_enabled'=>true,'crypto_enabled'=>false,'cards_enabled'=>true,
        'manual_enabled'=>false,'booking_payments_enabled'=>true,'membership_payments_enabled'=>false,
    ],bin2hex(random_bytes(16)));
    $paymentState=$paymentSettingsService->current($featureAdmin);
    $assert($paymentState['payments_enabled']===true && $paymentState['cards_enabled']===true &&
        $paymentState['booking_payments_enabled']===true && $paymentState['crypto_enabled']===false,
        'Super Admin payment capability settings persist independently from provider and settlement activation');
    $paymentSettingsService->update($featureAdmin,[
        'payments_enabled'=>false,'crypto_enabled'=>false,'cards_enabled'=>false,
        'manual_enabled'=>false,'booking_payments_enabled'=>false,'membership_payments_enabled'=>false,
    ],bin2hex(random_bytes(16)));

    $bookingSettingsService=new BookingSettingsService($pdo,$audit,new FeatureAvailability([],static fn(string $dependency):bool=>false));
    $initialBookingJson=(string)$pdo->query('SELECT setting_json FROM deployment_settings WHERE setting_key="booking.rules"')->fetchColumn();
    $initialBookingVersion=(int)$pdo->query('SELECT version FROM deployment_settings WHERE setting_key="booking.rules"')->fetchColumn();
    $initialBookingSettings=$bookingSettingsService->current($featureAdmin);
    $initialRuntimePolicy=(new BookingPolicyRepository($pdo))->current();
    $assert($initialBookingSettings['minimum_notice_hours']===(int)ceil($initialRuntimePolicy->minimumNoticeMinutes/60) &&
        $initialBookingSettings['auto_requires_verified_deposit']===$initialRuntimePolicy->depositRequired,
        'booking settings workspace reads the same effective values as the runtime booking policy repository');
    $bookingSettingsService->update($featureAdmin,[
        'booking_mode'=>'manual','auto_confirm_enabled'=>false,'auto_requires_verified_deposit'=>true,
        'minimum_notice_hours'=>24,'maximum_advance_days'=>60,'client_cancellation_enabled'=>true,
        'cancellation_cutoff_hours'=>6,
    ],bin2hex(random_bytes(16)));
    $currentBookingPolicy=(new BookingPolicyRepository($pdo))->current();
    $assert(!$currentBookingPolicy->automaticEnabled && $currentBookingPolicy->depositRequired &&
        $currentBookingPolicy->minimumNoticeMinutes===1440 && $currentBookingPolicy->maximumAdvanceDays===60 &&
        $currentBookingPolicy->clientCancellationEnabled && $currentBookingPolicy->clientCancellationCutoffMinutes===360,
        'booking policy settings persist in the runtime policy repository with correct minute/hour conversion');
    try {
        $bookingSettingsService->update($featureAdmin,[
            'booking_mode'=>'automatic','auto_confirm_enabled'=>true,'auto_requires_verified_deposit'=>true,
            'minimum_notice_hours'=>12,'maximum_advance_days'=>90,'client_cancellation_enabled'=>false,
            'cancellation_cutoff_hours'=>2,
        ],bin2hex(random_bytes(16)));
        $assert(false,'automatic booking mode cannot be configured while independent operational feature gates are closed');
    } catch (DomainException) {
        $assert(true,'automatic booking mode cannot be configured while independent operational feature gates are closed');
    }
    $restoreBooking=$pdo->prepare('UPDATE deployment_settings SET setting_json=:json,version=:version,updated_by=:actor,updated_at=UTC_TIMESTAMP() WHERE setting_key="booking.rules"');
    $restoreBooking->execute(['json'=>$initialBookingJson,'version'=>$initialBookingVersion,'actor'=>$id]);

    $paymentAudit=$pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE actor_user_id=:actor AND event_key="payment.configuration.updated"');
    $paymentAudit->execute(['actor'=>$featureAdminId]);
    $assert((int)$paymentAudit->fetchColumn()===2,'payment capability configuration changes are append-only audited');

    $methodService=new PaymentMethodManagementService($pdo,$audit,new FeatureAvailability([],static fn(string $dependency):bool=>false));
    $paymentMethodId=$methodService->create($featureAdmin,'BTC','Bitcoin','bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',null,'booking',bin2hex(random_bytes(16)));
    $methodService->review($featureAdmin,$paymentMethodId,'provider-approval-reference-001','legal-approval-reference-001',bin2hex(random_bytes(16)));
    $paymentSettingsService->update($featureAdmin,[
        'payments_enabled'=>true,'crypto_enabled'=>true,'cards_enabled'=>false,
        'manual_enabled'=>false,'booking_payments_enabled'=>true,'membership_payments_enabled'=>false,
    ],bin2hex(random_bytes(16)));
    try {
        $methodService->toggle($featureAdmin,$paymentMethodId,true,bin2hex(random_bytes(16)));
        $assert(false,'crypto receiving method cannot be enabled without independent provider/legal feature gates');
    } catch (DomainException) {
        $assert(true,'crypto receiving method cannot be enabled without independent provider/legal feature gates');
    }
    $methodStatus=$pdo->prepare('SELECT status FROM payment_methods WHERE id=:id');
    $methodStatus->execute(['id'=>$paymentMethodId]);
    $assert($methodStatus->fetchColumn()==='reviewed','blocked receiving-method activation leaves the reviewed method disabled');
    $paymentSettingsService->update($featureAdmin,[
        'payments_enabled'=>false,'crypto_enabled'=>false,'cards_enabled'=>false,
        'manual_enabled'=>false,'booking_payments_enabled'=>false,'membership_payments_enabled'=>false,
    ],bin2hex(random_bytes(16)));


    $assert((int)$globalAudit->fetchColumn()>=4,'globalization default and currency changes are append-only audited');


    return $assertions;
}
