<?php

namespace App\Services;

use App\Models\PlatformFeature;
use Illuminate\Database\Eloquent\Collection;

class FeatureRegistry
{
    public function isEnabled(string $key, array $visited = []): bool
    {
        if (! Schema::hasTable('platform_features') || in_array($key, $visited, true)) {
            return false;
        }

        $feature = PlatformFeature::query()->find($key);

        if (! $feature || $feature->implementation_status !== 'available' || ! $feature->is_enabled) {
            return false;
        }

        $visited[] = $key;

        foreach ($feature->dependencies ?? [] as $dependency) {
            if (! $this->isEnabled((string) $dependency, $visited)) {
                return false;
            }
        }

        return true;
    }

    public function assertEnabled(string $key): void
    {
        if (! $this->isEnabled($key)) {
            abort(404);
        }
    }

    public function isOperationallyEnabled(string $key): bool
    {
        if (! $this->isEnabled($key)) {
            return false;
        }

        $feature = PlatformFeature::query()->find($key);

        return $feature !== null
            && app(FeatureActivationEvaluator::class)->blockers($feature) === [];
    }

    public function assertOperationallyEnabled(string $key): void
    {
        if (! $this->isOperationallyEnabled($key)) {
            abort(404);
        }
    }

    public function catalog(): Collection
    {
        if (! Schema::hasTable('platform_features')) {
            return collect();
        }

        return PlatformFeature::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->each(function (PlatformFeature $feature): void {
                $feature->setAttribute('effective_enabled', $this->isOperationallyEnabled($feature->key));
            });
    }
}
