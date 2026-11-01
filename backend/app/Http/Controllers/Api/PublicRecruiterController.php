<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicRecruiterController extends Controller
{
    /**
     * WEB-12 recruiter data is a *view* of the reviewed public CV, never a
     * separate publication path or a generated hiring-fit prediction.
     */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:en,ar'],
            'track' => ['sometimes', 'in:all,database,software,delivery'],
        ]);
        $lang = $input['lang'] ?? 'en';
        $track = $input['track'] ?? 'all';

        // PublicResumeController owns the published-owner, proof-asset,
        // linked-skill and externally safe-claim checks. Reuse its current
        // response so revocations take effect on the next recruiter request.
        $approved = app(PublicResumeController::class)
            ->index($request)->getData(true)['data'] ?? null;

        if ($approved === null) {
            return response()->json(['data' => null])->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => [
            'name' => $approved['name'],
            'track' => $track,
            'skills' => $approved['skills'],
            // Verified experience and individually reviewed statements are
            // evidence, not a numerical match score or employment promise.
            'positions' => $approved['positions'],
            'claims' => $approved['claims'],
            'recruiter_url' => route('public.hire', ['lang' => $lang, 'track' => $track]),
            'resume_url' => $approved['resume_url'],
            'contact_url' => $approved['contact_url'],
        ]])->header('Cache-Control', 'no-store');
    }
}
