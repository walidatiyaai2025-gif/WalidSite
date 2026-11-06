<?php

use App\Domain\Identity\WebLoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * WEB-01: Keep the approved real owner portrait on this deployment.
 * A fixed, non-user-controlled path prevents filesystem traversal and avoids
 * third-party raw GitHub availability affecting the first viewport.
 */
Route::get('/media/owner-portrait.jpg', static function () {
    // In the source tree the approved photo lives next to backend/.
    // The cPanel installation ZIP intentionally keeps its copy under
    // public_html/assets/portraits; Laravel's public_path resolves that root.
    $packagedPortrait = public_path('assets/portraits/portrait-light-derived.jpg');
    $sourcePortrait = base_path('../assets/portraits/portrait-light-derived.jpg');
    $portrait = is_file($packagedPortrait) && is_readable($packagedPortrait)
        ? $packagedPortrait
        : $sourcePortrait;
    abort_unless(is_file($portrait) && is_readable($portrait), 404);

    return response()->file($portrait, [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=86400',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('public.owner-portrait');
Route::get('/media/about/{slot}', static function (string $slot) {
    return app(\App\Domain\Content\AboutMediaResponder::class)->show($slot);
})->where('slot', '[a-z0-9_]+')->name('public.about-media');


/*
 * WEB-01 public landing: do not turn mockup counts or CV draft rows into
 * published professional claims. Reuse the API's owner/URL approval policy.
 * Render only evidence-safe projects and publication-validated articles.
 */
Route::get('/', function (Request $request) {
    $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
    $publicProfile = $api->profile($request)->getData(true)['data'] ?? null;

    // First select *usable* public proofs, then cap the homepage cards.
    // A fixed pre-filter DB limit can hide all valid projects/articles if
    // earlier rows have revoked evidence or an invalid pinned revision.
    $featuredProjects = collect();
    foreach (\App\Models\Project::query()->publiclyVerified()
        ->with(['evidence' => static fn ($evidence) => $evidence
            ->where('visibility', 'public')
            ->whereNotNull('verified_at')->where('verified_at', '<=', now())
            ->orderBy('id')->limit(50)])
        ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $project) {
        // Featured CTAs must resolve to the exact public case-study route.
        // A stored slug with underscores/spaces can satisfy publication and
        // evidence checks but never match /projects/{slug}; skip it before
        // consuming one of the three visible homepage slots.
        if (preg_match('/\\A[A-Za-z0-9-]+\\z/D', $project->slug) !== 1) {
            continue;
        }
        $data = $api->publicProjectData($project, $request);
        if (empty($data['evidence_urls'])) {
            continue;
        }
        $featuredProjects->push($data);
        if ($featuredProjects->count() === 3) {
            break;
        }
    }

    $featuredArticles = collect();
    foreach (\App\Models\Article::query()->publiclyVisible()
        ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
        if (preg_match('/\A[A-Za-z0-9-]+\z/D', $article->slug) !== 1
            || $article->publicRevision() === null) {
            continue;
        }
        $featuredArticles->push($article);
        if ($featuredArticles->count() === 3) {
            break;
        }
    }

    $hasVerifiedAiCapability = false;
    if ($publicProfile !== null) {
        $ai = app(\App\Http\Controllers\Api\PublicAiExpertiseController::class)
            ->index($request)->getData(true)['data']['skills'] ?? [];
        $hasVerifiedAiCapability = is_array($ai) && $ai !== [];
    }

    return view('welcome', compact(
        'publicProfile', 'featuredProjects', 'featuredArticles', 'hasVerifiedAiCapability'
    ));
})->name('home');

/*
 * WEB-06 public AI Engineering surface. Reuse the canonical evidence-gated
 * API projection; do not infer demos, provider availability or private work.
 */
Route::get('/ai-engineering', function (Request $request) {
    $data = app(\App\Http\Controllers\Api\PublicAiExpertiseController::class)
        ->index($request)->getData(true)['data'];

    return response()->view('public.ai-engineering', ['aiData' => $data])
        ->header('Cache-Control', 'no-store')
        ->header('X-Content-Type-Options', 'nosniff');
})->name('public.ai-engineering')->middleware('throttle:60,1');


/*
 * T012 public SEO sitemap: derive destinations from the SAME publication and
 * proof checks as public pages, never from unapproved DB rows or draft revisions.
 * Bounded reads prevent a crawler from triggering unbounded database work.
 */
Route::get('/sitemap.xml', function (Request $request) {
    $entries = [];
    $add = static function (string $name, array $parameters = []) use (&$entries): void {
        $en = route($name, array_merge($parameters, ['lang' => 'en']));
        $ar = route($name, array_merge($parameters, ['lang' => 'ar']));
        foreach ([$en, $ar] as $url) {
            $entries[] = ['url' => $url, 'en' => $en, 'ar' => $ar];
        }
    };

    foreach ([
        'home', 'public.about', 'public.expertise', 'public.ai-engineering', 'public.hire',
        'public.projects', 'public.github', 'public.blog', 'public.lab',
        'public.resume',
    ] as $name) {
        $add($name);
    }

    // Cap *verified destinations*, not the first 200 nominally published DB
    // rows. Revoked citations or unsafe proof links must never displace a
    // later, genuinely public article or case study in search discovery.
    $articleCount = 0;
    foreach (\App\Models\Article::query()->publiclyVisible()
        ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
        if (preg_match('/\A[a-zA-Z0-9-]+\z/D', $article->slug) !== 1
            || $article->publicRevision() === null) {
            continue;
        }
        $add('public.article', ['slug' => $article->slug]);
        if (++$articleCount === 200) {
            break;
        }
    }

    $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
    $projectCount = 0;
    foreach (\App\Models\Project::query()->publiclyVerified()
        // Use the same 50-evidence per-project policy as the public API.
        // Chunked eager loading avoids one DB query per sitemap candidate.
        ->with(['evidence' => static fn ($evidence) => $evidence
            ->where('visibility', 'public')
            ->whereNotNull('verified_at')
            ->where('verified_at', '<=', now())
            ->orderBy('id')->limit(50)])
        ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $project) {
        if (preg_match('/\A[a-zA-Z0-9-]+\z/D', $project->slug) !== 1
            || empty($api->publicProjectData($project, $request)['evidence_urls'])) {
            continue;
        }
        $add('public.project', ['slug' => $project->slug]);
        if (++$projectCount === 200) {
            break;
        }
    }

    return response()->view('public.sitemap', compact('entries'))
        ->header('Content-Type', 'application/xml; charset=UTF-8')
        ->header('Cache-Control', 'no-store');
})->name('public.sitemap')->middleware('throttle:30,1');


/*
 * Public draft disclosures: accessible for informed contact decisions, but
 * not indexed until legal controller, processor and retention review passes.
 */
Route::get('/privacy', function (Request $request) {
    $request->validate(['lang' => ['sometimes', 'in:ar,en']]);

    return response()->view('public.privacy')
        ->header('Cache-Control', 'no-store')
        ->header('X-Content-Type-Options', 'nosniff');
})->name('public.privacy')->middleware('throttle:30,1');

Route::get('/terms', function (Request $request) {
    $request->validate(['lang' => ['sometimes', 'in:ar,en']]);

    return response()->view('public.terms')
        ->header('Cache-Control', 'no-store')
        ->header('X-Content-Type-Options', 'nosniff');
})->name('public.terms')->middleware('throttle:30,1');

Route::get('/admin/login', [WebLoginController::class, 'form'])
    ->middleware('guest:web')->name('login');
Route::post('/admin/login', [WebLoginController::class, 'login'])
    ->middleware(['guest:web', 'throttle:6,1'])->name('admin.login.submit');

Route::middleware('auth:web')->group(function (): void {
    Route::post('/admin/logout', [WebLoginController::class, 'logout'])->name('admin.logout');

    Route::post('/admin/page-content/career', [\App\Http\Controllers\AdminAboutCareerController::class,'create'])
        ->name('admin.about-career.create')->middleware('throttle:10,1');
    Route::post('/admin/page-content/career/{position}/draft', [\App\Http\Controllers\AdminAboutCareerController::class,'save'])
        ->whereUuid('position')->name('admin.about-career.draft')->middleware('throttle:20,1');
    Route::post('/admin/page-content/career/{position}/publish', [\App\Http\Controllers\AdminAboutCareerController::class,'publish'])
        ->whereUuid('position')->name('admin.about-career.publish')->middleware('throttle:10,1');
    Route::post('/admin/page-content/career/{position}/retract', [\App\Http\Controllers\AdminAboutCareerController::class,'retract'])
        ->whereUuid('position')->name('admin.about-career.retract')->middleware('throttle:10,1');

    Route::post('/admin/page-content/media/{slot}/draft', [\App\Http\Controllers\AdminAboutMediaController::class, 'save'])
        ->where('slot','[a-z0-9_]+')->name('admin.about-media.draft')->middleware('throttle:8,1');
    Route::post('/admin/page-content/media/{slot}/publish', [\App\Http\Controllers\AdminAboutMediaController::class, 'publish'])
        ->where('slot','[a-z0-9_]+')->name('admin.about-media.publish')->middleware('throttle:8,1');
    Route::post('/admin/page-content/media/{slot}/retract', [\App\Http\Controllers\AdminAboutMediaController::class, 'retract'])
        ->where('slot','[a-z0-9_]+')->name('admin.about-media.retract')->middleware('throttle:8,1');
    Route::get('/admin/page-content/media/{slot}/preview', [\App\Http\Controllers\AdminAboutMediaController::class, 'draft'])
        ->where('slot','[a-z0-9_]+')->name('admin.about-media.preview')->middleware('throttle:30,1');

    // P0 first-install CMS: authenticated owner alone may create a missing
    // owner-design slot, save drafts, or publish after source/rights attestation.
    Route::get('/admin/page-content', [\App\Http\Controllers\AdminPageContentController::class, 'index'])
        ->name('admin.page-content.index')->middleware('throttle:30,1');
    Route::post('/admin/page-content/slots', [\App\Http\Controllers\AdminPageContentController::class, 'add'])
        ->name('admin.page-content.add')->middleware('throttle:10,1');
    Route::post('/admin/page-content/{entry}/draft', [\App\Http\Controllers\AdminPageContentController::class, 'save'])
        ->whereUuid('entry')->name('admin.page-content.draft')->middleware('throttle:20,1');
    Route::post('/admin/page-content/{entry}/publish', [\App\Http\Controllers\AdminPageContentController::class, 'publish'])
        ->whereUuid('entry')->name('admin.page-content.publish')->middleware('throttle:10,1');
    Route::post('/admin/page-content/{entry}/retract', [\App\Http\Controllers\AdminPageContentController::class, 'retract'])
        ->whereUuid('entry')->name('admin.page-content.retract')->middleware('throttle:10,1');

    Route::get('/admin', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $stats = [
            'configured' => \App\Models\AiProvider::query()->count(),
            'enabled' => \App\Models\AiProvider::query()->where('enabled', true)->count(),
            'healthy' => \App\Models\AiProvider::query()->where('health_status', 'healthy')->count(),
            'unverified' => \App\Models\AiProvider::query()->where('health_status', 'unverified')->count(),
        ];
        // T016: read only persisted, server-side job records. Never fabricate
        // provider telemetry or sum cost across unrelated currencies.
        $jobs = \Illuminate\Support\Facades\DB::table('ai_jobs')
            ->select('status')->selectRaw('COUNT(*) AS job_count')
            ->groupBy('status')->orderBy('status')->get();
        $utcStart = now('UTC')->startOfDay();
        $usage = \Illuminate\Support\Facades\DB::table('ai_usage')
            ->where('created_at', '>=', $utcStart)
            ->where('created_at', '<', $utcStart->copy()->addDay())
            ->select('cost_currency')
            ->selectRaw('COUNT(*) AS request_count, SUM(cost_minor) AS spend_minor')
            ->groupBy('cost_currency')->orderBy('cost_currency')->get();

        return view('admin.dashboard', compact('stats', 'jobs', 'usage'));
    })->name('admin.home');

    Route::get('/admin/ai/providers', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        return view('admin.ai.providers', [
            'providers' => \App\Models\AiProvider::query()->orderBy('display_name')->paginate(20)
                ->appends(['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']),
        ]);
    })->name('admin.providers.index');

    // ADMIN-04: scoped editor for one active provider. API authorization
    // remains on every writer, and the page must never print saved secrets.
    Route::get('/admin/ai/providers/{provider}', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        return response()->view('admin.ai.editor', compact('provider'))
            ->header('Cache-Control', 'no-store');
    })->whereUuid('provider')->name('admin.providers.show');

    // ADMIN-04: render redacted settings-audit history for an active provider.
    // Match the API's field-name allowlist. A stored event is never trusted as
    // HTML or as a source of credential values, endpoint URLs or actor PII.
    Route::get('/admin/ai/providers/{provider}/audit', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        $allowedFields = [
            'display_name', 'provider_type', 'base_url', 'enabled',
            'concurrent_limit', 'timeout_seconds', 'daily_budget_minor',
            'currency', 'routing_priority', 'secret_rotated', 'secret_removed',
        ];
        $events = \Illuminate\Support\Facades\DB::table('ai_provider_audit_events')
            ->where('provider_id', $provider->id)
            ->select('id', 'action', 'changed_fields_json', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)
            ->appends(['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']);

        // Decode/redact only this bounded page, preserving true audit totals.
        $events->getCollection()->transform(static function ($event) use ($allowedFields) {
            $decoded = json_decode($event->changed_fields_json, true);
            $event->changed_fields = array_values(array_unique(array_filter(
                is_array($decoded) ? $decoded : [],
                static fn ($field): bool => is_string($field)
                    && in_array($field, $allowedFields, true)
            )));
            $event->action = in_array($event->action, ['created', 'updated', 'tested', 'deleted'], true)
                ? $event->action : 'other';
            unset($event->changed_fields_json);

            return $event;
        });

        return response()->view('admin.ai.audit', compact('provider', 'events'))
            ->header('Cache-Control', 'no-store');
    })->whereUuid('provider')->name('admin.providers.audit');

    // ADMIN-13: bounded cross-domain audit & security projection.
    // Provider configuration, editorial decisions and media-rights decisions
    // are persisted independently. Never expose credential values, prompts,
    // draft bodies, citation URLs, private storage paths or actor identities.
    Route::get('/admin/audit', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $today = now('UTC')->toDateString();
        $providerActions = ['created', 'updated', 'tested', 'deleted'];
        $mediaActions = ['media_rights_approved', 'media_rights_revoked'];
        $editorialActions = [
            'manual_published', 'manual_unpublished', 'manual_rejected',
            'scheduled', 'requires_review', 'refresh_required', 'refresh_superseded',
        ];
        $projectActions = ['evidence_submitted', 'evidence_approved', 'evidence_discarded', 'draft_updated', 'project_published', 'project_withdrawn'];
        $allActions = array_values(array_unique(array_merge(
            $providerActions, $editorialActions, $mediaActions, $projectActions
        )));
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'editorial_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'project_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'domain' => ['sometimes', 'in:all,provider,editorial,media,project'],
            'provider' => ['sometimes', 'uuid'],
            'action' => ['sometimes', \Illuminate\Validation\Rule::in($allActions)],
            'day' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'format' => ['sometimes', 'in:csv'],
        ]);
        $locale = $input['lang'] ?? 'en';
        $domain = $input['domain'] ?? 'all';
        $allowedFields = [
            'display_name', 'provider_type', 'base_url', 'enabled',
            'concurrent_limit', 'timeout_seconds', 'daily_budget_minor',
            'currency', 'routing_priority', 'secret_rotated', 'secret_removed',
        ];

        $auditQuery = \Illuminate\Support\Facades\DB::table('ai_provider_audit_events as event')
            ->join('ai_providers as provider', 'provider.id', '=', 'event.provider_id')
            ->select([
                'event.id', 'event.provider_id', 'event.action',
                'event.changed_fields_json', 'event.created_at',
                'provider.display_name', 'provider.deleted_at',
            ])
            ->when(! in_array($domain, ['all', 'provider'], true), fn ($query) => $query->whereRaw('1 = 0'))
            ->when(isset($input['provider']), fn ($query) => $query
                ->where('event.provider_id', $input['provider']))
            ->when(isset($input['action']), function ($query) use ($input, $providerActions) {
                in_array($input['action'], $providerActions, true)
                    ? $query->where('event.action', $input['action'])
                    : $query->whereRaw('1 = 0');
            })
            ->when(isset($input['day']), function ($query) use ($input) {
                $start = \Carbon\CarbonImmutable::parse($input['day'], 'UTC')->startOfDay();
                $query->where('event.created_at', '>=', $start)
                    ->where('event.created_at', '<', $start->addDay());
            })
            ->orderByDesc('event.created_at')->orderByDesc('event.id');

        $redact = static function ($event) use ($allowedFields) {
            $raw = json_decode($event->changed_fields_json, true);
            $event->fields = array_values(array_unique(array_filter(
                is_array($raw) ? $raw : [],
                static fn ($field): bool => is_string($field)
                    && in_array($field, $allowedFields, true)
            )));
            $event->action = in_array($event->action, ['created', 'updated', 'tested', 'deleted'], true)
                ? $event->action : 'other';
            unset($event->changed_fields_json);
            return $event;
        };

        $editorialQuery = \Illuminate\Support\Facades\DB::table('editorial_events as event')
            ->leftJoin('articles as article', 'article.id', '=', 'event.article_id')
            ->select([
                'event.id', 'event.article_id', 'event.revision_no', 'event.action',
                'event.reason', 'event.created_at', 'article.title_en', 'article.title_ar',
            ])
            ->when(in_array($domain, ['provider', 'project'], true) || isset($input['provider']), fn ($query) => $query->whereRaw('1 = 0'))
            // The shared editorial log also contains internal/system events.
            // Only audited editorial/media decisions may cross this Admin
            // presentation/export boundary, including domain=all.
            ->whereIn('event.action', $domain === 'media' ? $mediaActions
                : ($domain === 'editorial' ? $editorialActions
                    : array_merge($editorialActions, $mediaActions)))
            ->when(isset($input['action']), fn ($query) => $query->where('event.action', $input['action']))
            ->when(isset($input['day']), function ($query) use ($input) {
                $start = \Carbon\CarbonImmutable::parse($input['day'], 'UTC')->startOfDay();
                $query->where('event.created_at', '>=', $start)
                    ->where('event.created_at', '<', $start->addDay());
            })
            ->orderByDesc('event.created_at')->orderByDesc('event.id');

        // Never expose private evidence URLs, request bodies or actor emails.
        $projectQuery = \Illuminate\Support\Facades\DB::table('project_audit_events')
            ->select(['id', 'project_id', 'evidence_id', 'action', 'created_at'])
            ->when(! in_array($domain, ['all', 'project'], true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when(isset($input['provider']), fn ($q) => $q->whereRaw('1 = 0'))
            ->when(isset($input['action']), function ($q) use ($input, $projectActions) {
                in_array($input['action'], $projectActions, true)
                    ? $q->where('action', $input['action']) : $q->whereRaw('1 = 0');
            })
            ->when(isset($input['day']), function ($q) use ($input) {
                $start = \Carbon\CarbonImmutable::parse($input['day'], 'UTC')->startOfDay();
                $q->where('created_at', '>=', $start)->where('created_at', '<', $start->addDay());
            })
            ->orderByDesc('created_at')->orderByDesc('id');

        if (($input['format'] ?? null) === 'csv') {
            $providerWindow = (clone $auditQuery)->limit(1001)->get();
            $editorialWindow = (clone $editorialQuery)->limit(1001)->get();
            $projectWindow = (clone $projectQuery)->limit(1001)->get();
            $truncated = $providerWindow->count() > 1000 || $editorialWindow->count() > 1000 || $projectWindow->count() > 1000;
            $providerRows = $providerWindow->take(1000)->map($redact);
            $editorialRows = $editorialWindow->take(1000);
            $projectRows = $projectWindow->take(1000);
            $safeCell = static function (string $value): string {
                return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
            };
            return response()->streamDownload(static function () use (
                $providerRows, $editorialRows, $projectRows, $safeCell, $mediaActions, $locale
            ): void {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, [
                    'UTC time', 'Provider', 'Action', 'Changed field names', 'Domain', 'Subject',
                ]);
                foreach ($providerRows as $event) {
                    fputcsv($stream, [
                        (string) $event->created_at,
                        $safeCell((string) $event->display_name),
                        $event->action,
                        implode(', ', $event->fields),
                        'provider',
                        '',
                    ]);
                }
                foreach ($editorialRows as $event) {
                    $isMedia = in_array($event->action, $mediaActions, true);
                    $title = $locale === 'ar'
                        ? ((string) ($event->title_ar ?: $event->title_en))
                        : ((string) ($event->title_en ?: $event->title_ar));
                    $subject = $isMedia ? (string) ($event->reason ?? '') : $title;
                    fputcsv($stream, [
                        (string) $event->created_at,
                        '',
                        (string) $event->action,
                        '',
                        $isMedia ? 'media' : 'editorial',
                        $safeCell($subject),
                    ]);
                }
                foreach ($projectRows as $event) {
                    fputcsv($stream, [
                        (string) $event->created_at, '', (string) $event->action,
                        '', 'project', (string) $event->project_id
                            .($event->evidence_id ? ' / '.(string) $event->evidence_id : ''),
                    ]);
                }
                fclose($stream);
            }, 'admin-audit-'.$today.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
                'X-Export-Truncated' => $truncated ? 'true' : 'false',
            ]);
        }

        $events = (clone $auditQuery)->paginate(20, ['*'], 'page')->appends(array_filter([
            'lang' => $locale,
            'domain' => $domain !== 'all' ? $domain : null,
            'provider' => $input['provider'] ?? null,
            'action' => $input['action'] ?? null,
            'day' => $input['day'] ?? null,
            'editorial_page' => $input['editorial_page'] ?? null,
            'project_page' => $input['project_page'] ?? null,
        ], static fn ($value): bool => $value !== null));
        $events->getCollection()->transform($redact);

        $editorialEvents = (clone $editorialQuery)
            ->paginate(20, ['*'], 'editorial_page')
            ->appends(array_filter([
                'lang' => $locale,
                'domain' => $domain !== 'all' ? $domain : null,
                'provider' => $input['provider'] ?? null,
                'action' => $input['action'] ?? null,
                'day' => $input['day'] ?? null,
                'page' => $input['page'] ?? null,
                'project_page' => $input['project_page'] ?? null,
            ], static fn ($value): bool => $value !== null));

        $projectEvents = (clone $projectQuery)
            ->paginate(20, ['*'], 'project_page')
            ->appends(array_filter([
                'lang' => $locale, 'domain' => $domain !== 'all' ? $domain : null,
                'provider' => $input['provider'] ?? null, 'action' => $input['action'] ?? null,
                'day' => $input['day'] ?? null, 'page' => $input['page'] ?? null,
                'editorial_page' => $input['editorial_page'] ?? null,
            ], static fn ($value): bool => $value !== null));

        $providers = \App\Models\AiProvider::withTrashed()
            ->select('id', 'display_name')->orderBy('display_name')
            ->orderBy('id')->get();
        $filters = [
            'domain' => $domain,
            'provider' => $input['provider'] ?? '',
            'action' => $input['action'] ?? '',
            'day' => $input['day'] ?? '',
        ];

        return response()->view('admin.ai.security-audit', compact(
            'events', 'editorialEvents', 'providers', 'filters', 'locale', 'today',
            'providerActions', 'editorialActions', 'mediaActions', 'projectActions', 'projectEvents'
        ))->header('Cache-Control', 'no-store, private');
    })->name('admin.audit.index')->middleware('throttle:30,1');

    // ADMIN-05: one read-only routing projection for authorized web admins.
    // Persisted limits and observed health are not live provider eligibility
    // or proof that an AI task can run. Never select endpoint or secret fields.
    Route::get('/admin/ai/routing', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $providers = \App\Models\AiProvider::query()
            ->select([
                'id', 'display_name', 'provider_type', 'enabled',
                'health_status', 'routing_priority', 'concurrent_limit',
                'timeout_seconds', 'daily_budget_minor', 'currency',
            ])
            ->orderBy('routing_priority')->orderBy('id')
            ->paginate(20)
            ->appends(['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']);

        return response()->view('admin.ai.routing', compact('providers'))
            ->header('Cache-Control', 'no-store');
    })->name('admin.routing.index');

    // ADMIN-04/05: browser journey to register actual AI models and configure
    // the single implemented diagnosis task. These are persisted settings,
    // not a live provider test or an assertion that paid inference will run.
    Route::get('/admin/ai/model-routes', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';
        $providersForModels = \App\Models\AiProvider::query()
            ->select(['id', 'display_name', 'provider_type', 'currency', 'enabled'])
            ->orderBy('display_name')->get();
        $models = \App\Models\AiModel::query()->whereHas('provider')
            ->with('provider:id,display_name,provider_type,currency,enabled')
            ->orderBy('provider_id')->orderBy('provider_model_id')
            ->paginate(25)->appends(['lang' => $locale]);
        // Bounded route choices; never silently imply that the entire
        // registry has loaded once the safety limit is exceeded.
        $candidateQuery = \App\Models\AiModel::query()->whereHas('provider')
            ->where('enabled', true)
            ->whereJsonContains('capabilities_json', 'text')
            ->with('provider:id,display_name,provider_type,currency,enabled')
            ->orderBy('provider_id')->orderBy('provider_model_id');
        $candidates = $candidateQuery->limit(201)->get();
        $choicesTruncated = $candidates->count() > 200;
        $candidates = $candidates->take(200);
        $taskRoute = \App\Models\AiRoute::query()
            ->where('task_type', \App\AI\MobileDiagnosisAdmission::TASK_TYPE)->first();
        // The same fail-closed selector used by mobile: read-only DB checks,
        // no outbound provider call, no generated answer or billing.
        $selected = app(\App\AI\MobileDiagnosisAdmission::class)->select();

        return response()->view('admin.ai.model-routes', compact(
            'locale', 'providersForModels', 'models', 'candidates',
            'choicesTruncated', 'taskRoute', 'selected'
        ))->header('Cache-Control', 'no-store');
    })->name('admin.models.index');

    Route::post('/admin/ai/models', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        $input = $request->validate([
            'provider_id' => ['required', 'uuid',
                \Illuminate\Validation\Rule::exists('ai_providers', 'id')->whereNull('deleted_at')],
            'provider_model_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.:\-]{1,90}\z/D'],
            'enabled' => ['required', 'boolean'],
            'supports_text' => ['required', 'boolean'],
        ]);
        if (array_diff(array_keys($request->except('_token', '_method', 'lang')), array_keys($input)) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'model' => 'Only model registration fields are supported.',
            ]);
        }
        $provider = \App\Models\AiProvider::query()->findOrFail($input['provider_id']);
        $request->validate(['provider_model_id' => [
            \Illuminate\Validation\Rule::unique('ai_models', 'provider_model_id')
                ->where('provider_id', $provider->id),
        ]]);
        \App\Models\AiModel::create([
            'provider_id' => $provider->id,
            'provider_model_id' => $input['provider_model_id'],
            'enabled' => (bool) $input['enabled'],
            'capabilities_json' => $input['supports_text'] ? ['text'] : [],
            'cost_policy_json' => $provider->provider_type === 'openai-compatible'
                ? ['currency' => $provider->currency] : [],
        ]);
        $ar = $request->query('lang') === 'ar';
        return redirect()->route('admin.models.index', $ar ? ['lang' => 'ar'] : [])
            ->with('status', $ar ? 'تم تسجيل النموذج.' : 'Model registered.');
    })->middleware('throttle:20,1')->name('admin.models.create');

    Route::patch('/admin/ai/models/{model}', function (
        Request $request, \App\Models\AiModel $model
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        $fields = $request->validate(['enabled' => ['required', 'boolean']]);
        if (array_diff(array_keys($request->except('_token', '_method', 'lang')), ['enabled']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'model' => 'Only the enablement state can be changed here.',
            ]);
        }
        abort_if($model->provider === null, 404); // archived provider
        $model->forceFill(['enabled' => (bool) $fields['enabled']])->save();
        $ar = $request->query('lang') === 'ar';
        return redirect()->route('admin.models.index', $ar ? ['lang' => 'ar'] : [])
            ->with('status', $ar ? 'تم تحديث حالة النموذج.' : 'Model state updated.');
    })->whereUuid('model')->middleware('throttle:20,1')->name('admin.models.update');

    Route::put('/admin/ai/task-routes/mobile-ai-diagnose', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        $input = $request->validate([
            'primary_model_id' => ['required', 'uuid'],
            'fallback_model_ids' => ['sometimes', 'array', 'max:8'],
            'fallback_model_ids.*' => ['required', 'uuid', 'distinct'],
            'currency' => ['required', 'in:USD,KWD,EUR'],
            'reservation_minor' => ['required', 'integer', 'between:0,100000000'],
            'per_minute' => ['required', 'integer', 'between:1,60'],
            'per_day' => ['required', 'integer', 'between:1,10000'],
        ]);
        if (array_diff(array_keys($request->except('_token', '_method', 'lang')), array_keys($input)) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'route' => 'Only task routing fields are supported.',
            ]);
        }
        if ($input['per_day'] < $input['per_minute']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'per_day' => 'Daily limit must be at least the minute limit.',
            ]);
        }
        $fallbacks = $input['fallback_model_ids'] ?? [];
        $ids = [$input['primary_model_id'], ...$fallbacks];
        if (count($ids) !== count(array_unique($ids))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'fallback_model_ids' => 'Primary and fallback models must be distinct.',
            ]);
        }
        $registry = \App\Models\AiModel::query()->with('provider')
            ->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $model = $registry->get($id);
            $provider = $model?->provider;
            if (! $model || ! $provider || ! $model->enabled
                || ! in_array('text', $model->capabilities_json ?? [], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'primary_model_id' => 'Choose existing, enabled text-capable models on active providers.',
                ]);
            }
            if ($provider->provider_type === 'openai-compatible'
                && ($input['reservation_minor'] < 1
                    || $input['currency'] !== $provider->currency
                    || ($model->cost_policy_json['currency'] ?? null) !== $provider->currency)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'currency' => 'Paid model, provider and route reservation currencies must match and reserve a positive amount.',
                ]);
            }
        }
        \Illuminate\Support\Facades\DB::transaction(static function () use ($input, $fallbacks): void {
            $route = \App\Models\AiRoute::query()
                ->where('task_type', \App\AI\MobileDiagnosisAdmission::TASK_TYPE)
                ->lockForUpdate()->first() ?? new \App\Models\AiRoute([
                    'task_type' => \App\AI\MobileDiagnosisAdmission::TASK_TYPE,
                ]);
            $route->fill([
                'primary_model_id' => $input['primary_model_id'],
                'fallback_model_ids_json' => array_values($fallbacks),
                'budget_json' => [
                    'currency' => $input['currency'],
                    'reservation_minor' => (int) $input['reservation_minor'],
                ],
                'rate_limits_json' => [
                    'per_minute' => (int) $input['per_minute'],
                    'per_day' => (int) $input['per_day'],
                ],
            ])->save();
        }, 3);
        $ar = $request->query('lang') === 'ar';
        return redirect()->route('admin.models.index', $ar ? ['lang' => 'ar'] : [])
            ->with('status', $ar ? 'تم حفظ مسار التشخيص وحدوده.' : 'Diagnosis route and limits saved.');
    })->middleware('throttle:20,1')->name('admin.task-routes.save');

    // T016: bounded, read-only provider billing audit. Saved daily budgets
    // are displayed as settings, not claimed as enforced spend limits.
    // Keep receipts partitioned by their *actual* currency and provider.
    Route::get('/admin/ai/usage', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $todayUtc = now('UTC')->startOfDay();
        $todayDay = $todayUtc->toDateString();
        $oldestDay = $todayUtc->copy()->subDays(90)->toDateString();
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'day' => ['sometimes', 'date_format:Y-m-d',
                'after_or_equal:'.$oldestDay, 'before_or_equal:'.$todayDay],
        ]);
        // This is a read-only UTC calendar-day window, never a floating 24h
        // interval or a live provider cost assertion. Bound queries to 90 days.
        $day = $input['day'] ?? $todayDay;
        $dayStart = \Carbon\CarbonImmutable::parse($day, 'UTC')->startOfDay();
        $dayEnd = $dayStart->addDay();
        $rows = \Illuminate\Support\Facades\DB::table('ai_providers as provider')
            ->leftJoin('ai_usage as usage', function ($join) use ($dayStart, $dayEnd) {
                $join->on('usage.provider_id', '=', 'provider.id')
                    ->where('usage.created_at', '>=', $dayStart)
                    ->where('usage.created_at', '<', $dayEnd);
            })
            // Provider archival revokes credentials, not financial history.
            // Keep active zero-use rows and only archived rows with receipts
            // from the current UTC day; never reactivate a deleted provider.
            ->where(fn ($query) => $query->whereNull('provider.deleted_at')
                ->orWhereNotNull('usage.id'))
            ->select('provider.id', 'provider.display_name', 'provider.daily_budget_minor',
                'provider.currency', 'provider.deleted_at', 'usage.cost_currency')
            ->selectRaw('COUNT(usage.id) AS receipt_count, COALESCE(SUM(usage.cost_minor), 0) AS spend_minor')
            ->groupBy('provider.id', 'provider.display_name', 'provider.daily_budget_minor',
                'provider.currency', 'provider.deleted_at', 'usage.cost_currency')
            ->orderBy('provider.display_name')->orderBy('provider.id')
            ->orderBy('usage.cost_currency')
            ->paginate(20)->appends(array_filter([
                'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
                // Preserve canonical today links unless the admin chose a
                // day explicitly. Historical reports must retain the filter.
                'day' => isset($input['day']) ? $day : null,
            ], static fn ($value): bool => $value !== null));

        return response()->view('admin.ai.usage', compact('rows', 'day', 'oldestDay', 'todayDay'))
            ->header('Cache-Control', 'no-store');
    })->name('admin.usage.index');

    // Laravel web middleware enforces CSRF. API controller remains the only
    // writer, validator, secret encryption and audit implementation.
    Route::post('/admin/ai/providers', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        app(\App\Http\Controllers\Api\AiProviderController::class)->store($request);
        return redirect()->route('admin.providers.index',
            $request->query('lang') === 'ar' ? ['lang' => 'ar'] : []
        )->with('status', $request->query('lang') === 'ar'
            ? 'تم حفظ مزود الذكاء الاصطناعي.' : 'Provider saved.');
    })->middleware('throttle:10,1')->name('admin.providers.create');

    Route::patch('/admin/ai/providers/{provider}', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        // Blank inputs must not erase a stored credential. Do not coerce
        // array/object-shaped values into strings: leave malformed payloads
        // intact for the API writer's strict string validation (no warnings).
        if ($request->boolean('clear_secret')) {
            $request->merge(['secret' => null]);
        } else {
            $secret = $request->input('secret');
            if ($secret === null || (is_string($secret) && trim($secret) === '')) {
                $request->request->remove('secret');
            }
        }
        // Only exact internal return markers are valid redirect targets.
        // Arbitrary URLs never become destinations.
        $backToRouting = $request->query('return') === 'routing';
        $backToEditor = $request->query('return') === 'editor';
        if ($backToRouting) {
            $request->validate([
                'lang' => ['sometimes', 'in:ar,en'],
                'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            ]);
        }
        app(\App\Http\Controllers\Api\AiProviderController::class)->update($request, $provider);
        $redirect = $backToRouting ? 'admin.routing.index'
            : ($backToEditor ? 'admin.providers.show' : 'admin.providers.index');
        $parameters = $request->query('lang') === 'ar' ? ['lang' => 'ar'] : [];
        if ($backToEditor) {
            $parameters['provider'] = $provider->id;
        }
        if ($backToRouting && $request->query('page') !== null) {
            $parameters['page'] = (int) $request->query('page');
        }
        return redirect()->route($redirect, $parameters)
            ->with('status', $request->query('lang') === 'ar'
                ? 'تم تحديث مزود الذكاء الاصطناعي.' : 'Provider updated.');
    })->middleware('throttle:20,1')->name('admin.providers.update');

    Route::post('/admin/ai/providers/{provider}/test', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $result = app(\App\Http\Controllers\Api\AiProviderController::class)->test($request, $provider);
        $destination = $request->query('return') === 'editor'
            ? 'admin.providers.show' : 'admin.providers.index';
        $parameters = $request->query('lang') === 'ar' ? ['lang' => 'ar'] : [];
        if ($destination === 'admin.providers.show') {
            $parameters['provider'] = $provider->id;
        }
        // The API correctly rejects stale health results when the provider is
        // changed during network I/O. Never relabel that 409 as a saved test.
        if ($result->getStatusCode() === 409) {
            return redirect()->route($destination, $parameters)->with('error', $request->query('lang') === 'ar'
                ? 'تغيّرت إعدادات المزود أثناء فحص الاتصال. لم تُسجّل نتيجة؛ أعد الفحص للإعدادات الحالية.'
                : 'Provider settings changed during the connection check. No result was recorded; test the current settings again.');
        }
        $health = $result->getData(true)['data']['health_status'] ?? 'unverified';
        return redirect()->route($destination, $parameters)
            ->with('status', $request->query('lang') === 'ar'
                ? 'تم تسجيل نتيجة فحص الاتصال: '.$health.'.'
            : 'Connection check recorded: '.$health.'.');
    })->middleware('throttle:5,1')->name('admin.providers.test');

    // T016: explicit destructive browser operation is CSRF-protected and
    // name-confirmed. Reuse T015's encrypted credential revocation and audit
    // rather than duplicating provider deletion logic in the web layer.
    Route::delete('/admin/ai/providers/{provider}', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'confirm_name' => [
                'required', 'string', 'max:160',
                \Illuminate\Validation\Rule::in([$provider->display_name]),
            ],
        ]);

        app(\App\Http\Controllers\Api\AiProviderController::class)
            ->destroy($request, $provider);

        return redirect()->route('admin.providers.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
            ? 'تمت أرشفة المزود وإبطال مفتاحه.'
            : 'Provider archived; credential revoked.');
    })->middleware('throttle:5,1')->name('admin.providers.delete');

    // T020: editorial decisions remain server-authorized and fail closed.
    Route::get('/admin/content', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'article' => ['sometimes', 'uuid'],
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $articles = \App\Models\Article::query()
            ->withCount('revisions')->orderByDesc('updated_at')->orderBy('id')
            ->paginate(15)->withQueryString();
        $selected = isset($input['article'])
            ? \App\Models\Article::query()->with('heroAsset')
                ->findOrFail($input['article']) : null;
        $revisions = $selected
            ? $selected->revisions()->orderByDesc('revision_no')->limit(30)->get()
            : collect();
        $latestRevision = $revisions->first();
        $latestChecks = $latestRevision && is_array($latestRevision->validation_json)
            ? $latestRevision->validation_json : [];
        $requiresExceptionReview = $latestRevision !== null
            && (($latestChecks['risk_level'] ?? null) !== 'low'
                || ($latestChecks['contains_professional_claims'] ?? null) !== false);

        return view('admin.content.index', compact(
            'articles', 'selected', 'revisions', 'requiresExceptionReview'
        ));
    })->name('admin.content.index');

    Route::post('/admin/content/{article}/publish', function (
        Request $request, \App\Models\Article $article
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        // Never write status directly: the domain pins the exact validated
        // revision and rejects unsafe citations or insufficient image rights.
        if (! $article->isReadyToPublish()) {
            return back()->withErrors(['publish' => 'Citation, technical or image rights gates have not passed.']);
        }
        $revision = $article->revisions()->orderByDesc('revision_no')->first();
        $checks = $revision?->validation_json ?? [];
        // High-risk and professional content must use an explicit, recorded
        // owner-only exception decision, never the generic publish action.
        if (($checks['risk_level'] ?? null) !== 'low'
            || ($checks['contains_professional_claims'] ?? null) !== false) {
            return back()->withErrors(['publish' => 'Owner manual review and a reason are required.']);
        }
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($article, $request): void {
                $article = \App\Models\Article::query()->whereKey($article->id)
                    ->lockForUpdate()->firstOrFail();
                $reviewer = \App\Models\User::query()->whereKey($request->user()->id)
                    ->lockForUpdate()->first();
                abort_unless($reviewer?->canAccessAdmin(), 403);
                if (! $article->isReadyToPublish()) {
                    throw new \DomainException('Publication evidence changed.');
                }
                // Never trust a pre-transaction revision or its original risk:
                // this exact latest snapshot must have an independent checker.
                $latest = $article->revisions()->orderByDesc('revision_no')->firstOrFail();
                abort_unless($latest->canBeIndependentlyReviewedBy($reviewer), 403,
                    'Independent editorial reviewer required.');
                $currentChecks = is_array($latest->validation_json)
                    ? $latest->validation_json : [];
                if (($currentChecks['risk_level'] ?? null) !== 'low'
                    || ($currentChecks['contains_professional_claims'] ?? null) !== false) {
                    throw new \DomainException('Owner exception review required.');
                }

                $article->publishVerified();
                $latest->forceFill([
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now('UTC'),
                ])->save();
                app(\App\AI\EditorialScheduleRunner::class)->event(
                    'manual:'.\Illuminate\Support\Str::uuid(),
                    $article->id, $latest->revision_no, $reviewer->id,
                    'manual_published', 'content_reviewed'
                );
            }, 3);
        } catch (\DomainException $exception) {
            return back()->withErrors(['publish' => 'Publication checks changed; review again before publishing.']);
        }

        return redirect()->route('admin.content.index', [
            'article' => $article->id, 'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Verified revision published.');
    })->middleware('throttle:10,1')->name('admin.content.publish');

    Route::post('/admin/content/{article}/unpublish', function (
        Request $request, \App\Models\Article $article
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        if (array_diff(array_keys($request->request->all()), ['_token']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'unpublish' => 'Unsupported article withdrawal fields.',
            ]);
        }
        // Lock and recheck the ACTUAL published pointer before any audit write.
        // A repeated submit or an inconsistent draft can never generate a
        // second "manual_unpublished" event for a transition that did not occur.
        \Illuminate\Support\Facades\DB::transaction(function () use ($article, $request): void {
            $locked = \App\Models\Article::query()->whereKey($article->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'published'
                && $locked->published_at !== null
                && $locked->published_revision_no !== null, 409,
                'Article is not currently published.');
            $revisionNumber = $locked->published_revision_no;
            $locked->forceFill([
                'status' => 'draft', 'published_at' => null,
                'published_revision_no' => null,
            ])->save();
            app(\App\AI\EditorialScheduleRunner::class)->event(
                'manual:'.\Illuminate\Support\Str::uuid(),
                $locked->id, $revisionNumber, $request->user()->id,
                'manual_unpublished', 'owner_or_admin_revoked'
            );
        }, 3);

        return redirect()->route('admin.content.index', [
            'article' => $article->id, 'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
            ? 'تم إلغاء نشر المقال.' : 'Article unpublished.');
    })->middleware('throttle:10,1')->name('admin.content.unpublish');

    // ADMIN-12: explicit review queue. It exposes only bounded gate state;
    // draft bodies, citation URLs and provider details remain private.
    Route::get('/admin/approvals', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $locale = $input['lang'] ?? 'en';
        $approvals = \App\Models\ArticleRevision::query()
            ->with('article.heroAsset')
            ->whereHas('article', fn ($article) => $article->where('status', 'draft'))
            ->whereRaw(
                'article_revisions.revision_no = (SELECT MAX(latest.revision_no) FROM article_revisions AS latest WHERE latest.article_id = article_revisions.article_id)'
            )
            ->orderByDesc('updated_at')->orderBy('id')
            ->paginate(25)->appends(['lang' => $locale]);

        $approvals->getCollection()->transform(function ($revision) use ($request) {
            $article = $revision->article;
            $checks = is_array($revision->validation_json) ? $revision->validation_json : [];
            $lastDecision = \Illuminate\Support\Facades\DB::table('editorial_events')
                ->where('article_id', $article->id)
                ->where('revision_no', $revision->revision_no)
                ->whereIn('action', ['manual_published', 'manual_rejected', 'requires_review'])
                ->orderByDesc('created_at')->orderByDesc('id')->first();

            return (object) [
                'article_id' => $article->id,
                'revision_no' => $revision->revision_no,
                'title_en' => $article->title_en,
                'title_ar' => $article->title_ar,
                'evidence_passed' => $revision->hasVerifiedEvidence(),
                'technical_passed' => ($checks['technical_passed'] ?? false) === true,
                'image_rights_passed' => $article->hero_asset_id === null
                    || $article->heroAsset?->isPubliclyUsable() === true,
                'independent_review_allowed' => $revision->canBeIndependentlyReviewedBy($request->user()),
                'requires_owner' => ($checks['risk_level'] ?? null) !== 'low'
                    || ($checks['contains_professional_claims'] ?? null) !== false,
                'last_decision' => $lastDecision?->action,
            ];
        });

        return response()->view('admin.approvals', compact('approvals', 'locale'))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    })->name('admin.approvals.index')->middleware('throttle:30,1');

    Route::post('/admin/approvals/{article}/decision', function (
        Request $request, \App\Models\Article $article
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
        if (array_diff(
            array_keys($request->request->all()),
            ['_token', 'decision', 'reason']
        ) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'decision' => 'Unsupported decision input.',
            ]);
        }
        $input = $request->validate([
            'decision' => ['required', \Illuminate\Validation\Rule::in(['approve', 'reject', 'defer'])],
            'reason' => ['required', \Illuminate\Validation\Rule::in([
                'source_reviewed', 'technical_reviewed', 'legal_reviewed',
            ])],
        ]);
        $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';

        try {
            $state = \Illuminate\Support\Facades\DB::transaction(function () use (
                $request, $article, $input
            ): string {
                $locked = \App\Models\Article::query()->whereKey($article->id)
                    ->lockForUpdate()->firstOrFail();
                abort_if($locked->status === 'published', 409, 'Article is already published.');
                $revision = $locked->revisions()->orderByDesc('revision_no')->firstOrFail();

                if ($input['decision'] === 'defer') {
                    app(\App\AI\EditorialScheduleRunner::class)->event(
                        'web-defer:'.\Illuminate\Support\Str::uuid(),
                        $locked->id, $revision->revision_no, $request->user()->id,
                        'requires_review', $input['reason']
                    );
                    return 'deferred';
                }

                if ($input['decision'] === 'reject') {
                    app(\App\AI\EditorialScheduleRunner::class)->event(
                        'web-reject:'.\Illuminate\Support\Str::uuid(),
                        $locked->id, $revision->revision_no, $request->user()->id,
                        'manual_rejected', $input['reason']
                    );
                    return 'rejected';
                }

                if (! $locked->isReadyToPublish()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'approval' => 'Independent citation, technical and media review is incomplete.',
                    ]);
                }
                $reviewer = \App\Models\User::query()->whereKey($request->user()->id)
                    ->lockForUpdate()->first();
                abort_unless($reviewer?->canAccessAdmin()
                    && $revision->canBeIndependentlyReviewedBy($reviewer), 403,
                    'Independent editorial reviewer required.');

                $checks = is_array($revision->validation_json) ? $revision->validation_json : [];
                if (($checks['risk_level'] ?? null) !== 'low'
                    || ($checks['contains_professional_claims'] ?? null) !== false) {
                    abort_unless($reviewer->hasRole('owner'), 403);
                }

                $locked->publishVerified();
                $revision->forceFill([
                    'reviewed_by_user_id' => $reviewer->id,
                    'reviewed_at' => now('UTC'),
                ])->save();
                app(\App\AI\EditorialScheduleRunner::class)->event(
                    'web-approve:'.\Illuminate\Support\Str::uuid(),
                    $locked->id, $revision->revision_no, $reviewer->id,
                    'manual_published', $input['reason']
                );
                return 'published';
            }, 3);
        } catch (\DomainException) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'approval' => 'Publication gates changed during approval.',
            ]);
        }

        $messages = [
            'en' => [
                'published' => 'Verified revision approved and published.',
                'rejected' => 'Revision rejected and recorded for review.',
                'deferred' => 'Decision deferred; nothing was published.',
            ],
            'ar' => [
                'published' => 'تم اعتماد الإصدار المتحقق منه ونشره.',
                'rejected' => 'تم رفض الإصدار وتسجيل القرار للمراجعة.',
                'deferred' => 'تم تأجيل القرار ولم يُنشر أي محتوى.',
            ],
        ];
        return redirect()->route('admin.approvals.index', ['lang' => $locale])
            ->with('status', $messages[$locale][$state]);
    })->whereUuid('article')->name('admin.approvals.decision')
        ->middleware('throttle:10,1');

    // T020: generated artwork approval delegates to the existing domain
    // verifier. The web layer only captures an explicit human attestation;
    // it never reads private storage paths, prompts or provider metadata.
    Route::get('/admin/ai/images', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'jobs_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        // ADMIN-06 queue form: display only server-registered models that can
        // actually reach the installed image executor. Never expose endpoint
        // URLs, credentials, routing internals or provider/model secrets.
        $models = \App\Models\AiModel::query()
            ->with('provider')
            ->where('enabled', true)
            ->whereHas('provider', fn ($provider) => $provider
                ->whereNull('deleted_at')
                ->where('enabled', true)
                ->where('health_status', 'healthy')
                ->where('provider_type', 'openai-compatible')
                ->where('daily_budget_minor', '>', 0))
            ->orderBy('provider_model_id')->limit(100)->get()
            ->filter(function ($model): bool {
                $capabilities = $model->capabilities_json;
                $provider = $model->provider;
                $savedCredential = $provider?->getRawOriginal('secret_ciphertext');

                return is_array($capabilities)
                    && in_array('image-generation', $capabilities, true)
                    && is_string($savedCredential) && $savedCredential !== ''
                    && preg_match('/^[A-Za-z0-9_.:\\-]{1,90}$/D', (string) $model->provider_model_id) === 1;
            })
            ->take(50)->values();

        // Offer only the current draft revision for each draft article.
        // The dispatcher refreshes and re-checks this invariant again at queue
        // admission, so a concurrent editorial change fails closed.
        $drafts = \App\Models\ArticleRevision::query()
            ->with('article')
            ->where('status', 'draft')
            ->whereHas('article', fn ($article) => $article->where('status', 'draft'))
            ->whereRaw('article_revisions.revision_no = (SELECT MAX(latest.revision_no) FROM article_revisions AS latest WHERE latest.article_id = article_revisions.article_id)')
            ->orderByDesc('updated_at')->orderBy('id')->limit(50)->get();

        $imageJobs = \Illuminate\Support\Facades\DB::table('ai_jobs as job')
            ->join('ai_providers as provider', 'provider.id', '=', 'job.provider_id')
            ->where('job.job_type', 'editorial-art')
            ->select([
                'job.id', 'job.status', 'job.attempt_count', 'job.max_attempts',
                'job.reserved_budget_minor', 'job.last_error_code', 'job.updated_at',
                'provider.display_name as provider_name', 'provider.currency',
            ])->orderByDesc('job.created_at')->orderBy('job.id')
            // A bounded independent page keeps old and retried artwork jobs
            // inspectable without changing the registered-assets page.
            ->paginate(20, ['*'], 'jobs_page')->withQueryString();

        return view('admin.ai.media', [
            'assets' => \App\Models\Asset::query()
                ->orderByDesc('created_at')->orderBy('id')->paginate(12)->withQueryString(),
            'models' => $models,
            'drafts' => $drafts,
            'imageJobs' => $imageJobs,
        ]);
    })->name('admin.media.index');

    Route::post('/admin/ai/images/queue', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'revision_id' => ['required', 'uuid'],
            'model_id' => ['required', 'uuid'],
            'estimated_cost_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        $model = \App\Models\AiModel::query()->findOrFail($input['model_id']);
        $revision = \App\Models\ArticleRevision::query()->with('article')
            ->findOrFail($input['revision_id']);
        $estimate = (int) $input['estimated_cost_minor'];
        $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';

        try {
            // All admin image reservations use the provider row as a mutex,
            // shared with worker claims and edits to the provider budget.
            // Capacity check and durable enqueue must commit together.
            $jobId = \Illuminate\Support\Facades\DB::transaction(
                function () use ($model, $revision, $estimate, $locale): string {
                    $provider = \App\Models\AiProvider::query()
                        ->whereKey($model->provider_id)->lockForUpdate()->first();
                    // Re-read the model and provider under the budget lock:
                    // neither a stale UI selection nor cached health is safe.
                    $currentModel = \App\Models\AiModel::query()->find($model->id);
                    $capabilities = $currentModel?->capabilities_json;
                    $savedCredential = $provider?->getRawOriginal('secret_ciphertext');
                    $eligible = $provider !== null
                        && $currentModel !== null
                        && $currentModel->provider_id === $provider->id
                        && $currentModel->enabled
                        && is_array($capabilities)
                        && in_array('image-generation', $capabilities, true)
                        && preg_match('/^[A-Za-z0-9_.:\\-]{1,90}$/D', (string) $currentModel->provider_model_id) === 1
                        && ! $provider->trashed()
                        && $provider->enabled
                        && $provider->health_status === 'healthy'
                        && $provider->provider_type === 'openai-compatible'
                        && is_string($savedCredential) && $savedCredential !== '';
                    if (! $eligible) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'model_id' => $locale === 'ar'
                                ? 'النموذج المحدد غير مؤهل حاليًا لتوليد الصور.'
                                : 'The selected model is not currently eligible for image generation.',
                        ]);
                    }

                    $today = now('UTC')->startOfDay();
                    $tomorrow = $today->copy()->addDay();
                    $spentToday = (int) \Illuminate\Support\Facades\DB::table('ai_usage')
                        ->where('provider_id', $provider->id)
                        ->where('created_at', '>=', $today)
                        ->where('created_at', '<', $tomorrow)
                        ->sum('cost_minor');
                    // Include unsettled reservations, less already-reported
                    // receipts, without counting that spend twice.
                    $outstanding = 0;
                    $pendingJobs = \Illuminate\Support\Facades\DB::table('ai_jobs')
                        ->where('provider_id', $provider->id)
                        ->whereIn('status', ['queued', 'retry', 'running'])
                        ->get(['id', 'reserved_budget_minor']);
                    foreach ($pendingJobs as $pendingJob) {
                        $recorded = (int) \Illuminate\Support\Facades\DB::table('ai_usage')
                            ->where('job_id', $pendingJob->id)->sum('cost_minor');
                        $outstanding += max(0, (int) $pendingJob->reserved_budget_minor - $recorded);
                    }
                    $remaining = max(0, (int) $provider->daily_budget_minor - $spentToday - $outstanding);
                    if ($estimate > $remaining) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'estimated_cost_minor' => $locale === 'ar'
                                ? 'الحجز المطلوب يتجاوز الميزانية اليومية المتاحة للمزود.'
                                : 'The requested reservation exceeds the provider daily budget still available.',
                        ]);
                    }

                    return app(\App\AI\EditorialJobDispatcher::class)->enqueue(
                        $revision, $provider, $currentModel->provider_model_id, true, $estimate
                    );
                }, 3
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return back()->withErrors([
                'revision_id' => $locale === 'ar'
                    ? 'المسودة تغيرت أو لم تعد مؤهلة. حدّث الصفحة وحاول من أحدث مسودة.'
                    : 'The draft changed or is no longer eligible. Refresh and use the latest draft.',
            ]);
        }

        return redirect()->route('admin.media.index', [
            'lang' => $locale,
        ])->with('status', $locale === 'ar'
            ? 'تمت إضافة مهمة توليد الصورة إلى الطابور الآمن: '.$jobId
            : 'Artwork generation queued safely: '.$jobId);
    })->middleware('throttle:10,1')->name('admin.media.queue');

    Route::post('/admin/ai/images/{asset}/approve', function (
        Request $request, \App\Models\Asset $asset
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'confirm_asset_key' => [
                'required', 'string', 'max:180',
                \Illuminate\Validation\Rule::in([$asset->asset_key]),
            ],
            'licensed_for_publication' => ['accepted'],
            'no_owner_impersonation' => ['accepted'],
        ]);

        try {
            $approved = \Illuminate\Support\Facades\DB::transaction(
                function () use ($asset, $request): bool {
                    $approved = app(\App\AI\EditorialArtworkRights::class)->approve(
                        $asset,
                        $request->user(),
                        $request->boolean('licensed_for_publication'),
                        $request->boolean('no_owner_impersonation')
                    );
                    if ($approved) {
                        \Illuminate\Support\Facades\DB::table('editorial_events')->insert([
                            'id' => (string) \Illuminate\Support\Str::uuid(),
                            'event_key' => 'media-rights-approved:'.\Illuminate\Support\Str::uuid(),
                            'article_id' => null,
                            'revision_no' => null,
                            'actor_id' => $request->user()->id,
                            'action' => 'media_rights_approved',
                            'reason' => 'asset:'.$asset->id,
                            'created_at' => now('UTC'),
                        ]);
                    }
                    return $approved;
                }, 3
            );
        } catch (\DomainException|\RuntimeException $exception) {
            return back()->withErrors([
                'approval' => $request->query('lang') === 'ar'
                    ? 'تعذر التحقق من أصل الصورة وحقوقها بأمان. بقيت الصورة خاصة وغير قابلة للنشر.'
                    : 'Artwork provenance and rights could not be verified safely. The asset remains private and unpublished.',
            ]);
        }

        if (! $approved) {
            return back()->withErrors([
                'approval' => $request->query('lang') === 'ar'
                    ? 'يلزم تأكيد الترخيص وعدم انتحال هوية المالك قبل النشر.'
                    : 'Publication licensing and no-owner-impersonation attestations are required.',
            ]);
        }

        return redirect()->route('admin.media.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
            ? 'تم التحقق من الأصل واعتماد حقوق النشر للصورة.'
            : 'Artwork provenance verified and publication rights approved.');
    })->middleware('throttle:5,1')->name('admin.media.approve');

    Route::post('/admin/ai/images/{asset}/revoke', function (
        Request $request, \App\Models\Asset $asset
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        // Revocation is a fail-closed one-way admin action. Record the
        // first state transition only; repeated revocation remains idempotent.
        \Illuminate\Support\Facades\DB::transaction(function () use ($asset, $request): void {
            $locked = \App\Models\Asset::query()->whereKey($asset->id)
                ->lockForUpdate()->firstOrFail();
            if ($locked->rights_status === 'revoked' && ! $locked->publishable) {
                return;
            }
            $locked->forceFill(['rights_status' => 'revoked', 'publishable' => false])->save();
            \Illuminate\Support\Facades\DB::table('editorial_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'event_key' => 'media-rights-revoked:'.\Illuminate\Support\Str::uuid(),
                'article_id' => null,
                'revision_no' => null,
                'actor_id' => $request->user()->id,
                'action' => 'media_rights_revoked',
                'reason' => 'asset:'.$locked->id,
                'created_at' => now('UTC'),
            ]);
        }, 3);

        return redirect()->route('admin.media.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
            ? 'تم إلغاء حقوق النشر العام؛ أزيلت أهلية الصورة للنشر.'
            : 'Media rights revoked; public eligibility removed.');
    })->middleware('throttle:10,1')->name('admin.media.revoke');
    // T020 ADMIN-07: bounded, persisted quality evidence only. Never render
    // article bodies, citation URLs, provider endpoints, secrets, payload
    // hashes, idempotency keys, prompts, result refs or private storage paths.
    Route::get('/admin/quality', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'drafts_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'jobs_page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'job_status' => ['sometimes', 'nullable', 'in:retry,failed'],
            'job_id' => ['sometimes', 'nullable', 'uuid'],
            'inspect_job' => ['sometimes', 'nullable', 'uuid'],
        ]);
        // HTML GET forms submit empty filter controls. Normalize optional
        // values after validation; never turn empty into WHERE id IS NULL.
        $jobStatus = $request->query('job_status') ?: '';
        $jobFilterId = $request->query('job_id') ?: '';
        $inspectJobId = $request->query('inspect_job') ?: '';

        $qualityRows = \App\Models\ArticleRevision::query()
            ->with(['article.heroAsset'])
            ->whereHas('article', fn ($article) => $article->where('status', 'draft'))
            ->whereRaw('article_revisions.revision_no = (SELECT MAX(latest.revision_no) FROM article_revisions AS latest WHERE latest.article_id = article_revisions.article_id)')
            // Only current revisions of draft articles are eligible; paging
            // keeps older publication blockers accessible without unbounded reads.
            ->orderByDesc('updated_at')->orderBy('id')
            ->paginate(25, ['*'], 'drafts_page')
            ->appends(array_filter([
                'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
                'jobs_page' => $request->query('jobs_page'),
                'job_status' => $jobStatus ?: null,
                'job_id' => $jobFilterId ?: null,
                'inspect_job' => $inspectJobId ?: null,
            ], static fn ($value) => $value !== null))
            ->through(function ($revision): array {
                $article = $revision->article;
                $validation = is_array($revision->validation_json)
                    ? $revision->validation_json : [];
                $imageRights = $article->hero_asset_id === null
                    || $article->heroAsset?->isPubliclyUsable() === true;

                return [
                    'article_id' => $article->id,
                    'revision_id' => $revision->id,
                    'title_en' => $article->title_en,
                    'title_ar' => $article->title_ar,
                    'revision_no' => $revision->revision_no,
                    'revision_status' => $revision->status,
                    'citations_passed' => ($validation['citations_passed'] ?? false) === true,
                    'technical_passed' => ($validation['technical_passed'] ?? false) === true,
                    'evidence_passed' => $revision->hasVerifiedEvidence(),
                    'image_rights_passed' => $imageRights,
                    'publication_ready' => $article->isReadyToPublish(),
                ];
            });

        $failedJobs = \Illuminate\Support\Facades\DB::table('ai_jobs as job')
            ->join('ai_providers as provider', 'provider.id', '=', 'job.provider_id')
            ->whereIn('job.status', ['retry', 'failed'])
            ->when($jobStatus !== '', static fn ($q) => $q->where('job.status', $jobStatus))
            ->when($jobFilterId !== '', static fn ($q) => $q->where('job.id', $jobFilterId))
            ->select([
                'job.id', 'job.job_type', 'job.status',
                'job.attempt_count', 'job.max_attempts',
                'job.next_attempt_at', 'job.last_error_code', 'job.updated_at',
                'provider.display_name as provider_name',
            ])
            // A hard 50-row cap hid older recoverable jobs from admins.
            // A stable tie-break prevents duplicate/missing rows across pages.
            ->orderByDesc('job.updated_at')->orderBy('job.id')
            ->paginate(25, ['*'], 'jobs_page')
            ->appends(array_filter([
                'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
                'drafts_page' => $request->query('drafts_page'),
                'job_status' => $jobStatus ?: null,
                'job_id' => $jobFilterId ?: null,
                'inspect_job' => $inspectJobId ?: null,
            ], static fn ($value) => $value !== null));

        // An inspection is a *read-only*, allowlisted view of stored
        // incidents. Never pass a raw AI job or checkpoint model to Blade.
        $inspectedJob = null;
        $checkpointRows = collect();
        $inspectionLeased = false;
        if ($inspectJobId !== '') {
            $inspectedJob = \Illuminate\Support\Facades\DB::table('ai_jobs as job')
                ->join('ai_providers as provider', 'provider.id', '=', 'job.provider_id')
                ->whereIn('job.status', ['failed', 'retry'])
                ->where('job.id', $inspectJobId)
                ->first([
                    'job.id', 'job.job_type', 'job.status',
                    'job.attempt_count', 'job.max_attempts',
                    'job.next_attempt_at', 'job.last_error_code',
                    'job.updated_at', 'provider.display_name as provider_name',
                ]);
            abort_if($inspectedJob === null, 404);
            $checkpointRows = \Illuminate\Support\Facades\DB::table('ai_job_steps')
                ->where('job_id', $inspectJobId)
                ->orderBy('position')->limit(20)
                ->get(['position', 'status', 'attempt_no', 'completed_at']);
            $inspectionLeased = \Illuminate\Support\Facades\DB::table('job_leases')
                ->where('job_id', $inspectJobId)->exists();
        }

        return view('admin.quality', compact(
            'qualityRows', 'failedJobs', 'jobStatus', 'jobFilterId',
            'inspectJobId', 'inspectedJob', 'checkpointRows', 'inspectionLeased'
        ));
    })->name('admin.quality.index');

    Route::post('/admin/quality/jobs/{job}/retry', function (Request $request, string $job) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'inspect_job' => ['sometimes', 'uuid'],
        ]);
        // The existing retry transaction remains the sole authority.
        // Optional navigation context may only point to this same job.
        $returnToInspection = $request->query('inspect_job') === $job;

        $retried = \Illuminate\Support\Facades\DB::transaction(function () use ($job): bool {
            $record = \Illuminate\Support\Facades\DB::table('ai_jobs')
                ->where('id', $job)->lockForUpdate()->first();
            if (! $record) {
                abort(404);
            }

            // "Retry now" accelerates only an already recoverable retry.
            // It never increases max_attempts or revives terminal/active work.
            if ($record->status !== 'retry'
                || (int) $record->attempt_count >= (int) $record->max_attempts
                || \Illuminate\Support\Facades\DB::table('job_leases')
                    ->where('job_id', $job)->exists()) {
                return false;
            }

            \Illuminate\Support\Facades\DB::table('ai_jobs')->where('id', $job)->update([
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
            return true;
        }, 3);

        if (! $retried) {
            return back()->withErrors([
                'retry' => $request->query('lang') === 'ar'
                    ? 'المهمة ليست في حالة إعادة محاولة قابلة للاسترداد، ولم يتم تغييرها.'
                    : 'The job is not in a recoverable retry state and was not changed.',
            ]);
        }

        return redirect()->route('admin.quality.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
            ...($returnToInspection ? ['inspect_job' => $job] : []),
        ])->with('status', $request->query('lang') === 'ar'
            ? 'أصبحت المهمة جاهزة لمحاولة العامل التالية ضمن حدودها الحالية.'
            : 'The job is ready for the next worker attempt within its existing limits.');
    })->whereUuid('job')->middleware('throttle:10,1')->name('admin.quality.retry');

});

