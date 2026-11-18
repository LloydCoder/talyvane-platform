<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companion_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->string('display_name', 80);
            $table->string('slug', 120)->unique();
            $table->string('summary', 500)->nullable();
            $table->json('languages')->nullable();
            $table->string('publication_status', 20)->default('draft')->index();
            $table->timestamp('age_verified_at')->nullable()->index();
            $table->timestamp('publication_consent_at')->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['destination_id', 'publication_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companion_profiles');
    }
};
