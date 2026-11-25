<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Security;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class RateLimiter
{
    public function __construct(private PDO $pdo, private string $pepper)
    {
        if (strlen($pepper) < 32) {
            throw new \InvalidArgumentException('Rate-limit key material must contain at least 32 bytes.');
        }
    }

    /** Atomically consumes one attempt. Returns false once the limit or active block is reached. */
    public function consume(string $scope, string $identifier, int $limit, int $windowSeconds, int $blockSeconds): bool
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{1,79}$/D', $scope) ||
            $identifier === '' || strlen($identifier) > 512 ||
            $limit < 1 || $limit > 10000 ||
            $windowSeconds < 1 || $windowSeconds > 86400 ||
            $blockSeconds < 1 || $blockSeconds > 86400) {
            throw new \InvalidArgumentException('Rate-limit parameters are invalid.');
        }
        $bucket = hash_hmac('sha256', $scope . "\0" . $identifier, $this->pepper);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO rate_limit_buckets (bucket_key, scope, attempts, window_started_at, blocked_until, created_at)
                 VALUES (:bucket, :scope, 0, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())'
            );
            $insert->execute(['bucket'=>$bucket,'scope'=>$scope]);
            $select = $this->pdo->prepare('SELECT attempts, window_started_at, blocked_until FROM rate_limit_buckets WHERE bucket_key = :bucket FOR UPDATE');
            $select->execute(['bucket'=>$bucket]);
            $row = $select->fetch();
            if (!is_array($row)) {
                throw new \RuntimeException('Rate-limit bucket could not be loaded.');
            }
            $blockedUntil = $row['blocked_until'] === null ? null : new DateTimeImmutable((string) $row['blocked_until'], new DateTimeZone('UTC'));
            if ($blockedUntil !== null && $blockedUntil > $now) {
                $this->pdo->commit();
                return false;
            }
            $windowStarted = new DateTimeImmutable((string) $row['window_started_at'], new DateTimeZone('UTC'));
            if (($blockedUntil !== null && $blockedUntil <= $now) || $windowStarted->modify('+' . $windowSeconds . ' seconds') <= $now) {
                $attempts = 0;
                $windowStarted = $now;
            } else {
                $attempts = (int) $row['attempts'];
            }
            $attempts++;
            $newBlockedUntil = $attempts > $limit ? $now->modify('+' . $blockSeconds . ' seconds')->format('Y-m-d H:i:s') : null;
            $update = $this->pdo->prepare(
                'UPDATE rate_limit_buckets SET attempts = :attempts, window_started_at = :started, blocked_until = :blocked WHERE bucket_key = :bucket'
            );
            $update->execute([
                'attempts'=>$attempts,
                'started'=>$windowStarted->format('Y-m-d H:i:s'),
                'blocked'=>$newBlockedUntil,
                'bucket'=>$bucket,
            ]);
            $this->pdo->commit();
            return $attempts <= $limit;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
