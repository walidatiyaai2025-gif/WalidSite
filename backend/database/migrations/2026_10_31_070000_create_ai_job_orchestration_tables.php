<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('ai_providers');
            $table->string('job_type', 80);
            $table->string('idempotency_key', 160)->unique();
            $table->char('payload_hash', 64);
            $table->string('status', 25)->default('queued');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->unsignedBigInteger('reserved_budget_minor')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('result_ref')->nullable();
            $table->string('last_error_code', 50)->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at', 'created_at']);
            $table->index(['provider_id', 'status']);
        });

        Schema::create('ai_job_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_id')->constrained('ai_jobs')->cascadeOnDelete();
            $table->string('step_key', 100);
            $table->string('status', 24)->default('pending');
            $table->text('output_ref')->nullable();
            $table->unsignedSmallInteger('attempt_no')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['job_id', 'step_key']);
        });

        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('usage_key', 180)->unique();
            $table->foreignUuid('job_id')->constrained('ai_jobs')->cascadeOnDelete();
            $table->foreignUuid('provider_id')->constrained('ai_providers');
            $table->string('model_key', 160)->nullable();
            $table->unsignedBigInteger('cost_minor');
            $table->char('cost_currency', 3);
            $table->json('usage_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['provider_id', 'created_at']);
        });

        Schema::create('job_leases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('job_id')->unique()->constrained('ai_jobs')->cascadeOnDelete();
            $table->string('owner_key', 180);
            $table->timestamp('expires_at');
            $table->unsignedInteger('lease_version')->default(1);
            $table->timestamps();
            $table->index('expires_at');
        });

        Schema::create('ai_provider_circuits', function (Blueprint $table): void {
            $table->foreignUuid('provider_id')->primary()->constrained('ai_providers')->cascadeOnDelete();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('opened_until')->nullable();
            $table->string('last_failure_code', 50)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('idempotency_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('request_key', 180)->unique();
            $table->string('status', 20);
            $table->json('receipt_json');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_receipts');
        Schema::dropIfExists('ai_provider_circuits');
        Schema::dropIfExists('job_leases');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_job_steps');
        Schema::dropIfExists('ai_jobs');
    }
};
