<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Audit;

use PDO;
use RuntimeException;

final class AuditLogger
{
    public function __construct(private PDO $pdo) {}

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    public function record(
        ?string $actorUserId,
        string $eventKey,
        string $targetType,
        ?string $targetId,
        ?string $reason,
        ?array $before,
        ?array $after,
        string $requestId,
        string $policyVersion
    ): void {
        foreach ([$eventKey, $targetType, $requestId, $policyVersion] as $value) {
            if ($value === '' || strlen($value) > 190) {
                throw new RuntimeException('Audit event metadata is invalid.');
            }
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events
            (id, actor_user_id, event_key, target_type, target_id, reason, before_json, after_json, request_id, policy_version, occurred_at)
            VALUES (:id, :actor, :event, :type, :target, :reason, :before, :after, :request, :policy, UTC_TIMESTAMP())'
        );
        $statement->execute([
            'id' => self::uuid(),
            'actor' => $actorUserId,
            'event' => $eventKey,
            'type' => $targetType,
            'target' => $targetId,
            'reason' => $reason,
            'before' => $before === null ? null : json_encode(self::redact($before), JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode(self::redact($after), JSON_THROW_ON_ERROR),
            'request' => $requestId,
            'policy' => $policyVersion,
        ]);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function redact(array $data): array
    {
        $blocked = ['password', 'passwordhash', 'secret', 'secretkey', 'token', 'apikey', 'privatekey', 'seedphrase', 'identitydocument', 'totpsecret', 'recoverycodes'];
        foreach ($data as $key => $value) {
            $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (in_array($normalized, $blocked, true) || preg_match('/(password|secret|token|apikey|privatekey|seedphrase|credential|authorization|identitydocument|totp|recoverycode)/', $normalized) === 1) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
