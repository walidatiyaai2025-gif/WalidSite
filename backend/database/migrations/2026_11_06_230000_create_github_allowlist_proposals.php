<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('github_allowlist_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('repository_slug', 140)->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignUuid('submitted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('decision_reason', 40)->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // Immutable receipt per submitted/approved/rejected transition.
        // Domain event rows never carry repository metadata, tokens or URLs.
        Schema::create('github_allowlist_proposal_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('proposal_id')->constrained('github_allowlist_proposals')->restrictOnDelete();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 16);
            $table->string('reason', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['proposal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_allowlist_proposal_events');
        Schema::dropIfExists('github_allowlist_proposals');
    }
};
