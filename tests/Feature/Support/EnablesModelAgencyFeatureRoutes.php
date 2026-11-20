<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;

trait EnablesModelAgencyFeatureRoutes
{
    protected function enableModelAgencyFeatureRoutes(array $additionalFeatures = []): void
    {
        $keys = array_values(array_unique(array_merge([
            'model-agency.applications',
            'model-agency.usage-rights',
        ], $additionalFeatures)));

        DB::table('platform_features')->whereIn('key', $keys)->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }
}
