<?php

namespace Tests\Feature\AI;

use App\AI\ProviderRouter;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderRouterTest extends TestCase
{
    private function provider(array $overrides = []): array
    {
        return array_replace([
            'id' => 'local-model',
            'provider_type' => 'openai-compatible',
            'enabled' => true,
            'health_status' => 'healthy',
            'capabilities' => ['text', 'embeddings'],
            'concurrent_limit' => 1,
            'in_flight' => 0,
            'daily_budget_remaining' => 10.00,
            'routing_priority' => 10,
        ], $overrides);
    }

    public function test_selects_eligible_provider_by_priority(): void
    {
        $router = new ProviderRouter;

        $this->assertSame('local-model', $router->selectProviderId('text', [
            $this->provider(['id' => 'paid-model', 'routing_priority' => 50]),
            $this->provider(),
        ]));
    }

    public function test_provider_is_skipped_when_limit_or_budget_is_exhausted(): void
    {
        $router = new ProviderRouter;

        $this->assertSame('paid-model', $router->selectProviderId('text', [
            $this->provider(['in_flight' => 1]),
            $this->provider(['id' => 'paid-model', 'routing_priority' => 20]),
        ]));

        $this->assertNull($router->selectProviderId('text', [
            $this->provider(['daily_budget_remaining' => 0]),
            $this->provider(['id' => 'disabled', 'enabled' => false]),
            $this->provider(['id' => 'unknown', 'health_status' => 'unknown']),
        ]));
    }

    public function test_routing_fails_closed_for_wrong_capability(): void
    {
        $router = new ProviderRouter;

        $this->assertNull($router->selectProviderId('image-generation', [
            $this->provider(),
        ]));
        $this->expectException(InvalidArgumentException::class);
        $router->selectProviderId('unrecognized-capability', [$this->provider()]);
    }

    public function test_unverified_provider_cannot_be_selected(): void
    {
        $this->assertNull((new ProviderRouter)->selectProviderId('vision', [
            $this->provider(['capabilities' => ['vision'], 'health_status' => 'unverified']),
        ]));
    }
    public function test_zero_budget_healthy_local_ollama_remains_eligible_without_paid_reservation(): void
    {
        $router = new ProviderRouter;

        $this->assertSame('local-free', $router->selectProviderId('text', [
            $this->provider([
                'id' => 'managed-exhausted', 'provider_type' => 'openai-compatible',
                'daily_budget_remaining' => 0, 'routing_priority' => 1,
            ]),
            $this->provider([
                'id' => 'local-free', 'provider_type' => 'ollama-compatible',
                'daily_budget_remaining' => 0, 'routing_priority' => 5,
            ]),
        ]));

        $this->assertNull($router->selectProviderId('text', [
            $this->provider([
                'id' => 'unknown-type', 'provider_type' => 'unknown',
                'daily_budget_remaining' => 0,
            ]),
            $this->provider([
                'id' => 'managed-exhausted', 'provider_type' => 'openai-compatible',
                'daily_budget_remaining' => 0,
            ]),
        ]));
    }

    public function test_zero_budget_does_not_bypass_local_provider_safety_gates(): void
    {
        $router = new ProviderRouter;
        $local = [
            'provider_type' => 'ollama-compatible',
            'daily_budget_remaining' => 0,
            'capabilities' => ['text'],
        ];

        $this->assertNull($router->selectProviderId('text', [
            $this->provider(array_replace($local, ['enabled' => false])),
        ]));
        $this->assertNull($router->selectProviderId('text', [
            $this->provider(array_replace($local, ['enabled' => false])),
            $this->provider(array_replace($local, ['health_status' => 'unverified'])),
            $this->provider(array_replace($local, ['in_flight' => 1])),
            $this->provider(array_replace($local, ['daily_budget_remaining' => -1])),
            $this->provider(array_replace($local, ['daily_budget_remaining' => INF])),
        ]));
        $this->assertNull($router->selectProviderId('image-generation', [
            $this->provider($local),
        ]));
    }

}
