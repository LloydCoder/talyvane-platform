<?php

namespace App\Services;

use App\Models\PlatformFeature;
use Illuminate\Database\Eloquent\Collection;

class FeatureRegistry
{
    public function isEnabled(string $key, array $visited = []): bool
    {
        if (in_array($key, $visited, true)) {
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

    public function catalog(): Collection
    {
        return PlatformFeature::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->each(function (PlatformFeature $feature): void {
                $feature->setAttribute('effective_enabled', $this->isEnabled($feature->key));
            });
    }
}
