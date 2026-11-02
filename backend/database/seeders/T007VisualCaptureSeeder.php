<?php

namespace Database\Seeders;

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

        // Seed only the owner-supplied baseline. Its review, verification,
        // publication and role states are intentionally preserved: a visual
        // fixture must never grant owner access or manufacture approved claims.
        $this->call(OwnerCvBaselineSeeder::class);
    }
}
