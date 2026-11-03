<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('article_revisions', function (Blueprint $table): void {
            // Legacy revisions stay NULL: no fictitious historical authors.
            $table->foreignUuid('author_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('last_editor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('article_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropConstrainedForeignId('last_editor_user_id');
            $table->dropConstrainedForeignId('author_user_id');
            $table->dropColumn('reviewed_at');
        });
    }
};
