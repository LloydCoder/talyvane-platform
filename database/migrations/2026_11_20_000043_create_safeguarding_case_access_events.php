<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safeguarding_case_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('safeguarding_cases')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->timestamp('accessed_at');
            $table->index(['case_id', 'accessed_at']);
            $table->index(['actor_id', 'accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safeguarding_case_access_events');
    }
};
