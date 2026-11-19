<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_talent_applications', function (Blueprint $table): void {
            $table->timestamp('adult_eligibility_verified_at')->nullable();
            $table->foreignId('adult_eligibility_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('adult_eligibility_verification_method', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('model_talent_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('adult_eligibility_verified_by');
            $table->dropColumn(['adult_eligibility_verified_at', 'adult_eligibility_verification_method']);
        });
    }
};
