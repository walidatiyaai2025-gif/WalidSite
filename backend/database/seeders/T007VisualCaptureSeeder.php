<?php

namespace Database\Seeders;

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
        $commit = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'WalidSite-T007-visual-fixture'])
            ->connectTimeout(5)->timeout(10)
            ->get('https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.$sha);
        if (! $commit->successful() || $commit->json('sha') !== $sha) {
            throw new RuntimeException('T007 visual fixture requires the exact public renderer commit.');
        }

        $this->call(OwnerCvBaselineSeeder::class);

        DB::transaction(function (): void {
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

        });
    }
}
