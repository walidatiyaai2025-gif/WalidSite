<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Canonical v6 learning_paths: no generated/sample tracks on install.
        Schema::create('learning_paths', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 150)->unique();
            $table->string('title', 220);
            $table->json('outline_json');
            $table->boolean('published')->default(false);
            $table->index(['published', 'slug']);
        });

        // Per-user progress is private and must never cross the guest list API.
        // Future authenticated writes are a separate T021 acceptance slice.
        Schema::create('learning_progress', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('path_id')->constrained('learning_paths')->cascadeOnDelete();
            $table->json('progress_json');
            $table->timestamp('updated_at');
            $table->unique(['user_id', 'path_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_progress');
        Schema::dropIfExists('learning_paths');
    }
};
