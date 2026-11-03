<?php

namespace App\Http\Controllers\Api;

use App\Domain\Deployment\TrustedReleaseCatalog;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * An explicitly read-only owner API for the existing Admin Updates journey.
 *
 * This must never execute a migration, accept a remote URL from the request,
 * expose publisher keys, or advertise a code-update Apply capability. The
 * separate web/session/CSRF layer and maintenance-gated Apply operation are
 * release blockers until real staging acceptance.
 */
final class AdminTrustedUpdateController extends Controller
{
    public function check(Request $request, TrustedReleaseCatalog $catalog): JsonResponse
    {
        $user = $request->user()?->fresh();
        $token = $request->user()?->currentAccessToken();

        abort_unless(
            $user !== null
                && $user->status === 'active'
                && $user->hasRole('owner')
                && $token instanceof PersonalAccessToken
                && $token->can('admin')
                && $token->expires_at?->isFuture() === true,
            403,
        );

        $query = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
        ]);
        // Never permit publisher URL, public-key, or version overrides from
        // an HTTP request, even if unrelated Laravel validation would ignore
        // an unknown field.
        if (array_diff(array_keys($request->query()), ['lang']) !== []) {
            return response()->json(['message' => 'Unexpected query parameter.'], 422)
                ->header('Cache-Control', 'no-store');
        }
        $lang = $query['lang'] ?? 'en';

        $installed = config('site.trusted_installed_version');
        $origin = config('site.trusted_release_origin');
        $publisher = config('site.trusted_release_public_key');
        $enabled = config('site.updates_enabled') === true;
        $configured = $enabled
            && is_string($installed) && preg_match('/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/D', $installed) === 1
            && is_string($origin) && $origin !== ''
            && is_string($publisher) && $publisher !== '';

        if (! $configured) {
            return response()->json([
                'data' => [
                    'current_version' => is_string($installed) && preg_match('/\A\d+\.\d+\.\d+\z/D', $installed)
                        ? $installed : null,
                    'available_version' => null,
                    'release_notes' => null,
                    'status' => $enabled ? 'not_configured' : 'disabled',
                    'code_update_ready' => false,
                    'apply_available' => false,
                ],
                'meta' => ['lang' => $lang, 'publisher_verified' => false],
            ])->header('Cache-Control', 'no-store');
        }

        try {
            $candidate = $catalog->check($origin, $publisher, $installed)['release'];
        } catch (Throwable $failure) {
            // Do not leak transport credentials, trust keys, internal exception
            // paths or catalog payloads to a caller, even an authorized owner.
            return response()->json([
                'data' => [
                    'current_version' => $installed,
                    'available_version' => null,
                    'release_notes' => null,
                    'status' => 'publisher_unverified',
                    'code_update_ready' => false,
                    'apply_available' => false,
                ],
                'meta' => ['lang' => $lang, 'publisher_verified' => false],
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => [
                'current_version' => $installed,
                'available_version' => $candidate['version'],
                'release_notes' => null,
                'status' => 'signed_release_available',
                'code_update_ready' => false,
                'apply_available' => false,
                'source_sha' => $candidate['source_sha'],
                'artifact_sha256' => $candidate['artifact_sha256'],
                'artifact_size' => $candidate['artifact_size'],
            ],
            'meta' => [
                'lang' => $lang,
                'publisher_verified' => true,
                'full_code_apply_requires_maintenance_and_recovery' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
