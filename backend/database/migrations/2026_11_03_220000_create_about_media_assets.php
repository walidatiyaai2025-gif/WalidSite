<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('about_media_assets', function (Blueprint $table): void {
            $table->string('slot', 64)->primary();
            $table->string('label', 120);
            $table->string('kind', 24);
            $table->string('fallback_icon', 32);
            $table->string('published_path', 255)->nullable();
            $table->string('published_mime', 32)->nullable();
            $table->string('draft_path', 255)->nullable();
            $table->string('draft_mime', 32)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->foreignUuid('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('about_media_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slot', 64);
            $table->foreign('slot')->references('slot')->on('about_media_assets')->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->unsignedInteger('revision');
            $table->char('old_hash', 64)->nullable();
            $table->char('new_hash', 64)->nullable();
            $table->string('source_note', 240)->nullable();
            $table->timestamp('created_at');
            $table->index(['slot', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('about_media_events');
        Schema::dropIfExists('about_media_assets');
    }
};
