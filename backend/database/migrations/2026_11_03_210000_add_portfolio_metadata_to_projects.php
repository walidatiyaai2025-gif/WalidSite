<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('portfolio_category', 32)->default('enterprise')->index();
            $table->string('portfolio_source', 60)->nullable();
            $table->boolean('portfolio_featured')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', static function (Blueprint $table): void {
            $table->dropIndex(['portfolio_category']);
            $table->dropColumn(['portfolio_category', 'portfolio_source', 'portfolio_featured']);
        });
    }
};
