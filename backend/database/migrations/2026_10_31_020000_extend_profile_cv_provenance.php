<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // T003 phase 2 extends the already-merged schema from PR #36;
        // NEVER create a second profiles/career_positions/certifications table.
        Schema::table('career_positions', function (Blueprint $table): void {
            $table->string('date_precision', 12)->default('year');
            $table->json('source_refs_json')->nullable();
            $table->unique(['profile_id', 'org_name', 'started_on'], 'career_profile_period_unique');
        });
        Schema::table('certifications', function (Blueprint $table): void {
            // Issuer is absent from the source CV; keeping NULL avoids inventing one.
            $table->string('issuer', 200)->nullable()->change();
            $table->json('source_refs_json')->nullable();
            $table->unique(['profile_id', 'title'], 'certification_profile_title_unique');
        });
        Schema::create('profile_skill', function (Blueprint $table): void {
            $table->foreignUuid('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->primary(['profile_id', 'skill_id']);
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
        Schema::dropIfExists('profile_skill');
        Schema::table('certifications', function (Blueprint $table): void {
            $table->dropUnique('certification_profile_title_unique');
            $table->dropColumn('source_refs_json');
        });
        // Reversing a nullable column must not invent an issuer: blank means unknown.
        DB::table('certifications')->whereNull('issuer')->update(['issuer' => '']);
        Schema::table('certifications', function (Blueprint $table): void {
            $table->string('issuer', 200)->nullable(false)->change();
        });
        Schema::table('career_positions', function (Blueprint $table): void {
            $table->dropUnique('career_profile_period_unique');
            $table->dropColumn(['date_precision', 'source_refs_json']);
        });
    }
};
