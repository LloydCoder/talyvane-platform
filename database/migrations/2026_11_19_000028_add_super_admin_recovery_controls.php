<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_session_version')->default(1);
        });

        Schema::table('platform_control', function (Blueprint $table): void {
            $table->string('super_admin_recovery_key_hash')->nullable();
            $table->timestamp('super_admin_recovery_key_rotated_at')->nullable();
            $table->string('super_admin_recovery_email')->nullable();
            $table->timestamp('super_admin_recovery_email_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_control', function (Blueprint $table): void {
            $table->dropColumn([
                'super_admin_recovery_key_hash',
                'super_admin_recovery_key_rotated_at',
                'super_admin_recovery_email',
                'super_admin_recovery_email_verified_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('admin_session_version');
        });
    }
};
