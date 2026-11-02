<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(false);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['user_id', 'purpose', 'channel'],
                'notification_preferences_user_purpose_channel_unique'
            );
            $table->index(['enabled', 'purpose', 'channel']);
        });

        Schema::create('account_deletion_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('subject_hash', 64)->unique();
            $table->string('reason_code', 50);
            $table->unsignedInteger('cancelled_ai_jobs')->default(0);
            $table->timestamp('deleted_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletion_events');
        Schema::dropIfExists('notification_preferences');
    }
};
