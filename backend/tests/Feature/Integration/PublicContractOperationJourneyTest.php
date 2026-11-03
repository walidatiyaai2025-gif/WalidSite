<?php

namespace Tests\Feature\Integration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * T024: run every published anonymous operation through the real Laravel
 * kernel. Complements the 46-route registry and 26-private-route denial matrix.
 *
 * This verifies empty/negative states, not unperformed provider, publication,
 * staging or device acceptance. The OpenAPI document owns the inventory.
 */
final class PublicContractOperationJourneyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{path:string,method:string,role:string}>
     */
    private function operations(): array
    {
        $source = file_get_contents(base_path('../api/openapi-contract.yaml'));
        $this->assertIsString($source, 'Canonical OpenAPI must exist');

        $inPaths = false;
        $path = null;
        $last = null;
        $items = [];
        foreach (preg_split('/\r?\n/', $source) as $line) {
            if ($line === 'paths:') {
                $inPaths = true;
                continue;
            }
            if ($inPaths && $line !== '' && ! str_starts_with($line, ' ')
                && ! str_starts_with($line, '#')) {
                break;
            }
            if (! $inPaths) {
                continue;
            }
            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $matches)) {
                $path = $matches[1];
                $last = null;
            } elseif ($path !== null
                && preg_match('/^    (get|post|put|patch|delete|head|options|trace):\s*$/', $line, $matches)) {
                $items[] = ['path' => $path, 'method' => strtoupper($matches[1]), 'role' => ''];
                $last = count($items) - 1;
            } elseif ($last !== null
                && preg_match('/^      x-required-role:\s*(\S+)\s*$/', $line, $matches)) {
                $items[$last]['role'] = $matches[1];
            }
        }

        $this->assertCount(46, $items, 'The 46 canonical operations must remain accounted for');

        return array_values(array_filter(
            $items, static fn (array $operation): bool => $operation['role'] === 'public'
        ));
    }

    public function test_all_twenty_public_operations_have_real_anonymous_bilingual_http_outcomes(): void
    {
        // Exercise app/controller and database logic, not a mocked API client.
        // Throttling has its own tests and must not distort this full matrix.
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['portfolio.github_public_repos' => []]);
        Http::fake();
        Mail::fake();

        $operations = $this->operations();
        $this->assertCount(20, $operations, 'Every public operation must be exercised');
        $static = 0;
        $missing = 0;
        $invalidContact = 0;

        foreach (['en', 'ar'] as $locale) {
            foreach ($operations as $operation) {
                $label = $operation['method'].' '.$operation['path'].' ['.$locale.']';
                $path = preg_replace(
                    '/\{[^}]+\}/', '00000000-0000-4000-8000-000000000001',
                    $operation['path']
                );
                $url = '/api/v1'.$path.'?lang='.$locale;

                if ($operation['method'] === 'POST') {
                    $this->assertSame('/public/contact', $operation['path'], $label);
                    $this->postJson($url, [])->assertUnprocessable();
                    $invalidContact++;
                    continue;
                }

                $this->assertSame('GET', $operation['method'], $label);
                if (str_contains($operation['path'], '{')) {
                    // All dynamic resources are absent in an empty database.
                    // A public resource must not leak an internal 500 or draft.
                    $this->getJson($url)->assertNotFound();
                    $missing++;
                    continue;
                }

                $response = $this->getJson($url);
                $this->assertSame(200, $response->status(), $label);
                $this->assertTrue(
                    array_key_exists('data', (array) $response->json()),
                    $label.' must return a JSON data envelope, even when empty'
                );
                $this->assertNotSame('', $response->headers->get('Content-Type', ''));
                $static++;
            }
        }

        $this->assertSame(32, $static, '16 static anonymous GETs × two locales');
        $this->assertSame(6, $missing, 'Three dynamic public GETs × two locales');
        $this->assertSame(2, $invalidContact, 'One invalid contact POST × two locales');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }
}
