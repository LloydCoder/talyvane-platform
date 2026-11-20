<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_restore_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('backup_reference', 160);
            $table->unsignedInteger('retention_cases_erased')->default(0);
            $table->unsignedInteger('active_keys_verified')->default(0);
            $table->unsignedInteger('orphan_keys_destroyed')->default(0);
            $table->timestamp('verified_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('safeguarding_restore_verifications')
            && \Illuminate\Support\Facades\DB::table('safeguarding_restore_verifications')->exists()) {
            throw new RuntimeException('Safeguarding restore verification history is append-only and cannot be dropped after use.');
        }

        Schema::dropIfExists('safeguarding_restore_verifications');
    }
};
