<?php
declare(strict_types=1);

namespace Talyvane\Application\Destinations;

use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Storage\PrivateMediaStorage;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

/** Rights-attested destination cover intake, independent review and public delivery. */
final class DestinationCoverService
{
    public const RIGHTS_VERSION='destination-cover-rights-v1';
    public const RIGHTS_STATEMENT='I confirm that I own or have the necessary rights and permissions to use this image as the destination cover, including all copyright, licence, property and identifiable-person likeness rights. I authorize its display on this destination page and understand that approval is required before publication.';

    public function __construct(
        private PDO $pdo,
        private PrivateMediaStorage $storage,
        private AuditLogger $audit
    ) {}

    public function submit(Principal $actor,string $destinationId,array $upload,bool $rightsConfirmed,string $requestId): string
    {
        $this->assertAgencyAdministrator($actor);
        if (!$rightsConfirmed) throw new InvalidArgumentException('An explicit destination-image rights declaration is required.');
        if (!preg_match('/^[0-9a-f-]{36}$/iD',$destinationId) || strlen($requestId)<16 || strlen($requestId)>190) {
            throw new InvalidArgumentException('Destination cover request is invalid.');
        }
        $destination=$this->pdo->prepare('SELECT id,status FROM destinations WHERE id=:id LIMIT 1');
        $destination->execute(['id'=>$destinationId]);
        if (!is_array($destination->fetch(PDO::FETCH_ASSOC))) throw new DomainException('Destination was not found.');
        $image=$this->storage->storeImage($upload,'destination-covers',8_388_608);
        $id=self::uuid();
        try {
            $this->pdo->beginTransaction();
            $lock=$this->pdo->prepare('SELECT id FROM destinations WHERE id=:id FOR UPDATE');
            $lock->execute(['id'=>$destinationId]);
            if ($lock->fetchColumn()===false) throw new DomainException('Destination was removed before cover submission.');
            $insert=$this->pdo->prepare(
                'INSERT INTO destination_cover_assets
                 (id,destination_id,uploaded_by,private_storage_key,mime_type,file_size,sha256,
                  rights_declaration_version,rights_statement_hash,rights_confirmed_at,review_status,created_at)
                 VALUES (:id,:destination,:actor,:key,:mime,:size,:hash,:version,:statement_hash,UTC_TIMESTAMP(),"pending",UTC_TIMESTAMP())'
            );
            $insert->execute([
                'id'=>$id,'destination'=>$destinationId,'actor'=>$actor->id,'key'=>$image['key'],
                'mime'=>$image['mime'],'size'=>$image['size'],'hash'=>$image['sha256'],
                'version'=>self::RIGHTS_VERSION,'statement_hash'=>hash('sha256',self::RIGHTS_STATEMENT),
            ]);
            $this->audit->record($actor->id,'destination.cover.submitted','destination_cover',$id,
                'Rights-attested destination cover submitted for independent review',null,
                ['destination_id'=>$destinationId,'review_status'=>'pending','rights_version'=>self::RIGHTS_VERSION],
                $requestId,'destination-cover-governance-v1');
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->storage->delete($image['key']);
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> */
    public function pending(Principal $reviewer): array
    {
        $this->assertAgencyAdministrator($reviewer);
        return $this->pdo->query(
            'SELECT a.id,a.destination_id,a.uploaded_by,a.mime_type,a.file_size,a.created_at,
                    d.display_name AS destination_name,d.slug
             FROM destination_cover_assets a JOIN destinations d ON d.id=a.destination_id
             WHERE a.review_status="pending" AND a.withdrawn_at IS NULL
             ORDER BY a.created_at ASC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function review(Principal $reviewer,string $assetId,string $decision,string $reason,string $requestId): void
    {
        $this->assertAgencyAdministrator($reviewer);
        $reason=trim($reason);
        if (!in_array($decision,['approved','rejected'],true) || strlen($reason)<10 || strlen($reason)>500 ||
            strlen($requestId)<16 || strlen($requestId)>190) {
            throw new InvalidArgumentException('A valid cover decision and reason are required.');
        }
        $deleteKey=null;
        $oldKey=null;
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT a.*,d.cover_media_key FROM destination_cover_assets a
                 JOIN destinations d ON d.id=a.destination_id WHERE a.id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$assetId]);
            $asset=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($asset) || $asset['review_status']!=='pending' || $asset['withdrawn_at']!==null) {
                throw new DomainException('Destination cover is no longer awaiting review.');
            }
            if ((string)$asset['uploaded_by']===$reviewer->id) {
                throw new DomainException('The uploader cannot approve or reject their own destination cover.');
            }
            if ($decision==='approved') {
                if (!hash_equals(self::RIGHTS_VERSION,(string)$asset['rights_declaration_version']) ||
                    !hash_equals(hash('sha256',self::RIGHTS_STATEMENT),(string)$asset['rights_statement_hash'])) {
                    throw new DomainException('The destination cover rights declaration is missing or outdated.');
                }
                $bytes=$this->storage->read((string)$asset['private_storage_key']);
                if (!hash_equals((string)$asset['sha256'],hash('sha256',$bytes))) {
                    throw new RuntimeException('Destination cover integrity verification failed.');
                }
                $oldKey=$asset['cover_media_key']===null?null:(string)$asset['cover_media_key'];
                $this->pdo->prepare(
                    'UPDATE destinations SET cover_media_key=:key,rights_declaration_version=:version,updated_at=UTC_TIMESTAMP()
                     WHERE id=:destination'
                )->execute(['key'=>$asset['private_storage_key'],'version'=>self::RIGHTS_VERSION,'destination'=>$asset['destination_id']]);
            } else {
                $deleteKey=(string)$asset['private_storage_key'];
            }
            $this->pdo->prepare(
                'UPDATE destination_cover_assets SET review_status=:status,reviewed_by=:reviewer,
                 reviewed_at=UTC_TIMESTAMP(),review_reason=:reason WHERE id=:id AND review_status="pending"'
            )->execute(['status'=>$decision,'reviewer'=>$reviewer->id,'reason'=>$reason,'id'=>$assetId]);
            $this->audit->record($reviewer->id,'destination.cover.reviewed','destination_cover',$assetId,
                $reason,['review_status'=>'pending'],['review_status'=>$decision,'destination_id'=>$asset['destination_id']],
                $requestId,'destination-cover-governance-v1');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        if ($deleteKey!==null) $this->storage->delete($deleteKey);
        if ($oldKey!==null && $oldKey!==$asset['private_storage_key']) $this->storage->delete($oldKey);
    }

    public function withdraw(Principal $actor,string $assetId,string $reason,string $requestId): void
    {
        $this->assertAgencyAdministrator($actor);
        $reason=trim($reason);
        if (strlen($reason)<10 || strlen($reason)>500 || strlen($requestId)<16 || strlen($requestId)>190) {
            throw new InvalidArgumentException('A meaningful cover withdrawal reason is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT a.id,a.destination_id,a.private_storage_key,a.review_status,a.withdrawn_at,d.cover_media_key
                 FROM destination_cover_assets a JOIN destinations d ON d.id=a.destination_id
                 WHERE a.id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$assetId]);
            $asset=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($asset) || $asset['withdrawn_at']!==null ||
                !in_array($asset['review_status'],['approved','pending'],true)) {
                throw new DomainException('Destination cover is not available for withdrawal.');
            }
            $this->pdo->prepare(
                'UPDATE destination_cover_assets SET review_status="withdrawn",withdrawn_at=UTC_TIMESTAMP(),
                 review_reason=:reason WHERE id=:id'
            )->execute(['reason'=>$reason,'id'=>$assetId]);
            if ($asset['cover_media_key']!==null && hash_equals((string)$asset['private_storage_key'],(string)$asset['cover_media_key'])) {
                $this->pdo->prepare(
                    'UPDATE destinations SET cover_media_key=NULL,rights_declaration_version=NULL,updated_at=UTC_TIMESTAMP()
                     WHERE id=:destination AND cover_media_key=:key'
                )->execute(['destination'=>$asset['destination_id'],'key'=>$asset['private_storage_key']]);
            }
            $this->audit->record($actor->id,'destination.cover.withdrawn','destination_cover',$assetId,$reason,
                ['review_status'=>$asset['review_status']],['review_status'=>'withdrawn','destination_id'=>$asset['destination_id']],
                $requestId,'destination-cover-governance-v1');
            $this->pdo->commit();
        } catch (\\Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
        $this->storage->delete((string)$asset['private_storage_key']);
    }

