<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADMIN-05: read and safely edit persisted AI routing configuration.
 *
 * Configured capacity, limits and last observed health are NOT live routing
 * decisions, live available budget or provider reachability assertions.
 */
final class AdminAiRouteController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        // Session-only and mobile-only Sanctum access must not administer AI.
        abort_unless(
            $user?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin'),
            403
        );
    }

    private static function routeData(AiProvider $provider): array
    {
        return [
            'provider_id' => $provider->id,
            'display_name' => $provider->display_name,
            'provider_type' => $provider->provider_type,
            'enabled' => $provider->enabled,
            'last_recorded_health_status' => $provider->health_status,
            'routing_priority' => $provider->routing_priority,
            'configured_concurrent_limit' => $provider->concurrent_limit,
            'configured_timeout_seconds' => $provider->timeout_seconds,
            'configured_daily_budget_minor' => $provider->daily_budget_minor,
            'currency' => $provider->currency,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $lang = $input['lang'] ?? 'en';

        // Do not load ciphertext or internal URLs into a list projection.
        // SoftDeletes excludes archived providers.
        $page = AiProvider::query()
            ->select([
                'id', 'display_name', 'provider_type', 'enabled',
                'health_status', 'routing_priority', 'concurrent_limit',
                'timeout_seconds', 'daily_budget_minor', 'currency',
            ])
            ->orderBy('routing_priority')
            ->orderBy('id')
            ->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(self::routeData(...))->values()->all(),
            'meta' => [
                'lang' => $lang,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'configuration_only' => true,
                'no_live_eligibility_or_remaining_budget_claim' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Reuse the canonical provider update for transaction, audit event and
     * invalidation of stale health. Reject credentials and endpoint mutations
     * from this narrower routing policy route.
     */
    public function update(Request $request, AiProvider $provider): JsonResponse
    {
        $this->authorizeAdmin($request);
        $rules = [
            'enabled' => ['sometimes', 'boolean'],
            'routing_priority' => ['sometimes', 'integer', 'between:1,999'],
            'concurrent_limit' => ['sometimes', 'integer', 'between:1,20'],
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,30'],
            'daily_budget_minor' => ['sometimes', 'integer', 'between:0,1000000000'],
            'currency' => ['sometimes', 'in:USD,KWD,EUR'],
        ];

        // Only routing fields belong here. No silent acceptance of secret,
        // URL, display name or adapter type mutations.
        if (array_diff(array_keys($request->all()), array_keys($rules)) !== []) {
            throw ValidationException::withMessages([
                'routing' => 'Only routing configuration fields may be updated.',
            ]);
        }
        $fields = $request->validate($rules);
        if ($fields === []) {
            throw ValidationException::withMessages([
                'routing' => 'At least one routing setting is required.',
            ]);
        }

        // No health check or network call: the standard provider endpoint
        // transaction clears recorded health and writes a redacted audit.
        app(AiProviderController::class)->update($request, $provider);
        $updated = $provider->fresh();

        return response()->json([
            'data' => self::routeData($updated),
            'meta' => [
                'configuration_only' => true,
                'no_live_eligibility_or_remaining_budget_claim' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
