<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('career_positions', function (Blueprint $table): void {
            $table->json('draft_json')->nullable();
            $table->unsignedInteger('cms_revision')->default(0);
            $table->string('cms_source_note', 240)->nullable();
            $table->foreignUuid('cms_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cms_published_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('career_position_edit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('career_position_id')->constrained('career_positions')->restrictOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->unsignedInteger('revision');
            $table->string('source_note', 240)->nullable();
            $table->char('old_hash', 64)->nullable();
            $table->char('new_hash', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['career_position_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_position_edit_events');
        Schema::table('career_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cms_edited_by');
            $table->dropConstrainedForeignId('cms_published_by');
            $table->dropColumn(['draft_json','cms_revision','cms_source_note']);
        });
    }
};
