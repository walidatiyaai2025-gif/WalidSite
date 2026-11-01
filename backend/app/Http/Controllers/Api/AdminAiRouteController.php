<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADMIN-05: an admin-only inventory of configured AI routing parameters.
 *
 * These are persisted settings, NOT live capacity, available budget,
 * effective eligibility or a current routing decision.
 */
final class AdminAiRouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        // The Sanctum guard can resolve a web session: require a real
        // personal admin bearer token as well as the active owner/admin role.
        abort_unless(
            $user?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin'),
            403
        );

        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $lang = $input['lang'] ?? 'en';

        // Select only the allowed DTO fields: never even load encrypted
        // credentials or internal URLs into this listing. SoftDeletes excludes
        // retired providers from the active configuration inventory.
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
            'data' => $page->getCollection()->map(static fn (AiProvider $provider): array => [
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
            ])->values()->all(),
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
}
