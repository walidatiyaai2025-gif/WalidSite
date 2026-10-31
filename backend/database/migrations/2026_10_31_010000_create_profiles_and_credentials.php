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
            $table->text('bio_en')->nullable();
            $table->text('bio_ar')->nullable();
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
            $table->boolean('publishable')->default(false);
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });
        Schema::create('skills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('name_en', 200);
            $table->string('name_ar', 200);
            $table->string('category', 80);
            $table->string('evidence_status', 20)->default('unverified');
            $table->timestamps();
        });
        Schema::create('certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('issuer', 200);
            $table->date('issued_on')->nullable();
            $table->boolean('verified')->default(false);
            $table->uuid('evidence_asset_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certifications');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('career_positions');
        Schema::dropIfExists('profiles');
    }
};
