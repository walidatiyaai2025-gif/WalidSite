<?php

namespace Database\Seeders;

use App\Models\CareerPosition;
use App\Models\Certification;
use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class OwnerCvBaselineSeeder extends Seeder
{
    public function run(): void
    {
        // Mandatory canonical source; NEVER import the retired SQL dump.
        $path = base_path('../contracts/owner-cv-baseline.json');
        if (! is_readable($path)) {
            throw new RuntimeException('Canonical owner CV baseline missing: deployment must include contracts/owner-cv-baseline.json.');
        }

        $cv = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($cv['schema'] ?? null) !== 'walidsite.owner-cv-baseline.v1') {
            throw new RuntimeException('Unsupported owner CV contract version.');
        }

        // Inactive, passwordless account is a relationship anchor, NOT an admin login or role.
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
            $startYear = (int) $experience['period']['start'];
            $endYear = isset($experience['period']['end']) ? (int) $experience['period']['end'] : null;
            // CV supplies *years*, not specific dates. Dates are normalized ONLY for DB indexing;
            // consumers MUST render years using date_precision, never imply January/December exactness.
            CareerPosition::firstOrCreate(
                ['profile_id' => $profile->id, 'org_name' => $experience['organization'], 'started_on' => sprintf('%04d-01-01', $startYear)],
                [
                    'role_en' => implode(' / ', $experience['roles']),
                    'role_ar' => implode(' / ', $experience['roles']),
                    'ended_on' => $endYear ? sprintf('%04d-12-31', $endYear) : null,
                    'date_precision' => 'year',
                    'publishable' => false,
                    'verified' => false,
                    'source_refs_json' => $experience['source_refs'],
                ]
            );
        }

        foreach ($cv['competencies'] as $group) {
            foreach ($group['items'] as $name) {
                $skill = Skill::firstOrCreate(['slug' => Str::slug($name)], [
                    'name_en' => $name,
                    'name_ar' => $name,
                    'category' => $group['group'],
                    'evidence_status' => 'owner_cv_unverified',
                ]);
                $profile->skills()->syncWithoutDetaching([$skill->id]);
            }
        }

        foreach ($cv['certifications'] as $certificate) {
            Certification::firstOrCreate(
                ['profile_id' => $profile->id, 'title' => $certificate['title']],
                [
                    'issuer' => null, 'issued_on' => null, 'verified' => false,
                    'evidence_asset_id' => null, 'source_refs_json' => $certificate['source_refs'],
                ]
            );
        }

        // High-level owner CV summary is available for editorial review; no unverified claim
        // becomes public automatically. Publication gates belong to T008/T016.
        ProfessionalClaim::firstOrCreate(
            ['profile_id' => $profile->id, 'claim_en' => $summary],
            [
                'claim_ar' => $profile->bio_ar, 'evidence_url' => null,
                'status' => 'review', 'is_public' => false,
                'source_refs_json' => $cv['professional_summary']['source_refs'],
            ]
        );
    }
}
