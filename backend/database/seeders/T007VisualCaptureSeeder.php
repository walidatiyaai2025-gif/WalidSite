<?php

namespace Database\Seeders;

use App\Domain\Profile\OwnerCvPublisher;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
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

        // Start from the exact fail-closed source import, then reproduce
        // the legitimate post-activation transition used by SiteInstaller.
        // The fixture never marks a career/skill verified itself; the shared
        // domain gate re-checks the canonical owner, source values and refs.
        $this->call(OwnerCvBaselineSeeder::class);
        $owner = User::query()->where('email', app(\App\Support\Deployment\SiteInstaller::class)->ownerEmail())
            ->firstOrFail();
        $owner->forceFill(['status' => 'active'])->save();
        $owner->roles()->syncWithoutDetaching([
            Role::firstOrCreate(['name' => 'owner'])->id,
        ]);
        app(OwnerCvPublisher::class)->publishAfterVerifiedActivation($owner->fresh());
    }
}
