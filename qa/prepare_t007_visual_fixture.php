<?php

declare(strict_types=1);

/**
 * Prepare deterministic, source-backed T007 visual data for local/CI screenshots.
 *
 * This script is deliberately TESTING-ONLY. It promotes the already seeded,
 * owner-supplied CV records into the public scopes used by the five T007 pages
 * and adds one AI skill whose evidence is the repository's implemented AI
 * subsystem. It never runs in production and never inserts credentials,
 * metrics, certifications, employer secrets or invented project results.
 */

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/backend/vendor/autoload.php';
$app = require $root.'/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || getenv('WALIDSITE_T007_VISUAL_FIXTURE') !== '1') {
    fwrite(STDERR, "REFUSED: T007 visual fixture is restricted to APP_ENV=testing with explicit opt-in.\n");
    exit(2);
}

$sourcePath = $root.'/contracts/owner-cv-baseline.json';
$source = json_decode((string) file_get_contents($sourcePath), true, 512, JSON_THROW_ON_ERROR);
if (($source['schema'] ?? null) !== 'walidsite.owner-cv-baseline.v1') {
    throw new RuntimeException('Unexpected owner CV source schema.');
}
if (count($source['experience'] ?? []) < 3 || count($source['competencies'] ?? []) < 1) {
    throw new RuntimeException('Owner CV source is incomplete for the visual fixture.');
}

DB::transaction(function () use ($source): void {
    $user = User::query()->where('email', $source['identity']['contact']['email'])->firstOrFail();
    $user->update(['status' => 'active']);

    $ownerRole = Role::firstOrCreate(['name' => 'owner']);
    $user->roles()->syncWithoutDetaching([$ownerRole->id]);

    $profile = Profile::query()->where('user_id', $user->id)->firstOrFail();
    $profile->update(['publish_status' => 'published']);

    // The baseline seeder imported only the owner-approved, high-level career
    // rows. For this deterministic screenshot fixture, mark those same rows as
    // publishable/verified; no extra employer detail is synthesized.
    DB::table('career_positions')->where('profile_id', $profile->id)->update([
        'publishable' => true,
        'verified' => true,
        'updated_at' => now(),
    ]);

    // Publish the canonical CV summary with its real public source document in
    // this repository. raw.githubusercontent.com is accepted by the production
    // evidence URL policy and the URL contains no secret/query/fragment.
    ProfessionalClaim::query()
        ->where('profile_id', $profile->id)
        ->where('claim_en', $source['professional_summary']['text'])
        ->firstOrFail()
        ->update([
            'status' => 'approved',
            'is_public' => true,
            'evidence_url' => 'https://raw.githubusercontent.com/walidatiyaai2025-gif/WalidSite/main/contracts/owner-cv-baseline.json',
        ]);

    // CV competencies were imported by the canonical seeder. Mark those exact
    // source-backed rows verified for the controlled screenshot fixture.
    DB::table('skills')
        ->whereIn('id', DB::table('profile_skill')->where('profile_id', $profile->id)->select('skill_id'))
        ->update(['evidence_status' => 'verified', 'updated_at' => now()]);

    // T007's approved reference contains an AI Engineering capability. The
    // repository itself contains the implemented backend/app/AI subsystem, so
    // the visual fixture records that demonstrable capability without claiming
    // a live provider, paid credential, KPI or production deployment.
    $aiSkill = Skill::firstOrCreate(
        ['slug' => 'ai-engineering'],
        [
            'name_en' => 'AI Engineering',
            'name_ar' => 'هندسة الذكاء الاصطناعي',
            'category' => 'AI Engineering',
            'evidence_status' => 'verified',
        ]
    );
    if ($aiSkill->evidence_status !== 'verified') {
        $aiSkill->update(['evidence_status' => 'verified']);
    }
    DB::table('profile_skill')->insertOrIgnore([
        'profile_id' => $profile->id,
        'skill_id' => $aiSkill->id,
    ]);
});

echo "PASS: T007 visual fixture prepared from canonical CV + repository AI implementation evidence.\n";
