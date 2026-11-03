<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Append-only while an account is active. On account erasure this
        // account-scoped history is deleted with its owner, not retained as a
        // pseudonymous cross-account tracking identifier.
        Schema::create('notification_consent_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 40);
            $table->string('channel', 20);
            $table->enum('transition', ['granted', 'revoked']);
            $table->timestamp('occurred_at');
            $table->index(
                ['user_id', 'purpose', 'channel', 'occurred_at'],
                'consent_events_history_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_consent_events');
    }
};
