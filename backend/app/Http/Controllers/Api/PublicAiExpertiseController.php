<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WEB-06: public AI engineering expertise is an evidenced selection of
 * approved owner skills, not a generator of fictional projects or live demos.
 */
final class PublicAiExpertiseController extends Controller
{
    // Deliberate reviewed taxonomy. Substring "ai" searches would incorrectly
    // classify generic "Training" or other unrelated skill categories.
    private const AI_CATEGORIES = [
        'AI', 'AI Engineering', 'AI & Automation', 'AI/ML',
        'Artificial Intelligence', 'Machine Learning',
        'AI & Machine Learning', 'Machine Learning & AI',
        'Generative AI', 'Applied AI', 'AI & Data Science',
    ];

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
        ]);
        $lang = $input['lang'] ?? 'en';
        $ar = $lang === 'ar';

        // This call already enforces published owner, active account and
        // individually evidenced professional claims. Reuse owner identity
        // without publishing the claims or private CV in this API.
        $owner = app(PublicPortfolioController::class)
            ->profile($request)->getData(true)['data'] ?? null;

        $skills = [];
        if ($owner !== null) {
            foreach (Skill::query()->withVerifiedEvidence()
                ->whereIn('category', self::AI_CATEGORIES)
                ->whereIn('id', DB::table('profile_skill')
                    ->where('profile_id', $owner['id'])->select('skill_id'))
                ->orderBy('category')->orderBy('name_en')->orderBy('id')
                ->lazy(100) as $skill) {
                // A verified flag cannot turn malformed or blank published
                // labels into a valid website link or public capability.
                if (preg_match('/\A[A-Za-z0-9-]+\z/D', $skill->slug ?? '') !== 1
                    || trim((string) $skill->name_en) === ''
                    || trim((string) $skill->name_ar) === '') {
                    continue;
                }
                $skills[] = [
                    'slug' => $skill->slug,
                    'name' => $ar ? $skill->name_ar : $skill->name_en,
                    'category' => $skill->category,
                ];
                if (count($skills) === 12) {
                    break;
                }
            }
        }

        // No independently approved AI-demo/workflow association is defined
        // by the v6 DB contract. An honest empty list is safer than deriving
        // marketing claims from project titles or private article drafts.
        return response()->json([
            'data' => [
                'skills' => $skills,
                'workflows' => [],
                'verified_demos' => [],
                'safety_notes' => $ar
                    ? [
                        'تتطلب مخرجات الذكاء الاصطناعي مراجعة بشرية قبل استخدامها.',
                        'لا تُرسل أسرارًا أو بيانات شخصية إلى مزود دون تصريح.',
                    ]
                    : [
                        'AI-generated output requires human review before use.',
                        'Do not send secrets or personal data to a provider without authorization.',
                    ],
            ],
            'meta' => ['lang' => $lang],
        ])->header('Cache-Control', 'no-store');
    }
}
