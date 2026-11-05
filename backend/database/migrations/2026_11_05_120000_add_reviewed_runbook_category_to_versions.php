<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('runbook_versions', function (Blueprint $table): void {
            // A category is a reviewed attribute of an exact published
            // version, not a mutable live runbook tag. No legacy backfill:
            // unlabeled guides remain public but cannot invent categories.
            $table->string('reviewed_category_slug', 60)->nullable();
            $table->string('reviewed_category_name_en', 160)->nullable();
            $table->string('reviewed_category_name_ar', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runbook_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'reviewed_category_slug',
                'reviewed_category_name_en',
                'reviewed_category_name_ar',
            ]);
        });
    }
};
