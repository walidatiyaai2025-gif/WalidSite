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
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_allowlist_proposals');
    }
};
