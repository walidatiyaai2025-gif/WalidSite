<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            // Retain provider identity for immutable audit rows after deletion.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
