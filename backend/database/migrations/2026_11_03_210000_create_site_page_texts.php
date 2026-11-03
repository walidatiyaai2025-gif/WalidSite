<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('site_page_texts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('page_key', 40);
            $table->string('section_key', 64);
            $table->string('field_key', 80);
            $table->char('locale', 2);
            $table->longText('published_text')->nullable();
            $table->longText('draft_text')->nullable();
            $table->string('source', 48);
            $table->unsignedInteger('revision')->default(0);
            $table->foreignUuid('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['page_key','section_key','field_key','locale'], 'page_text_slot_lang_unique');
            $table->index(['page_key','section_key','locale']);
        });

        Schema::create('site_page_text_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_page_text_id')->constrained('site_page_texts')->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('source_note', 240)->nullable();
            $table->unsignedInteger('revision');
            $table->char('old_hash', 64)->nullable();
            $table->char('new_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['site_page_text_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_page_text_events');
        Schema::dropIfExists('site_page_texts');
    }
};
