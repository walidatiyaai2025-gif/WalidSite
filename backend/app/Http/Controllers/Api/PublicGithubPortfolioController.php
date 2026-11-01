<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\GitHub\PublicRepositoryDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WEB-10: JSON companion for the public GitHub portfolio.
 *
 * The directory owns the allowlist, GitHub visibility verification and
 * security-sensitive URL validation. Never reimplement its rules or expose
 * configured candidates whose public visibility has not been confirmed.
 */
final class PublicGithubPortfolioController extends Controller
{
    public function index(Request $request, PublicRepositoryDirectory $directory): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'q' => ['sometimes', 'string', 'max:80'],
            'language' => ['sometimes', 'string', 'max:60'],
        ]);

        $locale = $input['lang'] ?? 'en';
        $search = mb_strtolower(trim($input['q'] ?? ''));
        $language = trim($input['language'] ?? '');
        $repositories = [];

        foreach ($directory->repositories() as $candidate) {
            // An allowlisted private, deleted or unverified repository must
            // not leak its identifier, even via a query/search oracle.
            if (($candidate['status'] ?? null) !== 'verified'
                || ! is_string($candidate['full_name'] ?? null)
                || ! is_string($candidate['url'] ?? null)
                || ! is_string($candidate['verified_at'] ?? null)) {
                continue;
            }

            $description = $candidate['description'] ?? null;
            $repoLanguage = $candidate['language'] ?? null;
            if ($search !== '' && ! str_contains(
                mb_strtolower($candidate['full_name'].' '.(is_string($description) ? $description : '')),
                $search
            )) {
                continue;
            }
            if ($language !== '' && $repoLanguage !== $language) {
                continue;
            }

            // Do not pass raw provider JSON, metadata or diagnostic fields
            // to a public API, only the exact independently verified DTO.
            $repositories[] = [
                'full_name' => $candidate['full_name'],
                'url' => $candidate['url'],
                'description' => is_string($description) ? $description : null,
                'language' => is_string($repoLanguage) ? $repoLanguage : null,
                'status' => 'verified',
                'verified_at' => $candidate['verified_at'],
                'source' => 'github_public_rest',
            ];
        }

        return response()->json([
            'data' => $repositories,
            'meta' => ['lang' => $locale, 'total' => count($repositories)],
        ])->header('Cache-Control', 'no-store');
    }
}
