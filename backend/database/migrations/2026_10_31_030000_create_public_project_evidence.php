<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 140)->unique();
            $table->string('title', 220);
            $table->text('summary_en');
            $table->text('summary_ar');
            $table->string('role_en', 200)->nullable();
            $table->string('role_ar', 200)->nullable();
            $table->string('status', 24)->default('research');
            $table->string('visibility', 20)->default('private');
            // The GitHub repository registry is a later dedicated migration.
            // This UUID is not a trusted public repo URL.
            $table->uuid('github_repo_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['visibility', 'published_at', 'status']);
        });

        Schema::create('project_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('evidence_type', 40);
            $table->text('url');
            $table->string('visibility', 20)->default('private');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'visibility', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_evidence');
        Schema::dropIfExists('projects');
    }
};
