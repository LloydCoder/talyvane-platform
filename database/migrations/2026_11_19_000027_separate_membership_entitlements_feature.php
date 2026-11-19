<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_features')
            ->where('key', 'memberships')
            ->update([
                'description' => 'Basic agency-managed client membership assignment and display. Protected-benefit entitlement enforcement is a separate planned capability.',
                'activation_requirements' => json_encode([]),
                'updated_at' => now(),
            ]);

        DB::table('platform_features')->insert([
            'key' => 'memberships.entitlements',
            'name' => 'Membership entitlements',
            'module' => 'client-experience',
            'description' => 'Server-side access entitlements and protected-benefit enforcement. Not yet implemented.',
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'risk_level' => 'critical',
            'dependencies' => json_encode(['memberships']),
            'activation_requirements' => json_encode(['server_side_entitlement_checks']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_features')->where('key', 'memberships.entitlements')->delete();

        DB::table('platform_features')
            ->where('key', 'memberships')
            ->update([
                'description' => 'Admin-managed client membership assignment and entitlement display.',
                'activation_requirements' => json_encode(['server_side_entitlement_checks']),
                'updated_at' => now(),
            ]);
    }
};
