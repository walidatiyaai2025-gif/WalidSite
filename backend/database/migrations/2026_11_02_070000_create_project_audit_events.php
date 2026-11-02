<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Append-only decision receipts intentionally have no cascading project
        // or user FK: deleting the source record must not destroy audit history.
        // Never store submitted URLs, body content, credentials or actor email.
        Schema::create('project_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('evidence_id')->nullable();
            $table->uuid('actor_id');
            $table->string('action', 40);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['project_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_audit_events');
    }
};