Route::get('/github', function (Request $request, \App\Integrations\GitHub\PublicRepositoryDirectory $directory) {
    $repositories = $directory->repositories();
    // Query strings may be array-valued (q[]=... / language[]=...).
    // Casting those arrays raises a PHP warning and may turn a public GET
    // into a server error. Unknown shapes are neutral empty filters.
    $rawSearch = $request->query('q', '');
    $rawLanguage = $request->query('language', '');
    $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 80) : '';
    $language = is_string($rawLanguage) ? mb_substr(trim($rawLanguage), 0, 60) : '';

    $languages = collect($repositories)
        ->filter(fn (array $repo) => ($repo['status'] ?? null) === 'verified' && ! empty($repo['language']))
        ->pluck('language')->unique()->sort()->values()->all();

    $filtered = array_values(array_filter($repositories, static function (array $repo) use ($search, $language): bool {
        $matchesSearch = $search === '' || str_contains(
            mb_strtolower(($repo['full_name'] ?? '').' '.($repo['description'] ?? '')),
            mb_strtolower($search)
        );
        $matchesLanguage = $language === '' || (($repo['language'] ?? null) === $language);

        return $matchesSearch && $matchesLanguage;
    }));

    return view('github.index', compact('repositories', 'filtered', 'languages', 'search', 'language'));
})->name('public.github');

