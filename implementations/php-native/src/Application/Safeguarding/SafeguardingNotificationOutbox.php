<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use PDO;
use Talyvane\Security\SecretBox;

/** Encrypts notification destinations and payloads before they enter the outbox. */
final class SafeguardingNotificationOutbox
{
    public function __construct(private PDO $pdo,private SecretBox $box,private string $fingerprintKey)
    {
        if (strlen($fingerprintKey)<32) {
            throw new \InvalidArgumentException('Notification fingerprint key must contain at least 256 bits.');
        }
    }

    public function enqueue(string $caseId,string $specialistId,string $caseReference,string $category,string $severity): void
    {
        $query=$this->pdo->prepare(
            'SELECT u.email FROM users u
             JOIN user_roles r ON r.user_id=u.id AND r.role_key="safeguarding"
             JOIN admin_mfa_credentials m ON m.user_id=u.id AND m.enabled_at IS NOT NULL
             WHERE u.id=:id AND u.status="active" AND u.email_verified_at IS NOT NULL LIMIT 1'
        );
        $query->execute(['id'=>$specialistId]);
        $recipient=$query->fetchColumn();
        if (!is_string($recipient) || !filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Safeguarding notification recipient is unavailable.');
        }
        $id=self::uuid();
        $payload=json_encode([
            'case_reference'=>$caseReference,
            'category'=>$category,
            'severity'=>$severity,
            'url_path'=>'/admin/safeguarding/cases',
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $insert=$this->pdo->prepare(
            'INSERT INTO safeguarding_notification_outbox
             (id,case_id,event_key,recipient_ciphertext,recipient_fingerprint,dedupe_key,payload_ciphertext,
              status,is_external,attempts,available_at,created_at)
             VALUES (:id,:case,"case.created",:recipient,:fingerprint,:dedupe,:payload,
                     "pending",0,0,UTC_TIMESTAMP(),UTC_TIMESTAMP())'
        );
        $insert->execute([
            'id'=>$id,'case'=>$caseId,
            'recipient'=>$this->box->encrypt($recipient,'safeguarding-outbox-v1:recipient:'.$id),
            'fingerprint'=>hash_hmac('sha256',strtolower($recipient),$this->fingerprintKey),
            'dedupe'=>hash('sha256','safeguarding-case-created-v1:'.$caseId.':'.$specialistId),
            'payload'=>$this->box->encrypt($payload,'safeguarding-outbox-v1:payload:'.$id),
        ]);
    }

    private static function uuid(): string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);$hex=bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
