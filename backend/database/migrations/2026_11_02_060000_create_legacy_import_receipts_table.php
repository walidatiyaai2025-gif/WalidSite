<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_import_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_name', 180);
            $table->char('source_sha256', 64);
            $table->char('record_hash', 64)->unique();
            $table->string('source_table', 40);
            $table->string('source_key', 180);
            $table->string('target_type', 40);
            $table->uuid('target_id');
            $table->timestamp('imported_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['source_sha256', 'source_table']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_receipts');
    }
};
