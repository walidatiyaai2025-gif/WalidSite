<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Private, one-question self-review sessions. No AI score or certification.
        Schema::create('mobile_interview_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('path_id')->constrained('learning_paths')->cascadeOnDelete();
            $table->unsignedTinyInteger('lesson_index');
            $table->string('locale', 2);
            $table->string('lesson_title', 220);
            $table->text('lesson_context');
            $table->text('answer_text')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_interview_sessions');
    }
};
