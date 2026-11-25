<?php
declare(strict_types=1);

namespace Talyvane\Infrastructure\Database;

use PDO;
use Talyvane\Features\FeatureRegistry;

final class FeatureStateRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<string,bool> */
    public function enabledStates(): array
    {
        $rows = $this->pdo->query('SELECT feature_key, enabled FROM feature_states')->fetchAll();
        $states = [];
        foreach ($rows as $row) {
            if (is_string($row['feature_key']) && FeatureRegistry::known($row['feature_key'])) {
                $states[$row['feature_key']] = (bool) $row['enabled'];
            }
        }
        return $states;
    }

    public function set(string $key, bool $enabled, ?array $config, string $actorId): void
    {
        if (!FeatureRegistry::known($key) || in_array($key, FeatureRegistry::HARD_INVARIANTS, true)) {
            throw new \DomainException('Unknown or non-configurable feature key.');
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO feature_states (feature_key, enabled, config_json, updated_by, updated_at)
             VALUES (:key, :enabled, :config, :actor, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), config_json = VALUES(config_json), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'key' => $key,
            'enabled' => $enabled ? 1 : 0,
            'config' => $config === null ? null : json_encode($config, JSON_THROW_ON_ERROR),
            'actor' => $actorId,
        ]);
    }
}
