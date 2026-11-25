<?php
declare(strict_types=1);

namespace Talyvane\Application\Features;

use PDO;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Features\FeatureRegistry;
use Talyvane\Infrastructure\Audit\AuditLogger;
use Talyvane\Infrastructure\Database\FeatureStateRepository;
use Talyvane\Security\Authorization;
use Talyvane\Security\Principal;

final class FeatureManagementService
{
    /** @param callable(string):bool $eligibility */
    public function __construct(
        private PDO $pdo,
        private FeatureStateRepository $states,
        private AuditLogger $audit,
        private $eligibility
    ) {}

    /** @param array<string,mixed>|null $config */
    public function change(Principal $actor, string $key, bool $enabled, ?array $config, string $reason, string $requestId): void
    {
        Authorization::require($actor, 'features.manage', true);
        if (!$actor->hasRole('super_admin')) {
            throw new \DomainException('Only the deployment Super Admin can change feature availability.');
        }
        if (!FeatureRegistry::known($key) || in_array($key, FeatureRegistry::HARD_INVARIANTS, true)) {
            throw new \DomainException('Feature is unknown or is a non-overridable safeguard.');
        }
        if (strlen(trim($reason)) < 20 || strlen($reason) > 500) {
            throw new \InvalidArgumentException('A reason of 20 to 500 characters is required.');
        }
        $before = $this->states->enabledStates();
        $after = $before;
        $after[$key] = $enabled;
        if ($enabled) {
            $availability = new FeatureAvailability($after, $this->eligibility);
            if (!$availability->enabled($key)) {
                throw new \DomainException('Feature prerequisites are not satisfied; activation is blocked.');
            }
        }
        $wasEnabled = $before[$key] ?? FeatureRegistry::definitions()[$key]['default'];
        $this->pdo->beginTransaction();
        try {
            $this->states->set($key, $enabled, $config, $actor->id);
            $this->audit->record(
                $actor->id,
                'feature.configuration.changed',
                'feature',
                $key,
                trim($reason),
                ['enabled' => $wasEnabled],
                ['enabled' => $enabled, 'config' => $config],
                $requestId,
                'feature-registry-v1'
            );
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
