<?php

namespace Tests\Feature\Api;

use App\Models\AiProvider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

    private function uri(string $contractPath, ?string $providerId = null): string
    {
        // All protected model-bound provider URLs use a real fixture so an
        // authorization result can never be masked by a missing model 404.
        $id = $providerId !== null && str_contains($contractPath, '/admin/ai/')
            ? $providerId : '00000000-0000-4000-8000-000000000001';
        $path = str_replace(
            ['{id}', '{slug}'],
            [$id, 'missing-public-record'],
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

    /**
     * Create a real Sanctum personal access token; first-party web session
     * TransientToken is intentionally never used for API authorization.
     */
    private function bearer(string $role, string $ability): array
    {
        $user = User::create([
            'email' => Str::lower(Str::random(20)).'@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role])->id);
        $token = $user->createToken(
            'openapi-role-scope-regression', [$ability], now()->addHour()
        );

        return [$user, $token->plainTextToken];
    }

    private function checkedProvider(): AiProvider
    {
        return AiProvider::create([
            'display_name' => 'Local-only scope fixture',
            'provider_type' => 'openai-compatible',
            'base_url' => 'https://api.example.test/v1',
            'secret_ciphertext' => 'SYNTHETIC_ENCRYPTED_PLACEHOLDER_NOT_LIVE',
            'enabled' => false,
            'health_status' => 'unverified',
            'concurrent_limit' => 1,
            'timeout_seconds' => 5,
            'daily_budget_minor' => 100,
            'currency' => 'USD',
            'routing_priority' => 10,
        ]);
    }

    public function test_every_contract_admin_method_rejects_reader_and_mobile_only_owner_bearers(): void
    {
        // The role matrix intentionally traverses 44 requests in one test.
        // Laravel rate limiting has its own dedicated security tests and can
        // legitimately return 429 after the quota. Disable ONLY the throttle
        // middleware here, keeping auth:sanctum and all controller RBAC live.
        $this->withoutMiddleware(ThrottleRequests::class);
        $provider = $this->checkedProvider();
        [, $readerAdminBearer] = $this->bearer('reader', 'admin');
        [, $ownerMobileBearer] = $this->bearer('owner', 'mobile');
        $before = [
            'tokens' => DB::table('personal_access_tokens')->count(),
            'jobs' => DB::table('ai_jobs')->count(),
            'providers' => DB::table('ai_providers')->count(),
            'articles' => DB::table('articles')->count(),
            'audit' => DB::table('project_audit_events')->count(),
        ];

        $operations = array_values(array_filter(
            $this->operations(), static fn (array $op): bool => $op['role'] === 'admin'
        ));
        $this->assertCount(22, $operations);
        foreach ([
            'reader-with-admin-bearer' => $readerAdminBearer,
            'owner-with-mobile-only-bearer' => $ownerMobileBearer,
        ] as $scenario => $token) {
            foreach ($operations as $op) {
                Auth::forgetGuards();
                $this->withToken($token)->json(
                    $op['method'], $this->uri($op['path'], $provider->id), []
                )->assertForbidden()->assertHeader('Content-Type', 'application/json');
            }
        }

        $this->assertSame($before, [
            'tokens' => DB::table('personal_access_tokens')->count(),
            'jobs' => DB::table('ai_jobs')->count(),
            'providers' => DB::table('ai_providers')->count(),
            'articles' => DB::table('articles')->count(),
            'audit' => DB::table('project_audit_events')->count(),
        ], 'Denied admin requests must never mutate persistent records');
        Http::assertNothingSent();
    }

    public function test_genuine_owner_admin_bearer_can_read_every_contract_admin_get(): void
    {
        $provider = $this->checkedProvider();
        [, $ownerAdminBearer] = $this->bearer('owner', 'admin');
        $reads = array_values(array_filter($this->operations(),
            static fn (array $op): bool =>
                $op['role'] === 'admin' && $op['method'] === 'GET'
        ));
        $this->assertCount(13, $reads);
        foreach ($reads as $op) {
            Auth::forgetGuards();
            $this->withToken($ownerAdminBearer)->getJson(
                $this->uri($op['path'], $provider->id)
            )->assertOk()->assertHeader('Content-Type', 'application/json');
        }
        $this->assertDatabaseCount('ai_jobs', 0);
        $this->assertDatabaseCount('ai_providers', 1);
        Http::assertNothingSent();
    }

    public function test_admin_only_bearer_is_denied_all_four_private_mobile_contract_operations(): void
    {
        [, $ownerAdminBearer] = $this->bearer('owner', 'admin');
        $operations = array_values(array_filter(
            $this->operations(),
            static fn (array $op): bool => $op['role'] === 'authenticated'
        ));
        $this->assertCount(4, $operations);
        foreach ($operations as $op) {
            Auth::forgetGuards();
            $this->withToken($ownerAdminBearer)->json(
                $op['method'], $this->uri($op['path']), []
            )->assertForbidden()->assertHeader('Content-Type', 'application/json');
        }
        $this->assertDatabaseCount('ai_jobs', 0);
        $this->assertDatabaseCount('ai_diagnosis_requests', 0);
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_legitimate_mobile_bearer_preserves_private_read_and_validation_boundaries(): void
    {
        [$user, $ownerMobileBearer] = $this->bearer('owner', 'mobile');
        foreach (['/mobile/me', '/mobile/saved'] as $path) {
            Auth::forgetGuards();
            $response = $this->withToken($ownerMobileBearer)
                ->getJson($this->uri($path))->assertOk();
            if ($path === '/mobile/me') {
                $response->assertJsonPath('data.id', $user->id);
            }
        }
        foreach (['/mobile/ai/diagnose', '/mobile/interview'] as $path) {
            Auth::forgetGuards();
            $this->withToken($ownerMobileBearer)->postJson(
                $this->uri($path), []
            )->assertUnprocessable()->assertHeader('Content-Type', 'application/json');
        }
        $this->assertDatabaseCount('ai_jobs', 0);
        $this->assertDatabaseCount('ai_diagnosis_requests', 0);
        $this->assertDatabaseCount('mobile_interview_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_all_protected_openapi_operations_deny_revoked_and_expired_bearers(): void
    {
        // Test the REAL Sanctum + Laravel HTTP middleware, not route-list
        // registration. This executes 52 protected requests across every
        // documented admin/mobile method. Throttling has separate dedicated
        // tests; disable it only here so the authorization matrix remains
        // deterministic, without bypassing Sanctum, roles or abilities.
        $this->withoutMiddleware(ThrottleRequests::class);
        $provider = $this->checkedProvider();
        $operations = array_values(array_filter(
            $this->operations(),
            static fn (array $op): bool => $op['role'] !== 'public'
        ));
        $this->assertCount(26, $operations);

        foreach (['revoked', 'expired'] as $state) {
            foreach ([
                'admin' => ['ability' => 'admin', 'read' => '/api/v1/admin/dashboard'],
                'authenticated' => ['ability' => 'mobile', 'read' => '/api/v1/mobile/me'],
            ] as $contractRole => $identity) {
                [$user, $plainText] = $this->bearer('owner', $identity['ability']);

                // Prove the token was genuinely accepted before revocation or
                // expiration. Otherwise an always-401 stub could pass a
                // negative-only security test without exercising Sanctum.
                Auth::forgetGuards();
                $this->withToken($plainText)
                    ->getJson($identity['read'])->assertOk();

                if ($state === 'revoked') {
                    $this->assertSame(1, $user->tokens()->delete());
                } else {
                    $this->assertSame(
                        1, $user->tokens()->update(['expires_at' => now()->subMinute()])
                    );
                }

                $examined = 0;
                foreach ($operations as $op) {
                    if ($op['role'] !== $contractRole) {
                        continue;
                    }
                    $examined++;
                    Auth::forgetGuards();
                    $response = $this->withToken($plainText)->json(
                        $op['method'], $this->uri($op['path'], $provider->id), []
                    );
                    $response->assertUnauthorized()
                        ->assertHeader('Content-Type', 'application/json');
                    $this->assertStringNotContainsString(
                        'PRIVATE_TEST_SENTINEL', $response->getContent(),
                        'Stale bearer exposed private data: '.$op['id']
                    );
                }
                $this->assertSame(
                    $contractRole === 'admin' ? 22 : 4, $examined,
                    'Revoked/expired token matrix unexpectedly lost coverage'
                );
            }
        }

        foreach ([
            'ai_jobs', 'ai_diagnosis_requests', 'mobile_interview_sessions',
            'articles', 'projects', 'project_audit_events',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(),
                'Rejected stale-bearer request changed '.$table);
        }
        $this->assertDatabaseCount('ai_providers', 1);
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
