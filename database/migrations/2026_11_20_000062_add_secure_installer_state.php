<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_control', function (Blueprint $table): void {
            $table->uuid('installation_id')->nullable()->unique();
            $table->char('installation_lock_hash', 64)->nullable()->unique();
            $table->timestamp('installation_completed_at')->nullable();
            $table->text('super_admin_recovery_key_handoff')->nullable();
            $table->timestamp('super_admin_recovery_key_handoff_expires_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('must_change_password_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password_at');
        });

        Schema::table('platform_control', function (Blueprint $table): void {
            $table->dropUnique(['installation_id']);
            $table->dropUnique(['installation_lock_hash']);
            $table->dropColumn([
                'installation_id',
                'installation_lock_hash',
                'installation_completed_at',
                'super_admin_recovery_key_handoff',
                'super_admin_recovery_key_handoff_expires_at',
            ]);
        });
    }
};
