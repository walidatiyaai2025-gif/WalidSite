<?php

namespace Tests\Feature\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T024: run every canonical public API contract operation through the real
 * anonymous Laravel HTTP kernel in both supported languages. This is a
 * deterministic empty-state/negative-delivery matrix, not positive DTO
 * certification for every registered endpoint.
 */
final class PublicApiGuestContractMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Parse the canonical OpenAPI file, never a hand-maintained public-route
     * list that could accidentally exclude a newly introduced endpoint.
     *
     * @return list<array{path:string,method:string,role:string}>
     */
    private function operations(): array
    {
        $source = file_get_contents(base_path('../api/openapi-contract.yaml'));
        $this->assertIsString($source);

        $inPaths = false;
        $path = null;
        $current = null;
        $operations = [];
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
                $current = null;
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete|head|options|trace):\s*$/', $line, $match)) {
                $operations[] = [
                    'path' => $path,
                    'method' => strtoupper($match[1]),
                    'role' => '',
                ];
                $current = count($operations) - 1;
            } elseif ($current !== null && preg_match('/^      x-required-role:\s*(\S+)\s*$/', $line, $match)) {
                $operations[$current]['role'] = $match[1];
            }
        }

        $this->assertCount(46, $operations, 'Canonical OpenAPI must declare 46 HTTP operations.');
        $public = array_values(array_filter($operations, static fn (array $operation): bool =>
            $operation['role'] === 'public'
        ));
        $this->assertCount(20, $public, 'All 20 public contract operations must be exercised.');
        $this->assertCount(19, array_filter($public, static fn (array $operation): bool =>
            $operation['method'] === 'GET'
        ));
        $this->assertSame(
            [['path' => '/public/contact', 'method' => 'POST', 'role' => 'public']],
            array_values(array_filter($public, static fn (array $operation): bool =>
                $operation['method'] !== 'GET'
            )),
            'Only consent-based contact submission may be a public write.'
        );

        return $public;
    }

    private function prepareAnonymousFixture(): void
    {
        // Retain real authentication/security/installation middleware; rate
        // limits are verified separately and would otherwise distort the
        // multi-route matrix into unrelated HTTP 429 outcomes.
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'portfolio.github_public_repos' => [],
            'mail.contact_recipient' => null,
            'mail.default' => 'log',
        ]);
        Http::preventStrayRequests();
    }

    public function test_every_public_get_has_an_honest_bilingual_guest_response(): void
    {
        $this->prepareAnonymousFixture();

        $static = 0;
        $dynamic = 0;
        foreach ($this->operations() as $operation) {
            if ($operation['method'] !== 'GET') {
                continue;
            }

            $path = $operation['path'];
            $uri = '/api/v1'.$path;
            $isDynamic = str_contains($path, '{');
            if ($isDynamic) {
                // Canonical URI placeholders use only {slug} and {id}; never
                // substitute actual private records or run production calls.
                $this->assertContains($path, [
                    '/public/projects/{slug}',
                    '/public/articles/{slug}',
                    '/mobile/solutions/{id}',
                ], 'Unexpected public dynamic path requires an explicit safe fixture.');
                $uri = str_replace(
                    ['{slug}', '{id}'],
                    ['missing-reviewed-record', '00000000-0000-4000-8000-000000000001'],
                    $uri
                );
                $dynamic++;
            } else {
                $static++;
            }

            foreach (['en', 'ar'] as $locale) {
                $response = $this->getJson($uri.'?lang='.$locale);
                $this->assertSame(
                    $isDynamic ? 404 : 200,
                    $response->status(),
                    'Public '.$path.' must expose a truthful guest '.$locale.' response.'
                );
                $this->assertStringContainsString(
                    'application/json',
                    strtolower((string) $response->headers->get('Content-Type')),
                    $path.' must not return a login page or HTML error.'
                );
                $this->assertIsArray($response->json(), $path.' must return JSON.');
                if (! $isDynamic) {
                    $this->assertArrayHasKey(
                        'data', $response->json(), $path.' requires a response data envelope.'
                    );
                }
                $response->assertDontSee('DB_PASSWORD')
                    ->assertDontSee('APP_KEY')
                    ->assertDontSee('PRIVATE_SOURCE_SECRET');
            }
        }

        $this->assertSame(16, $static, 'Every one of the 16 static public GET routes was exercised.');
        $this->assertSame(3, $dynamic, 'Every one of the 3 dynamic public GET routes was exercised.');
        Http::assertNothingSent();
    }

    public function test_public_contact_post_cannot_claim_delivery_when_mail_is_disabled(): void
    {
        $this->prepareAnonymousFixture();
        Mail::fake();

        foreach (['en', 'ar'] as $locale) {
            $this->postJson('/api/v1/public/contact?lang='.$locale, [
                'name' => 'Contract visitor',
                'email' => 'private-visitor@example.test',
                'message' => 'Request for a technical discussion with proper consent.',
                'privacy_consent' => true,
            ])->assertStatus(503)
                ->assertDontSee('private-visitor@example.test')
                ->assertDontSee('Request for a technical discussion');
        }

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
