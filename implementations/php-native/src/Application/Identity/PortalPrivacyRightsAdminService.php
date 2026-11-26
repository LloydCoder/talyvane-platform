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
    public function queue(Principal $actor): array
    {
        Authorization::require($actor,'privacy.requests.review');
        $query=$this->pdo->query(
            'SELECT r.id,r.user_id,r.account_type,r.request_type,r.status,r.payload_ciphertext,
                    r.verified_at,r.created_at,u.email
             FROM portal_privacy_requests r
             JOIN users u ON u.id=r.user_id
             WHERE r.status IN ("in_review","deferred")
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

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$value)===1;
    }
}
