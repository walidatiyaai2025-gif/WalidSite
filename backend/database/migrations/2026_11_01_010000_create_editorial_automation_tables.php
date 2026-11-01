<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('editorial_policies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('max_per_tick')->default(10);
            $table->unsignedSmallInteger('refresh_days')->default(30);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('editorial_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedInteger('revision_no');
            $table->foreignUuid('approved_by')->constrained('users');
            $table->timestamp('due_at');
            $table->timestamp('refresh_due_at')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique(['article_id', 'revision_no']);
            $table->index(['status', 'due_at']);
            $table->index(['status', 'refresh_due_at']);
        });

        Schema::create('editorial_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_key', 180)->unique();
            $table->foreignUuid('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->unsignedInteger('revision_no')->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->string('reason', 80)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_events');
        Schema::dropIfExists('editorial_schedules');
        Schema::dropIfExists('editorial_policies');
    }
};
