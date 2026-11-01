<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')
                ->constrained('ai_providers')
                ->cascadeOnDelete();
            $table->string('provider_model_id', 200);
            $table->json('capabilities_json');
            $table->boolean('enabled');
            $table->json('cost_policy_json');

            // A provider model ID is only meaningful inside one provider.
            // Keep the registry deterministic without preventing two
            // providers from exposing the same upstream model identifier.
            $table->unique(['provider_id', 'provider_model_id']);
            $table->index(['provider_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
