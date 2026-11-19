<?php

namespace Tests\Feature\Support;

use Illuminate\Support\Facades\DB;

trait EnablesModelAgencyFeatureRoutes
{
    protected function enableModelAgencyFeatureRoutes(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.applications')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);

        DB::table('platform_features')->where('key', 'model-agency.usage-rights')->update([
            'implementation_status' => 'available',
            'is_enabled' => true,
        ]);
    }
}
