<?php
declare(strict_types=1);

namespace Talyvane\Application\Identity;

use PDO;
use Talyvane\Security\SecretBox;

/** Queues generic privacy fulfilment notices in the shared encrypted communications outbox. */
final class PortalPrivacyOutcomeOutbox
{
    public function __construct(private PDO $pdo,private SecretBox $box) {}

    public function enqueue(string $requestId,string $recipient,string $accountType): void
    {
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD',$requestId) ||
            !filter_var($recipient,FILTER_VALIDATE_EMAIL) || !in_array($accountType,['client','companion'],true)) {
            throw new \InvalidArgumentException('Privacy outcome notification details are invalid.');
        }
        $id=self::uuid();
        $body=$accountType==='companion'
            ? "Your verified privacy request has been completed. Your Talyvane account has been anonymized and your companion profile withdrawn from public discovery. Records retained for legal, safeguarding, booking or financial obligations may remain restricted for the applicable retention period. Encrypted backups and third-party copies follow separate expiry and deletion procedures."
            : "Your verified privacy request has been completed. Your Talyvane account has been anonymized and sign-in access disabled. Records retained for legal, safeguarding, booking or financial obligations may remain restricted for the applicable retention period. Encrypted backups and third-party copies follow separate expiry and deletion procedures.";
        $payload=json_encode([
            'subject'=>'Your Talyvane privacy request has been completed',
            'body'=>$body,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare(
            'INSERT INTO communication_outbox
             (id,idempotency_key,related_type,related_id,channel,recipient_ciphertext,
              payload_ciphertext,status,attempts,available_at,created_at)
             VALUES (:id,:dedupe,"portal_privacy",:related,"email",:recipient,:payload,
                     "pending",0,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id=id'
        )->execute([
            'id'=>$id,'dedupe'=>'portal-privacy-outcome-v1:'.$requestId,'related'=>$requestId,
            'recipient'=>$this->box->encrypt($recipient,'communication-outbox-v1:recipient:'.$id),
            'payload'=>$this->box->encrypt($payload,'communication-outbox-v1:payload:'.$id),
        ]);
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
