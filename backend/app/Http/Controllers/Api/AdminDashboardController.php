<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ADMIN-01: a read-only snapshot of stored admin dashboard records.
 *
 * Never confuse recorded AI jobs with live worker health, or aggregate costs
 * across currencies. Credentials, job payloads and internal endpoints stay
 * private even for callers who are authorized to view these counters.
 */
final class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Sanctum can also accept first-party sessions: this API deliberately
        // requires a genuine, scoped admin personal access token instead.
        abort_unless(
            $user?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin'),
            403
        );

        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
        ]);
        $lang = $input['lang'] ?? 'en';

        $providers = [
            'configured' => AiProvider::query()->count(),
            'enabled' => AiProvider::query()->where('enabled', true)->count(),
            'healthy' => AiProvider::query()->where('health_status', 'healthy')->count(),
            'unverified' => AiProvider::query()->where('health_status', 'unverified')->count(),
        ];

        $jobs = DB::table('ai_jobs')
            ->select('status')->selectRaw('COUNT(*) AS job_count')
            ->groupBy('status')->orderBy('status')->get()
            ->map(static fn ($row): array => [
                'status' => $row->status,
                'count' => (int) $row->job_count,
            ])->all();

        $start = now('UTC')->startOfDay();
        $end = $start->copy()->addDay();
        $usage = DB::table('ai_usage')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->select('cost_currency')
            ->selectRaw('COUNT(*) AS request_count, SUM(cost_minor) AS spend_minor')
            ->groupBy('cost_currency')->orderBy('cost_currency')->get()
            ->map(static fn ($row): array => [
                'currency' => $row->cost_currency,
                'receipt_count' => (int) $row->request_count,
                'cost_minor' => (int) $row->spend_minor,
            ])->all();

        return response()->json([
            'data' => [
                'providers' => $providers,
                'jobs' => $jobs,
                'usage_today_utc' => $usage,
            ],
            'meta' => [
                'lang' => $lang,
                'usage_window_start_utc' => $start->toIso8601String(),
                'usage_window_end_utc' => $end->toIso8601String(),
                'jobs_are_persisted_not_live' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