    /** @return array{bytes:string,mime:string}|null */
    public function publicCover(string $slug): ?array
    {
        $query=$this->pdo->prepare(
            'SELECT a.private_storage_key,a.sha256,a.mime_type
             FROM destinations d
             JOIN market_policies m ON m.country_code=d.country_code
             JOIN countries c ON c.alpha2=d.country_code
             JOIN destination_cover_assets a ON a.destination_id=d.id
             WHERE d.slug=:slug AND d.status="published" AND d.cover_media_key=a.private_storage_key
               AND c.enabled=1 AND m.enabled=1 AND m.decision="approved"
               AND m.reviewed_at IS NOT NULL AND m.expires_at>UTC_TIMESTAMP()
               AND EXISTS (
                   SELECT 1 FROM user_roles ur JOIN admin_mfa_credentials mfa ON mfa.user_id=ur.user_id
                   WHERE ur.user_id=m.reviewer_id AND ur.role_key IN ("owner","admin","super_admin")
                     AND mfa.enabled_at IS NOT NULL
               )
               AND a.review_status="approved" AND a.reviewed_by IS NOT NULL
               AND a.reviewed_at IS NOT NULL AND a.withdrawn_at IS NULL
             ORDER BY a.reviewed_at DESC LIMIT 1'
        );
        $query->execute(['slug'=>$slug]);
        $asset=$query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($asset)) return null;
        $bytes=$this->storage->read((string)$asset['private_storage_key']);
        if (!hash_equals((string)$asset['sha256'],hash('sha256',$bytes))) {
            throw new RuntimeException('Destination cover integrity verification failed.');
        }
        return ['bytes'=>$bytes,'mime'=>(string)$asset['mime_type']];
    }

    private function assertAgencyAdministrator(Principal $actor): void
    {
        Authorization::require($actor,'site.content.manage',true);
        if (!$actor->hasRole('super_admin') && count(array_intersect($actor->roles,['owner','admin']))!==1) {
            throw new DomainException('An MFA-authorized agency administrator is required.');
        }
    }

    private static function uuid(): string
    {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
