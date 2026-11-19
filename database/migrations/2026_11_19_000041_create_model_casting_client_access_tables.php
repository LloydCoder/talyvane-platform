<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_casting_client_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('model_casting_calls')->restrictOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at');
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('reason');
            $table->timestamps();

            $table->unique(['call_id', 'client_user_id']);
            $table->index(['client_user_id', 'expires_at', 'revoked_at']);
        });

        Schema::create('model_casting_client_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('model_casting_calls')->restrictOnDelete();
            $table->foreignId('submission_id')->constrained('model_casting_submissions')->restrictOnDelete();
            $table->foreignId('access_id')->constrained('model_casting_client_accesses')->restrictOnDelete();
            $table->foreignId('client_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->text('note');
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique(['submission_id', 'client_user_id']);
            $table->index(['call_id', 'client_user_id']);
        });

        DB::table('platform_features')->insert([
            'key' => 'model-agency.client-casting',
            'name' => 'Private client casting review',
            'module' => 'model-agency',
            'description' => 'Expiring per-client casting access and consent-gated private image review; client decisions are advisory only.',
            'implementation_status' => 'available',
            'is_enabled' => false,
            'risk_level' => 'critical',
            'dependencies' => json_encode(['model-agency.casting']),
            'activation_requirements' => json_encode([
                'model_agency_client_casting_schema',
                'model_agency_client_casting_policy',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.client-casting')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('model_casting_client_reviews');
        Schema::dropIfExists('model_casting_client_accesses');
    }
};
