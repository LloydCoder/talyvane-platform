<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('country_markets', function (Blueprint $table): void {
            $table->string('compliance_evidence_reference', 255)->nullable()->after('compliance_reviewed_by');
            $table->text('compliance_review_summary')->nullable()->after('compliance_evidence_reference');
        });

        // Existing destinations are registered as pending, never implicitly approved.
        $destinations = DB::table('destinations')
            ->select('country_code', 'country_name')
            ->distinct()
            ->get();

        foreach ($destinations as $destination) {
            DB::table('country_markets')->insertOrIgnore([
                'country_code' => $destination->country_code,
                'name' => $destination->country_name,
                'is_enabled' => false,
                'compliance_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('country_markets', function (Blueprint $table): void {
            $table->dropColumn(['compliance_evidence_reference', 'compliance_review_summary']);
        });
    }
};
