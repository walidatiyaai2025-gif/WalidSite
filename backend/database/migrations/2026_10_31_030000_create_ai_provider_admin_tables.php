<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('display_name', 160)->unique();
            $table->string('provider_type', 40);
            $table->string('base_url', 2048);
            $table->text('secret_ciphertext')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('health_status', 20)->default('unverified');
            $table->unsignedSmallInteger('concurrent_limit')->default(1);
            $table->unsignedSmallInteger('timeout_seconds')->default(5);
            $table->unsignedBigInteger('daily_budget_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('routing_priority')->default(100);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->index(['enabled', 'health_status']);
        });

        Schema::create('ai_provider_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('action', 36);
            $table->json('changed_fields_json');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['provider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_audit_events');
        Schema::dropIfExists('ai_providers');
    }
};
