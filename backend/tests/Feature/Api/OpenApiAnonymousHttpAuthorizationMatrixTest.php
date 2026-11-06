<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T024 contract-to-HTTP acceptance, not route-registration or screenshot QA.
 *
 * Iterate the checked-in 42-path/46-operation OpenAPI contract, then dispatch
 * real Laravel JSON HTTP requests through its middleware. Protected operations
 * must reject anonymous callers *before* input validation, database mutation,
 * provider use or side effects. Guest public reads must behave honestly.
 */
final class OpenApiAnonymousHttpAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Parse only the fixed, indentation-defined OpenAPI operation metadata.
     * A missing/unknown declaration must fail closed, not silently disappear
     * from the matrix as the contract evolves.
     *
     * @return list<array{path:string,method:string,role:string,security:bool,id:string}>
     */
    private function operations(): array
    {
        $source = file_get_contents(dirname(base_path()).'/api/openapi-contract.yaml');
        $this->assertIsString($source, 'Canonical API contract is unavailable');
        $inPaths = false;
        $path = null;
        $current = null;
        $operations = [];
        $keys = [];

        foreach (preg_split('/\\r\\n|\\r|\\n/', $source) as $line) {
            if ($line === 'paths:') {
                $this->assertFalse($inPaths, 'Duplicate OpenAPI paths section');
                $inPaths = true;
                continue;
            }
            if (! $inPaths) {
                continue;
            }
            if (preg_match('/^[A-Za-z]/', $line) === 1) {
                break; // components: begins at root, after all paths.
            }

            if (preg_match('~^  (/[A-Za-z0-9_/{}/-]+):$~', $line, $match) === 1) {
                $path = $match[1];
                $current = null;
                continue;
            }
            if (preg_match('/^    (get|post|put|patch|delete):$/', $line, $match) === 1) {
                $this->assertNotNull($path, 'Operation has no contract path');
                $key = strtoupper($match[1]).' '.$path;
                $this->assertArrayNotHasKey($key, $keys, 'Duplicate OpenAPI HTTP operation');
                $keys[$key] = true;
                $operations[] = [
                    'path' => $path,
                    'method' => strtoupper($match[1]),
                    'role' => '',
                    'security' => false,
                    'id' => '',
                ];
                $current = count($operations) - 1;
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^      x-required-role: (.+)$/', $line, $match) === 1) {
                $operations[$current]['role'] = $match[1];
            } elseif (preg_match('/^      operationId: (.+)$/', $line, $match) === 1) {
                $operations[$current]['id'] = $match[1];
            } elseif ($line === '      security:') {
                $operations[$current]['security'] = true;
            }
        }

        $this->assertCount(46, $operations, 'Every contract method needs an HTTP case');
        $this->assertCount(42, array_unique(array_column($operations, 'path')));
        $ids = [];
        foreach ($operations as $entry) {
            $this->assertContains($entry['role'], ['public', 'admin', 'authenticated'],
                'Unknown/absent role for '.$entry['method'].' '.$entry['path']);
            $this->assertNotSame('', $entry['id'], 'Missing operationId');
            $this->assertNotContains($entry['id'], $ids, 'Duplicate operationId');
            $ids[] = $entry['id'];
            $this->assertSame($entry['role'] !== 'public', $entry['security'],
                'Bearer protection and required-role must agree for '.$entry['id']);
        }

        return $operations;
    }

    private function uri(string $contractPath): string
    {
        // Use route-valid, nonexistent IDs: 401 must precede entity selection.
        $path = str_replace(
            ['{id}', '{slug}'],
            ['00000000-0000-4000-8000-000000000001', 'missing-public-record'],
            $contractPath
        );
        $this->assertStringNotContainsString('{', $path,
            'Unknown path parameter requires an explicit test fixture');

        return '/api/v1'.$path;
    }

    public function test_every_protected_openapi_operation_returns_real_http_401_to_anonymous_callers(): void
    {
        $protected = 0;
        foreach ($this->operations() as $entry) {
            if ($entry['role'] === 'public') {
                continue;
            }
            $protected++;
            $response = $this->json(
                $entry['method'], $this->uri($entry['path']), []
            );
            $response->assertUnauthorized();
            $response->assertHeader('Content-Type', 'application/json');
            $this->assertStringNotContainsString(
                'PRIVATE_TEST_SENTINEL', $response->getContent(),
                'Protected operation returned private data: '.$entry['id']
            );
        }
        $this->assertGreaterThan(20, $protected,
            'An unexpectedly small protected surface needs security review');

        foreach (['personal_access_tokens', 'ai_jobs', 'projects', 'articles'] as $table) {
            $this->assertSame(0, DB::table($table)->count(),
                'Anonymous HTTP operation mutated '.$table);
        }
        Http::assertNothingSent();
    }

    public function test_public_get_contracts_execute_real_http_with_honest_empty_or_missing_states(): void
    {
        $publicReads = 0;
        $missingDetails = [
            '/public/projects/{slug}',
            '/public/articles/{slug}',
            '/mobile/solutions/{id}',
        ];
        foreach ($this->operations() as $entry) {
            if ($entry['role'] !== 'public' || $entry['method'] !== 'GET') {
                continue;
            }
            $publicReads++;
            $response = $this->getJson($this->uri($entry['path']));
            if (in_array($entry['path'], $missingDetails, true)) {
                $response->assertNotFound();
            } else {
                $response->assertOk()->assertHeader('Content-Type', 'application/json');
            }
            $this->assertStringNotContainsString(
                'PRIVATE_TEST_SENTINEL', $response->getContent()
            );
        }
        $this->assertGreaterThan(14, $publicReads,
            'The public read-only HTTP matrix must not silently shrink');
        Http::assertNothingSent();
    }

    public function test_public_contact_contract_does_not_fabricate_mail_delivery_without_transport(): void
    {
        config()->set('mail.contact_recipient', null);
        $contact = collect($this->operations())->first(
            static fn (array $operation) => $operation['path'] === '/public/contact'
                && $operation['method'] === 'POST'
        );
        $this->assertNotNull($contact, 'Contracted public contact is missing');

        $this->postJson($this->uri($contact['path']), [
            'name' => 'Contract user',
            'email' => 'test@example.test',
            'message' => 'Do not send this message without an approved transport.',
            'privacy_consent' => true,
        ])->assertStatus(503)->assertJsonMissingPath('data.accepted_by_transport');
        Http::assertNothingSent();
    }
}
