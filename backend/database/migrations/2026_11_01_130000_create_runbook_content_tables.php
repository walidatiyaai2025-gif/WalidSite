<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Canonical database/schema-blueprint.json: no imported or fabricated
        // production guides. Publishing is a distinct editorial decision.
        Schema::create('runbooks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 150)->unique();
            $table->string('title', 220);
            $table->longText('body_md');
            $table->unsignedInteger('version');
            $table->timestamp('validated_at')->nullable();
            $table->boolean('offline_ok')->default(false);
            $table->index(['offline_ok', 'validated_at']);
        });

        Schema::create('runbook_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('runbook_id')
                ->constrained('runbooks')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('body_md');
            $table->char('checksum', 64);
            $table->timestamp('created_at');
            $table->unique(['runbook_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runbook_versions');
        Schema::dropIfExists('runbooks');
    }
};
