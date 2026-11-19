<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_talent_settlements', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 48)->unique();
            $table->foreignId('assignment_id')->unique()->constrained('model_talent_assignments')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('model_talent_applications')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('currency_precision');
            $table->decimal('gross_amount', 20, 4);
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 20, 4);
            $table->decimal('approved_expenses_amount', 20, 4);
            $table->decimal('model_net_amount', 20, 4);
            $table->string('settlement_policy_version', 80);
            $table->text('calculation_snapshot');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('payment_method', 32)->nullable();
            $table->text('payment_reference')->nullable();
            $table->text('payment_evidence_reference')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->text('reversal_evidence_reference')->nullable();
            $table->foreignId('disputed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disputed_at')->nullable();
            $table->text('dispute_reason')->nullable();
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['application_id', 'status']);
        });

        Schema::create('model_talent_settlement_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('model_talent_assignments')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('model_talent_settlements')->restrictOnDelete();
            $table->text('description');
            $table->decimal('amount', 20, 4);
            $table->char('currency_code', 3);
            $table->text('evidence_reference');
            $table->string('status', 16)->default('pending')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['assignment_id', 'status']);
        });

        Schema::create('model_talent_settlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('model_talent_assignments')->restrictOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('model_talent_settlements')->restrictOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('model_talent_settlement_expenses')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 24);
            $table->string('event_type', 64);
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['assignment_id', 'occurred_at']);
            $table->index(['settlement_id', 'occurred_at']);
        });

        DB::table('platform_features')->insert([
            'key' => 'model-agency.settlements',
            'name' => 'Model talent settlements',
            'module' => 'model-agency',
            'description' => 'Expense review, decimal-safe settlement calculation, approval and external payment evidence register; no payment processing.',
            'implementation_status' => 'available',
            'is_enabled' => false,
            'risk_level' => 'critical',
            'dependencies' => json_encode(['model-agency.assignments']),
            'activation_requirements' => json_encode([
                'model_agency_settlement_schema',
                'model_agency_settlement_policy',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('platform_features')->where('key', 'model-agency.settlements')->update([
            'implementation_status' => 'planned',
            'is_enabled' => false,
            'updated_at' => now(),
        ]);

        Schema::dropIfExists('model_talent_settlement_events');
        Schema::dropIfExists('model_talent_settlement_expenses');
        Schema::dropIfExists('model_talent_settlements');
    }
};
