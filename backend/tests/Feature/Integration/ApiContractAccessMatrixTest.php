<?php

namespace Tests\Feature\Integration;

use App\Http\Middleware\RequireSecureApiTransport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * T024: exercise the registered API routes in the actual Laravel HTTP kernel.
 *
 * This is not a claim that every published operation is functionally accepted.
 * Route completeness and unauthorized-access failures must be fully enforced.
 */
final class ApiContractAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read the immutable OpenAPI method/role declarations, not a manually
     * curated allowlist that silently drops unimplemented operations.
     *
     * @return list<array{path:string,method:string,role:string}>
     */
    private function contractedOperations(): array
    {
        $source = file_get_contents(base_path('../api/openapi-contract.yaml'));
        $this->assertIsString($source, 'The canonical OpenAPI contract must be readable.');

        $inPaths = false;
        $path = null;
        $currentIndex = null;
        $items = [];
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
            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $matches)) {
                $path = $matches[1];
                $currentIndex = null;
            } elseif ($path !== null && preg_match('/^    (get|post|put|patch|delete|head|options|trace):\s*$/', $line, $matches)) {
                $items[] = [
                    'path' => $path,
                    'method' => strtoupper($matches[1]),
                    'role' => '',
                ];
                $currentIndex = count($items) - 1;
            } elseif ($currentIndex !== null && preg_match('/^      x-required-role:\s*(\S+)\s*$/', $line, $matches)) {
                $items[$currentIndex]['role'] = $matches[1];
            }
        }

        return $items;
    }

    private function registeredRoute(string $method, string $path): ?LaravelRoute
    {
        // Variable *names* in the published contract and in Laravel may differ
        // (for example {id} versus {provider}); the path shape must not.
        $expected = preg_replace('/\{[^}]+\}/', '{}', 'api/v1'.$path);
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($method, $route->methods(), true)) {
                continue;
            }
            $actual = preg_replace('/\{[^}]+\}/', '{}', $route->uri());
            if ($actual === $expected) {
                return $route;
            }
        }

        return null;
    }

    public function test_all_canonical_operations_are_registered_and_protected(): void
    {
        $operations = $this->contractedOperations();
        $this->assertCount(46, $operations, 'The current v6 OpenAPI contract contains 46 operations.');
        $this->assertCount(42, array_unique(array_column($operations, 'path')));
        $this->assertCount(26, array_filter($operations, static fn (array $item): bool => $item['role'] !== 'public'));

        $missing = [];
        foreach ($operations as $operation) {
            $label = $operation['method'].' '.$operation['path'];
            $this->assertContains($operation['role'], ['public', 'authenticated', 'admin'], $label);
            $route = $this->registeredRoute($operation['method'], $operation['path']);
            if ($route === null) {
                $missing[] = $label;
                continue;
            }

            $stack = $route->gatherMiddleware();
            if ($operation['role'] === 'public') {
                $this->assertNotContains('auth:sanctum', $stack, 'Public contract must not require login: '.$label);
            } else {
                $this->assertContains('auth:sanctum', $stack, 'Missing authentication: '.$label);
                $this->assertContains(
                    RequireSecureApiTransport::class,
                    $stack,
                    'Private API must prohibit plaintext production transport: '.$label
                );
            }
        }

        $this->assertSame([], $missing, 'Every canonical OpenAPI operation must be registered; no legacy allowlist.');
    }

    public function test_guests_are_denied_by_all_registered_private_openapi_operations(): void
    {
        $protected = 0;
        $tested = 0;
        foreach ($this->contractedOperations() as $operation) {
            if ($operation['role'] === 'public') {
                continue;
            }
            $protected++;
            $this->assertNotNull(
                $this->registeredRoute($operation['method'], $operation['path']),
                'Unregistered private operation: '.$operation['method'].' '.$operation['path']
            );

            // A valid-shaped but non-existent UUID must never bypass the
            // auth middleware; no secrets or private fixtures are required.
            $uri = '/api/v1'.preg_replace(
                '/\{[^}]+\}/',
                '00000000-0000-4000-8000-000000000001',
                $operation['path']
            );
            $response = $this->json($operation['method'], $uri, []);
            $this->assertSame(
                401,
                $response->status(),
                $operation['method'].' '.$operation['path'].' must deny unauthenticated guests.'
            );
            $tested++;
        }

        $this->assertSame(26, $protected, 'All 26 private contract operations must be examined.');
        $this->assertSame(26, $tested, 'All 26 protected operations must deny unauthenticated guests.');
    }
    /**
     * Exercise every non-parameterized admin route against a real bearer.
     * A reader with an admin scope, and an owner with a mobile-only scope,
     * must both fail before request validation, SQL writes or HTTP calls.
     * Dynamic resource route authorizations are covered by controller tests.
     */
    public function test_all_static_admin_operations_deny_reader_roles_and_mobile_only_tokens(): void
    {
        // These matrix calls are about authorization, not throttle policy;
        // dedicated throttle tests continue to exercise the real limiter.
        $this->withoutMiddleware(\\Illuminate\\Routing\\Middleware\\ThrottleRequests::class);
        Http::preventStrayRequests();

        $reader = User::create([
            'email' => 'reader-matrix@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $reader->roles()->attach(Role::firstOrCreate(['name' => 'reader'])->id);
        $owner = User::create([
            'email' => 'owner-matrix@example.test',
            'status' => 'active',
            'password_hash' => null,
        ]);
        $owner->roles()->attach(Role::firstOrCreate(['name' => 'owner'])->id);

        $bearers = [
            $reader->createToken('reader-admin-scope', ['admin'], now()->addHour())->plainTextToken,
            $owner->createToken('owner-mobile-scope', ['mobile'], now()->addHour())->plainTextToken,
        ];
        $tested = 0;
        foreach ($this->contractedOperations() as $operation) {
            if ($operation['role'] !== 'admin' || str_contains($operation['path'], '{')) {
                continue;
            }
            $label = $operation['method'].' '.$operation['path'];
            $this->assertNotNull($this->registeredRoute($operation['method'], $operation['path']), $label);
            foreach ($bearers as $token) {
                Auth::forgetGuards();
                $this->withToken($token)->json(
                    $operation['method'], '/api/v1'.$operation['path'], []
                )->assertForbidden($label.' must reject a non-admin role or unscoped bearer.');
            }
            $tested++;
        }

        $this->assertSame(16, $tested, 'Exercise all 16 static admin contract operations.');
        Http::assertNothingSent();
    }

}
