<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WEB-06: source-verified AI engineering catalog. No inferred achievements,
 * generated demos, unreviewed employer claims or remote AI calls.
 */
final class PublicAiExpertiseController extends Controller
{
    /**
     * Match complete technical terms, not accidental "ai" inside words such
     * as "maintenance". This is classification of already reviewed source
     * metadata, never automatic evidence verification or publication.
     */
    private function isAiRelated(string $text): bool
    {
        return preg_match(
            '/(?:\b(?:ai|llms?|ml|rag|machine learning|artificial intelligence|deep learning|computer vision|automation|generative ai|agentic ai|ollama|prompt engineering)\b|ذكاء\s+اصطناعي|تعلم\s+آلي|أتمتة|نماذج\s+لغوية)/iu',
            $text
        ) === 1;
    }

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            // WEB-06 is a compact showcase, not an arbitrary scan endpoint.
            'page' => ['prohibited'],
        ]);
        $lang = $input['lang'] ?? 'en';
        $arabic = $lang === 'ar';
        $skills = [];
        $projects = [];

        $profile = Profile::query()->published()
            ->whereHas('user', fn ($user) => $user->where('status', 'active')
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
            ->orderBy('id')->first();

        if ($profile !== null) {
            foreach (Skill::query()->withVerifiedEvidence()
                ->whereIn('id', DB::table('profile_skill')
                    ->where('profile_id', $profile->id)->select('skill_id'))
                ->orderBy('category')->orderBy('name_en')->orderBy('id')->lazy(100) as $skill) {
                if (! $this->isAiRelated($skill->category.' '.$skill->name_en.' '.$skill->name_ar)) {
                    continue;
                }
                if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?\z/D', $skill->slug) !== 1) {
                    continue;
                }

                $skills[] = [
                    'slug' => $skill->slug,
                    'name' => $arabic ? $skill->name_ar : $skill->name_en,
                    'category' => $skill->category,
                ];
                if (count($skills) === 15) {
                    break;
                }
            }
        }

        $portfolio = app(PublicPortfolioController::class);
        foreach (Project::query()->publiclyVerified()
            // Reuse the exact 50-evidence-per-project bound of public projects.
            // lazy() eager-loads one batch instead of issuing N+1 proof queries.
            ->with(['evidence' => static fn ($query) => $query
                ->where('visibility', 'public')
                ->whereNotNull('verified_at')
                ->where('verified_at', '<=', now())
                ->orderBy('id')->limit(50)])
            ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $project) {
            if (! $this->isAiRelated(
                $project->title.' '.($project->summary_en ?? '').' '.($project->summary_ar ?? '')
            ) || preg_match('/\A[A-Za-z0-9-]+\z/D', $project->slug) !== 1) {
                continue;
            }
            // A verified_at timestamp alone is insufficient: reject revoked,
            // private DNS, signed, and unsafe links BEFORE consuming a slot.
            $public = $portfolio->publicProjectData($project, $request);
            if (empty($public['evidence_urls'])) {
                continue;
            }

            $projects[] = [
                'slug' => $public['slug'],
                'name' => $public['name'],
                'summary' => $public['summary'],
                'role' => $public['role'],
                'evidence_urls' => $public['evidence_urls'],
                'url' => route('public.project', ['slug' => $public['slug'], 'lang' => $lang]),
            ];
            if (count($projects) === 10) {
                break;
            }
        }

        return response()->json([
            'data' => [
                'skills' => $skills,
                'projects' => $projects,
                // No independently reviewed interactive demo registry exists.
                // An empty array is safer than a clickable mock "live demo".
                'demos' => [],
                'safety_notes' => [
                    $arabic
                        ? 'المشاريع والمهارات المعروضة اجتازت مراجعة أدلتها العامة؛ ولا يعني ذلك وجود عرض توضيحي قابل للتشغيل.'
                        : 'Displayed skills and projects passed public evidence review; no executable demo is implied.',
                ],
            ],
            'meta' => ['lang' => $lang],
        ])->header('Cache-Control', 'no-store');
    }
}
