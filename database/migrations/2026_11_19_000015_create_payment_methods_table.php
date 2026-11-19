<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('asset', 8);
            $table->string('network', 40);
            $table->string('address', 255);
            $table->string('token_contract', 255)->nullable();
            $table->unsignedSmallInteger('confirmations_required')->default(3);
            $table->boolean('purpose_booking')->default(false);
            $table->boolean('purpose_membership')->default(false);
            $table->boolean('enabled')->default(false);
            $table->string('verification_status', 20)->default('pending');
            $table->char('verification_reference_hash', 64)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('address_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['asset', 'network', 'address']);
            $table->index(['enabled', 'asset', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
