<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safeguarding_cases', function (Blueprint $table): void {
            $table->timestamp('legal_hold_released_at')->nullable();
            $table->foreignId('legal_hold_released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('legal_hold_release_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('safeguarding_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legal_hold_released_by');
            $table->dropColumn(['legal_hold_released_at', 'legal_hold_release_reason']);
        });
    }
};
