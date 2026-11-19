<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('companion_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_path', 500);
            $table->string('mime_type', 80)->default('image/webp');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('file_size');
            $table->string('status', 24)->default('pending_review')->index();
            $table->timestamp('rights_confirmed_at');
            $table->string('consent_version', 80);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['companion_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_media');
    }
};
