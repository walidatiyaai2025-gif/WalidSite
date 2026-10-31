<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('public_name', 140);
            $table->text('bio_en');
            $table->text('bio_ar');
            $table->uuid('portrait_asset_id')->nullable();
            $table->string('publish_status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::create('career_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('org_name', 200);
            $table->string('role_en', 200);
            $table->string('role_ar', 200);
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('date_precision', 12)->default('year');
            $table->boolean('publishable')->default(false);
            $table->boolean('verified')->default(false);
            $table->json('source_refs_json')->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'org_name', 'started_on']);
        });

        Schema::create('skills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name_en', 200);
            $table->string('name_ar', 200);
            $table->string('category', 80);
            $table->string('evidence_status', 30)->default('unverified');
            $table->timestamps();
        });

        Schema::create('profile_skill', function (Blueprint $table): void {
            $table->foreignUuid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->primary(['profile_id', 'skill_id']);
        });

        Schema::create('certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title', 200);
            // The CV does not prove the issuer; never guess it.
            $table->string('issuer', 200)->nullable();
            $table->date('issued_on')->nullable();
            $table->boolean('verified')->default(false);
            $table->uuid('evidence_asset_id')->nullable();
            $table->json('source_refs_json')->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'title']);
        });

        Schema::create('professional_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->text('claim_en');
            $table->text('claim_ar');
            $table->text('evidence_url')->nullable();
            $table->string('status', 20)->default('review');
            $table->boolean('is_public')->default(false);
            $table->json('source_refs_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_claims');
        Schema::dropIfExists('certifications');
        Schema::dropIfExists('profile_skill');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('career_positions');
        Schema::dropIfExists('profiles');
    }
};
