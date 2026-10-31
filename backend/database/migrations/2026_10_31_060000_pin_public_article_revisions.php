<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            // A public response always uses this reviewed snapshot; new drafts
            // never alter the live revision until they pass publication gates.
            $table->unsignedInteger('published_revision_no')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropColumn('published_revision_no');
        });
    }
};
