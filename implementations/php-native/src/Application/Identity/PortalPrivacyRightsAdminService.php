<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Throwable;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;
use Talyvane\Security\SecretBox;

/** MFA-gated privacy request review. Fulfilment is deliberately not implied by a review decision. */
final class PortalPrivacyRightsAdminService
{
    public function __construct(private PDO $pdo,private SecretBox $box,private AuditLogger $audit) {}

    /** @return list<array<string,mixed>> */
    public function queue(Principal $actor,string $requestId): array
    {
        Authorization::require($actor,'privacy.requests.review');
        if(strlen($requestId)<16 || strlen($requestId)>64) throw new \InvalidArgumentException('Privacy queue request reference is invalid.');
        $query=$this->pdo->query(
            'SELECT r.id,r.user_id,r.account_type,r.request_type,r.status,r.payload_ciphertext,
                    r.verified_at,r.created_at,u.email
             FROM portal_privacy_requests r
             JOIN users u ON u.id=r.user_id
             WHERE r.status="in_review"
             ORDER BY r.created_at ASC LIMIT 200'
        );
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) {
            $row['correction_summary']=null;
            if(is_string($row['payload_ciphertext']) && $row['payload_ciphertext']!=='') {
                try {
                    $payload=json_decode($this->box->decrypt($row['payload_ciphertext'],
                        'portal-privacy-request-v1:payload:'.$row['id']),true,8,JSON_THROW_ON_ERROR);
                    $row['correction_summary']=is_array($payload)?($payload['summary']??null):null;
                } catch(Throwable) {
                    $row['correction_summary']='Encrypted request details are unavailable; investigate key/configuration integrity.';
                }
            }
            unset($row['payload_ciphertext']);
        }
        unset($row);
        $this->audit->record($actor->id,'portal.privacy.queue.viewed','portal_privacy_request',null,
            'Administrator accessed the verified privacy request review queue',null,['request_count'=>count($rows)],$requestId,'portal-privacy-rights-v1');
        return $rows;
    }

    public function review(Principal $actor,string $requestId,string $decision,string $reason,string $correlationId): void
    {
        Authorization::require($actor,'privacy.requests.review');
        $reason=trim($reason);
        if(!self::isUuid($requestId) || !in_array($decision,['rejected','deferred'],true) ||
            strlen($reason)<10 || strlen($reason)>500 || strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Privacy review decision is invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,request_type,status FROM portal_privacy_requests
                 WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($request) || $request['status']!=='in_review') {
                throw new \DomainException('Privacy request is not awaiting review.');
            }
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests
                 SET status=:status,reviewed_by=:reviewer,reviewed_at=UTC_TIMESTAMP(),
                     decision_reason=:reason,completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute(['status'=>$decision,'reviewer'=>$actor->id,'reason'=>$reason,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.reviewed','portal_privacy_request',$requestId,
                'Administrator recorded a privacy-rights review decision',
                ['status'=>'in_review'],['status'=>$decision,'reason'=>$reason,'request_type'=>$request['request_type']],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }


    /**
     * Fulfil a verified companion-profile correction after an administrator has
     * independently checked the requested values. Public discovery is suspended
     * until the corrected profile is reviewed and republished.
     *
     * @param list<string> $languages BCP-47 language tags
     */
    public function fulfillCompanionCorrection(
        Principal $actor,
        string $requestId,
        string $displayName,
        string $biography,
        array $languages,
        string $reason,
        string $correlationId
    ): void {
        Authorization::require($actor,'privacy.requests.review');
        $displayName=trim($displayName);
        $biography=trim($biography);
        $reason=trim($reason);
        if (!self::isUuid($requestId) || $displayName==='' || strlen($displayName)>120 ||
            strlen($biography)>8000 || strlen($reason)<10 || strlen($reason)>500 ||
            strlen($correlationId)<16 || strlen($correlationId)>64 ||
            count($languages)>20 || $languages===[]) {
            throw new \InvalidArgumentException('Companion correction details are invalid.');
        }
        $normalized=[];
        foreach($languages as $language) {
            if (!is_string($language) || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',trim($language))!==1) {
                throw new \InvalidArgumentException('Companion languages must be valid BCP-47-style tags.');
            }
            $tag=trim($language);
            if (!in_array(strtolower($tag),array_map('strtolower',$normalized),true)) $normalized[]=$tag;
        }
        if ($normalized===[]) throw new \InvalidArgumentException('At least one valid language is required.');

        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,account_type,request_type,status
                 FROM portal_privacy_requests WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($request) || $request['status']!=='in_review' ||
                $request['request_type']!=='correction' || $request['account_type']!=='companion') {
                throw new \DomainException('Only a verified companion correction request awaiting review can be fulfilled.');
            }
            $user=$this->pdo->prepare('SELECT id,status FROM users WHERE id=:id FOR UPDATE');
            $user->execute(['id'=>$request['user_id']]);
            $account=$user->fetch(PDO::FETCH_ASSOC);
            if (!is_array($account) || $account['status']!=='active') {
                throw new \DomainException('The companion account is not active.');
            }
            $profileQuery=$this->pdo->prepare(
                'SELECT id,status FROM companion_profiles WHERE user_id=:user FOR UPDATE'
            );
            $profileQuery->execute(['user'=>$request['user_id']]);
            $profile=$profileQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($profile)) throw new \DomainException('The companion profile is unavailable.');

            $before=$this->pdo->prepare('SELECT display_name,biography,languages_json,status FROM companion_profiles WHERE id=:id');
            $before->execute(['id'=>$profile['id']]);
            $previous=$before->fetch(PDO::FETCH_ASSOC);
            $this->pdo->prepare(
                'UPDATE companion_profiles
                 SET display_name=:name,biography=:biography,languages_json=:languages,status="draft",updated_at=UTC_TIMESTAMP()
                 WHERE id=:id'
            )->execute([
                'name'=>$displayName,'biography'=>$biography,
                'languages'=>json_encode(array_values($normalized),JSON_THROW_ON_ERROR),
                'id'=>$profile['id'],
            ]);
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests SET status="fulfilled",reviewed_by=:reviewer,
                 reviewed_at=UTC_TIMESTAMP(),decision_reason=:reason,payload_ciphertext=NULL,
                 completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['reviewer'=>$actor->id,'reason'=>$reason,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.correction.fulfilled','portal_privacy_request',$requestId,
                'Verified companion profile correction applied; profile returned to draft for independent republication review',
                ['profile_status'=>$previous['status']??null],
                ['profile_status'=>'draft','fields'=>['display_name','biography','languages'],'reason'=>$reason],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    /** Fulfil the allowlisted client name correction; contact-email changes require a separate new-address verification flow. */
    public function fulfillClientCorrection(
        Principal $actor,string $requestId,string $name,string $reason,string $correlationId
    ): void {
        Authorization::require($actor,'privacy.requests.review');
        $name=trim($name);
        $reason=trim($reason);
        if(!self::isUuid($requestId) || $name==='' || mb_strlen($name,'UTF-8')>120 ||
            strlen($reason)<10 || strlen($reason)>500 ||
            strlen($correlationId)<16 || strlen($correlationId)>64) {
            throw new \InvalidArgumentException('Client correction details are invalid.');
        }
        $this->pdo->beginTransaction();
        try {
            $query=$this->pdo->prepare(
                'SELECT id,user_id,account_type,request_type,status
                 FROM portal_privacy_requests WHERE id=:id FOR UPDATE'
            );
            $query->execute(['id'=>$requestId]);
            $request=$query->fetch(PDO::FETCH_ASSOC);
            if(!is_array($request) || $request['status']!=='in_review' ||
                $request['request_type']!=='correction' || $request['account_type']!=='client') {
                throw new \DomainException('Only a verified client correction request awaiting review can be fulfilled.');
            }
            $user=$this->pdo->prepare('SELECT id,status,display_name FROM users WHERE id=:id FOR UPDATE');
            $user->execute(['id'=>$request['user_id']]);
            $account=$user->fetch(PDO::FETCH_ASSOC);
            $roles=$this->pdo->prepare('SELECT role_key FROM user_roles WHERE user_id=:id ORDER BY role_key');
            $roles->execute(['id'=>$request['user_id']]);
            $roleList=$roles->fetchAll(PDO::FETCH_COLUMN);
            if(!is_array($account) || $account['status']!=='active' || $roleList!==['client']) {
                throw new \DomainException('The client account is inactive or does not have an exclusive client role.');
            }
            $this->pdo->prepare('UPDATE users SET display_name=:name,updated_at=UTC_TIMESTAMP() WHERE id=:id')
                ->execute(['name'=>$name,'id'=>$request['user_id']]);
            $this->pdo->prepare(
                'UPDATE portal_privacy_requests SET status="fulfilled",reviewed_by=:reviewer,
                 reviewed_at=UTC_TIMESTAMP(),decision_reason=:reason,payload_ciphertext=NULL,
                 completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id'
            )->execute(['reviewer'=>$actor->id,'reason'=>$reason,'id'=>$requestId]);
            $this->audit->record($actor->id,'portal.privacy.correction.fulfilled','portal_privacy_request',$requestId,
                'Verified client display-name correction applied; contact details were not changed.',
                ['fields'=>['display_name']],
                ['fields'=>['display_name'],'reason'=>$reason],
                $correlationId,'portal-privacy-rights-v1');
            $this->pdo->commit();
        } catch(Throwable $error) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;
    }
}
