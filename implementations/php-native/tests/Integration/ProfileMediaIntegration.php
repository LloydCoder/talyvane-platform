<?php
declare(strict_types=1);

use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Application\Clients\MembershipEntitlementService;
use Talyvane\Application\Clients\MembershipEntitlementCatalog;
use Talyvane\Application\Clients\MembershipGalleryPolicyReadiness;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Application\Media\ProfileMediaService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Principal;

function runProfileMediaIntegration(PDO $pdo): int
{
    $assertions=0;
    $assert=static function(bool $condition,string $message) use (&$assertions): void {
        $assertions++;
        if (!$condition) { throw new RuntimeException('Profile media integration failed: '.$message); }
    };
    $fixture=$pdo->query(
        'SELECT p.id,p.slug,p.user_id,p.publication_consent_version,cg.id AS consent_grant_id
         FROM companion_profiles p JOIN users u ON u.id=p.user_id
         JOIN consent_grants cg ON cg.subject_type="companion_profile" AND cg.subject_id=p.id
           AND cg.purpose="profile.publication" AND cg.audience="public" AND cg.granted=1
           AND cg.withdrawn_at IS NULL AND cg.policy_version=p.publication_consent_version
         WHERE u.email="native-companion@example.test" AND p.status="published" LIMIT 1'
    )->fetch();
    if (!is_array($fixture)) { throw new RuntimeException('Profile media integration fixture is missing.'); }
    $companionId=(string) $fixture['user_id'];
    $profileId=(string) $fixture['id'];
    $reviewerId=(string) $pdo->query('SELECT id FROM users WHERE email="native-bootstrap@example.test" LIMIT 1')->fetchColumn();
    if ($reviewerId==='') { throw new RuntimeException('Profile media integration reviewer fixture is missing.'); }

    $tempRoot=sys_get_temp_dir().DIRECTORY_SEPARATOR.'talyvane-media-'.bin2hex(random_bytes(8));
    $privateRoot=$tempRoot.DIRECTORY_SEPARATOR.'private';
    $publicRoot=$tempRoot.DIRECTORY_SEPARATOR.'public';
    mkdir($publicRoot,0755,true);
    $storage=new PrivateMediaStorage($privateRoot,$publicRoot);
    $imageDirectory=$privateRoot.DIRECTORY_SEPARATOR.'profile-media';
    mkdir($imageDirectory,0700,true);
    $makeImage=static function() use ($imageDirectory): array {
        $image=imagecreatetruecolor(320,320);
        if (!$image instanceof GdImage) { throw new RuntimeException('GD test image could not be created.'); }
        $background=imagecolorallocate($image,185,149,96);
        imagefill($image,0,0,$background);
        $filename=bin2hex(random_bytes(16)).'.webp';
        $path=$imageDirectory.DIRECTORY_SEPARATOR.$filename;
        $ok=imagewebp($image,$path,82);
        imagedestroy($image);
        if (!$ok || !is_file($path) || !chmod($path,0600)) { throw new RuntimeException('GD test image could not be stored.'); }
        return ['key'=>'profile-media/'.$filename,'path'=>$path,'size'=>(int) filesize($path),'sha256'=>(string) hash_file('sha256',$path)];
    };
    $uuid=static function(): string {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&15)|64);
        $bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    };
    $insertMedia=$pdo->prepare(
        'INSERT INTO profile_media
         (id,profile_id,uploaded_by,private_storage_key,mime_type,file_size,sha256,width,height,
          media_scope,review_status,consent_grant_id,rights_confirmed_at,consent_version,
          reviewed_by,reviewed_at,created_at,updated_at)
         VALUES (:id,:profile,:actor,:key,"image/webp",:size,:hash,320,320,:scope,:status,:grant,
                 UTC_TIMESTAMP(),:version,:reviewer,:reviewed,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
    );
    $recordUploaderAttestations=static function(string $mediaId) use ($pdo,$companionId,$profileId,$uuid): void {
        $actorEmail='native-companion@example.test';
        $statements=[
            ['image_rights.attested','profile-media-rights-v1','I confirm I own or have the necessary rights and permissions to submit this image.'],
            ['depicted_adults.attested','profile-media-adult-subjects-v1','I confirm every identifiable person depicted is an adult aged 18 or older and no minor is depicted.'],
            ['public_publication.attested','profile-media-publication-v1','I separately consent to human review and public display only after approval.'],
        ];
        $insert=$pdo->prepare(
            'INSERT INTO profile_media_consent_events
             (id,profile_media_id,companion_profile_id,media_reference,actor_id,actor_email,action,
              capture_method,consent_policy_version,consent_statement,statement_sha256,correlation_id,created_at)
             VALUES (:id,:media,:profile,:reference,:actor,:email,:action,
                     "authenticated_companion_portal_checkbox",:version,:statement,:hash,:correlation,UTC_TIMESTAMP())'
        );
        foreach ($statements as [$action,$version,$statement]) {
            $insert->execute([
                'id'=>$uuid(),'media'=>$mediaId,'profile'=>$profileId,'reference'=>'profile-media:'.$mediaId,
                'actor'=>$companionId,'email'=>$actorEmail,'action'=>$action,'version'=>$version,
                'statement'=>$statement,'hash'=>hash('sha256',$statement),'correlation'=>$uuid(),
            ]);
        }
    };
    $audit=new AuditLogger($pdo);
    $discovery=new DiscoveryService($pdo);
    $service=new ProfileMediaService($pdo,$storage,$discovery,$audit);
    $admin=new Principal($reviewerId,['super_admin'],true);
    $companion=new Principal($companionId,['companion'],false);
    $requestId=bin2hex(random_bytes(16));

    try {
        $approvedImage=$makeImage();
        $approvedId=$uuid();
        $insertMedia->execute([
            'id'=>$approvedId,'profile'=>$profileId,'actor'=>$companionId,'key'=>$approvedImage['key'],
            'size'=>$approvedImage['size'],'hash'=>$approvedImage['sha256'],'scope'=>'private','status'=>'pending',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>null,'reviewed'=>null,
        ]);
        $recordUploaderAttestations($approvedId);
        $service->review($admin,$approvedId,'approved','Image rights and public profile consent were reviewed and confirmed',true,true,true,$requestId);
        $public=$service->publicMedia($approvedId);
        $assert($public['mime']==='image/webp' && hash_equals($approvedImage['sha256'],hash('sha256',$public['bytes'])),
            'public delivery reads a sanitized private-storage object only after approval and integrity verification');
        $profile=$discovery->profile((string) $fixture['slug']);
        $assert(is_array($profile) && is_array($profile['media']) && count($profile['media'])===1 &&
            $profile['media'][0]['url']==='/profile-media/'.$approvedId &&
            $profile['primary_media_url']==='/profile-media/'.$approvedId &&
            !array_key_exists('private_storage_key',$profile),
            'public profile projection exposes opaque approved media routes but never private storage keys');

        $replacementGrant=$uuid();
        $pdo->prepare(
            'INSERT INTO consent_grants (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at,evidence_reference,created_at)
             VALUES (:id,"companion_profile",:profile,"profile.publication","public",:version,1,UTC_TIMESTAMP(),NULL,"replacement-consent-test",UTC_TIMESTAMP())'
        )->execute(['id'=>$replacementGrant,'profile'=>$profileId,'version'=>$fixture['publication_consent_version']]);
        $pdo->prepare('UPDATE consent_grants SET withdrawn_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$fixture['consent_grant_id']]);
        $assert($discovery->profile((string) $fixture['slug'])!==null,
            'a renewed profile consent grant keeps the profile discoverable');
        try {
            $service->publicMedia($approvedId);
            $assert(false,'renewed profile consent cannot silently reactivate media tied to an older grant');
        } catch (DomainException) {
            $assert(true,'renewed profile consent cannot silently reactivate media tied to an older grant');
        }
        $pdo->prepare('UPDATE consent_grants SET withdrawn_at=NULL WHERE id=:id')->execute(['id'=>$fixture['consent_grant_id']]);
        $pdo->prepare('DELETE FROM consent_grants WHERE id=:id')->execute(['id'=>$replacementGrant]);

        $pendingImage=$makeImage();
        $pendingId=$uuid();
        $insertMedia->execute([
            'id'=>$pendingId,'profile'=>$profileId,'actor'=>$companionId,'key'=>$pendingImage['key'],
            'size'=>$pendingImage['size'],'hash'=>$pendingImage['sha256'],'scope'=>'private','status'=>'pending',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>null,'reviewed'=>null,
        ]);
        $recordUploaderAttestations($pendingId);
        $queue=$service->pendingReview($admin);
        $assert(count(array_filter($queue,static fn(array $item): bool => $item['id']===$pendingId))===1,
            'MFA-authorized administrators can view only pending profile media in the review queue');
        $preview=$service->preview($admin,$pendingId);
        $assert(hash_equals($pendingImage['sha256'],hash('sha256',$preview['bytes'])),
            'pending media preview verifies private-file integrity before returning bytes');
        try {
            $service->pendingReview($companion);
            $assert(false,'companions cannot access the administrator media-review queue');
        } catch (DomainException) {
            $assert(true,'companions cannot access the administrator media-review queue');
        }
        try {
            $service->review($admin,$pendingId,'approved','Image rights and public profile consent were reviewed and confirmed',false,true,true,$requestId);
            $assert(false,'media cannot be approved without adult-safety attestation');
        } catch (InvalidArgumentException) {
            $assert(true,'media cannot be approved without adult-safety attestation');
        }
        $service->review($admin,$pendingId,'approved','Image rights and public profile consent were reviewed and confirmed',true,true,true,$requestId);
        $approvedAfterReview=$service->publicMedia($pendingId);
        $assert(hash_equals($pendingImage['sha256'],hash('sha256',$approvedAfterReview['bytes'])),
            'human approval changes pending media to public only after current eligibility and consent rechecks');

        $membersOnlyImage=$makeImage();
        $membersOnlyId=$uuid();
        $insertMedia->execute([
            'id'=>$membersOnlyId,'profile'=>$profileId,'actor'=>$companionId,'key'=>$membersOnlyImage['key'],
            'size'=>$membersOnlyImage['size'],'hash'=>$membersOnlyImage['sha256'],'scope'=>'private','status'=>'pending',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>null,'reviewed'=>null,
        ]);
        $recordUploaderAttestations($membersOnlyId);
        $service->review($admin,$membersOnlyId,'approved','Image rights and private audience restrictions were reviewed and confirmed',true,true,true,$requestId,'members_only');
        $membersOnlyState=$pdo->prepare('SELECT review_status,media_scope FROM profile_media WHERE id=:id');
        $membersOnlyState->execute(['id'=>$membersOnlyId]);
        $membersOnlyRow=$membersOnlyState->fetch();
        $assert(is_array($membersOnlyRow) && $membersOnlyRow['review_status']==='approved' && $membersOnlyRow['media_scope']==='members_only',
            'reviewers can approve an image for the members-only audience without publishing it publicly');
        try {
            $service->publicMedia($membersOnlyId);
            $assert(false,'members-only media must never pass the public media delivery endpoint');
        } catch (DomainException) {
            $assert(true,'members-only media must never pass the public media delivery endpoint');
        }
        $publicProjection=$service->publicMediaForProfile($profileId);
        $assert(count(array_filter($publicProjection,static fn(array $item): bool => $item['id']===$membersOnlyId))===0,
            'members-only media is excluded from public profile media projections');

        $clientId=$uuid();
        $clientRole=$pdo->prepare(
            'INSERT INTO users (id,email,password_hash,status,email_verified_at,first_login_required,admin_session_version,created_at,updated_at)
             VALUES (:id,:email,:password,"active",UTC_TIMESTAMP(),0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $clientRole->execute(['id'=>$clientId,'email'=>'private-gallery-'.$clientId.'@example.test','password'=>password_hash('not-used',PASSWORD_DEFAULT)]);
        $pdo->prepare('INSERT INTO user_roles (user_id,role_key,granted_by,granted_at) VALUES (:id,"client",NULL,UTC_TIMESTAMP())')
            ->execute(['id'=>$clientId]);
        $membershipId=$uuid();
        $pdo->prepare(
            'INSERT INTO memberships (id,client_user_id,plan_key,status,starts_at,ends_at,created_at,updated_at)
             VALUES (:id,:client,"gallery-test","active",DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        )->execute(['id'=>$membershipId,'client'=>$clientId]);
        $pdo->prepare(
            'INSERT INTO membership_grants (id,membership_id,entitlement_key,active,starts_at,ends_at,granted_by,created_at)
             VALUES (:id,:membership,"media.private_gallery",1,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),:actor,UTC_TIMESTAMP())'
        )->execute(['id'=>$uuid(),'membership'=>$membershipId,'actor'=>$reviewerId]);
        $galleryFeatures=new FeatureAvailability(
            ['memberships'=>true,'memberships.private_gallery'=>true],
            static fn(string $dependency): bool => true
        );
        $galleryEntitlements=new MembershipEntitlementService($pdo,$galleryFeatures);
        $galleryService=new ProfileMediaService($pdo,$storage,$discovery,$audit,$galleryEntitlements);
        $companionClient=new Principal($clientId,['client'],false);
        $assert(MembershipEntitlementCatalog::privateGalleryConsentStatement('Talyvane')===
            'I separately agree that Talyvane may display this image only in its members-only gallery to eligible clients with a current agency-approved membership, as described in the current privacy notice. This permission is separate from public profile publication and may be withdrawn at any time.',
            'native consent catalogue preserves the canonical Laravel members-only consent statement exactly');
        $previousPolicyQuery=$pdo->prepare(
            'SELECT enabled,config_json,updated_by FROM feature_states WHERE feature_key="memberships.private_gallery" LIMIT 1'
        );
        $previousPolicyQuery->execute();
        $previousPolicy=$previousPolicyQuery->fetch(PDO::FETCH_ASSOC);
        $savePolicy=$pdo->prepare(
            'INSERT INTO feature_states (feature_key,enabled,config_json,updated_by,updated_at)
             VALUES ("memberships.private_gallery",1,:config,:actor,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),config_json=VALUES(config_json),
                 updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()'
        );
        $savePolicy->execute(['config'=>json_encode([
            'private_gallery_policy_approved'=>false,
            'private_gallery_policy_version'=>MembershipEntitlementCatalog::PRIVATE_GALLERY_POLICY_VERSION,
            'legal_approval_reference'=>'LEGAL-APPROVAL-TEST-001',
            'private_gallery_statement_sha256'=>MembershipEntitlementCatalog::privateGalleryConsentStatementHash('Talyvane'),
        ],JSON_THROW_ON_ERROR),'actor'=>$reviewerId]);
        $assert(!MembershipGalleryPolicyReadiness::approved($pdo,'Talyvane'),
            'private gallery activation fails closed without explicit deployment legal approval');
        $savePolicy->execute(['config'=>json_encode([
            'private_gallery_policy_approved'=>true,
            'private_gallery_policy_version'=>MembershipEntitlementCatalog::PRIVATE_GALLERY_POLICY_VERSION,
            'legal_approval_reference'=>'LEGAL-APPROVAL-TEST-001',
            'private_gallery_statement_sha256'=>MembershipEntitlementCatalog::privateGalleryConsentStatementHash('Talyvane'),
        ],JSON_THROW_ON_ERROR),'actor'=>$reviewerId]);
        $assert(MembershipGalleryPolicyReadiness::approved($pdo,'Talyvane'),
            'private gallery activation requires the approved current policy version and exact brand-bound statement hash');
        $assert(!MembershipGalleryPolicyReadiness::approved($pdo,'Different Agency'),
            'brand changes invalidate deployment policy approval until the exact statement hash is re-approved');
        if (is_array($previousPolicy)) {
            $pdo->prepare(
                'UPDATE feature_states SET enabled=:enabled,config_json=:config,updated_by=:actor,updated_at=UTC_TIMESTAMP()
                 WHERE feature_key="memberships.private_gallery"'
            )->execute([
                'enabled'=>$previousPolicy['enabled'],'config'=>$previousPolicy['config_json'],
                'actor'=>$previousPolicy['updated_by'],
            ]);
        } else {
            $pdo->exec('DELETE FROM feature_states WHERE feature_key="memberships.private_gallery"');
        }
        $service->grantMembersOnlyConsent($companion,$profileId,$membersOnlyId,true,'Talyvane',$requestId);
        $gallery=$galleryService->privateGalleryForClient($companionClient,'Talyvane');
        $assert(count(array_filter($gallery,static fn(array $item): bool => $item['id']===$membersOnlyId))===1,
            'a client with a current membership entitlement can see separately consented members-only media');
        $renamedBrandGallery=$galleryService->privateGalleryForClient($companionClient,'Different Agency');
        $assert(count(array_filter($renamedBrandGallery,static fn(array $item): bool => $item['id']===$membersOnlyId))===0,
            'changing the deployment brand invalidates the old exact members-only consent statement');
        $privateDelivery=$galleryService->privateMediaForClient($companionClient,$membersOnlyId,'Talyvane');
        $assert(hash_equals($membersOnlyImage['sha256'],hash('sha256',$privateDelivery['bytes'])) &&
            $privateDelivery['mime']==='image/webp',
            'private gallery delivery verifies media integrity after rechecking client entitlement and companion consent');
        $renamedBrandDeliveryDenied=false;
        try { $galleryService->privateMediaForClient($companionClient,$membersOnlyId,'Different Agency'); }
        catch (DomainException) { $renamedBrandDeliveryDenied=true; }
        $assert($renamedBrandDeliveryDenied,'changing the deployment brand invalidates final media delivery, not only the gallery listing');
        $noEntitlementDenied=false;
        try { $galleryService->privateMediaForClient(new Principal($reviewerId,['super_admin'],true),$membersOnlyId,'Talyvane'); }
        catch (DomainException) { $noEntitlementDenied=true; }
        $assert($noEntitlementDenied,'non-client principals cannot access the members-only gallery even when they have administrative MFA');
        $pdo->prepare('UPDATE memberships SET status="suspended",updated_at=UTC_TIMESTAMP() WHERE id=:id')
            ->execute(['id'=>$membershipId]);
        $suspendedMembershipDenied=false;
        try { $galleryService->privateMediaForClient($companionClient,$membersOnlyId,'Talyvane'); }
        catch (DomainException) { $suspendedMembershipDenied=true; }
        $assert($suspendedMembershipDenied,'suspending a membership immediately blocks final private media delivery');
        $pdo->prepare('UPDATE memberships SET status="active",updated_at=UTC_TIMESTAMP() WHERE id=:id')
            ->execute(['id'=>$membershipId]);
        $service->withdrawMembersOnlyConsent($companion,$profileId,$membersOnlyId,'Talyvane',$requestId);
        $withdrawnState=$pdo->prepare(
            'SELECT review_status,media_scope,members_only_consent_withdrawn_at FROM profile_media WHERE id=:id'
        );
        $withdrawnState->execute(['id'=>$membersOnlyId]);
        $withdrawn=$withdrawnState->fetch(PDO::FETCH_ASSOC);
        $assert(is_array($withdrawn) && $withdrawn['review_status']==='withdrawn' &&
            $withdrawn['media_scope']==='private' && $withdrawn['members_only_consent_withdrawn_at']!==null &&
            !file_exists($membersOnlyImage['path']),
            'companion withdrawal immediately makes the image non-deliverable and deletes its private bytes');

        $rejectedImage=$makeImage();
        $rejectedId=$uuid();
        $insertMedia->execute([
            'id'=>$rejectedId,'profile'=>$profileId,'actor'=>$companionId,'key'=>$rejectedImage['key'],
            'size'=>$rejectedImage['size'],'hash'=>$rejectedImage['sha256'],'scope'=>'private','status'=>'pending',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>null,'reviewed'=>null,
        ]);
        $recordUploaderAttestations($rejectedId);
        $service->review($admin,$rejectedId,'rejected','Image did not meet the current rights and quality review requirements',false,false,false,$requestId);
        $rejectedState=$pdo->prepare('SELECT review_status,media_scope FROM profile_media WHERE id=:id');
        $rejectedState->execute(['id'=>$rejectedId]);
        $rejected=$rejectedState->fetch();
        $assert(is_array($rejected) && $rejected['review_status']==='rejected' && $rejected['media_scope']==='private' &&
            !file_exists($rejectedImage['path']),
            'rejected media is retained as a private audit record while its image file is deleted');

        $withdrawnImage=$makeImage();
        $withdrawnId=$uuid();
        $insertMedia->execute([
            'id'=>$withdrawnId,'profile'=>$profileId,'actor'=>$companionId,'key'=>$withdrawnImage['key'],
            'size'=>$withdrawnImage['size'],'hash'=>$withdrawnImage['sha256'],'scope'=>'private','status'=>'pending',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>null,'reviewed'=>null,
        ]);
        $recordUploaderAttestations($withdrawnId);
        $service->withdraw($companion,$profileId,$withdrawnId,$requestId);
        $withdrawnState=$pdo->prepare('SELECT review_status,media_scope,withdrawn_by FROM profile_media WHERE id=:id');
        $withdrawnState->execute(['id'=>$withdrawnId]);
        $withdrawn=$withdrawnState->fetch();
        $assert(is_array($withdrawn) && $withdrawn['review_status']==='withdrawn' && $withdrawn['media_scope']==='private' &&
            $withdrawn['withdrawn_by']===$companionId && !file_exists($withdrawnImage['path']),
            'companion withdrawal immediately privatizes the media, records the actor and deletes the image');
        $eventQuery=$pdo->prepare('SELECT id FROM profile_media_consent_events WHERE profile_media_id=:media ORDER BY created_at DESC LIMIT 1');
        $eventQuery->execute(['media'=>$withdrawnId]);
        $eventId=(string) $eventQuery->fetchColumn();
        $updateRejected=false;
        try { $pdo->prepare('UPDATE profile_media_consent_events SET action="tampered" WHERE id=:id')->execute(['id'=>$eventId]); }
        catch (PDOException) { $updateRejected=true; }
        $deleteRejected=false;
        try { $pdo->prepare('DELETE FROM profile_media_consent_events WHERE id=:id')->execute(['id'=>$eventId]); }
        catch (PDOException) { $deleteRejected=true; }
        $assert($eventId!=='' && $updateRejected && $deleteRejected,
            'database triggers make profile-media consent evidence append-only');

        $service->withdrawPublicationConsent($companion,$profileId,$requestId);
        $withdrawnProfile=$pdo->prepare('SELECT status,publication_consent_withdrawn_at,auto_booking_opt_in FROM companion_profiles WHERE id=:id');
        $withdrawnProfile->execute(['id'=>$profileId]);
        $withdrawnProfileRow=$withdrawnProfile->fetch();
        $assert(is_array($withdrawnProfileRow) && $withdrawnProfileRow['status']==='unpublished' &&
            $withdrawnProfileRow['publication_consent_withdrawn_at']!==null && !(bool) $withdrawnProfileRow['auto_booking_opt_in'] &&
            $discovery->profile((string) $fixture['slug'])===null &&
            !file_exists($approvedImage['path']) && !file_exists($pendingImage['path']),
            'profile-wide publication withdrawal immediately unpublishes the profile, disables automatic booking and deletes public/pending images');
        try {
            $service->renewPublicationConsent($companion,$profileId,false,$requestId);
            $assert(false,'publication consent renewal requires an explicit confirmation');
        } catch (InvalidArgumentException) {
            $assert(true,'publication consent renewal requires an explicit confirmation');
        }
        $service->renewPublicationConsent($companion,$profileId,true,$requestId);
        $pendingProfile=$pdo->prepare('SELECT status,publication_consent_version,publication_consent_withdrawn_at,auto_booking_opt_in FROM companion_profiles WHERE id=:id');
        $pendingProfile->execute(['id'=>$profileId]);
        $pendingProfileRow=$pendingProfile->fetch();
        $assert(is_array($pendingProfileRow) && $pendingProfileRow['status']==='pending_review' &&
            $pendingProfileRow['publication_consent_version']==='profile-publication-v2' &&
            $pendingProfileRow['publication_consent_withdrawn_at']===null && !(bool) $pendingProfileRow['auto_booking_opt_in'],
            'renewed publication consent creates a new version and requires fresh human publication review');
        $publication=new \Talyvane\Application\Companions\CompanionProfilePublicationService($pdo,$audit);
        $publication->publish($admin,$profileId,'New versioned consent and current adult eligibility reviewed',bin2hex(random_bytes(16)));
        $republished=$discovery->profile((string) $fixture['slug']);
        $assert(is_array($republished) && ($republished['media'] ?? [])===[] && ($republished['primary_media_url'] ?? null)===null,
            'republishing after consent renewal never resurrects withdrawn or deleted images');
        try {
            $service->publicMedia($approvedId);
            $assert(false,'withdrawn media remains unavailable after profile re-publication');
        } catch (DomainException) {
            $assert(true,'withdrawn media remains unavailable after profile re-publication');
        }

        try {
            $storage->read('../outside.webp');
            $assert(false,'private media storage rejects path traversal');
        } catch (InvalidArgumentException) {
            $assert(true,'private media storage rejects path traversal');
        }
    } finally {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { @rmdir($entry->getPathname()); }
            else { @unlink($entry->getPathname()); }
        }
        @rmdir($tempRoot);
    }
    return $assertions;
}
