<?php

namespace App\Http\Controllers\Api;

use App\AI\EditorialJobDispatcher;
use App\AI\EditorialScheduleRunner;
use App\Http\Controllers\Controller;
use App\Integrations\GitHub\PublicRepositoryDirectory;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Canonical ADMIN-06..13 API control plane.
 *
 * Every response is a bounded projection of persisted state. It never returns
 * provider credentials, private prompts, draft bodies, citation URLs, storage
 * paths, raw job payloads or invented delivery/analytics claims.
 */
final class AdminProductController extends Controller
{
    private const REVIEW_REASONS = [
        'source_reviewed',
        'technical_reviewed',
        'legal_reviewed',
    ];

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        abort_unless(
            $user?->canAccessAdmin()
                && $token instanceof PersonalAccessToken
                && $token->can('admin'),
            403
        );
    }

    /**
     * The initial route check can become stale during a long request.
     * Recheck current role and real Sanctum bearer inside the locked
     * publication transaction before any durable reviewer attribution.
     */
    private function independentReviewer(Request $request, ArticleRevision $revision): User
    {
        $initial = $request->user();
        $token = $initial?->currentAccessToken();
        $actor = $initial === null ? null : User::query()
            ->whereKey($initial->id)->lockForUpdate()->first();

        abort_unless($actor?->canAccessAdmin()
            && $token instanceof PersonalAccessToken
            && $token->can('admin')
            && $actor->tokens()->whereKey($token->getKey())
                ->where(function ($q): void {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })->exists(), 403);

        abort_unless($revision->canBeIndependentlyReviewedBy($actor), 403,
            'Independent editorial reviewer required.');

        return $actor;
    }

    private function onlyFields(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages([
                'request' => 'Unsupported request fields.',
            ]);
        }
    }

    public function publishArticle(
        Request $request,
        string $id,
        EditorialScheduleRunner $runner
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $article = Article::query()->findOrFail($id);
        if ($article->status === 'published') {
            return response()->json(['message' => 'Article is already published.'], 409);
        }

        try {
            DB::transaction(function () use ($article, $request, $runner): void {
                $locked = Article::query()->whereKey($article->id)
                    ->lockForUpdate()->firstOrFail();

                // An initial status read is not sufficient: a concurrent
                // publisher may complete while this request waits for the
                // article row lock. Never attribute a second publish event
                // or re-pin a published revision on that stale observation.
                if ($locked->status === 'published'
                    || $locked->published_revision_no !== null
                    || $locked->published_at !== null) {
                    abort(409, 'Article publication state changed; reload the article.');
                }

                if (! $locked->isReadyToPublish()) {
                    throw ValidationException::withMessages([
                        'article' => 'Citation, technical or media-rights review is incomplete.',
                    ]);
                }

                $revision = $locked->revisions()->orderByDesc('revision_no')->firstOrFail();
                $reviewer = $this->independentReviewer($request, $revision);
                $checks = is_array($revision->validation_json)
                    ? $revision->validation_json : [];

                // Generic publish cannot become a shortcut around the explicit
                // owner exception path used for high-risk/professional claims.
                if (($checks['risk_level'] ?? null) !== 'low'
                    || ($checks['contains_professional_claims'] ?? null) !== false) {
                    throw ValidationException::withMessages([
                        'article' => 'Explicit owner approval is required for this reviewed revision.',
                    ]);
                }

                $locked->publishVerified();
                $revision->forceFill([
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now('UTC'),
                ])->save();
                $runner->event(
                    'api-publish:'.Str::uuid(),
                    $locked->id,
                    $revision->revision_no,
                    $request->user()->id,
                    'manual_published',
                    'content_reviewed'
                );
            }, 3);
        } catch (DomainException) {
            throw ValidationException::withMessages([
                'article' => 'Publication gates changed; review the latest revision again.',
            ]);
        }

        $fresh = $article->fresh();

        return response()->json([
            'data' => [
                'id' => $fresh->id,
                'status' => $fresh->status,
                'published_revision_no' => $fresh->published_revision_no,
                'published_at' => $fresh->published_at?->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function images(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'jobs_page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);

        $assets = Asset::query()
            ->select([
                'id', 'asset_key', 'mime_type', 'origin',
                'rights_status', 'publishable', 'created_at',
            ])
            ->orderByDesc('created_at')->orderBy('id')
            ->paginate(20, ['*'], 'page', (int) ($input['page'] ?? 1));

        $jobs = DB::table('ai_jobs as job')
            ->join('ai_providers as provider', 'provider.id', '=', 'job.provider_id')
            ->where('job.job_type', 'editorial-art')
            ->select([
                'job.id', 'job.status', 'job.attempt_count', 'job.max_attempts',
                'job.reserved_budget_minor', 'job.last_error_code', 'job.updated_at',
                'provider.display_name as provider_name', 'provider.currency',
            ])
            ->orderByDesc('job.created_at')->orderBy('job.id')
            ->paginate(20, ['*'], 'jobs_page', (int) ($input['jobs_page'] ?? 1));

        $models = $this->eligibleImageModels();

        return response()->json([
            'data' => [
                'assets' => $assets->items(),
                'jobs' => $jobs->items(),
                'eligible_models' => $models->map(static fn (AiModel $model): array => [
                    'id' => $model->id,
                    'provider_id' => $model->provider_id,
                    'provider_name' => $model->provider?->display_name,
                    'provider_model_id' => $model->provider_model_id,
                    'currency' => $model->provider?->currency,
                    'configured_reservation_minor' =>
                        (int) ($model->cost_policy_json['image_reservation_minor'] ?? 0),
                ])->values()->all(),
            ],
            'meta' => [
                'assets' => [
                    'current_page' => $assets->currentPage(),
                    'last_page' => $assets->lastPage(),
                    'total' => $assets->total(),
                ],
                'jobs' => [
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'total' => $jobs->total(),
                ],
                'generated_assets_require_separate_rights_review' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function createImageJob(
        Request $request,
        EditorialJobDispatcher $dispatcher
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $this->onlyFields($request, [
            'title', 'topic', 'language', 'publication_mode',
        ]);
        $input = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:250'],
            'topic' => ['required', 'string', 'min:3', 'max:4000'],
            'language' => ['required', Rule::in(['ar', 'en'])],
            'publication_mode' => ['required', Rule::in(['draft', 'auto_eligible'])],
        ]);

        $model = $this->eligibleImageModels()->first();
        if ($model === null || $model->provider === null) {
            throw ValidationException::withMessages([
                'image' => 'No healthy image model with a configured positive reservation is available.',
            ]);
        }

        $provider = $model->provider;
        $reserve = (int) ($model->cost_policy_json['image_reservation_minor'] ?? 0);
        if ($reserve < 1 || $reserve > (int) $provider->daily_budget_minor) {
            throw ValidationException::withMessages([
                'image' => 'The configured image reservation is outside the provider budget.',
            ]);
        }

        $result = DB::transaction(function () use (
            $input, $request, $dispatcher, $model, $provider, $reserve
        ): array {
            $slug = 'api-image-draft-'.Str::lower((string) Str::ulid());
            $article = Article::create([
                'slug' => $slug,
                // Never invent a translation. The literal admin-supplied draft
                // label is copied to the non-canonical slot until an editor
                // supplies and reviews the real bilingual title.
                'title_en' => $input['title'],
                'title_ar' => $input['title'],
                'canonical_lang' => $input['language'],
            ]);

            $revision = $article->revisions()->create([
                'revision_no' => 1,
                'body_md' => $input['topic'],
                'citations_json' => [],
                'validation_json' => [
                    'citations_passed' => false,
                    'technical_passed' => false,
                    'risk_level' => 'unknown',
                    'contains_professional_claims' => null,
                    'requested_publication_mode' => $input['publication_mode'],
                    'requested_language' => $input['language'],
                    'requested_by' => $request->user()->id,
                ],
                'status' => 'draft',
            ]);

            $jobId = $dispatcher->enqueue(
                $revision,
                $provider,
                $model->provider_model_id,
                true,
                $reserve
            );

            return [$article, $jobId];
        }, 3);

        [$article, $jobId] = $result;
        $job = DB::table('ai_jobs')->where('id', $jobId)->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $job->id,
                'status' => $job->status,
                'progress_percent' => 0,
                'result_url' => null,
                'retry_after_seconds' => 5,
                'draft_article_id' => $article->id,
            ],
            'meta' => [
                'draft_only' => true,
                'publication_requires_independent_review' => true,
                'image_requires_separate_rights_review' => true,
            ],
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function quality(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'jobs_page' => ['sometimes', 'integer', 'between:1,10000'],
            'job_status' => ['sometimes', Rule::in(['retry', 'failed'])],
        ]);

        $drafts = ArticleRevision::query()
            ->with(['article.heroAsset'])
            ->whereHas('article', fn ($article) => $article->where('status', 'draft'))
            ->whereRaw(
                'article_revisions.revision_no = (SELECT MAX(latest.revision_no) FROM article_revisions AS latest WHERE latest.article_id = article_revisions.article_id)'
            )
            ->orderByDesc('updated_at')->orderBy('id')
            ->paginate(25, ['*'], 'page', (int) ($input['page'] ?? 1));

        $draftData = $drafts->getCollection()->map(static function (ArticleRevision $revision): array {
            $article = $revision->article;
            $validation = is_array($revision->validation_json)
                ? $revision->validation_json : [];

            return [
                'article_id' => $article->id,
                'revision_id' => $revision->id,
                'revision_no' => $revision->revision_no,
                'title_en' => $article->title_en,
                'title_ar' => $article->title_ar,
                'revision_status' => $revision->status,
                'citations_passed' => ($validation['citations_passed'] ?? false) === true,
                'technical_passed' => ($validation['technical_passed'] ?? false) === true,
                'evidence_passed' => $revision->hasVerifiedEvidence(),
                'image_rights_passed' => $article->hero_asset_id === null
                    || $article->heroAsset?->isPubliclyUsable() === true,
                'publication_ready' => $article->isReadyToPublish(),
            ];
        })->values()->all();

        $jobQuery = DB::table('ai_jobs as job')
            ->join('ai_providers as provider', 'provider.id', '=', 'job.provider_id')
            ->whereIn('job.status', ['retry', 'failed'])
            ->when(isset($input['job_status']), static fn ($query) =>
                $query->where('job.status', $input['job_status']))
            ->select([
                'job.id', 'job.job_type', 'job.status',
                'job.attempt_count', 'job.max_attempts',
                'job.next_attempt_at', 'job.last_error_code', 'job.updated_at',
                'provider.display_name as provider_name',
            ])
            ->orderByDesc('job.updated_at')->orderBy('job.id');

        $jobs = $jobQuery->paginate(
            25,
            ['*'],
            'jobs_page',
            (int) ($input['jobs_page'] ?? 1)
        );

        return response()->json([
            'data' => [
                'drafts' => $draftData,
                'failed_jobs' => $jobs->items(),
            ],
            'meta' => [
                'drafts' => [
                    'current_page' => $drafts->currentPage(),
                    'last_page' => $drafts->lastPage(),
                    'total' => $drafts->total(),
                ],
                'failed_jobs' => [
                    'current_page' => $jobs->currentPage(),
                    'last_page' => $jobs->lastPage(),
                    'total' => $jobs->total(),
                ],
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function github(
        Request $request,
        PublicRepositoryDirectory $directory
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $repositories = $directory->repositories();
        $verified = array_values(array_filter(
            $repositories,
            static fn (array $repo): bool => ($repo['status'] ?? null) === 'verified'
                && is_string($repo['full_name'] ?? null)
        ));

        return response()->json([
            'data' => $verified,
            'meta' => [
                'configured_count' => $this->configuredGithubCount(),
                'verified_count' => count($verified),
                'unavailable_count' => max(0, count($repositories) - count($verified)),
                'public_allowlist_only' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function syncGithub(
        Request $request,
        PublicRepositoryDirectory $directory
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $this->onlyFields($request, []);

        $configuredSlugs = $directory->configuredSlugs();
        $configuredCount = count($configuredSlugs);
        if ($configuredCount === 0) {
            // A no-op without configured sources is not an external sync.
            // Never fabricate provider availability or a verification time.
            return response()->json([
                'message' => 'No valid public GitHub repositories are configured.',
                'data' => [],
                'meta' => [
                    'configured_count' => 0,
                    'verified_count' => 0,
                    'external_available' => false,
                    'configuration_status' => 'not_configured',
                ],
            ])->header('Cache-Control', 'no-store');
        }
        foreach ($configuredSlugs as $slug) {
            Cache::forget('public-github:'.hash('sha256', strtolower($slug)));
        }

        $repositories = $directory->repositories();
        $verified = array_values(array_filter(
            $repositories,
            static fn (array $repo): bool => ($repo['status'] ?? null) === 'verified'
                && is_string($repo['full_name'] ?? null)
        ));


        if ($configuredCount > 0 && count($verified) === 0) {
            return response()->json([
                'message' => 'GitHub public metadata is currently unavailable.',
                'data' => [],
                'meta' => [
                    'configured_count' => $configuredCount,
                    'verified_count' => 0,
                    'external_available' => false,
                ],
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => $verified,
            'meta' => [
                'configured_count' => $configuredCount,
                'verified_count' => count($verified),
                'external_available' => true,
                'synced_at' => now('UTC')->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function projects(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'status' => ['sometimes', Rule::in(['completed', 'in_progress', 'research', 'archived'])],
            'visibility' => ['sometimes', Rule::in(['public', 'private'])],
        ]);

        $query = Project::query()
            ->withCount([
                'evidence',
                'evidence as verified_public_evidence_count' => static fn ($q) =>
                    $q->where('visibility', 'public')->whereNotNull('verified_at')
                        ->where('verified_at', '<=', now()),
            ])
            ->when(isset($input['status']), static fn ($q) => $q->where('status', $input['status']))
            ->when(isset($input['visibility']), static fn ($q) => $q->where('visibility', $input['visibility']))
            ->orderByDesc('updated_at')->orderBy('id');

        $page = $query->paginate(20, ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(static fn (Project $project): array => [
                'id' => $project->id,
                'slug' => $project->slug,
                'title' => $project->title,
                'status' => $project->status,
                'visibility' => $project->visibility,
                'published_at' => $project->published_at?->toIso8601String(),
                'evidence_count' => (int) $project->evidence_count,
                'verified_public_evidence_count' => (int) $project->verified_public_evidence_count,
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function audience(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $roleCounts = DB::table('roles')
            ->leftJoin('role_user', 'role_user.role_id', '=', 'roles.id')
            ->select('roles.name')->selectRaw('COUNT(role_user.user_id) AS users')
            ->groupBy('roles.id', 'roles.name')->orderBy('roles.name')->get()
            ->map(static fn ($row): array => [
                'role' => $row->name,
                'users' => (int) $row->users,
            ])->all();

        $consentTable = Schema::hasTable('notification_preferences');
        $consented = 0;
        if ($consentTable) {
            $consented = (int) DB::table('notification_preferences as preference')
                ->join('users', 'users.id', '=', 'preference.user_id')
                ->where('users.status', 'active')
                ->where('preference.enabled', true)
                ->whereNotNull('preference.consented_at')
                // A future timestamp cannot authorize a recipient.
                ->where('preference.consented_at', '<=', now('UTC'))
                ->whereNull('preference.revoked_at')
                ->distinct()
                ->count('preference.user_id');
        }

        return response()->json([
            'data' => [
                'accounts' => [
                    'active' => DB::table('users')->where('status', 'active')->count(),
                    'inactive' => DB::table('users')->where('status', '!=', 'active')->count(),
                    'roles' => $roleCounts,
                ],
                'notifications' => [
                    'consent_storage_available' => $consentTable,
                    'consented_recipients' => $consented,
                    'delivery_enabled' => false,
                ],
            ],
            'meta' => [
                'no_email_addresses_exposed' => true,
                'no_push_is_sent_by_this_read_endpoint' => true,
                'delivery_requires_explicit_consent_and_configured_provider' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function analytics(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        // ADMIN-11 must report content that an anonymous visitor can actually
        // open, not merely SQL rows marked published. Reuse the definitive
        // public serializer and pinned editorial/rights gate used by the
        // browser dashboard; each query is processed in bounded DB chunks.
        $publicProjects = 0;
        $portfolio = app(PublicPortfolioController::class);
        foreach (Project::query()->publiclyVerified()
            ->with(['evidence' => static fn ($evidence) => $evidence
                ->where('visibility', 'public')
                ->whereNotNull('verified_at')->where('verified_at', '<=', now('UTC'))
                ->orderBy('id')->limit(50)])
            ->orderBy('id')->lazy(100) as $project) {
            if ($portfolio->publicProjectData($project, $request)['evidence_urls'] !== []) {
                $publicProjects++;
            }
        }

        $publicArticles = 0;
        foreach (Article::query()->publiclyVisible()
            ->orderBy('id')->lazy(100) as $article) {
            if (is_string($article->slug)
                && preg_match('/\\A[A-Za-z0-9-]+\\z/D', $article->slug) === 1
                && $article->publicRevision() !== null) {
                $publicArticles++;
            }
        }

        $jobCounts = DB::table('ai_jobs')
            ->select('status')->selectRaw('COUNT(*) AS total')
            ->groupBy('status')->orderBy('status')->get()
            ->map(static fn ($row): array => [
                'status' => $row->status,
                'count' => (int) $row->total,
            ])->all();

        $usage = DB::table('ai_usage')
            ->select('cost_currency')
            ->selectRaw('COUNT(*) AS receipts, SUM(cost_minor) AS cost_minor')
            ->groupBy('cost_currency')->orderBy('cost_currency')->get()
            ->map(static fn ($row): array => [
                'currency' => $row->cost_currency,
                'receipts' => (int) $row->receipts,
                'cost_minor' => (int) $row->cost_minor,
            ])->all();

        return response()->json([
            'data' => [
                'content' => [
                    'public_articles' => $publicArticles,
                    'public_projects' => $publicProjects,
                    'draft_articles' => Article::query()->where('status', 'draft')->count(),
                ],
                'ai_jobs' => $jobCounts,
                'ai_usage_by_currency' => $usage,
            ],
            'meta' => [
                'database_counts_only' => true,
                'not_live_traffic_analytics' => true,
                'currencies_are_never_summed_together' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function approvals(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);

        $page = ArticleRevision::query()
            ->with('article.heroAsset')
            ->whereHas('article', fn ($article) => $article->where('status', 'draft'))
            ->whereRaw(
                'article_revisions.revision_no = (SELECT MAX(latest.revision_no) FROM article_revisions AS latest WHERE latest.article_id = article_revisions.article_id)'
            )
            ->orderByDesc('updated_at')->orderBy('id')
            ->paginate(25);

        $data = $page->getCollection()->map(function (ArticleRevision $revision): array {
            $article = $revision->article;
            $checks = is_array($revision->validation_json)
                ? $revision->validation_json : [];
            $lastDecision = DB::table('editorial_events')
                ->where('article_id', $article->id)
                ->where('revision_no', $revision->revision_no)
                ->whereIn('action', ['manual_published', 'manual_rejected'])
                ->orderByDesc('created_at')->orderByDesc('id')->first();

            return [
                'id' => $article->id,
                'article_id' => $article->id,
                'revision_no' => $revision->revision_no,
                'title_en' => $article->title_en,
                'title_ar' => $article->title_ar,
                'ready' => $article->isReadyToPublish(),
                'risk_level' => $checks['risk_level'] ?? 'unknown',
                'contains_professional_claims' =>
                    ($checks['contains_professional_claims'] ?? null),
                'state' => $lastDecision?->action === 'manual_rejected'
                    ? 'rejected' : 'pending',
            ];
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'decision_id_is_article_id_for_current_revision' => true,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function approvalDecision(
        Request $request,
        string $id,
        EditorialScheduleRunner $runner
    ): JsonResponse {
        $this->authorizeAdmin($request);
        $this->onlyFields($request, ['decision', 'reason']);
        $input = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required', Rule::in(self::REVIEW_REASONS)],
        ]);

        $result = DB::transaction(function () use (
            $request, $id, $input, $runner
        ): array {
            $article = Article::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($article->status === 'published', 409, 'Article is already published.');

            $revision = $article->revisions()->orderByDesc('revision_no')->firstOrFail();

            if ($input['decision'] === 'reject') {
                $runner->event(
                    'api-reject:'.Str::uuid(),
                    $article->id,
                    $revision->revision_no,
                    $request->user()->id,
                    'manual_rejected',
                    $input['reason']
                );

                return [$article, $revision, 'rejected'];
            }

            if (! $article->isReadyToPublish()) {
                throw ValidationException::withMessages([
                    'approval' => 'Independent citation, technical and media review is incomplete.',
                ]);
            }

            $reviewer = $this->independentReviewer($request, $revision);
            $checks = is_array($revision->validation_json)
                ? $revision->validation_json : [];
            if (($checks['risk_level'] ?? null) !== 'low'
                || ($checks['contains_professional_claims'] ?? null) !== false) {
                abort_unless($request->user()->hasRole('owner'), 403);
            }

            try {
                $article->publishVerified();
            } catch (DomainException) {
                throw ValidationException::withMessages([
                    'approval' => 'Publication gates changed during approval.',
                ]);
            }

            $revision->forceFill([
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now('UTC'),
            ])->save();

            $runner->event(
                'api-approve:'.Str::uuid(),
                $article->id,
                $revision->revision_no,
                $request->user()->id,
                'manual_published',
                $input['reason']
            );

            return [$article, $revision, 'published'];
        }, 3);

        [$article, $revision, $state] = $result;

        return response()->json([
            'data' => [
                'id' => $article->id,
                'revision_no' => $revision->revision_no,
                'decision' => $input['decision'],
                'state' => $state,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function audit(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $allowedQuery = ['page', 'editorial_page', 'domain', 'action', 'lang'];
        if (array_diff(array_keys($request->query()), $allowedQuery) !== []) {
            throw ValidationException::withMessages([
                'query' => 'Unsupported audit query parameters.',
            ]);
        }

        $providerActions = ['created', 'updated', 'tested', 'deleted'];
        $editorialActions = [
            'manual_published', 'manual_unpublished', 'manual_rejected',
            'scheduled', 'requires_review', 'refresh_required',
            'refresh_superseded', 'media_rights_approved', 'media_rights_revoked',
        ];
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'editorial_page' => ['sometimes', 'integer', 'between:1,10000'],
            'lang' => ['sometimes', Rule::in(['ar', 'en'])],
            'domain' => ['sometimes', Rule::in(['all', 'provider', 'editorial'])],
            'action' => ['sometimes', Rule::in(array_merge(
                $providerActions, $editorialActions
            ))],
        ]);
        $domain = $input['domain'] ?? 'all';
        $action = $input['action'] ?? null;

        // An action filter always applies to BOTH families. A mismatched
        // domain/action pair is a caller error, not permission to return
        // unfiltered unrelated audit records.
        if ($action !== null
            && (($domain === 'provider' && ! in_array($action, $providerActions, true))
                || ($domain === 'editorial' && ! in_array($action, $editorialActions, true)))) {
            throw ValidationException::withMessages([
                'action' => 'Audit action does not belong to the selected domain.',
            ]);
        }

        $providerQuery = DB::table('ai_provider_audit_events as event')
            ->join('ai_providers as provider', 'provider.id', '=', 'event.provider_id')
            ->select([
                'event.id', 'event.provider_id', 'event.action',
                'event.changed_fields_json', 'event.created_at',
                'provider.display_name',
            ])
            ->whereIn('event.action', $providerActions)
            ->when($action !== null, static fn ($q) => $q->where('event.action', $action))
            ->when($domain === 'editorial', static fn ($q) => $q->whereRaw('1 = 0'))
            ->orderByDesc('event.created_at')->orderByDesc('event.id');

        $providers = $providerQuery->paginate(20, ['*'], 'page',
            (int) ($input['page'] ?? 1));

        $allowedFields = [
            'display_name', 'provider_type', 'base_url', 'enabled',
            'concurrent_limit', 'timeout_seconds', 'daily_budget_minor',
            'currency', 'routing_priority', 'secret_rotated', 'secret_removed',
        ];
        $providerEvents = collect($providers->items())->map(
            static function ($event) use ($allowedFields): array {
                $raw = json_decode($event->changed_fields_json, true);
                $fields = array_values(array_unique(array_filter(
                    is_array($raw) ? $raw : [],
                    static fn ($field): bool => is_string($field)
                        && in_array($field, $allowedFields, true)
                )));

                return [
                    'id' => $event->id,
                    'provider_id' => $event->provider_id,
                    'provider_name' => $event->display_name,
                    'action' => $event->action,
                    'changed_fields' => $fields,
                    'created_at' => (string) $event->created_at,
                ];
            }
        )->values()->all();

        $editorialPage = DB::table('editorial_events')
            ->select(['id', 'article_id', 'revision_no', 'action', 'reason', 'created_at'])
            ->whereIn('action', $editorialActions)
            ->when($action !== null, static fn ($q) => $q->where('action', $action))
            ->when($domain === 'provider', static fn ($q) => $q->whereRaw('1 = 0'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50, ['*'], 'editorial_page',
                (int) ($input['editorial_page'] ?? 1));

        $editorial = $editorialPage->getCollection()->map(
            static function ($event): array {
                // The event store may contain imported/legacy free text.
                // Show only non-sensitive reason codes or opaque asset UUIDs;
                // never reflect passwords, API keys, emails or raw prompts.
                $reason = $event->reason;
                $safeCode = is_string($reason)
                    && preg_match('/\\A[a-z][a-z0-9_]{0,79}\\z/D', $reason) === 1
                    && preg_match('/(?:password|secret|token|api_key|credential)/i', $reason) !== 1;
                $safeAssetId = is_string($reason)
                    && preg_match('/\\Aasset:[0-9a-f-]{36}\\z/iD', $reason) === 1;

                return [
                    'id' => $event->id,
                    'article_id' => $event->article_id,
                    'revision_no' => $event->revision_no,
                    'action' => $event->action,
                    'reason' => $safeCode || $safeAssetId ? $reason : null,
                    'created_at' => (string) $event->created_at,
                ];
            }
        )->values()->all();

        return response()->json([
            'data' => [
                'provider_events' => $providerEvents,
                'editorial_events' => $editorial,
            ],
            'meta' => [
                'domain' => $domain,
                'current_page' => $providers->currentPage(),
                'last_page' => $providers->lastPage(),
                'provider_total' => $providers->total(),
                'editorial_limit' => 50,
                'editorial_current_page' => $editorialPage->currentPage(),
                'editorial_last_page' => $editorialPage->lastPage(),
                'editorial_total' => $editorialPage->total(),
                'secrets_and_raw_payloads_excluded' => true,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function eligibleImageModels()
    {
        return AiModel::query()
            ->with('provider')
            ->where('enabled', true)
            ->orderBy('provider_id')->orderBy('provider_model_id')->limit(100)->get()
            ->filter(static function (AiModel $model): bool {
                $provider = $model->provider;
                $capabilities = $model->capabilities_json;
                $policy = $model->cost_policy_json;
                $secret = $provider?->getRawOriginal('secret_ciphertext');
                $reserve = is_array($policy)
                    ? (int) ($policy['image_reservation_minor'] ?? 0) : 0;

                return $provider !== null
                    && ! $provider->trashed()
                    && $provider->enabled
                    && $provider->health_status === 'healthy'
                    && $provider->provider_type === 'openai-compatible'
                    && (int) $provider->daily_budget_minor > 0
                    && is_string($secret) && $secret !== ''
                    && is_array($capabilities)
                    && in_array('image-generation', $capabilities, true)
                    && is_array($policy)
                    && ($policy['currency'] ?? null) === $provider->currency
                    && $reserve > 0
                    && $reserve <= (int) $provider->daily_budget_minor
                    && preg_match(
                        '/^[A-Za-z0-9_.:\\-]{1,90}$/D',
                        (string) $model->provider_model_id
                    ) === 1;
            })
            ->sortBy(static fn (AiModel $model): string =>
                str_pad((string) $model->provider->routing_priority, 4, '0', STR_PAD_LEFT)
                .':'.$model->provider_id.':'.$model->id
            )
            ->values();
    }

    private function configuredGithubCount(): int
    {
        return count(app(PublicRepositoryDirectory::class)->configuredSlugs());
    }
}
