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
        return $this->resolve($key, []);
    }

    /** @param list<string> $stack */
    private function resolve(string $key, array $stack): bool
    {
        if (in_array($key, $stack, true)) {
            return false;
        }
        $definition = FeatureRegistry::definitions()[$key];
        if (!($this->deploymentState[$key] ?? $definition['default'])) {
            return false;
        }
        $stack[] = $key;
        foreach ($definition['dependencies'] as $dependency) {
            if (in_array($dependency, ['market.policy','verification.age','consent.current','provider.approved','legal.approved','safeguarding.ready'], true)) {
                if (!(($this->eligibility)($dependency))) {
                    return false;
                }
                continue;
            }
            if (!FeatureRegistry::known($dependency) || !$this->resolve($dependency, $stack)) {
                return false;
            }
        }
        return true;
    }
}
