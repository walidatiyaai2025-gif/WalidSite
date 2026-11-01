<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_diagnosis_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('job_id')->nullable()->unique()
                ->constrained('ai_jobs')->cascadeOnDelete();
            $table->foreignUuid('model_id')->constrained('ai_models')->restrictOnDelete();
            $table->foreignUuid('selected_runbook_id')->nullable()
                ->constrained('runbooks')->nullOnDelete();
            $table->longText('request_blob')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_diagnosis_requests');
    }
};
