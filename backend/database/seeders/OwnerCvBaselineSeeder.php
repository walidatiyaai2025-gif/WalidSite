<?php

namespace Database\Seeders;

use App\Models\Certification;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OwnerCvBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('../contracts/owner-cv-baseline.json');
        if (! is_readable($path)) {
            throw new RuntimeException('Required ../contracts/owner-cv-baseline.json is missing.');
        }
        $cv = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($cv['schema'] ?? null) !== 'walidsite.owner-cv-baseline.v1') {
            throw new RuntimeException('Unexpected owner CV baseline schema.');
        }

        DB::transaction(function () use ($cv): void {
            // No owner password, token, permissions or admin role is issued by a seed.
            $user = User::firstOrCreate(
                ['email' => $cv['identity']['contact']['email']],
                ['password_hash' => null, 'status' => 'pending_activation']
            );
            $summary = $cv['professional_summary']['text'];
            $profile = Profile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'public_name' => $cv['identity']['full_name'],
                    'bio_en' => $summary,
                    'bio_ar' => 'متخصص في إدارة قواعد بيانات SQL Server وإدارة المشاريع التقنية وهندسة البرمجيات، مع خبرة في التوافر العالي والتعافي من الكوارث وتحسين الأداء والتنسيق التقني.',
                    'publish_status' => 'review',
                    'portrait_asset_id' => null,
                ]
            );

            foreach ($cv['experience'] as $experience) {
                if (! in_array($experience['publication'], ['public_high_level', 'role_and_non_sensitive_summary_only'], true)) {
                    continue;
                }
                // Source gives YEARS ONLY. Jan/Dec are DB normalization, NOT real exact dates.
                $start = sprintf('%04d-01-01', (int) $experience['period']['start']);
                $end = isset($experience['period']['end'])
                    ? sprintf('%04d-12-31', (int) $experience['period']['end']) : null;

                // One row represents one employer-period. The source may list multiple
                // concurrent titles for that same period; preserve them together and let
                // the database unique key make reruns idempotent without overwriting edits.
                $roles = implode(' / ', array_values(array_unique($experience['roles'])));
                DB::table('career_positions')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'profile_id' => $profile->id,
                    'org_name' => $experience['organization'],
                    'started_on' => $start,
                    'role_en' => $roles,
                    'role_ar' => $roles,
                    'ended_on' => $end,
                    'publishable' => false,
                    'verified' => false,
                    'date_precision' => 'year',
                    'source_refs_json' => json_encode($experience['source_refs'], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($cv['competencies'] as $group) {
                foreach ($group['items'] as $name) {
                    $skill = Skill::firstOrCreate(['slug' => Str::slug($name)], [
                        'name_en' => $name, 'name_ar' => $name,
                        'category' => $group['group'], 'evidence_status' => 'owner_cv',
                    ]);
                    DB::table('profile_skill')->insertOrIgnore([
                        'profile_id' => $profile->id, 'skill_id' => $skill->id,
                    ]);
                }
            }

            foreach ($cv['certifications'] as $certificate) {
                $cert = Certification::firstOrCreate(
                    ['profile_id' => $profile->id, 'title' => $certificate['title']],
                    ['issuer' => null, 'issued_on' => null, 'verified' => false, 'evidence_asset_id' => null]
                );
                if ($cert->wasRecentlyCreated) {
                    DB::table('certifications')->where('id', $cert->id)->update([
                        'source_refs_json' => json_encode($certificate['source_refs'], JSON_THROW_ON_ERROR),
                    ]);
                }
            }

            ProfessionalClaim::firstOrCreate(
                ['profile_id' => $profile->id, 'claim_en' => $summary],
                [
                    'claim_ar' => $profile->bio_ar,
                    'evidence_url' => null,
                    'status' => 'review',
                    'is_public' => false,
                    'source_refs_json' => $cv['professional_summary']['source_refs'],
                ]
            );
        });
    }
}
