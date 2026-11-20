<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_membership_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_id')->constrained('client_memberships')->cascadeOnDelete();
            $table->string('entitlement_key', 120);
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['membership_id', 'entitlement_key'], 'cme_membership_entitlement_unique');
            $table->index(['entitlement_key', 'starts_at', 'ends_at'], 'cme_entitlement_period_index');
        });

        Schema::table('profile_media', function (Blueprint $table): void {
            $table->string('access_level', 24)->default('public')->index();
            $table->timestamp('members_only_consent_at')->nullable();
            $table->string('members_only_consent_version', 80)->nullable();
            $table->timestamp('members_only_consent_withdrawn_at')->nullable();
            $table->foreignId('members_only_consent_withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('profile_media_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('profile_media_id')->nullable()->constrained('profile_media')->nullOnDelete();
            $table->foreignId('companion_profile_id')->nullable()->constrained('companion_profiles')->nullOnDelete();
            $table->string('media_reference', 80);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email', 254);
            $table->string('action', 32);
            $table->string('capture_method', 80);
            $table->string('consent_policy_version', 80);
            $table->text('consent_statement');
            $table->char('statement_sha256', 64);
            $table->uuid('correlation_id');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['profile_media_id', 'created_at']);
            $table->index(['media_reference', 'created_at']);
        });

        DB::table('platform_features')
            ->where('key', 'memberships.entitlements')
            ->update([
                'description' => 'Immutable, server-enforced membership grants for protected private gallery media.',
                'implementation_status' => 'available',
                'is_enabled' => false,
                'activation_requirements' => json_encode(['server_side_entitlement_checks', 'private_gallery_consent_policy']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_media_consent_events');

        DB::table('platform_features')
            ->where('key', 'memberships.entitlements')
            ->update([
                'description' => 'Server-side access entitlements and protected-benefit enforcement. Not yet implemented.',
                'implementation_status' => 'planned',
                'is_enabled' => false,
                'activation_requirements' => json_encode(['server_side_entitlement_checks']),
                'updated_at' => now(),
            ]);

        Schema::table('profile_media', function (Blueprint $table): void {
            $table->dropForeign(['members_only_consent_withdrawn_by']);
            $table->dropIndex(['access_level']);
            $table->dropColumn(['access_level', 'members_only_consent_at', 'members_only_consent_version', 'members_only_consent_withdrawn_at', 'members_only_consent_withdrawn_by']);
        });

        Schema::dropIfExists('client_membership_entitlements');
    }
};
