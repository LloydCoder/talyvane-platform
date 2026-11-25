<?php
declare(strict_types=1);

namespace Talyvane\Features;

final class FeatureAvailability
{
    /** @param array<string,bool> $deploymentState @param callable(string):bool $eligibility */
    public function __construct(private array $deploymentState, private $eligibility) {}

    public function enabled(string $key): bool
    {
        if (!FeatureRegistry::known($key)) {
            return false;
        }
        $definition = FeatureRegistry::definitions()[$key];
        if (!($this->deploymentState[$key] ?? $definition['default'])) {
            return false;
        }
        foreach ($definition['dependencies'] as $dependency) {
            if ($dependency === 'market.policy' || $dependency === 'verification.age' || $dependency === 'consent.current') {
                if (!(($this->eligibility)($dependency))) {
                    return false;
                }
                continue;
            }
            if (!$this->enabled($dependency)) {
                return false;
            }
        }
        return true;
    }
}
