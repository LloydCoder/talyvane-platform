<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_publication_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('verification_provider', 80);
            $table->char('verification_reference_hash', 64);
            $table->string('consent_version', 80);
            $table->string('decision', 20)->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['companion_profile_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_publication_reviews');
    }
};
