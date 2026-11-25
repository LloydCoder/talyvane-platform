<?php
declare(strict_types=1);

namespace Talyvane\Application\Media;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Companion image intake, human moderation and request-time public delivery checks. */
final class ProfileMediaService
{
    public function __construct(
        private PDO $pdo,
        private PrivateMediaStorage $storage,
        private DiscoveryService $discovery,
        private AuditLogger $audit
    ) {}

    /** @return array<string,mixed>|null */
    public function profileForCompanion(Principal $actor): ?array
    {
        $this->assertCompanion($actor);
        $query = $this->pdo->prepare(
            'SELECT p.id,p.slug,p.display_name,p.status,p.adult_eligibility_status,
                    p.publication_consent_version,d.display_name AS destination_name
             FROM companion_profiles p JOIN destinations d ON d.id=p.destination_id
             WHERE p.user_id=:actor LIMIT 1'
        );
        $query->execute(['actor'=>$actor->id]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForCompanion(Principal $actor): array
    {
        $this->assertCompanion($actor);
        $query = $this->pdo->prepare(
            'SELECT pm.id,pm.width,pm.height,pm.file_size,pm.review_status,pm.media_scope,
                    pm.review_note,pm.created_at,pm.reviewed_at,pm.withdrawn_at
             FROM profile_media pm JOIN companion_profiles p ON p.id=pm.profile_id
             WHERE p.user_id=:actor
             ORDER BY pm.created_at DESC LIMIT 50'
        );
        $query->execute(['actor'=>$actor->id]);
        return $query->fetchAll();
    }

    /** @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $upload */
    public function submit(
        Principal $actor,
        string $profileId,
        array $upload,
        bool $rightsConfirmed,
        bool $depictedAdultsConfirmed,
        bool $publicationConfirmed,
        string $requestId
    ): string {
        $this->assertCompanion($actor);
        if (!$rightsConfirmed || !$depictedAdultsConfirmed || !$publicationConfirmed) {
            throw new InvalidArgumentException('Separate image-rights, depicted-adult and public-publication confirmations are required.');
        }
        $profile = $this->profileForCompanion($actor);
        if (!is_array($profile) || !hash_equals((string) $profile['id'],$profileId)) {
            throw new DomainException('This profile is not linked to the signed-in companion account.');
        }
        if ($profile['status'] !== 'published' || $profile['adult_eligibility_status'] !== 'verified' ||
            !is_string($profile['publication_consent_version']) || $profile['publication_consent_version'] === '') {
            throw new DomainException('An actively published, independently verified and consented profile is required.');
        }
        if ($this->discovery->profile((string) $profile['slug']) === null) {
            throw new DomainException('The profile is not currently eligible for public media submission.');
        }
        $consent = $this->activePublicationGrant($profileId,(string) $profile['publication_consent_version']);
        if (!is_array($consent)) {
            throw new DomainException('Current public publication consent could not be verified.');
        }

        $image = $this->storage->storeImage($upload,'profile-media',8_388_608);
        $mediaId = self::uuid();
        $rightsStatement = 'I confirm that I own or have the necessary rights and permissions to submit this image, including the likeness rights of every identifiable person depicted.';
        $adultStatement = 'I confirm that every identifiable person depicted in this image is an adult aged 18 or older and that no minor is depicted.';
        $publicationStatement = 'I separately consent to this image being submitted for human review and, only if approved, displayed publicly on my companion profile.';
        $this->pdo->beginTransaction();
        try {
            $lockedProfile = $this->pdo->prepare(
                'SELECT id,user_id,status,adult_eligibility_status,publication_consent_version,publication_consent_withdrawn_at
                 FROM companion_profiles WHERE id=:profile FOR UPDATE'
            );
            $lockedProfile->execute(['profile'=>$profileId]);
            $current = $lockedProfile->fetch();
            if (!is_array($current) || (string) $current['user_id'] !== $actor->id ||
                $current['status'] !== 'published' || $current['adult_eligibility_status'] !== 'verified' ||
                $current['publication_consent_withdrawn_at'] !== null ||
                !hash_equals((string) $current['publication_consent_version'],(string) $profile['publication_consent_version']) ||
                $this->discovery->profile((string) $profile['slug']) === null) {
                throw new DomainException('Profile eligibility or consent changed while the image was being processed.');
            }
            $grant = $this->activePublicationGrant($profileId,(string) $current['publication_consent_version'],true);
            if (!is_array($grant) || (string) $grant['id'] !== (string) $consent['id']) {
                throw new DomainException('Public publication consent changed while the image was being processed.');
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO profile_media
                 (id,profile_id,uploaded_by,private_storage_key,mime_type,file_size,sha256,width,height,
                  media_scope,review_status,consent_grant_id,rights_confirmed_at,consent_version,created_at,updated_at)
                 VALUES (:id,:profile,:actor,:key,:mime,:size,:hash,:width,:height,
                         "private","pending",:grant,UTC_TIMESTAMP(),:version,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$mediaId,'profile'=>$profileId,'actor'=>$actor->id,'key'=>$image['key'],
                'mime'=>$image['mime'],'size'=>$image['size'],'hash'=>$image['sha256'],
                'width'=>$image['width'],'height'=>$image['height'],'grant'=>$grant['id'],
                'version'=>$current['publication_consent_version'],
            ]);
            $this->recordConsent($mediaId,$profileId,$actor,$rightsStatement,'image_rights.attested','profile-media-rights-v1',$requestId);
            $this->recordConsent($mediaId,$profileId,$actor,$adultStatement,'depicted_adults.attested','profile-media-adult-subjects-v1',$requestId);
            $this->recordConsent($mediaId,$profileId,$actor,$publicationStatement,'public_publication.attested','profile-media-publication-v1',$requestId);
            $this->audit->record($actor->id,'profile.media.submitted','profile_media',$mediaId,
                'Companion submitted a private image for human review',
                null,['review_status'=>'pending','media_scope'=>'private','profile_id'=>$profileId],
                $requestId,'profile-media-governance-v1');
            $this->pdo->commit();
            return $mediaId;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->storage->delete($image['key']);
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function pendingReview(Principal $actor,int $limit=50): array
    {
        $this->assertReviewer($actor);
        $limit=max(1,min(100,$limit));
        $query=$this->pdo->prepare(
            'SELECT pm.id,pm.width,pm.height,pm.file_size,pm.created_at,p.display_name AS profile_name,
                    p.slug AS profile_slug,d.display_name AS destination_name
             FROM profile_media pm JOIN companion_profiles p ON p.id=pm.profile_id
             JOIN destinations d ON d.id=p.destination_id
             WHERE pm.review_status="pending" AND pm.media_scope="private"
             ORDER BY pm.created_at ASC LIMIT :limit'
        );
        $query->bindValue(':limit',$limit,PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function preview(Principal $actor,string $mediaId): array
    {
        $this->assertReviewer($actor);
        $query=$this->pdo->prepare(
            'SELECT private_storage_key,sha256 FROM profile_media
             WHERE id=:id AND review_status="pending" AND media_scope="private" LIMIT 1'
        );
        $query->execute(['id'=>$mediaId]);
        $media=$query->fetch();
        if (!is_array($media)) {
            throw new DomainException('Pending media was not found.');
        }
        $bytes=$this->storage->read((string) $media['private_storage_key']);
        if (!hash_equals((string) $media['sha256'],hash('sha256',$bytes))) {
            throw new RuntimeException('Private media integrity verification failed.');
        }
        return ['bytes'=>$bytes,'mime'=>'image/webp'];
    }

    public function review(Principal $actor,string $mediaId,string $decision,string $reason,bool $adultSafetyConfirmed,bool $rightsConfirmed,bool $consentConfirmed,string $requestId): void
    {
        $this->assertReviewer($actor);
        if (!in_array($decision,['approved','rejected'],true)) {
            throw new InvalidArgumentException('Choose an approve or reject decision.');
        }
        $reason=trim($reason);
        if (strlen($reason)<10 || strlen($reason)>500) {
            throw new InvalidArgumentException('A review reason between 10 and 500 characters is required.');
        }
        if ($decision==='approved' && (!$adultSafetyConfirmed || !$rightsConfirmed || !$consentConfirmed)) {
            throw new InvalidArgumentException('Approval requires explicit adult-safety, image-rights and consent attestations.');
        }
        $this->pdo->beginTransaction();
        $deleteKey=null;
        try {
            $query=$this->pdo->prepare(
                'SELECT pm.id,pm.profile_id,pm.private_storage_key,pm.sha256,pm.consent_grant_id,pm.consent_version,pm.review_status,
                        p.slug,p.status,p.adult_eligibility_status,p.publication_consent_version,
                        p.publication_consent_withdrawn_at
                 FROM profile_media pm JOIN companion_profiles p ON p.id=pm.profile_id
                 WHERE pm.id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$mediaId]);
            $media=$query->fetch();
            if (!is_array($media) || $media['review_status']!=='pending') {
                throw new DomainException('This media item is not awaiting review.');
            }
            $before=['review_status'=>'pending','media_scope'=>'private'];
            if ($decision==='approved') {
                $publicProfile=$this->discovery->profile((string) $media['slug']);
                if (!is_array($publicProfile) || (string) $publicProfile['id']!==(string) $media['profile_id'] ||
                    $media['status']!=='published' || $media['adult_eligibility_status']!=='verified' ||
                    $media['publication_consent_withdrawn_at']!==null ||
                    !hash_equals((string) $media['consent_version'],(string) $media['publication_consent_version'])) {
                    throw new DomainException('Profile eligibility, market approval or public consent is no longer current.');
                }
                $grant=$this->activePublicationGrant((string) $media['profile_id'],(string) $media['publication_consent_version'],true);
                if (!is_array($grant) || (string) $grant['id'] !== (string) $media['consent_grant_id']) {
                    throw new DomainException('Current public publication consent is unavailable.');
                }
                $bytes=$this->storage->read((string) $media['private_storage_key']);
                if (!hash_equals((string) $media['sha256'],hash('sha256',$bytes))) {
                    throw new RuntimeException('Private media integrity verification failed.');
                }
            } else {
                $deleteKey=(string) $media['private_storage_key'];
            }
            $update=$this->pdo->prepare(
                'UPDATE profile_media SET review_status=:status,media_scope=:scope,reviewed_by=:actor,
                    reviewed_at=UTC_TIMESTAMP(),review_note=:reason,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id AND review_status="pending"'
            );
            $update->execute([
                'status'=>$decision,'scope'=>$decision==='approved'?'public':'private',
                'actor'=>$actor->id,'reason'=>$reason,'id'=>$mediaId,
            ]);
            if ($update->rowCount()!==1) {
                throw new DomainException('Media review state changed; reload and retry.');
            }
            if ($decision==='approved') {
                $this->recordConsent($mediaId,(string) $media['profile_id'],$actor,
                    'I reviewed the image and confirm every identifiable person depicted is an adult aged 18 or older; no minor is depicted.',
                    'reviewer.adult_safety.confirmed','profile-media-review-v1',$requestId,'mfa_admin_review_attestation');
                $this->recordConsent($mediaId,(string) $media['profile_id'],$actor,
                    'I reviewed the uploader rights attestation and the available image evidence and found no apparent rights conflict.',
                    'reviewer.image_rights.confirmed','profile-media-review-v1',$requestId,'mfa_admin_review_attestation');
                $this->recordConsent($mediaId,(string) $media['profile_id'],$actor,
                    'I reviewed the image and available evidence and found no apparent coercion or non-consensual depiction.',
                    'reviewer.consent_safety.confirmed','profile-media-review-v1',$requestId,'mfa_admin_review_attestation');
            }
            $this->audit->record($actor->id,'profile.media.reviewed','profile_media',$mediaId,$reason,
                $before,['review_status'=>$decision,'media_scope'=>$decision==='approved'?'public':'private'],
                $requestId,'profile-media-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        if (is_string($deleteKey)) {
            $this->storage->delete($deleteKey);
        }
    }

    public function withdrawPublicationConsent(Principal $actor,string $profileId,string $requestId): void
    {
        $this->assertCompanion($actor);
        $this->pdo->beginTransaction();
        $keys=[];
        try {
            $profileQuery=$this->pdo->prepare(
                'SELECT id,user_id,status,publication_consent_version,publication_consent_withdrawn_at
                 FROM companion_profiles WHERE id=:profile FOR UPDATE'
            );
            $profileQuery->execute(['profile'=>$profileId]);
            $profile=$profileQuery->fetch();
            if (!is_array($profile) || (string) $profile['user_id']!==$actor->id) {
                throw new DomainException('This profile is not linked to the signed-in companion account.');
            }
            if ($profile['publication_consent_withdrawn_at']!==null) {
                throw new DomainException('Public profile publication consent has already been withdrawn.');
            }
            $mediaQuery=$this->pdo->prepare(
                'SELECT id,private_storage_key FROM profile_media
                 WHERE profile_id=:profile AND review_status NOT IN ("withdrawn","rejected") FOR UPDATE'
            );
            $mediaQuery->execute(['profile'=>$profileId]);
            $mediaRows=$mediaQuery->fetchAll();
            foreach ($mediaRows as $media) {
                $keys[]=(string) $media['private_storage_key'];
                $this->recordConsent(
                    (string) $media['id'],$profileId,$actor,
                    'I withdraw publication consent for this profile and all submitted images. The profile must be unpublished and all public images removed.',
                    'profile.publication.withdrawn','profile-publication-withdrawal-v1',$requestId,
                    'authenticated_companion_portal_publication_withdrawal'
                );
            }
            $this->pdo->prepare(
                'UPDATE profile_media SET review_status="withdrawn",media_scope="private",
                    withdrawn_at=UTC_TIMESTAMP(),withdrawn_by=:actor,updated_at=UTC_TIMESTAMP()
                 WHERE profile_id=:profile AND review_status NOT IN ("withdrawn","rejected")'
            )->execute(['actor'=>$actor->id,'profile'=>$profileId]);
            $this->pdo->prepare(
                'UPDATE consent_grants SET withdrawn_at=UTC_TIMESTAMP()
                 WHERE subject_type="companion_profile" AND subject_id=:profile
                   AND purpose="profile.publication" AND audience="public" AND granted=1 AND withdrawn_at IS NULL'
            )->execute(['profile'=>$profileId]);
            $this->pdo->prepare(
                'UPDATE companion_profiles SET status="unpublished",publication_consent_withdrawn_at=UTC_TIMESTAMP(),
                    auto_booking_opt_in=0,updated_at=UTC_TIMESTAMP() WHERE id=:profile'
            )->execute(['profile'=>$profileId]);
            $this->audit->record($actor->id,'profile.publication.withdrawn','companion_profile',$profileId,
                'Companion withdrew profile publication consent',
                ['status'=>$profile['status'],'publication_consent_withdrawn_at'=>null],
                ['status'=>'unpublished','publication_consent_withdrawn_at'=>'set','auto_booking_opt_in'=>false],
                $requestId,'profile-media-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        foreach ($keys as $key) {
            $this->storage->delete($key);
        }
    }

    public function renewPublicationConsent(Principal $actor,string $profileId,bool $confirmed,string $requestId): void
    {
        $this->assertCompanion($actor);
        if (!$confirmed) {
            throw new InvalidArgumentException('Explicit public profile publication consent is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,status,publication_consent_withdrawn_at
                 FROM companion_profiles WHERE id=:profile FOR UPDATE'
            );
            $query->execute(['profile'=>$profileId]);
            $profile=$query->fetch();
            if (!is_array($profile) || (string) $profile['user_id']!==$actor->id ||
                $profile['status']!=='unpublished' || $profile['publication_consent_withdrawn_at']===null) {
                throw new DomainException('This profile is not eligible for publication-consent renewal.');
            }
            $version='profile-publication-v2';
            $grantId=self::uuid();
            $this->pdo->prepare(
                'INSERT INTO consent_grants
                 (id,subject_type,subject_id,purpose,audience,policy_version,granted,granted_at,withdrawn_at,evidence_reference,created_at)
                 VALUES (:id,"companion_profile",:profile,"profile.publication","public",:version,1,UTC_TIMESTAMP(),NULL,:evidence,UTC_TIMESTAMP())'
            )->execute([
                'id'=>$grantId,'profile'=>$profileId,'version'=>$version,
                'evidence'=>'companion-portal-renewal:'.self::uuid(),
            ]);
            $this->pdo->prepare(
                'UPDATE companion_profiles SET status="pending_review",publication_consent_version=:version,
                    publication_consent_withdrawn_at=NULL,auto_booking_opt_in=0,updated_at=UTC_TIMESTAMP()
                 WHERE id=:profile'
            )->execute(['version'=>$version,'profile'=>$profileId]);
            $this->audit->record($actor->id,'profile.publication.renewed','companion_profile',$profileId,
                'Companion granted a new versioned public publication consent',
                ['status'=>'unpublished','publication_consent_withdrawn_at'=>'set'],
                ['status'=>'pending_review','publication_consent_version'=>$version,'publication_consent_withdrawn_at'=>null],
                $requestId,'profile-media-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    public function publicMedia(string $mediaId): array
    {
        $query=$this->pdo->prepare(
            'SELECT pm.id,pm.profile_id,pm.private_storage_key,pm.sha256,pm.consent_grant_id,pm.consent_version,p.slug,p.publication_consent_version
             FROM profile_media pm JOIN companion_profiles p ON p.id=pm.profile_id
             WHERE pm.id=:id AND pm.review_status="approved" AND pm.media_scope="public" LIMIT 1'
        );
        $query->execute(['id'=>$mediaId]);
        $media=$query->fetch();
        if (!is_array($media)) {
            throw new DomainException('Public media was not found.');
        }
        $profile=$this->discovery->profile((string) $media['slug']);
        if (!is_array($profile) || (string) $profile['id']!==(string) $media['profile_id'] ||
            !hash_equals((string) $media['consent_version'],(string) $media['publication_consent_version'])) {
            throw new DomainException('Public profile eligibility or consent is no longer current.');
        }
        $grant=$this->activePublicationGrant((string) $media['profile_id'],(string) $media['publication_consent_version']);
        if (!is_array($grant) || (string) $grant['id'] !== (string) $media['consent_grant_id']) {
            throw new DomainException('Current publication consent is unavailable.');
        }
        $bytes=$this->storage->read((string) $media['private_storage_key']);
        if (!hash_equals((string) $media['sha256'],hash('sha256',$bytes))) {
            throw new RuntimeException('Public media integrity verification failed.');
        }
        return ['bytes'=>$bytes,'mime'=>'image/webp'];
    }

    /** @return list<array{id:string,url:string}> */
    public function publicMediaForProfile(string $profileId): array
    {
        $query=$this->pdo->prepare(
            'SELECT pm.id FROM profile_media pm
             JOIN companion_profiles p ON p.id=pm.profile_id
             JOIN consent_grants cg ON cg.id=pm.consent_grant_id
             WHERE pm.profile_id=:profile AND pm.review_status="approved" AND pm.media_scope="public"
               AND pm.consent_version=p.publication_consent_version
               AND cg.granted=1 AND cg.withdrawn_at IS NULL
               AND cg.policy_version=p.publication_consent_version
             ORDER BY pm.created_at ASC LIMIT 12'
        );
        $query->execute(['profile'=>$profileId]);
        return array_map(static fn(array $row): array=>[
            'id'=>(string) $row['id'],'url'=>'/profile-media/' . rawurlencode((string) $row['id']),
        ],$query->fetchAll());
    }

    public function withdraw(Principal $actor,string $profileId,string $mediaId,string $requestId): void
    {
        $this->assertCompanion($actor);
        $this->pdo->beginTransaction();
        $key=null;
        try {
            $query=$this->pdo->prepare(
                'SELECT pm.id,pm.private_storage_key,pm.review_status,pm.consent_version,p.user_id
                 FROM profile_media pm JOIN companion_profiles p ON p.id=pm.profile_id
                 WHERE pm.id=:media AND pm.profile_id=:profile FOR UPDATE'
            );
            $query->execute(['media'=>$mediaId,'profile'=>$profileId]);
            $media=$query->fetch();
            if (!is_array($media) || (string) $media['user_id']!==$actor->id ||
                in_array($media['review_status'],['withdrawn','rejected'],true)) {
                throw new DomainException('This media item cannot be withdrawn by this account.');
            }
            $update=$this->pdo->prepare(
                'UPDATE profile_media SET review_status="withdrawn",media_scope="private",
                    withdrawn_at=UTC_TIMESTAMP(),withdrawn_by=:actor,updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            );
            $update->execute(['actor'=>$actor->id,'id'=>$mediaId]);
            $key=(string) $media['private_storage_key'];
            $this->recordConsent($mediaId,$profileId,$actor,
                'I withdraw this image from my profile submissions and public distribution.',
                'media.withdrawn','profile-media-withdrawal-v1',$requestId,'authenticated_companion_portal_withdrawal');
            $this->audit->record($actor->id,'profile.media.withdrawn','profile_media',$mediaId,
                'Companion withdrew an individual profile image',
                ['review_status'=>$media['review_status']],['review_status'=>'withdrawn','media_scope'=>'private'],
                $requestId,'profile-media-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        if (is_string($key)) {
            $this->storage->delete($key);
        }
    }

    /** @return array<string,mixed>|null */
    private function activePublicationGrant(string $profileId,string $version,bool $lock=false): ?array
    {
        $sql='SELECT id,policy_version FROM consent_grants
              WHERE subject_type="companion_profile" AND subject_id=:profile
                AND purpose="profile.publication" AND audience="public"
                AND policy_version=:version AND granted=1 AND withdrawn_at IS NULL
              ORDER BY granted_at DESC LIMIT 1';
        if ($lock) { $sql.=' FOR UPDATE'; }
        $query=$this->pdo->prepare($sql);
        $query->execute(['profile'=>$profileId,'version'=>$version]);
        $row=$query->fetch();
        return is_array($row)?$row:null;
    }

    private function recordConsent(
        string $mediaId,string $profileId,Principal $actor,string $statement,string $action,string $version,string $requestId,
        string $captureMethod='authenticated_companion_portal_checkbox'
    ): void {
        $query=$this->pdo->prepare(
            'INSERT INTO profile_media_consent_events
             (id,profile_media_id,companion_profile_id,media_reference,actor_id,actor_email,action,
              capture_method,consent_policy_version,consent_statement,statement_sha256,correlation_id,created_at)
             SELECT :id,:media,:profile,:reference,:actor_id,u.email,:action,
                    :capture_method,:version,:statement,:hash,:correlation,UTC_TIMESTAMP()
             FROM users u WHERE u.id=:user_id'
        );
        $query->execute([
            'id'=>self::uuid(),'media'=>$mediaId,'profile'=>$profileId,'reference'=>'profile-media:' . $mediaId,
            'actor_id'=>$actor->id,'user_id'=>$actor->id,'action'=>$action,'capture_method'=>$captureMethod,
            'version'=>$version,'statement'=>$statement,'hash'=>hash('sha256',$statement),'correlation'=>self::uuid(),
        ]);
    }

    private function assertCompanion(Principal $actor): void
    {
        Authorization::require($actor,'companion.consent.manage',false);
        if (!$actor->hasRole('companion') || count($actor->roles)!==1) {
            throw new DomainException('Only the linked companion account can manage profile media.');
        }
    }

    private function assertReviewer(Principal $actor): void
    {
        Authorization::require($actor,'media.review',true);
        if (!$actor->hasRole('admin') && !$actor->hasRole('owner') && !$actor->hasRole('super_admin')) {
            throw new DomainException('Only an MFA-verified administrator can review profile media.');
        }
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);
        $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);
        $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        $hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