foreach (['about', 'expertise', 'hire'] as $professionalPage) {
    Route::get('/'.$professionalPage, function (Request $request) use ($professionalPage) {
        $profile = \App\Models\Profile::query()->published()
            ->whereHas('user', fn ($user) => $user->where('status', 'active')
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
            ->first();
        $positions = collect();
        $certifications = collect();
        $skills = collect();
        $approvedClaims = [];
        $rawTrack = $request->query()['track'] ?? 'all';
        $track = is_string($rawTrack) ? $rawTrack : 'all';
        $tracks = [
            'database' => ['Database & Reliability'],
            'software' => ['Software & Integration', 'Infrastructure & Security'],
            'delivery' => ['Project Delivery'],
        ];
        // WEB-03 supports filtering *on the expertise page*, not solely
        // navigating to the recruiter page. Accept canonical category keys
        // only; arbitrary query strings are never interpolated into SQL.
        $expertiseCategories = [
            'database' => 'Database & Reliability',
            'integration' => 'Software & Integration',
            'security' => 'Infrastructure & Security',
            'delivery' => 'Project Delivery',
        ];
        $rawExpertiseCategory = $request->query()['category'] ?? '';
        $expertiseCategory = is_string($rawExpertiseCategory)
            && isset($expertiseCategories[$rawExpertiseCategory]) ? $rawExpertiseCategory : '';
        if (! isset($tracks[$track])) {
            $track = 'all';
        }

        if ($profile) {
            if ($professionalPage === 'about') {
                $positions = \App\Models\CareerPosition::query()->publiclyVerified()
                    ->where('profile_id', $profile->id)->orderByDesc('started_on')->get();
                $certifications = \App\Models\Certification::query()->publiclyVerified()
                    ->where('profile_id', $profile->id)->orderBy('title')->get();
            } else {
                $skillsQuery = \App\Models\Skill::query()->withVerifiedEvidence()
                    ->whereIn('id', \Illuminate\Support\Facades\DB::table('profile_skill')
                        ->where('profile_id', $profile->id)->select('skill_id'));
                if ($professionalPage === 'hire' && $track !== 'all') {
                    $skillsQuery->whereIn('category', $tracks[$track]);
                }
                if ($professionalPage === 'expertise' && $expertiseCategory !== '') {
                    $skillsQuery->where('category', $expertiseCategories[$expertiseCategory]);
                }
                // Keep the browser CV bounded to the same scale as the PDF.
        // Every returned skill still requires owner linkage and evidence.
        $skills = $skillsQuery->orderBy('category')->orderBy('name_en')->limit(250)->get();
            }
        }

        // Reuse the public API's active-owner, publication and safe-URL gates;
        // never expose raw claim records or unchecked user-supplied URLs.
        if ($profile && in_array($professionalPage, ['about', 'hire'], true)) {
            $approvedClaims = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
                ->profile($request)->getData(true)['data']['claims'] ?? [];
        }

        return view('public.professional', [
            'page' => $professionalPage,
            // The download endpoint returns 404 without a currently
            // published/active owner. Hide its CTA in the same state.
            'hasPublishedOwnerCv' => $profile !== null,
            'positions' => $positions,
            'certifications' => $certifications,
            'skills' => $skills,
            'approvedClaims' => $approvedClaims,
            'track' => $track,
            'expertiseCategories' => $expertiseCategories,
            'expertiseCategory' => $expertiseCategory,
        ]);
    })->name('public.'.$professionalPage);
}


/*
 * T009: source-verified project presentation. Reuse the canonical public API
 * serializer so public web cannot bypass the backend evidence URL policy.
 */
Route::get('/projects', function (Request $request) {
    // The public route IS the real database-backed portfolio; no mockup,
    // preview flag or second unreviewed set of projects is served here.
    $rawCategory = $request->query('category', 'featured');
    $portfolioCategory = is_string($rawCategory) && in_array($rawCategory, [
        'featured', 'government', 'education', 'workflows', 'ai', 'enterprise',
    ], true) ? $rawCategory : 'featured';

    $rawSearch = $request->query('q', '');
    $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 80) : '';
    // Never interpolate untrusted filter values into the query or reveal
    // drafts: the public evidence gate remains authoritative.
    $rawStatus = $request->query('status', '');
    $status = is_string($rawStatus)
        && in_array($rawStatus, ['completed', 'in_progress', 'research', 'archived'], true)
            ? $rawStatus : '';
    $query = \App\Models\Project::query()->publiclyVerified();
    if ($portfolioCategory !== 'featured') {
        $query->where('portfolio_category', $portfolioCategory);
    }
    if ($status !== '') {
        $query->where('status', $status);
    }

    if ($search !== '') {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $query->where(function ($q) use ($term): void {
            $q->whereRaw("projects.title LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("projects.summary_en LIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("projects.summary_ar LIKE ? ESCAPE '!'", [$term]);
        });
    }

    // Match the canonical public API policy: hidden/unsafe evidence must
    // never consume page slots or inflate public pagination counts.
    $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10000],
    ]) ?: 1;
    $perPage = 9;
    $offset = ($page - 1) * $perPage;
    $totalSafe = 0;
    $selected = [];
    $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
    foreach ($query->with(['evidence' => static fn ($evidence) => $evidence
        ->where('visibility', 'public')
        ->whereNotNull('verified_at')->where('verified_at', '<=', now())
        ->orderBy('id')->limit(50)])
        ->orderByDesc('portfolio_featured')->orderByDesc('published_at')
        ->orderBy('id')->lazy(100) as $record) {
        // A verified database row is not a usable public project when its
        // stored slug cannot reach the canonical /projects/{slug} route.
        // Skip these BEFORE counting pagination slots, just as Home and
        // sitemap do; never expose dead-end links to visitors.
        if (preg_match('/\\A[A-Za-z0-9-]+\\z/D', $record->slug) !== 1) {
            continue;
        }
        $project = $api->publicProjectData($record, $request);
        if (empty($project['evidence_urls'])) {
            continue;
        }
        if ($totalSafe >= $offset && count($selected) < $perPage) {
            $selected[] = $project;
        }
        $totalSafe++;
    }
    $projects = collect($selected);
    $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
        $projects, $totalSafe, $perPage, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]
    );

    return view('public.projects', compact(
        'projects', 'paginated', 'search', 'status', 'portfolioCategory'
    ));
})->name('public.projects');

