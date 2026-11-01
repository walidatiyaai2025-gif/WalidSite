<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MOBILE-02 guest home. The two existing public catalogs remain the only
 * sources of publication, evidence and runbook-version authorization.
 */
final class MobileHomeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['prohibited'],
        ]);
        $lang = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';

        // Compose these already-approved public APIs, not raw draft tables.
        // Both source controllers revalidate live citations and image rights
        // before a resource occupies a page/card slot. If a review is revoked,
        // the next request no longer includes that item.
        $home = app(PublicHomeController::class)->index($request)->getData(true)['data'];
        $lab = app(PublicLabController::class)->index($request)->getData(true)['data'];

        return response()->json([
            'data' => [
                'profile' => $home['profile'],
                'featured_projects' => $home['featured_projects'],
                'reading_feed' => $lab['articles'],
                'offline_runbooks' => $lab['runbooks'],
                // No reviewed executable tool registry exists yet.
                'tools' => $lab['tools'],
                // Saved resources are account-scoped, never guest content.
                // Do not invent a saved count or expose a private collection.
                'saved_requires_auth' => true,
            ],
            'meta' => ['lang' => $lang],
        ])->header('Cache-Control', 'no-store');
    }
}
