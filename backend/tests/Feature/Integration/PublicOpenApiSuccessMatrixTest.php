<?php

namespace Tests\Feature\Integration;

use App\Models\Project;
use App\Models\ProjectEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T024 G-03/G-16: genuine public HTTP success, not route registration alone.
 *
 * The existing contract matrix denies guests on 26 protected operations.
 * This matrix exercises every non-parameterized public GET on the actual
 * Laravel kernel, in both locales, with no external providers or fake counts.
 * Parameterized resources are independently exercised with reviewed fixtures.
 */
final class PublicOpenApiSuccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Discover real public GET operations from the current v6 contract.
     * A new static guest endpoint must be tested here automatically, rather
     * than silently disappearing from a hard-coded positive allowlist.
     *
     * @return list<string>
     */
    private function staticPublicReads(): array
    {
        $source = file_get_contents(base_path('../api/openapi-contract.yaml'));
        $this->assertIsString($source);

        $inPaths = false;
        $path = null;
        $method = null;
        $reads = [];
        foreach (preg_split('/\r?\n/', $source) as $line) {
            if ($line === 'paths:') {
                $inPaths = true;
                continue;
            }
            if ($inPaths && $line !== '' && $line[0] !== ' ' && $line[0] !== '#') {
                break;
            }
            if (! $inPaths) {
                continue;
            }
            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $match)) {
                $path = $match[1];
                $method = null;
            } elseif (preg_match('/^    (get|post|put|patch|delete|head|options|trace):\s*$/', $line, $match)) {
                $method = $match[1];
            } elseif ($path !== null && $method === 'get'
                && preg_match('/^      x-required-role:\s*(\S+)\s*$/', $line, $match)
                && $match[1] === 'public' && ! str_contains($path, '{')) {
                $reads[] = $path;
            }
        }

        return $reads;
    }

    public function test_all_sixteen_static_guest_read_operations_return_real_bilingual_json(): void
    {
        Http::fake();

        $reads = $this->staticPublicReads();
        $this->assertCount(16, $reads, 'Every current static public GET must be verified.');
        $this->assertCount(16, array_unique($reads), 'Duplicate contract operations must not disappear.');
        $this->assertContains('/public/app-bootstrap', $reads);
        $this->assertContains('/mobile/preferences', $reads);
        $this->assertContains('/public/github/portfolio', $reads);

        foreach (['en', 'ar'] as $locale) {
            foreach ($reads as $path) {
                $response = $this->getJson('/api/v1'.$path.'?lang='.$locale);
                $this->assertSame(200, $response->status(),
                    'Public read failed: GET '.$path.' ('.$locale.')');
                $this->assertStringStartsWith(
                    'application/json',
                    (string) $response->headers->get('Content-Type'),
                    'Public read served a non-JSON payload: '.$path
                );
                $payload = $response->json();
                $this->assertIsArray($payload, 'Invalid JSON envelope: '.$path);
                $this->assertArrayHasKey('data', $payload,
                    'Missing public data envelope: '.$path);
                $this->assertArrayNotHasKey('password', $payload);
                $this->assertArrayNotHasKey('api_key', $payload);
            }
        }

        Http::assertNothingSent();
    }

    public function test_published_project_detail_is_readable_in_both_locales_but_revocation_withdraws_it(): void
    {
        Http::fake();
        $project = Project::create([
            'slug' => 'reviewed-api-journey',
            'title' => 'Reviewed API Journey',
            'summary_en' => 'Source-reviewed English summary',
            'summary_ar' => 'ملخص عربي موثق',
            'role_en' => 'Engineer',
            'role_ar' => 'مهندس',
            'status' => 'completed',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $evidence = ProjectEvidence::create([
            'project_id' => $project->id,
            'evidence_type' => 'document',
            'url' => 'https://docs.example.org/reviewed-api-journey',
            'visibility' => 'public',
            'verified_at' => now()->subDay(),
        ]);

        foreach (['en' => ['Source-reviewed English summary', 'Engineer'],
                  'ar' => ['ملخص عربي موثق', 'مهندس']] as $locale => $expected) {
            $this->getJson('/api/v1/public/projects/reviewed-api-journey?lang='.$locale)
                ->assertOk()
                ->assertJsonPath('data.slug', 'reviewed-api-journey')
                ->assertJsonPath('data.summary', $expected[0])
                ->assertJsonPath('data.role', $expected[1])
                ->assertJsonCount(1, 'data.evidence_urls');
            $this->getJson('/api/v1/public/projects?lang='.$locale)
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.slug', 'reviewed-api-journey');
        }

        // A flag is never permanent permission: revocation must immediately
        // remove both the detail route and the discoverable list entry.
        $evidence->update(['verified_at' => null]);
        foreach (['en', 'ar'] as $locale) {
            $this->getJson('/api/v1/public/projects/reviewed-api-journey?lang='.$locale)
                ->assertNotFound();
            $this->getJson('/api/v1/public/projects?lang='.$locale)
                ->assertOk()
                ->assertJsonPath('meta.total', 0)
                ->assertJsonCount(0, 'data');
        }
        Http::assertNothingSent();
    }
}
