<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use DateTimeImmutable;
use PDO;
use Talyvane\Domain\Market\MarketPolicy;

final class MarketPolicyRepository
{
    public function __construct(private PDO $pdo) {}

    public function find(string $countryCode): ?MarketPolicy
    {
        if (!preg_match('/^[A-Z]{2}$/D', $countryCode)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT country_code, enabled, decision, reviewer_id, reviewed_at, expires_at, evidence_reference, review_summary FROM market_policies WHERE country_code = :country LIMIT 1');
        $statement->execute(['country' => $countryCode]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        return new MarketPolicy(
            (string) $row['country_code'],
            (bool) $row['enabled'],
            (string) $row['decision'],
            $row['reviewer_id'] === null ? null : (string) $row['reviewer_id'],
            $row['reviewed_at'] === null ? null : new DateTimeImmutable((string) $row['reviewed_at']),
            $row['expires_at'] === null ? null : new DateTimeImmutable((string) $row['expires_at']),
            $row['evidence_reference'] === null ? null : (string) $row['evidence_reference'],
            $row['review_summary'] === null ? null : (string) $row['review_summary']
        );
    }
}
