<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('runbook_versions', function (Blueprint $table): void {
            // Existing version checksums cover the body alone. Do NOT
            // auto-approve a mutable live title as a historical review.
            // Legacy rows remain unpublishable until a real editorial
            // reviewer records the exact title for that version.
            $table->string('reviewed_title', 220)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runbook_versions', function (Blueprint $table): void {
            $table->dropColumn('reviewed_title');
        });
    }
};
