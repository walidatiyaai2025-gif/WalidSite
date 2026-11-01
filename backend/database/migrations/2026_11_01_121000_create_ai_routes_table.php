<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('task_type', 80)->unique();
            $table->foreignUuid('primary_model_id')
                ->constrained('ai_models')
                ->restrictOnDelete();
            $table->json('fallback_model_ids_json');
            $table->json('budget_json');
            $table->json('rate_limits_json');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_routes');
    }
};
