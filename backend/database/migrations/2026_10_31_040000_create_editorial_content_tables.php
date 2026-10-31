<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('asset_key', 180)->unique();
            $table->string('mime_type', 120);
            $table->text('storage_key');
            $table->char('sha256', 64);
            $table->string('origin', 24);
            $table->string('rights_status', 24)->default('unverified');
            $table->boolean('publishable')->default(false);
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 180)->unique();
            $table->string('title_en', 250);
            $table->string('title_ar', 250);
            $table->char('canonical_lang', 2)->default('en');
            $table->string('status', 24)->default('draft');
            $table->foreignUuid('hero_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
        });

        Schema::create('article_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedInteger('revision_no');
            $table->longText('body_md');
            $table->json('citations_json');
            $table->json('validation_json');
            $table->string('status', 24)->default('draft');
            $table->timestamps();
            $table->unique(['article_id', 'revision_no']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 110)->unique();
            $table->string('name_en', 170);
            $table->string('name_ar', 170);
            $table->timestamps();
        });
        Schema::create('article_categories', function (Blueprint $table): void {
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['article_id', 'category_id']);
        });
        Schema::create('asset_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('size_key', 50);
            $table->text('storage_key');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('format', 12);
            $table->unique(['asset_id', 'size_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_variants');
        Schema::dropIfExists('article_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('article_revisions');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('assets');
    }
};
