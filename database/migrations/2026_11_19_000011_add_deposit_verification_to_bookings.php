<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('deposit_verified_at')->nullable();
            $table->foreignId('deposit_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('deposit_reference_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deposit_verified_by');
            $table->dropColumn(['deposit_verified_at', 'deposit_reference_hash']);
        });
    }
};
