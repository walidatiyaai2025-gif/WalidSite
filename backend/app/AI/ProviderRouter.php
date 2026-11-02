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
     * @param array<int, mixed> $candidates
     */
    public function selectProviderId(string $capability, array $candidates): ?string
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            throw new InvalidArgumentException('Unknown AI capability.');
        }

        $eligible = array_filter($candidates, static function (mixed $candidate) use ($capability): bool {
            // An imported malformed row must not crash the whole route and
            // starve another healthy provider. Reject the row before casts.
            if (! is_array($candidate)) {
                return false;
            }
            $id = $candidate['id'] ?? null;
            $capabilities = $candidate['capabilities'] ?? [];
            // Only installed adapter implementations may receive jobs.
            // Unsupported types must not outrank a known provider merely
            // because an imported candidate advertises more paid budget.
            $type = $candidate['provider_type'] ?? null;
            // The installed Ollama adapter supports local generation of
            // text (and advertised read capabilities), but image output uses
            // OpenAI-compatible /images/generations exclusively. The backend
            // executor and queue admission also enforce this restriction.
            // Do not route a paid image job to a healthy but incapable local
            // provider just because the stored capability flag was enabled.
            $supported = in_array($type, ['ollama-compatible', 'openai-compatible'], true)
                && ($capability !== 'image-generation' || $type === 'openai-compatible');
            // SQL decimal balances can be strings, but arbitrary strings,
            // negative in-flight counters and malformed priorities must not
            // be silently cast to zero and select an ineligible paid route.
            $limit = self::integerSetting(array_key_exists('concurrent_limit', $candidate)
                ? $candidate['concurrent_limit'] : 1);
            $inFlight = self::integerSetting(array_key_exists('in_flight', $candidate)
                ? $candidate['in_flight'] : 0);
            $priority = self::integerSetting(array_key_exists('routing_priority', $candidate)
                ? $candidate['routing_priority'] : 100);
            $budgetValue = array_key_exists('daily_budget_remaining', $candidate)
                ? $candidate['daily_budget_remaining'] : 0;
            if ($limit === null || $limit < 1
                || $inFlight === null || $inFlight < 0
                || $priority === null || ! self::isBudgetNumber($budgetValue)) {
                return false;
            }
            $budgetRemaining = (float) $budgetValue;
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

    /**
     * Database integer columns may arrive as canonical decimal strings.
     * No lossy casts: '0busy' must not become zero in-flight requests, and
     * 'urgent' must never gain sorting priority over an approved route.
     */
    private static function integerSetting(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value)
            || preg_match('/\\A-?(?:0|[1-9][0-9]*)\\z/D', $value) !== 1) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? null : $parsed;
    }

    private static function isBudgetNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value)
            || (is_string($value)
                && preg_match('/\\A(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?\\z/D', $value) === 1);
    }
}
