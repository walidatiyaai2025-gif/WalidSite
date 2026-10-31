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
}
