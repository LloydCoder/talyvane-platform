<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_notification_outboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('safeguarding_cases')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('recipient_email')->nullable();
            $table->char('recipient_key', 64);
            $table->string('notification_type', 32);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->index();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();

            $table->unique(['case_id', 'notification_type', 'recipient_key'], 'safeguarding_outbox_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_notification_outboxes');
    }
};
