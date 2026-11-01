<?php

namespace App\AI;

use InvalidArgumentException;

final class ProviderRouter
{
    private const CAPABILITIES = [
        'text',
        'vision',
        'image-generation',
        'embeddings',
    ];

    /**
     * Select a healthy model-provider ID from admin-supplied server-side state.
     * Never return a secret or attempt a connection here; budget reservations
     * and durable dispatch belong to T011/T015.
     *
     * @param array<int, array<string, mixed>> $candidates
     */
    public function selectProviderId(string $capability, array $candidates): ?string
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new InvalidArgumentException('Unknown AI capability.');
        }

        $eligible = array_filter($candidates, static function (array $candidate) use ($capability): bool {
            $id = $candidate['id'] ?? null;
            $capabilities = $candidate['capabilities'] ?? [];
            // Only installed adapter implementations may receive jobs.
            // Unsupported types must not outrank a known provider merely
            // because an imported candidate advertises more paid budget.
            $type = $candidate['provider_type'] ?? null;
            $supported = in_array($type, ['ollama-compatible', 'openai-compatible'], true);
            // Missing capacity defaults to the documented single worker.
            // An explicit zero/negative limit means NO available slot.
            $limit = (int) ($candidate['concurrent_limit'] ?? 1);
            $inFlight = max(0, (int) ($candidate['in_flight'] ?? 0));
            $budgetRemaining = (float) ($candidate['daily_budget_remaining'] ?? 0);
            // Self-hosted Ollama inference has no paid-provider reservation.
            // Paid OpenAI-compatible routing still requires a positive budget.
            $local = $type === 'ollama-compatible';
            $budgetEligible = is_finite($budgetRemaining)
                && ($local ? $budgetRemaining >= 0 : $budgetRemaining > 0);

            return $supported
                && $limit >= 1
                && is_string($id) && $id !== ''
                && ($candidate['enabled'] ?? false) === true
                && ($candidate['health_status'] ?? 'unverified') === 'healthy'
                && is_array($capabilities)
                && in_array($capability, $capabilities, true)
                && $inFlight < $limit
                && $budgetEligible;
        });

        usort($eligible, static fn (array $a, array $b): int =>
            ((int) ($a['routing_priority'] ?? 100)) <=> ((int) ($b['routing_priority'] ?? 100))
            ?: strcmp((string) $a['id'], (string) $b['id'])
        );

        return $eligible === [] ? null : $eligible[0]['id'];
    }
}