Route::get('/projects/{slug}', function (Request $request, string $slug) {
    $record = \App\Models\Project::query()->publiclyVerified()
        ->where('slug', $slug)->firstOrFail();
    $project = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
        ->publicProjectData($record, $request);
    // A project without a usable public evidence link is not a public case study.
    abort_if(empty($project['evidence_urls']), 404);

    return view('public.case-study', compact('project'));
})->where('slug', '[a-zA-Z0-9-]+')->name('public.project');

Route::get('/resume', function (Request $request) {
    // CV variants change emphasis, not the owner's verified career history.
    // Unknown and array-valued parameters cannot select unapproved content.
    $requestedTrack = $request->query()['track'] ?? 'all';
    $tracks = [
        'all' => [],
        'database' => ['Database & Reliability'],
        'software' => ['Software & Integration', 'Infrastructure & Security'],
        'delivery' => ['Project Delivery'],
    ];
    $track = is_string($requestedTrack) && array_key_exists($requestedTrack, $tracks)
        ? $requestedTrack : 'all';

    $profile = \App\Models\Profile::query()->published()
        ->whereHas('user', fn ($user) => $user->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
        ->first();

    $positions = $profile
        ? \App\Models\CareerPosition::query()->publiclyVerified()
            ->where('profile_id', $profile->id)->orderByDesc('started_on')->limit(250)->get()
        : collect();
    $certifications = $profile
        ? \App\Models\Certification::query()->publiclyVerified()
            ->where('profile_id', $profile->id)->orderBy('title')->limit(250)->get()
        : collect();

    $skills = collect();
    if ($profile) {
        $skillsQuery = \App\Models\Skill::query()->withVerifiedEvidence()
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('profile_skill')
                ->where('profile_id', $profile->id)->select('skill_id'));
        if ($track !== 'all') {
            $skillsQuery->whereIn('category', $tracks[$track]);
        }
        $skills = $skillsQuery->orderBy('category')->orderBy('name_en')->get();
    }

    return view('public.resume', compact('profile', 'positions', 'certifications', 'skills', 'track'));
})->name('public.resume');

