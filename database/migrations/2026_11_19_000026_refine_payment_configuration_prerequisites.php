<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_features')
            ->where('key', 'payments.configuration')
            ->update([
                'activation_requirements' => json_encode([]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('platform_features')
            ->where('key', 'payments.configuration')
            ->update([
                'activation_requirements' => json_encode(['payment_provider_review']),
                'updated_at' => now(),
            ]);
    }
};
