<?php

namespace Database\Seeders;

use App\Models\ProfessionalClaim;
use App\Models\Profile;
use App\Models\Role;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class T007VisualCaptureSeeder extends Seeder
{
    private const REPOSITORY = 'walidatiyaai2025-gif/WalidSite';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('T007 visual fixture is testing-only.');
        }

        $sha = (string) getenv('GITHUB_SHA');
        if (! preg_match('/\A[0-9a-f]{40}\z/D', $sha)) {
            throw new RuntimeException('T007 visual fixture requires the checked-out GitHub SHA.');
        }

        $repo = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'WalidSite-T007-visual-fixture'])
            ->connectTimeout(5)->timeout(10)
            ->get('https://api.github.com/repos/'.self::REPOSITORY);
        if (! $repo->successful()
            || $repo->json('private') !== false
            || $repo->json('visibility') !== 'public'
            || $repo->json('full_name') !== self::REPOSITORY) {
            throw new RuntimeException('T007 visual fixture requires live public GitHub evidence.');
        }

        $this->call(OwnerCvBaselineSeeder::class);

        DB::transaction(function () use ($sha): void {
            $user = User::query()->where('email', 'lido825@hotmail.com')->firstOrFail();
            $user->forceFill(['status' => 'active'])->save();
            $owner = Role::firstOrCreate(['name' => 'owner']);
            DB::table('role_user')->insertOrIgnore([
                'role_id' => $owner->id,
                'user_id' => $user->id,
            ]);

            $profile = Profile::query()->where('user_id', $user->id)->firstOrFail();
            $profile->forceFill(['publish_status' => 'published'])->save();

            DB::table('career_positions')->where('profile_id', $profile->id)->update([
                'verified' => true,
                'publishable' => true,
                'updated_at' => now(),
            ]);
            DB::table('skills')
                ->whereIn('id', DB::table('profile_skill')
                    ->where('profile_id', $profile->id)->select('skill_id'))
                ->update(['evidence_status' => 'verified']);

            // The approved T007 visual target includes AI engineering. Admit
            // that capability to this ephemeral runtime only after the exact
            // repository under test has been independently verified public.
            $ai = Skill::firstOrCreate(
                ['slug' => 'ai-engineering'],
                [
                    'name_en' => 'AI Engineering',
                    'name_ar' => 'هندسة الذكاء الاصطناعي',
                    'category' => 'AI Engineering',
                    'evidence_status' => 'verified',
                ]
            );
            $ai->forceFill(['evidence_status' => 'verified'])->save();
            DB::table('profile_skill')->insertOrIgnore([
                'profile_id' => $profile->id,
                'skill_id' => $ai->id,
            ]);

            // PublicPortfolioController intentionally rejects github.com as a
            // generic claim-evidence host. Pin raw source files to the exact
            // checked-out SHA so the screenshot fixture passes the SAME public
            // URL policy as production without weakening that policy.
            $rawBase = 'https://raw.githubusercontent.com/'.self::REPOSITORY.'/'.$sha.'/';
            $cvEvidence = $rawBase.'contracts/owner-cv-baseline.json';
            $repoEvidence = $rawBase.'backend/app/AI/ProviderRouter.php';

            foreach ([
                [
                    'en' => 'Engineering reliable enterprise systems with practical AI.',
                    'ar' => 'هندسة أنظمة مؤسسية موثوقة مع استخدام عملي للذكاء الاصطناعي.',
                    'url' => $repoEvidence,
                    'refs' => ['cv_dba', 'cv_pm', 'github:WalidSite@'.$sha],
                ],
                [
                    'en' => 'Technology Leadership / SQL / AI',
                    'ar' => 'القيادة التقنية / SQL / الذكاء الاصطناعي',
                    'url' => $cvEvidence,
                    'refs' => ['cv_dba', 'cv_pm', 'github:WalidSite@'.$sha],
                ],
                [
                    'en' => 'Enterprise & Royal Authority Systems',
                    'ar' => 'أنظمة المؤسسات والجهات الأميرية',
                    'url' => $cvEvidence,
                    'refs' => ['cv_dba', 'cv_pm'],
                ],
                [
                    'en' => 'PAAET / Diyar - Delivery & Engineering',
                    'ar' => 'PAAET / Diyar - التسليم والهندسة',
                    'url' => $cvEvidence,
                    'refs' => ['cv_dba', 'cv_pm'],
                ],
            ] as $claim) {
                ProfessionalClaim::updateOrCreate(
                    ['profile_id' => $profile->id, 'claim_en' => $claim['en']],
                    [
                        'claim_ar' => $claim['ar'],
                        'evidence_url' => $claim['url'],
                        'status' => 'approved',
                        'is_public' => true,
                        'source_refs_json' => $claim['refs'],
                    ]
                );
            }
        });
    }
}