/*
 * WEB-11: downloadable PDF of the SAME evidence-gated public CV data.
 * Only embedded escaped text is rendered: no remote assets, HTML images,
 * submitted URLs, scripts, or privately held evidence reach the renderer.
 * This is not the browser's print-to-PDF approximation.
 */
Route::get('/resume/pdf', function (Request $request) {
    $input = $request->validate([
        'lang' => ['sometimes', 'in:ar,en'],
        'track' => ['sometimes', \Illuminate\Validation\Rule::in([
            'all', 'database', 'software', 'delivery',
        ])],
    ]);
    $locale = $input['lang'] ?? 'en';
    $track = $input['track'] ?? 'all';
    $tracks = [
        'all' => [],
        'database' => ['Database & Reliability'],
        'software' => ['Software & Integration', 'Infrastructure & Security'],
        'delivery' => ['Project Delivery'],
    ];
    $profile = \App\Models\Profile::query()->published()
        ->whereHas('user', fn ($q) => $q->where('status', 'active')
            ->whereHas('roles', fn ($r) => $r->where('name', 'owner')))
        ->firstOrFail();

    $positions = \App\Models\CareerPosition::query()->publiclyVerified()
        ->where('profile_id', $profile->id)
        ->orderByDesc('started_on')->limit(250)->get();
    $certifications = \App\Models\Certification::query()->publiclyVerified()
        ->where('profile_id', $profile->id)->orderBy('title')->limit(250)->get();
    $skillsQuery = \App\Models\Skill::query()->withVerifiedEvidence()
        ->whereIn('id', \Illuminate\Support\Facades\DB::table('profile_skill')
            ->where('profile_id', $profile->id)->select('skill_id'));
    if ($track !== 'all') {
        $skillsQuery->whereIn('category', $tracks[$track]);
    }
    $skills = $skillsQuery->orderBy('category')->orderBy('name_en')->limit(250)->get();

    // Do not pass user-controlled HTML or URL references to the PDF engine.
    // HTML-escape every value and use only a fixed, embedded stylesheet.
    $esc = static fn ($value): string => htmlspecialchars(
        mb_substr((string) ($value ?? ''), 0, 320),
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
    );
    $ar = $locale === 'ar';
    $title = $ar ? 'السيرة المهنية العامة' : 'Public professional resume';
    $html = '<style>body{font-family:freeserif;font-size:12pt;color:#142E4D}'
        .'h1{color:#163B66;font-size:20pt}h2{color:#163B66;font-size:15pt}'
        .'p{margin:3mm 0}.item{margin-bottom:5mm}</style>'
        .'<h1>'.$esc($title).'</h1><h2>'.$esc($profile->public_name).'</h2>'
        .'<p>'.$esc($ar
            ? 'تعرض هذه الوثيقة البيانات المنشورة والمتحقق من أدلتها فقط.'
            : 'This document includes only evidence-verified public records.').'</p>';
    $html .= '<h2>'.$esc($ar ? 'الخبرات المعتمدة' : 'Verified experience').'</h2>';
    foreach ($positions as $entry) {
        $period = ($entry->started_on?->format('Y') ?? '')
            .' – '.($entry->ended_on?->format('Y') ?? ($ar ? 'حتى الآن' : 'Present'));
        $html .= '<div class="item"><b>'.$esc($ar ? $entry->role_ar : $entry->role_en)
            .'</b><p>'.$esc($entry->org_name).' — '.$esc($period).'</p></div>';
    }
    if ($positions->isEmpty()) {
        $html .= '<p>'.$esc($ar ? 'لا توجد خبرات معتمدة بعد.' : 'No verified experience available.').'</p>';
    }
    $html .= '<h2>'.$esc($ar ? 'الشهادات المتحقق منها' : 'Verified credentials').'</h2>';
    foreach ($certifications as $entry) {
        $html .= '<div class="item"><b>'.$esc($entry->title).'</b>'
            .'<p>'.$esc($entry->issuer).'</p></div>';
    }
    if ($certifications->isEmpty()) {
        $html .= '<p>'.$esc($ar ? 'لا توجد شهادات معتمدة بعد.' : 'No verified credentials available.').'</p>';
    }
    $html .= '<h2>'.$esc($ar ? 'المهارات المهنية الموثقة' : 'Verified skills').'</h2>';
    foreach ($skills as $entry) {
        $html .= '<div class="item"><b>'.$esc($ar ? $entry->name_ar : $entry->name_en)
            .'</b><p>'.$esc($entry->category).'</p></div>';
    }
    if ($skills->isEmpty()) {
        $html .= '<p>'.$esc($ar ? 'لا توجد مهارات موثقة لهذا المسار بعد.'
            : 'No verified skills for this focus yet.').'</p>';
    }

    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(16, 18, 16);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetCreator('WalidSite');
    $pdf->SetTitle($title);
    $pdf->AddPage();
    $pdf->setRTL($ar);
    // FreeSerif is bundled with the installed TCPDF package and supports Arabic.
    $pdf->SetFont('freeserif', '', 12);
    $pdf->writeHTML($html, true, false, true, false, '');

    $bytes = $pdf->Output('', 'S');
    $filename = 'waleed-verified-cv-'.$track.'-'.$locale.'.pdf';
    return response($bytes, 200, [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('public.resume.pdf')->middleware('throttle:20,1');

/*
 * Secure-by-default contact flow. The recipient is intentionally not stored
 * in source or exposed to the browser; activation requires verified mail
 * delivery and a configured contact recipient on the server.
 */
Route::get('/contact', function (Request $request) {
    $request->validate(['lang' => ['sometimes', 'in:ar,en']]);

    // WEB-08: a report link is context, not authority. Display only an
    // existing, currently public article with its pinned evidence gate intact.
    // Ignore malformed/array-valued query input rather than echoing it.
    $rawArticle = $request->query()['article'] ?? null;
    $reportArticle = null;
    if (is_string($rawArticle)
        && preg_match('/\A[A-Za-z0-9-]{1,180}\z/D', $rawArticle) === 1) {
        $candidate = \App\Models\Article::query()->publiclyVisible()
            ->where('slug', $rawArticle)->first();
        if ($candidate !== null && $candidate->publicRevision() !== null) {
            $reportArticle = $candidate;
        }
    }
    // WEB-05 -> WEB-14: contextual project inquiries are only allowed for
    // currently public, evidence-verified, route-reachable case studies.
    // Never display a stored project title based only on an untrusted slug.
    // When both keys are supplied, an article request takes precedence and a
    // project cannot override or contaminate its subject.
    $reportProject = null;
    $rawProject = $request->query()['project'] ?? null;
    if (! array_key_exists('article', $request->query())
        && is_string($rawProject)
        && preg_match('/\\A[A-Za-z0-9-]{1,120}\\z/D', $rawProject) === 1) {
        $candidate = \App\Models\Project::query()->publiclyVerified()
            ->where('slug', $rawProject)->first();
        if ($candidate !== null) {
            $publicData = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
                ->publicProjectData($candidate, $request);
            if (! empty($publicData['evidence_urls'])) {
                $reportProject = $publicData;
            }
        }
    }

    // Limit the suggestion to the existing 140-character subject field.
    // Visitors can edit it; their inquiry is not verified project evidence.
    $locale = $request->query('lang') === 'ar' ? 'ar' : 'en';
    $rawTopic = $request->query()['topic'] ?? null;
    // Only one allowlisted privacy request topic may prefill an editable
    // subject. Never reflect arbitrary input or override verified context.
    $privacyTopic = $reportArticle === null && $reportProject === null
        && ! array_key_exists('article', $request->query())
        && ! array_key_exists('project', $request->query())
        && is_string($rawTopic)
        && in_array($rawTopic, ['privacy', 'privacy-access', 'privacy-correction', 'privacy-export', 'privacy-deletion', 'privacy-notifications'], true);
    $privacySubjects = [
        'privacy' => ['طلب متعلق بالخصوصية أو البيانات', 'Privacy or personal data request'],
        'privacy-access' => ['طلب الاطلاع على البيانات', 'Personal data access request'],
        'privacy-correction' => ['طلب تصحيح البيانات', 'Personal data correction request'],
        'privacy-export' => ['طلب تصدير البيانات', 'Personal data export request'],
        'privacy-deletion' => ['طلب حذف البيانات', 'Personal data deletion request'],
        'privacy-notifications' => ['طلب إلغاء موافقة الإشعارات', 'Notification consent revocation request'],
    ];
    // Canonical privacy links use topic=privacy&request=<allowlisted kind>.
    // Legacy topic=privacy-<kind> links remain valid for existing bookmarks.
    $rawRequest = $request->query()['request'] ?? null;
    $rightsSubjects = [
        'access' => ['طلب الاطلاع على بياناتي', 'Personal data access request'],
        'correction' => ['طلب تصحيح بياناتي', 'Personal data correction request'],
        'export' => ['طلب تصدير بياناتي', 'Personal data export request'],
        'deletion' => ['طلب حذف بياناتي', 'Personal data deletion request'],
        'revoke' => ['طلب سحب الموافقة على الإشعارات', 'Notification consent revocation inquiry'],
        'notifications' => ['طلب إلغاء الموافقة على الإشعارات', 'Privacy notification consent revocation request'],
    ];
    $privacyRight = $privacyTopic && $rawTopic === 'privacy'
        && is_string($rawRequest) && array_key_exists($rawRequest, $rightsSubjects)
        ? $rawRequest : null;
    if ($privacyRight === null && $privacyTopic && $rawTopic !== 'privacy') {
        $privacyRight = substr($rawTopic, strlen('privacy-'));
    }
    $suggestedSubject = $reportArticle !== null
        ? ($locale === 'ar' ? 'ملاحظة بشأن المقال: ' : 'Article issue: ')
            .mb_substr($reportArticle->slug, 0, 100)
        : ($reportProject !== null
            ? ($locale === 'ar' ? 'استفسار عن المشروع: ' : 'Project inquiry: ')
                .mb_substr($reportProject['slug'], 0, 100)
            : ($privacyTopic ? ($rawTopic === 'privacy' && $privacyRight !== null
                ? $rightsSubjects[$privacyRight][$locale === 'ar' ? 0 : 1]
                : $privacySubjects[$rawTopic][$locale === 'ar' ? 0 : 1]) : ''));

    $recipient = config('mail.contact_recipient');
    $available = is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)
        && in_array(config('mail.default'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true);

    return response()->view('public.contact', compact(
        'available', 'reportArticle', 'reportProject', 'suggestedSubject', 'privacyRight'
    ))->header('Cache-Control', 'private, no-store')
        ->header('X-Content-Type-Options', 'nosniff');
})->name('public.contact');

Route::post('/contact', function (Request $request) {
    $recipient = config('mail.contact_recipient');
    abort_unless(
        is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)
        && in_array(config('mail.default'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true),
        503,
        'Contact delivery has not been configured.'
    );

    // Drop obvious automated submissions before sender-field validation.
    // A populated hidden website field is not a legitimate inquiry and must
    // never reveal field errors, trigger a success notice, or reach mail.
    $honeypot = $request->input('website');
    // The hidden field is a single text input. Treat arrays/objects and all
    // other unexpected types as automated submissions as well: otherwise a
    // crafted website[]=... bypasses the early exit and leaks form errors.
    if ($honeypot !== null && (!is_string($honeypot) || trim($honeypot) !== '')) {
        return redirect()->route('public.contact', ['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']);
    }

    $arabicValidation = $request->query('lang') === 'ar';
    $contactMessages = $arabicValidation ? [
        'required' => 'حقل :attribute مطلوب.',
        'string' => 'يجب أن يكون :attribute نصًا.',
        'email' => 'يجب إدخال بريد إلكتروني صحيح.',
        'min' => 'يجب ألا يقل :attribute عن :min أحرف.',
        'max' => 'يجب ألا يزيد :attribute عن :max أحرف.',
        'boolean' => 'قيمة :attribute غير صحيحة.',
        'accepted' => 'يجب الموافقة على :attribute.',
        'not_regex' => 'يحتوي :attribute على أحرف غير مسموحة.',
    ] : [];
    $contactAttributes = $arabicValidation ? [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'subject' => 'الموضوع',
        'message' => 'الرسالة',
        'privacy_consent' => 'سياسة الخصوصية',
        'website' => 'الموقع',
    ] : [];

    $input = $request->validate([
        'name' => ['required', 'string', 'min:2', 'max:100', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
        'email' => ['required', 'email:rfc', 'max:254'],
        'subject' => ['required', 'string', 'min:3', 'max:140', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
        'message' => ['required', 'string', 'min:10', 'max:3000',
            'not_regex:/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u'],
        'privacy_consent' => ['required', 'boolean', 'accepted'],
        'website' => ['nullable', 'string', 'max:200'],
    ], $contactMessages, $contactAttributes);

    $body = "Name: {$input['name']}\nEmail: {$input['email']}\n\n{$input['message']}";
    try {
        // Use a text-only Mailable so Laravel's transport and test fake
        // both observe the exact delivery attempt. Never expose the recipient.
        \Illuminate\Support\Facades\Mail::to($recipient)->send(
            (new \Illuminate\Mail\Mailable)
                ->text('mail.contact-notification')
                ->with(['messageBody' => $body])
                ->replyTo($input['email'])
                ->subject('WalidSite contact: '.$input['subject'])
        );
    } catch (\Throwable $exception) {
        // Mail transport errors can embed SMTP passwords or the message body.
        // Never report the raw exception; log only its non-sensitive class.
        \Illuminate\Support\Facades\Log::warning('public.contact.web.delivery_failed', [
            'type' => $exception::class,
        ]);
        abort(503, 'Contact delivery is temporarily unavailable.');
    }

    return redirect()->route('public.contact', ['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en'])
        ->with('contact_delivered', true);
})->middleware('throttle:4,1')->name('public.contact.submit');


/*
 * T012 public technical blog: only approved pinned revisions can render.
 * All Markdown is sanitized by the view; unpublished drafts stay private.
 */
Route::get('/blog', function (Request $request) {
    $input = $request->validate([
        'q' => ['sometimes', 'string', 'max:80'],
        'category' => ['sometimes', 'string', 'max:110', 'regex:/^[a-z0-9-]+$/'],
        'lang' => ['sometimes', 'in:en,ar'],
        'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
    ]);
    $search = trim($input['q'] ?? '');
    $category = $input['category'] ?? '';
    $query = \App\Models\Article::query()->publiclyVisible();

    if ($search !== '') {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        // Match only the immutable published revision pinned to this article.
        // A newer draft must never make an otherwise unrelated title appear
        // searchable or reveal words from unpublished professional material.
        $query->where(fn ($builder) => $builder
            ->whereRaw("articles.title_en LIKE ? ESCAPE '!'", [$term])
            ->orWhereRaw("articles.title_ar LIKE ? ESCAPE '!'", [$term])
            ->orWhereHas('revisions', fn ($revision) => $revision
                ->where('status', 'validated')
                ->whereColumn('article_revisions.revision_no', 'articles.published_revision_no')
                ->whereRaw("article_revisions.body_md LIKE ? ESCAPE '!'", [$term])));
    }
    if ($category !== '') {
        $query->whereHas('categories', fn ($builder) => $builder->where('slug', $category));
    }

    // Blog filters are public discovery, so a category may only be listed
    // while at least one of its articles passes the definitive current
    // citation/media-rights check. The cheap SQL publication scope alone
    // includes withdrawn evidence and would expose misleading taxonomy.
    // Eager-load category IDs in bounded DB chunks; keep only distinct IDs
    // (not all articles) in memory and make the final list deterministic.
    $publicCategoryIds = [];
    foreach (\App\Models\Article::query()->publiclyVisible()
        ->with('categories:id')->orderBy('id')->lazy(100) as $candidate) {
        if (preg_match('/\A[A-Za-z0-9-]+\z/D', $candidate->slug) !== 1
            || $candidate->publicRevision() === null) {
            continue;
        }
        foreach ($candidate->categories as $candidateCategory) {
            $publicCategoryIds[$candidateCategory->id] = true;
        }
    }
    $categories = $publicCategoryIds === []
        ? collect()
        : \App\Models\Category::query()
            ->whereIn('id', array_keys($publicCategoryIds))
            ->orderBy('slug')->get();
    // A SQL-published article can lose citation or media rights after
    // publication. Apply the authoritative pinned revision check BEFORE
    // counting/page slicing; otherwise withdrawn rows create sparse pages
    // and reveal bogus totals. Cursor-like lazy chunks bound query memory.
    $perPage = 9;
    $page = (int) ($input['page'] ?? 1);
    $offset = ($page - 1) * $perPage;
    $total = 0;
    $entries = collect();
    foreach ($query->orderByDesc('published_at')->orderBy('id')->lazy(100) as $article) {
        $revision = $article->publicRevision();
        if (preg_match('/\A[A-Za-z0-9-]+\z/D', $article->slug) !== 1
            || $revision === null) {
            continue;
        }
        if ($total >= $offset && $entries->count() < $perPage) {
            $entries->push(['article' => $article, 'revision' => $revision]);
        }
        $total++;
    }
    $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
        $entries, $total, $perPage, $page,
        ['path' => $request->url(), 'query' => $request->query()]
    );

    return view('public.blog', compact('entries', 'paginated', 'categories', 'search', 'category'));
})->name('public.blog')->middleware('throttle:60,1');

Route::get('/blog/{slug}', function (Request $request, string $slug) {
    $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
    $article = \App\Models\Article::query()->publiclyVisible()
        ->where('slug', $slug)->firstOrFail();
    $revision = $article->publicRevision();
    abort_if($revision === null, 404);

    // WEB-08: only show same-category articles that independently pass the
    // public revision evidence/rights gate. Never leak draft or revoked titles.
    $related = collect();
    $categoryIds = $article->categories()->pluck('categories.id')->all();
    if ($categoryIds !== []) {
        // Keep scanning bounded SQL chunks until three *actually verified*
        // neighbours are found. A revoked prefix must not starve later links.
        foreach (\App\Models\Article::query()->publiclyVisible()
            ->where('articles.id', '!=', $article->id)
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categoryIds))
            ->orderByDesc('articles.published_at')->orderBy('articles.id')
            ->lazy(100) as $candidate) {
            if (preg_match('/\A[A-Za-z0-9-]+\z/D', $candidate->slug) !== 1
            || $candidate->publicRevision() === null) {
                continue;
            }
            $related->push($candidate);
            if ($related->count() === 3) {
                break;
            }
        }
    }

    return view('public.article', compact('article', 'revision', 'related'));
})->where('slug', '[a-zA-Z0-9-]+')->name('public.article')->middleware('throttle:60,1');

/* T012 additive LAB + SEARCH: never show unreviewed or private data. */
Route::get('/lab', function (Request $request) {
    // Reuse the canonical W1 publication/revocation gates. Independently
    // scanning raw runbook rows here would weaken immutable review rules.
    $catalog = app(\App\Http\Controllers\Api\PublicLabController::class)
        ->index($request)->getData(true)['data'];
    $articles = collect($catalog['articles']);
    $runbooks = collect($catalog['runbooks']);

    return response()->view('public.lab', compact('articles', 'runbooks'))
        ->header('Cache-Control', 'no-store');
})->name('public.lab')->middleware('throttle:60,1');

// WEB-09: page through every currently approved immutable runbook, not just
// the fifteen-item lab homepage preview. Reuse the W1 API verification and
// pagination contract instead of querying (or counting) draft database rows.
Route::get('/lab/runbooks', function (Request $request) {
    $request->validate([
        'lang' => ['sometimes', 'in:ar,en'],
        'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
    ]);
    $catalog = app(\App\Http\Controllers\Api\PublicRunbookController::class)
        ->index($request)->getData(true);
    $runbooks = collect($catalog['data'] ?? []);
    $page = (int) $catalog['meta']['current_page'];
    $hasMore = (bool) $catalog['meta']['has_more_pages'];

    return response()->view('public.lab-runbooks', compact('runbooks', 'page', 'hasMore'))
        ->header('Cache-Control', 'no-store');
})->name('public.lab.runbooks')->middleware('throttle:60,1');

Route::get('/lab/runbooks/{id}', function (Request $request, string $id) {
    $input = $request->validate([
        'lang' => ['sometimes', 'in:ar,en'],
        // Carry the catalog location back to the user; never relax the
        // immutable snapshot read gate or accept arbitrary return URLs.
        'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
    ]);
    $libraryPage = (int) ($input['page'] ?? 1);
    // The detail is checked afresh on every read: a changed version, title,
    // review timestamp or checksum must fail closed with the API's own 404.
    $runbook = app(\App\Http\Controllers\Api\PublicRunbookController::class)
        ->show($id)->getData(true)['data'];

    return response()->view('public.lab-runbook', compact('runbook', 'libraryPage'))
        ->header('Cache-Control', 'no-store');
})->whereUuid('id')->name('public.lab.runbook')->middleware('throttle:60,1');

Route::get('/search', function (Request $request) {
    $input = $request->validate([
        'q' => ['sometimes', 'string', 'max:80'],
        'lang' => ['sometimes', 'in:ar,en'],
        'type' => ['sometimes', 'in:all,articles,projects'],
        'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
    ]);
    $search = trim($input['q'] ?? '');
    $type = $input['type'] ?? 'all';
    $page = (int) ($input['page'] ?? 1);
    $perPage = 10;
    $offset = ($page - 1) * $perPage;
    $articles = collect();
    $projects = collect();
    $articleTotal = 0;
    $projectTotal = 0;

    if ($search !== '') {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        // Only definitive, currently published content contributes to totals
        // or page offsets. SQL-visible but rights-revoked entries never occupy
        // a result slot or create a misleading extra page.
        if ($type !== 'projects') {
            foreach (\App\Models\Article::query()->publiclyVisible()
                ->where(fn ($builder) => $builder
                    ->whereRaw("articles.title_en LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("articles.title_ar LIKE ? ESCAPE '!'", [$term])
                    ->orWhereHas('revisions', fn ($revision) => $revision
                        ->where('status', 'validated')
                        ->whereColumn('article_revisions.revision_no', 'articles.published_revision_no')
                        ->whereRaw("article_revisions.body_md LIKE ? ESCAPE '!'", [$term])))
                ->orderBy('id')->lazy(100) as $article) {
                if (preg_match('/\A[A-Za-z0-9-]+\z/D', $article->slug) !== 1
                    || $article->publicRevision() === null) {
                    continue;
                }
                if ($articleTotal >= $offset && $articles->count() < $perPage) {
                    $articles->push($article);
                }
                $articleTotal++;
            }
        }

        if ($type !== 'articles') {
            $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
            foreach (\App\Models\Project::query()->publiclyVerified()
                ->where(fn ($builder) => $builder
                    ->whereRaw("projects.title LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("projects.summary_en LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("projects.summary_ar LIKE ? ESCAPE '!'", [$term]))
                ->orderBy('id')->lazy(100) as $record) {
                $project = $api->publicProjectData($record, $request);
                if (empty($project['evidence_urls'])) {
                    continue;
                }
                if ($projectTotal >= $offset && $projects->count() < $perPage) {
                    $projects->push($project);
                }
                $projectTotal++;
            }
        }
    }

    // Both content types are independently page-sized; mixed results never
    // starve one verified type or expose unreviewed record counts.
    $totalPages = max(1, (int) ceil(max($articleTotal, $projectTotal) / $perPage));
    abort_if($page > $totalPages, 404);

    // Report totals counted AFTER publication/revocation checks, not only the
    // current ten-row slice. Never cache revocable public search evidence.
    return response()->view('public.search', compact('search', 'articles', 'projects',
        'type', 'page', 'totalPages', 'articleTotal', 'projectTotal'))
        ->header('Cache-Control', 'no-store');
})->name('public.search')->middleware('throttle:60,1');



/*
 * ADMIN-08..11: server-rendered admin operations. The bearer Admin API is
 * deliberately not called with a browser session. Both surfaces require their
 * own authorization, and neither trusts client-supplied GitHub URLs or metrics.
 */
Route::middleware('auth:web')->prefix('admin')->group(function (): void {
    Route::get('/github', function (Request $request, \App\Integrations\GitHub\PublicRepositoryDirectory $directory) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'repo' => ['sometimes', 'string', 'max:140'],
        ]);
        $repos = collect($directory->repositories());
        // Inspect only metadata that has ALREADY passed the public allowlist
        // and live verification gate. A private/missing repo is indistinguishable
        // from an unknown name; never render the requested slug unverified.
        $selectedRepo = null;
        if (isset($input['repo'])) {
            $selectedRepo = $repos->first(static fn (array $item): bool =>
                ($item['status'] ?? null) === 'verified'
                && is_string($item['full_name'] ?? null)
                && strcasecmp($item['full_name'], $input['repo']) === 0
            );
            abort_if($selectedRepo === null, 404);
        }
        return response()->view('admin.operations', [
            'section' => 'github',
            'repos' => $repos,
            'selectedRepo' => $selectedRepo,
            'configuredCount' => count($directory->configuredSlugs()),
            'githubProposals' => \App\Models\GithubAllowlistProposal::query()
                ->orderByDesc('created_at')->orderBy('id')->paginate(20),
            'canReviewGithub' => $request->user()->hasRole('owner'),
        ])->header('Cache-Control', 'no-store, private');
    })->name('admin.operations.github')->middleware('throttle:30,1');

    // ADMIN-08 real web-session forms use the SAME workflow as the bearer
    // API. No synthetic app-wide token, no duplicate approval policy.
    Route::post('/github/proposals', function (
        Request $request, \App\Domain\GitHub\GithubProposalWorkflow $workflow
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        if (array_diff(array_keys($request->request->all()),
            ['_token', 'repository_slug']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'repository_slug' => 'Unexpected form fields.',
            ]);
        }
        $input = $request->validate(['repository_slug' => ['required', 'string', 'max:140']]);
        $userId = $request->user()->getKey();
        $workflow->submit(static function (bool $lock) use ($userId): \App\Models\User {
            $query = \App\Models\User::query()->whereKey($userId);
            $actor = ($lock ? $query->lockForUpdate() : $query)->first();
            abort_unless($actor?->canAccessAdmin(), 403);
            return $actor;
        }, $input['repository_slug']);

        return redirect()->route('admin.operations.github', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Repository proposal submitted for independent review.');
    })->name('admin.operations.github.propose')->middleware('throttle:6,1');

    Route::post('/github/proposals/{id}/decision', function (
        Request $request, string $id,
        \App\Domain\GitHub\GithubProposalWorkflow $workflow
    ) {
        abort_unless($request->user()?->canAccessAdmin()
            && $request->user()->hasRole('owner'), 403);
        if (array_diff(array_keys($request->request->all()),
            ['_token', 'decision', 'reason']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'decision' => 'Unexpected form fields.',
            ]);
        }
        $input = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['sometimes', 'in:source_verified,not_public,owner_policy,out_of_scope'],
        ]);
        $ownerId = $request->user()->getKey();
        $workflow->decide(static function (bool $lock) use ($ownerId): \App\Models\User {
            $query = \App\Models\User::query()->whereKey($ownerId);
            $owner = ($lock ? $query->lockForUpdate() : $query)->first();
            abort_unless($owner?->canAccessAdmin() && $owner->hasRole('owner'), 403);
            return $owner;
        }, $id, $input['decision'], $input['reason'] ?? null);

        return redirect()->route('admin.operations.github', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Independent repository review recorded.');
    })->whereUuid('id')->name('admin.operations.github.decide')
        ->middleware('throttle:6,1');

    Route::post('/github/refresh', function (Request $request, \App\Integrations\GitHub\PublicRepositoryDirectory $directory) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'repo' => ['sometimes', 'string', 'max:140'],
        ]);
        if (array_diff(array_keys($request->request->all()), ['_token', 'repo']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'refresh' => 'Unrecognized synchronization input.',
            ]);
        }
        // A submitted slug alone is NOT permission to query GitHub. Restrict
        // individual refreshes to CURRENTLY verified public allowlist entries.
        if (isset($input['repo'])) {
            $verifiedRepo = collect($directory->repositories())->first(static fn (array $item): bool =>
                ($item['status'] ?? null) === 'verified'
                && is_string($item['full_name'] ?? null)
                && strcasecmp($item['full_name'], $input['repo']) === 0
            );
            if ($verifiedRepo === null) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'repo' => 'Selected repository is not verified public.',
                ]);
            }
            $slug = $verifiedRepo['full_name'];
            \Illuminate\Support\Facades\Cache::forget('public-github:'.hash('sha256', strtolower($slug)));
            $updated = collect($directory->repositories())->first(static fn (array $item): bool =>
                ($item['status'] ?? null) === 'verified'
                && is_string($item['full_name'] ?? null)
                && strcasecmp($item['full_name'], $slug) === 0
            );
            $lang = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
            // If the source is withdrawn during refresh, never preserve an
            // inspect link or claim verification success from old cached data.
            return redirect()->route('admin.operations.github', $updated === null
                ? ['lang' => $lang] : ['lang' => $lang, 'repo' => $updated['full_name']])
                ->with($updated === null ? 'warning' : 'status', $updated === null
                    ? 'Selected repository is no longer publicly verified.'
                    : 'Selected public repository verified from GitHub.');
        }
        $configuredSlugs = $directory->configuredSlugs();
        $configured = count($configuredSlugs);
        $lang = ($input['lang'] ?? 'en') === 'ar' ? 'ar' : 'en';
        if ($configured === 0) {
            // An empty/invalid allowlist must not claim a provider refresh.
            return redirect()->route('admin.operations.github', ['lang' => $lang])
                ->with('warning', $lang === 'ar'
                    ? 'لا توجد مستودعات عامة صالحة مهيأة للتحقق.'
                    : 'No valid public GitHub repositories are configured.');
        }
        foreach ($configuredSlugs as $slug) {
            \Illuminate\Support\Facades\Cache::forget('public-github:'.hash('sha256', strtolower($slug)));
        }
        $repos = collect($directory->repositories());
        $verified = $repos->filter(static fn (array $item): bool => $item['status'] === 'verified')->count();
        // Verification is successful only when GitHub confirms public metadata.
        return redirect()->route('admin.operations.github', ['lang' => $lang])
            ->with($verified === 0 ? 'warning' : 'status',
                $verified === 0 ? 'No configured public repository could be verified.' :
                'Verified public repositories: '.$verified);
    })->name('admin.operations.github.refresh')->middleware('throttle:5,1');

    Route::get('/projects', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'status' => ['sometimes', 'in:completed,in_progress,research,archived'],
            'visibility' => ['sometimes', 'in:public,private'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'in:recent,title'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        $search = trim((string) ($input['q'] ?? ''));
        $sort = $input['sort'] ?? 'recent';
        $query = \App\Models\Project::query()->withCount([
            'evidence',
            'evidence as verified_public_evidence_count' => static fn ($q) => $q
                ->where('visibility', 'public')->whereNotNull('verified_at')
                ->where('verified_at', '<=', now()),
        ])->when(isset($input['status']), static fn ($q) => $q->where('status', $input['status']))
            ->when(isset($input['visibility']), static fn ($q) => $q->where('visibility', $input['visibility']));
        if ($search !== '') {
            // Search human-visible project titles and route-safe slugs only.
            // Treat % and _ literally, never as caller-controlled SQL wildcards.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $pattern = '%'.$escaped.'%';
            $query->where(static fn ($q) => $q
                ->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]));
        }
        $projects = ($sort === 'title'
            ? $query->orderBy('title')->orderBy('id')
            : $query->orderByDesc('updated_at')->orderBy('id'))
            ->paginate(20)->appends($request->only(['lang', 'status', 'visibility', 'q', 'sort']));

        // ADMIN-09 publication readiness must match the public case-study
        // serializer, not merely a database flag. Eager-load only reviewed
        // public evidence, limited per project, before replacing the owner
        // relation with their private review queue below.
        $rows = $projects->getCollection();
        $rows->load(['evidence' => static fn ($q) => $q
            ->where('visibility', 'public')->whereNotNull('verified_at')
            ->where('verified_at', '<=', now('UTC'))->orderBy('id')->limit(50)]);
        $portfolio = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
        foreach ($rows as $project) {
            $projection = $portfolio->publicProjectData($project, $request);
            $project->setAttribute('usable_public_evidence_count', count($projection['evidence_urls']));
            $project->setAttribute('publication_content_ready', collect([
                $project->title, $project->summary_en, $project->summary_ar,
                $project->role_en, $project->role_ar,
            ])->every(static fn ($value): bool => is_string($value) && trim($value) !== ''));
        }

        // Private URLs are visible only to the owner, never to other admins.
        // Laravel's eager-limit confines the review queue to ten per project.
        if ($request->user()->hasRole('owner')) {
            $projects->getCollection()->load(['evidence' => static fn ($q) => $q
                ->where('visibility', 'private')->whereNull('verified_at')
                ->orderByDesc('created_at')->limit(10)]);
        }
        return response()->view('admin.operations', [
            'section' => 'projects', 'projects' => $projects,
            'statusFilter' => $input['status'] ?? '',
            'visibilityFilter' => $input['visibility'] ?? '',
            'projectSearch' => $search, 'projectSort' => $sort,
        ])->header('Cache-Control', 'no-store, private');
    })->name('admin.operations.projects')->middleware('throttle:30,1');

    // Owner-created projects are always private drafts until the existing
    // separate evidence-review and publication actions are completed.
    Route::post('/projects', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'slug' => ['required', 'string', 'max:140', 'regex:/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/D',
                'unique:projects,slug'],
            'title' => ['required', 'string', 'min:2', 'max:220'],
            'title_ar' => ['sometimes', 'nullable', 'string', 'min:2', 'max:220'],
            'summary_en' => ['required', 'string', 'min:5', 'max:5000'],
            'summary_ar' => ['required', 'string', 'min:5', 'max:5000'],
            'role_en' => ['required', 'string', 'min:2', 'max:200'],
            'role_ar' => ['required', 'string', 'min:2', 'max:200'],
            'status' => ['required', 'in:completed,in_progress,research,archived'],
            'portfolio_category' => ['required', 'in:government,education,workflows,ai,enterprise'],
            'portfolio_featured' => ['sometimes', 'boolean'],
        ]);
        if (array_diff(array_keys($request->request->all()), [
            '_token', 'slug', 'title', 'title_ar', 'summary_en', 'summary_ar', 'role_en', 'role_ar',
            'status', 'portfolio_category', 'portfolio_featured',
        ]) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'project' => 'Unsupported project creation fields.',
            ]);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($input, $request): void {
            $draft = \App\Models\Project::query()->create([
                'slug' => $input['slug'],
                'title' => $input['title'],
                'title_ar' => $input['title_ar'] ?? null,
                'summary_en' => $input['summary_en'],
                'summary_ar' => $input['summary_ar'],
                'role_en' => $input['role_en'],
                'role_ar' => $input['role_ar'],
                'status' => $input['status'],
                'portfolio_category' => $input['portfolio_category'],
                'portfolio_featured' => (bool) ($input['portfolio_featured'] ?? false),
                'portfolio_source' => 'Owner dashboard',
                'visibility' => 'private',
                'published_at' => null,
            ]);
            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $draft->id,
                'evidence_id' => null,
                'actor_id' => $request->user()->id,
                'action' => 'draft_created',
                'created_at' => now('UTC'),
            ]);
        }, 3);
        return redirect()->route('admin.operations.projects', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
            ? 'أُنشئت مسودة المشروع الخاصة؛ يلزم اعتماد الدليل قبل النشر.'
            : 'Private project draft created. Approve evidence before publishing.');
    })->name('admin.operations.projects.store')->middleware('throttle:10,1');

    // ADMIN-09: owner-only, paginated review of ALL pending private draft
    // evidence. Previously only ten newest candidates were reachable.
    Route::get('/projects/{project}/evidence', function (Request $request, string $project) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        $draft = \App\Models\Project::query()->whereKey($project)->firstOrFail();
        abort_unless($draft->visibility === 'private' && $draft->published_at === null, 409);
        $locale = $input['lang'] ?? 'en';
        $evidence = \App\Models\ProjectEvidence::query()
            ->where('project_id', $draft->id)
            ->where('visibility', 'private')->whereNull('verified_at')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(10)->appends(['lang' => $locale]);
        $verifiedEvidenceCount = \App\Models\ProjectEvidence::query()
            ->where('project_id', $draft->id)
            ->where('visibility', 'public')->whereNotNull('verified_at')
            ->where('verified_at', '<=', now('UTC'))->count();
        // The publish action must not appear for a reviewed but unusable URL.
        // Share its actual public serializer gate (including URL allowlist and
        // route-safe slug), not a separate approximation from DB flags.
        $publicProof = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
            ->publicProjectData($draft, $request);
        $usablePublicEvidenceCount = count($publicProof['evidence_urls']);
        return response()->view('admin.project-evidence-review', compact(
            'draft', 'evidence', 'locale', 'verifiedEvidenceCount',
            'usablePublicEvidenceCount'
        ))->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    })->whereUuid('project')->name('admin.operations.projects.evidence.review')
        ->middleware('throttle:30,1');

    // ADMIN-09: explicit owner review is the only transition from a private
    // candidate to verified public evidence. The application never fetches the
    // candidate URL here: source and publication rights are confirmed manually.
    Route::post('/projects/{project}/evidence/{evidence}/approve', function (Request $request, string $project, string $evidence) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
            'source_reviewed' => ['accepted'],
            'publication_rights_confirmed' => ['accepted'],
        ]);
        if (array_diff(array_keys($request->request->all()), [
            '_token', 'source_reviewed', 'publication_rights_confirmed',
        ]) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'evidence' => 'Unsupported evidence approval fields.',
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $evidence, $request): void {
            $draft = \App\Models\Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();
            abort_unless($draft->visibility === 'private' && $draft->published_at === null, 409);

            $candidate = \App\Models\ProjectEvidence::query()
                ->whereKey($evidence)->where('project_id', $draft->id)
                ->lockForUpdate()->firstOrFail();
            abort_unless($candidate->visibility === 'private' && $candidate->verified_at === null, 409);

            $candidate->forceFill([
                'visibility' => 'public',
                'verified_at' => now('UTC'),
            ])->save();

            // Identifier-only audit: never log the private source URL or request payload.
            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $draft->id, 'evidence_id' => $candidate->id,
                'actor_id' => $request->user()->id, 'action' => 'evidence_approved',
                'created_at' => now('UTC'),
            ]);
            \Illuminate\Support\Facades\Log::notice('admin.project_evidence.approved', [
                'project_id' => $draft->id,
                'evidence_id' => $candidate->id,
                'actor_id' => $request->user()->id,
                'source_reviewed' => true,
                'publication_rights_confirmed' => true,
            ]);
        }, 3);

        $ar = ($input['lang'] ?? 'en') === 'ar';
        return redirect()->route('admin.operations.projects.evidence.review', [
            'project' => $project,
            'lang' => $ar ? 'ar' : 'en',
            'page' => $input['page'] ?? 1,
        ])->with('status', $ar
            ? 'تم اعتماد الدليل للمشاركة العامة بعد تأكيد مراجعة المصدر وحقوق النشر. بقي المشروع خاصاً حتى خطوة النشر المنفصلة.'
            : 'Evidence approved for public use after source and publication-rights confirmation. The project remains private until the separate publish step.');
    })->whereUuid('project')->whereUuid('evidence')
        ->name('admin.operations.projects.evidence.approve')->middleware('throttle:10,1');

    // Publishing is a separate, explicit transition. It cannot create or
    // approve evidence and fails closed unless a currently verified public
    // source exists and the bilingual project record is complete.
    Route::post('/projects/{project}/publish', function (Request $request, string $project) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'publication_confirmed' => ['accepted'],
        ]);
        if (array_diff(array_keys($request->request->all()), [
            '_token', 'publication_confirmed',
        ]) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'project' => 'Unsupported project publication fields.',
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $request): void {
            $draft = \App\Models\Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();
            abort_unless($draft->visibility === 'private' && $draft->published_at === null, 409);

            foreach (['title', 'summary_en', 'summary_ar', 'role_en', 'role_ar'] as $field) {
                $value = $draft->{$field};
                if (! is_string($value) || trim($value) === '') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'project' => 'Bilingual project content and role fields must be complete before publication.',
                    ]);
                }
            }

            // A review flag alone is not proof of a reachable public case study.
            // Use the same live URL, GitHub and route-safe slug checks that
            // public /projects and /projects/{slug} apply before publishing.
            $publicProof = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
                ->publicProjectData($draft, $request);
            if (empty($publicProof['evidence_urls'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'project' => 'A usable public evidence link and route-safe project slug are required before publication.',
                ]);
            }

            $draft->forceFill([
                'visibility' => 'public',
                'published_at' => now('UTC'),
            ])->save();

            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $draft->id, 'evidence_id' => null,
                'actor_id' => $request->user()->id, 'action' => 'project_published',
                'created_at' => now('UTC'),
            ]);
            \Illuminate\Support\Facades\Log::notice('admin.project.published', [
                'project_id' => $draft->id,
                'actor_id' => $request->user()->id,
            ]);
        }, 3);

        $ar = ($input['lang'] ?? 'en') === 'ar';
        return redirect()->route('admin.operations.projects', ['lang' => $ar ? 'ar' : 'en'])
            ->with('status', $ar
                ? 'تم نشر المشروع بعد اجتياز بوابة الدليل المعتمد والمحتوى الثنائي.'
                : 'Project published after verified-evidence and bilingual-content gates passed.');
    })->whereUuid('project')->name('admin.operations.projects.publish')
        ->middleware('throttle:5,1');

    // ADMIN-09: immediate owner-controlled retraction of public claims.
    // Keep manually reviewed evidence for a separate, evidence-gated republish;
    // never retain the published flag after withdrawing visibility.
    Route::post('/projects/{project}/withdraw', function (Request $request, string $project) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'withdrawal_confirmed' => ['accepted'],
        ]);
        if (array_diff(array_keys($request->request->all()), [
            '_token', 'withdrawal_confirmed',
        ]) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'project' => 'Unsupported project withdrawal fields.',
            ]);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $request): void {
            $published = \App\Models\Project::query()->whereKey($project)
                ->lockForUpdate()->firstOrFail();
            abort_unless($published->visibility === 'public'
                && $published->published_at !== null, 409);

            // Preserve proof records, but make all public portfolio scopes
            // immediately exclude this project until a new explicit publish.
            $published->forceFill([
                'visibility' => 'private',
                'published_at' => null,
            ])->save();

            // No claim, URL or evidence payload enters the security log.
            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $published->id, 'evidence_id' => null,
                'actor_id' => $request->user()->id, 'action' => 'project_withdrawn',
                'created_at' => now('UTC'),
            ]);
            \Illuminate\Support\Facades\Log::notice('admin.project.withdrawn', [
                'project_id' => $published->id,
                'actor_id' => $request->user()->id,
            ]);
        }, 3);

        $ar = ($input['lang'] ?? 'en') === 'ar';
        return redirect()->route('admin.operations.projects', [
            'lang' => $ar ? 'ar' : 'en',
        ])->with('status', $ar
            ? 'تم سحب المشروع من العرض العام. الأدلة المعتمدة محفوظة، وإعادة النشر تتطلب تأكيداً منفصلاً.'
            : 'Project withdrawn from public view. Approved evidence remains saved; republication requires a separate confirmation.');
    })->whereUuid('project')->name('admin.operations.projects.withdraw')
        ->middleware('throttle:5,1');

    Route::patch('/projects/{project}', function (Request $request, string $project) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'title' => ['required', 'string', 'min:2', 'max:220'],
            'title_ar' => ['sometimes', 'nullable', 'string', 'min:2', 'max:220'],
            'summary_en' => ['required', 'string', 'min:5', 'max:5000'],
            'summary_ar' => ['required', 'string', 'min:5', 'max:5000'],
            'role_en' => ['nullable', 'string', 'max:200'],
            'role_ar' => ['nullable', 'string', 'max:200'],
            'status' => ['sometimes', 'in:completed,in_progress,research,archived'],
            'portfolio_category' => ['sometimes', 'in:government,education,workflows,ai,enterprise'],
            'portfolio_featured' => ['sometimes', 'boolean'],
        ]);
        if (array_diff(array_keys($request->request->all()), [
            '_token', '_method', 'title', 'title_ar', 'summary_en', 'summary_ar', 'role_en', 'role_ar',
            'status', 'portfolio_category', 'portfolio_featured',
        ]) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'project' => 'Unsupported project edit fields.',
            ]);
        }
        // No edit may quietly alter an approved public claim; only private
        // unpublished drafts can be edited. Publication is a separate
        // evidence/rights/owner-reviewed workflow.
        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $input, $request): void {
            $row = \App\Models\Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();
            abort_unless($row->visibility === 'private' && $row->published_at === null, 409);
            $row->fill(collect($input)->only([
                'title', 'title_ar', 'summary_en', 'summary_ar', 'role_en', 'role_ar',
                'status', 'portfolio_category', 'portfolio_featured',
            ])->all())->save();
            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $row->id, 'evidence_id' => null,
                'actor_id' => $request->user()->id, 'action' => 'draft_updated',
                'created_at' => now('UTC'),
            ]);
            \Illuminate\Support\Facades\Log::notice('admin.project_private_draft.updated', [
                'project_id' => $row->id, 'actor_id' => $request->user()->id,
            ]);
        }, 3);
        return redirect()->route('admin.operations.projects', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
            ? 'تم حفظ مسودة المشروع الخاصة دون نشرها.'
            : 'Private project draft updated; not published.');
    })->whereUuid('project')->name('admin.operations.projects.update')
        ->middleware('throttle:10,1');

    // ADMIN-09: submit supporting evidence for a private, unpublished
    // project. This records a review candidate, never verified/public proof.
    // The server does not fetch or preflight the submitted URL.
    Route::post('/projects/{project}/evidence', function (Request $request, string $project) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'evidence_type' => ['required', \Illuminate\Validation\Rule::in([
                'document', 'repository', 'publication',
            ])],
            'url' => ['required', 'string', 'max:2048'],
        ]);
        if (array_diff(array_keys($request->request->all()), [
            '_token', 'evidence_type', 'url',
        ]) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'evidence' => 'Only private evidence submission fields are supported.',
            ]);
        }
        $url = trim($input['url']);
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $host = is_string($host) ? strtolower($host) : '';
        // Accept a structurally public DNS name, not IP literals, local-only
        // names, redirect credentials or internal endpoint syntax. There is
        // deliberately NO DNS lookup or outbound URL request on this path.
        $publicHost = preg_match(
            '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D',
            $host
        ) === 1;
        $tld = substr(strrchr($host, '.') ?: '', 1);
        $blockedHost = in_array($tld, [
            'local', 'internal', 'localhost', 'test', 'invalid', 'onion', 'lan', 'home',
        ], true) || in_array($host, [
            'localtest.me', 'localhost.localdomain', 'nip.io', 'sslip.io',
        ], true) || str_ends_with($host, '.nip.io') || str_ends_with($host, '.sslip.io');
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! $publicHost || $blockedHost
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) === 1) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => 'Evidence must use a direct HTTPS URL on a public DNS hostname without credentials or query tokens.',
            ]);
        }
        $canonicalUrl = 'https://'.$host.($parts['path'] ?? '');
        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $input, $canonicalUrl, $request): void {
            $draft = \App\Models\Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();
            abort_unless($draft->visibility === 'private' && $draft->published_at === null, 409);
            if (\App\Models\ProjectEvidence::query()
                ->where('project_id', $draft->id)->where('url', $canonicalUrl)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'url' => 'This evidence URL is already attached to the project.',
                ]);
            }
            $candidate = \App\Models\ProjectEvidence::create([
                'project_id' => $draft->id,
                'evidence_type' => $input['evidence_type'],
                'url' => $canonicalUrl,
                'visibility' => 'private',
                'verified_at' => null,
            ]);
            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $draft->id, 'evidence_id' => $candidate->id,
                'actor_id' => $request->user()->id, 'action' => 'evidence_submitted',
                'created_at' => now('UTC'),
            ]);
            // No URL or user identifier appears in audit output.
            \Illuminate\Support\Facades\Log::notice('admin.project_private_evidence.queued', [
                'project_id' => $draft->id,
            ]);
        }, 3);
        $ar = $request->query('lang') === 'ar';
        return redirect()->route('admin.operations.projects', ['lang' => $ar ? 'ar' : 'en'])
            ->with('status', $ar
                ? 'تمت إضافة الدليل إلى المسودة الخاصة للمراجعة دون نشر أو اعتماد.'
                : 'Private draft evidence queued for review; not published or verified.');
    })->whereUuid('project')->name('admin.operations.projects.evidence')
        ->middleware('throttle:10,1');

    // Discard is a private draft review action, not approval or publication.
    Route::delete('/projects/{project}/evidence/{evidence}', function (Request $request, string $project, string $evidence) {
        abort_unless($request->user()?->canAccessAdmin() && $request->user()->hasRole('owner'), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'return' => ['sometimes', 'in:review'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ]);
        if (array_diff(array_keys($request->request->all()), ['_token', '_method']) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'evidence' => 'Unsupported evidence review fields.',
            ]);
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($project, $evidence, $request): void {
            $draft = \App\Models\Project::query()->whereKey($project)->lockForUpdate()->firstOrFail();
            abort_unless($draft->visibility === 'private' && $draft->published_at === null, 409);
            $candidate = \App\Models\ProjectEvidence::query()
                ->whereKey($evidence)->where('project_id', $draft->id)->lockForUpdate()->firstOrFail();
            abort_unless($candidate->visibility === 'private' && $candidate->verified_at === null, 409);
            $candidate->delete();
            \Illuminate\Support\Facades\DB::table('project_audit_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $draft->id, 'evidence_id' => $candidate->id,
                'actor_id' => $request->user()->id, 'action' => 'evidence_discarded',
                'created_at' => now('UTC'),
            ]);
            // Audit identifiers only, never private evidence URLs or secret payloads.
            \Illuminate\Support\Facades\Log::notice('admin.project_private_evidence.discarded', [
                'project_id' => $draft->id, 'evidence_id' => $candidate->id,
                'actor_id' => $request->user()->id,
            ]);
        }, 3);
        $ar = $request->query('lang') === 'ar';
        // Only a fixed internal marker can return an owner to the review
        // queue. Never accept an arbitrary redirect URL from the browser.
        $review = $request->query('return') === 'review';
        return redirect()->route($review
            ? 'admin.operations.projects.evidence.review'
            : 'admin.operations.projects', $review ? [
                'project' => $project,
                'lang' => $ar ? 'ar' : 'en',
                'page' => $request->query('page', 1),
            ] : ['lang' => $ar ? 'ar' : 'en'])
            ->with('status', $ar ? 'تم رفض الدليل الخاص غير المعتمد.' : 'Unverified private evidence discarded; no publication occurred.');
    })->whereUuid('project')->whereUuid('evidence')
        ->name('admin.operations.projects.evidence.discard')->middleware('throttle:10,1');

    Route::get('/audience', function (
        Request $request,
        \App\Domain\Privacy\AudienceConsentPreview $preview,
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'purpose' => ['sometimes', 'in:account_activity,editorial_updates,product_updates'],
            'channel' => ['sometimes', 'in:email,push'],
        ]);
        $purpose = $input['purpose'] ?? 'editorial_updates';
        $channel = $input['channel'] ?? 'push';
        // One database snapshot with disjoint eligible / suppressed buckets.
        // Source of truth remains each user's authenticated consent API;
        // this owner/admin view never sends, opts in, or exposes identities.
        $audience = $preview->forSegment($purpose, $channel);
        return response()->view('admin.operations', [
            'section' => 'audience',
            'purpose' => $purpose, 'channel' => $channel,
            'consented' => $audience['consented'],
            'activeAccounts' => $audience['active_accounts'],
            'audienceStates' => $audience,
        ])->header('Cache-Control', 'no-store, private');
    })->name('admin.operations.audience')->middleware('throttle:30,1');

    Route::get('/analytics', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        // Only bounded UTC calendar windows. Never infer a rolling local 24h
        // interval, and never mix currencies across the selected period.
        $utcToday = now('UTC')->toDateString();
        $oldest = now('UTC')->subDays(365)->toDateString();
        $input = $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'day' => ['sometimes', 'date_format:Y-m-d',
                'after_or_equal:'.$oldest, 'before_or_equal:'.$utcToday],
            'window' => ['sometimes', 'integer', \Illuminate\Validation\Rule::in([1, 7, 30])],
            'format' => ['sometimes', 'in:csv,jobs-csv'],
        ]);
        $day = $input['day'] ?? $utcToday;
        $window = (int) ($input['window'] ?? 1);
        $endDay = \Carbon\CarbonImmutable::parse($day, 'UTC')->startOfDay();
        $from = $endDay->subDays($window - 1);
        $to = $endDay->addDay();
        $startDay = $from->toDateString();
        $usage = \Illuminate\Support\Facades\DB::table('ai_usage')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->select('cost_currency')->selectRaw('COUNT(*) AS receipts, SUM(cost_minor) AS cost_minor')
            ->groupBy('cost_currency')->orderBy('cost_currency')->get();
        if (($input['format'] ?? null) === 'csv') {
            return response()->streamDownload(static function () use ($usage, $startDay, $day): void {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['UTC period start', 'UTC period end', 'Currency', 'Receipts', 'Cost (minor units)']);
                foreach ($usage as $line) {
                    // An imported database currency is not trusted CSV code.
                    $currency = preg_match('/\A[A-Z]{3}\z/D', (string) $line->cost_currency)
                        ? $line->cost_currency : 'INVALID';
                    fputcsv($stream, [$startDay, $day, $currency, (int) $line->receipts, (int) $line->cost_minor]);
                }
                fclose($stream);
            }, 'ai-usage-'.$startDay.'-to-'.$day.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        $jobs = \Illuminate\Support\Facades\DB::table('ai_jobs')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->select('status')->selectRaw('COUNT(*) AS total')
            ->groupBy('status')->orderBy('status')->get();
        if (($input['format'] ?? null) === 'jobs-csv') {
            return response()->streamDownload(static function () use ($jobs, $startDay, $day): void {
                $stream = fopen('php://output', 'wb');
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['UTC period start', 'UTC period end', 'AI job status', 'Recorded jobs']);
                foreach ($jobs as $job) {
                    // Imported job-status values are not trusted spreadsheet code.
                    $status = preg_match('/\A[a-z][a-z0-9_]{0,24}\z/D', (string) $job->status) === 1
                        ? $job->status : 'INVALID';
                    fputcsv($stream, [$startDay, $day, $status, (int) $job->total]);
                }
                fclose($stream);
            }, 'ai-jobs-'.$startDay.'-to-'.$day.'.csv', [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }
        // ADMIN-11 discovery KPIs must describe URLs visitors can actually
        // open, not only rows whose SQL publication flag is set. The public
        // Projects API and Web listing reject non-route slugs and unsafe,
        // revoked or future-dated evidence after SQL filtering.
        $portfolio = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
        $publicProjects = 0;
        foreach (\App\Models\Project::query()->publiclyVerified()
            ->with(['evidence' => static fn ($evidence) => $evidence
                ->where('visibility', 'public')
                ->whereNotNull('verified_at')->where('verified_at', '<=', now())
                ->orderBy('id')->limit(50)])
            ->orderBy('id')->lazy(100) as $project) {
            if ($portfolio->publicProjectData($project, $request)['evidence_urls'] !== []) {
                $publicProjects++;
            }
        }

        // SQL publication alone is also insufficient for articles: the
        // exact pinned revision must still satisfy current citations,
        // editorial validation and hero-asset rights, and have a route-safe
        // slug. Mirror the public blog's discovery gate without fetching URLs.
        $publicArticles = 0;
        foreach (\App\Models\Article::query()->publiclyVisible()
            ->orderBy('id')->lazy(100) as $article) {
            if (is_string($article->slug)
                && preg_match('/\A[A-Za-z0-9-]+\z/D', $article->slug) === 1
                && $article->publicRevision() !== null) {
                $publicArticles++;
            }
        }

        return response()->view('admin.operations', [
            'section' => 'analytics', 'day' => $day, 'startDay' => $startDay,
            'window' => $window, 'usage' => $usage,
            'jobs' => $jobs,
            'publicProjects' => $publicProjects,
            'publicArticles' => $publicArticles,
        ])->header('Cache-Control', 'no-store, private');
    })->name('admin.operations.analytics')->middleware('throttle:30,1');
});
