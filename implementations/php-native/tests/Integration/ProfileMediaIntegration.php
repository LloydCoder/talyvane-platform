<?php
declare(strict_types=1);

use Talyvane\Application\Discovery\DiscoveryService;
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
            'size'=>$approvedImage['size'],'hash'=>$approvedImage['sha256'],'scope'=>'public','status'=>'approved',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>$reviewerId,'reviewed'=>gmdate('Y-m-d H:i:s'),
        ]);
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

        $rejectedImage=$makeImage();
        $rejectedId=$uuid();
        $insertMedia->execute([
            'id'=>$rejectedId,'profile'=>$profileId,'actor'=>$companionId,'key'=>$rejectedImage['key'],
            'size'=>$rejectedImage['size'],'hash'=>$rejectedImage['sha256'],'scope'=>'private','status'=>'pending',
            'grant'=>$fixture['consent_grant_id'],'version'=>$fixture['publication_consent_version'],
            'reviewer'=>null,'reviewed'=>null,
        ]);
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
            $discovery->profile((string) $fixture['slug'])===null,
            'profile-wide publication withdrawal immediately unpublishes the profile and disables automatic booking');
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
        $publication=new \\Talyvane\\Application\\Companions\\CompanionProfilePublicationService($pdo,$audit);
        $publication->publish($admin,$profileId,'New versioned consent and current adult eligibility reviewed',bin2hex(random_bytes(16)));
        $republished=$discovery->profile((string) $fixture['slug']);
        $assert(is_array($republished) && ($republished['media'] ?? [])===[] && ($republished['primary_media_url'] ?? null)===null,
            'republishing after consent renewal never resurrects withdrawn or deleted images');

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
