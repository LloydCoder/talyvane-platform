<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_casting_calls', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->string('title', 180);
            $table->text('client_name');
            $table->text('client_reference')->nullable();
            $table->string('project_purpose', 40);
            $table->text('description');
            $table->text('requirements');
            $table->char('country_code', 2);
            $table->timestamp('opens_at');
            $table->timestamp('closes_at');
            $table->timestamp('shoot_starts_at');
            $table->timestamp('shoot_ends_at');
            $table->decimal('compensation_amount', 20, 4)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedSmallInteger('max_submissions')->default(50);
            $table->string('status', 16)->default('draft')->index();
            $table->string('casting_policy_version', 80);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('country_code')->references('country_code')->on('country_markets')->restrictOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies')->nullOnDelete();
            $table->index(['country_code', 'status']);
            $table->index(['status', 'closes_at']);
        });

        Schema::create('model_casting_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('model_casting_calls')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->foreignId('portfolio_asset_id')->constrained('model_talent_portfolio_assets')->restrictOnDelete();
            $table->foreignId('image_licence_id')->constrained('model_talent_image_licences')->restrictOnDelete();
            $table->string('reference', 48)->unique();
            $table->string('status', 16)->default('submitted')->index();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decision_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'application_id']);
            $table->index(['application_id', 'status']);
            $table->index(['image_licence_id', 'status']);
        });

        Schema::create('model_casting_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('call_id')->constrained('model_casting_calls')->restrictOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('model_casting_submissions')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24);
            $table->string('event_type', 48);
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['call_id', 'occurred_at']);
            $table->index(['submission_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_casting_events');
        Schema::dropIfExists('model_casting_submissions');
        Schema::dropIfExists('model_casting_calls');
    }
};
