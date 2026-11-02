<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_import_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedTinyInteger('source_schema_version');
            $table->string('source_table', 32);
            $table->char('content_hash', 64)->unique();
            $table->char('source_label_hash', 64);
            $table->char('source_key_hash', 64);
            $table->text('source_url')->nullable();
            $table->string('target_type', 24);
            $table->uuid('target_id');
            $table->timestamp('imported_at');
            $table->index(['target_type', 'target_id']);
            $table->index(['source_table', 'imported_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_import_receipts');
    }
};
