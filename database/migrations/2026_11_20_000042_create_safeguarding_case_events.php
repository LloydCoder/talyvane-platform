<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('safeguarding_cases')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['case_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_case_events');
    }
};
