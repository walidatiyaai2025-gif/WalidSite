<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('github_repository_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('repository_slug', 140);
            $table->string('normalized_slug', 140)->unique();
            $table->string('status', 16)->default('pending')->index();
            $table->foreignUuid('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('decision_reason', 180)->nullable();
            $table->timestamps();
        });

        Schema::create('github_repository_candidate_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('candidate_id')->constrained('github_repository_candidates')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->string('reason', 180)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['candidate_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_repository_candidate_events');
        Schema::dropIfExists('github_repository_candidates');
    }
};
