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
    $portrait = base_path('../assets/portraits/portrait-light-derived.jpg');
    abort_unless(is_file($portrait) && is_readable($portrait), 404);

    return response()->file($portrait, [
        'Content-Type' => 'image/jpeg',
        'Cache-Control' => 'public, max-age=86400',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('public.owner-portrait');

/*
 * WEB-01 public landing: do not turn mockup counts or CV draft rows into
 * published professional claims. Reuse the API's owner/URL approval policy.
 * Render only evidence-safe projects and publication-validated articles.
 */
Route::get('/', function (Request $request) {
    $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
    $publicProfile = $api->profile($request)->getData(true)['data'] ?? null;

    $featuredProjects = \App\Models\Project::query()->publiclyVerified()
        ->orderByDesc('published_at')->orderBy('id')->limit(24)->get()
        ->map(fn (\App\Models\Project $project) => $api->publicProjectData($project, $request))
        ->filter(fn (array $project) => ! empty($project['evidence_urls']))
        ->take(3)->values();

    $featuredArticles = \App\Models\Article::query()->publiclyVisible()
        ->orderByDesc('published_at')->orderBy('id')->limit(24)->get()
        ->filter(fn (\App\Models\Article $article) => $article->publicRevision() !== null)
        ->take(3)->values();

    return view('welcome', compact('publicProfile', 'featuredProjects', 'featuredArticles'));
})->name('home');

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
        'home', 'public.about', 'public.expertise', 'public.hire',
        'public.projects', 'public.github', 'public.blog', 'public.lab',
        'public.resume',
    ] as $name) {
        $add($name);
    }

    \App\Models\Article::query()->publiclyVisible()
        ->orderByDesc('published_at')->orderBy('id')->limit(200)->get()
        ->each(static function (\App\Models\Article $article) use ($add): void {
            if (preg_match('/\A[a-zA-Z0-9-]+\z/D', $article->slug) === 1
                && $article->publicRevision() !== null) {
                $add('public.article', ['slug' => $article->slug]);
            }
        });

    $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
    \App\Models\Project::query()->publiclyVerified()
        ->orderByDesc('published_at')->orderBy('id')->limit(200)->get()
        ->each(static function (\App\Models\Project $project) use ($api, $request, $add): void {
            // DB flags alone do not make a public, safe, independently usable
            // evidence URL. The canonical API serializer enforces that policy.
            if (preg_match('/\A[a-zA-Z0-9-]+\z/D', $project->slug) === 1
                && ! empty($api->publicProjectData($project, $request)['evidence_urls'])) {
                $add('public.project', ['slug' => $project->slug]);
            }
        });

    return response()->view('public.sitemap', compact('entries'))
        ->header('Content-Type', 'application/xml; charset=UTF-8')
        ->header('Cache-Control', 'no-store');
})->name('public.sitemap')->middleware('throttle:30,1');


Route::get('/admin/login', [WebLoginController::class, 'form'])
    ->middleware('guest:web')->name('login');
Route::post('/admin/login', [WebLoginController::class, 'login'])
    ->middleware(['guest:web', 'throttle:6,1'])->name('admin.login.submit');

Route::middleware('auth:web')->group(function (): void {
    Route::post('/admin/logout', [WebLoginController::class, 'logout'])->name('admin.logout');

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
        return view('admin.ai.providers', [
            'providers' => \App\Models\AiProvider::query()->orderBy('display_name')->paginate(20),
        ]);
    })->name('admin.providers.index');

    // T016: bounded, read-only provider billing audit. Saved daily budgets
    // are displayed as settings, not claimed as enforced spend limits.
    // Keep receipts partitioned by their *actual* currency and provider.
    Route::get('/admin/ai/usage', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $today = now('UTC')->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $rows = \Illuminate\Support\Facades\DB::table('ai_providers as provider')
            ->leftJoin('ai_usage as usage', function ($join) use ($today, $tomorrow) {
                $join->on('usage.provider_id', '=', 'provider.id')
                    ->where('usage.created_at', '>=', $today)
                    ->where('usage.created_at', '<', $tomorrow);
            })
            ->whereNull('provider.deleted_at')
            ->select('provider.id', 'provider.display_name', 'provider.daily_budget_minor',
                'provider.currency', 'usage.cost_currency')
            ->selectRaw('COUNT(usage.id) AS receipt_count, COALESCE(SUM(usage.cost_minor), 0) AS spend_minor')
            ->groupBy('provider.id', 'provider.display_name', 'provider.daily_budget_minor',
                'provider.currency', 'usage.cost_currency')
            ->orderBy('provider.display_name')->orderBy('provider.id')
            ->orderBy('usage.cost_currency')
            ->paginate(20)->withQueryString();

        return view('admin.ai.usage', compact('rows'));
    })->name('admin.usage.index');

    // Laravel web middleware enforces CSRF. API controller remains the only
    // writer, validator, secret encryption and audit implementation.
    Route::post('/admin/ai/providers', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        app(\App\Http\Controllers\Api\AiProviderController::class)->store($request);
        return redirect()->route('admin.providers.index')
            ->with('status', 'Provider saved.');
    })->middleware('throttle:10,1')->name('admin.providers.create');

    Route::patch('/admin/ai/providers/{provider}', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        // Empty input must not silently erase an existing secret.
        if ($request->boolean('clear_secret')) {
            $request->merge(['secret' => null]);
        } elseif (trim((string) $request->input('secret', '')) === '') {
            $request->request->remove('secret');
        }
        app(\App\Http\Controllers\Api\AiProviderController::class)->update($request, $provider);
        return redirect()->route('admin.providers.index')
            ->with('status', 'Provider updated.');
    })->middleware('throttle:20,1')->name('admin.providers.update');

    Route::post('/admin/ai/providers/{provider}/test', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $result = app(\App\Http\Controllers\Api\AiProviderController::class)->test($request, $provider);
        $health = $result->getData(true)['data']['health_status'] ?? 'unverified';
        return redirect()->route('admin.providers.index')
            ->with('status', 'Connection check recorded: '.$health.'.');
    })->middleware('throttle:5,1')->name('admin.providers.test');

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

        return view('admin.content.index', compact('articles', 'selected', 'revisions'));
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
        try {
            $article->publishVerified();
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
        // Revocation invalidates the public revision pointer immediately.
        $article->forceFill([
            'status' => 'draft', 'published_at' => null,
            'published_revision_no' => null,
        ])->save();

        return redirect()->route('admin.content.index', [
            'article' => $article->id, 'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Article unpublished.');
    })->middleware('throttle:10,1')->name('admin.content.unpublish');

    // T020: rights revocation is available before a licensed approval flow.
    Route::get('/admin/ai/images', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        return view('admin.ai.media', [
            'assets' => \App\Models\Asset::query()
                ->orderByDesc('created_at')->orderBy('id')->paginate(12)->withQueryString(),
        ]);
    })->name('admin.media.index');

    Route::post('/admin/ai/images/{asset}/revoke', function (
        Request $request, \App\Models\Asset $asset
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        // Revocation is a fail-closed one-way admin action. Never expose
        // internal storage paths or silently restore licenses in this view.
        $asset->forceFill(['rights_status' => 'revoked', 'publishable' => false])->save();

        return redirect()->route('admin.media.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', 'Media rights revoked; public eligibility removed.');
    })->middleware('throttle:10,1')->name('admin.media.revoke');
});

Route::get('/github', function (Request $request, \App\Integrations\GitHub\PublicRepositoryDirectory $directory) {
    $repositories = $directory->repositories();
    $search = mb_substr(trim((string) $request->query('q', '')), 0, 80);
    $language = mb_substr(trim((string) $request->query('language', '')), 0, 60);

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
        $rawTrack = $request->query()['track'] ?? 'all';
        $track = is_string($rawTrack) ? $rawTrack : 'all';
        $tracks = [
            'database' => ['Database & Reliability'],
            'software' => ['Software & Integration', 'Infrastructure & Security'],
            'delivery' => ['Project Delivery'],
        ];
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
                $skills = $skillsQuery->orderBy('category')->orderBy('name_en')->get();
            }
        }

        return view('public.professional', [
            'page' => $professionalPage,
            'positions' => $positions,
            'certifications' => $certifications,
            'skills' => $skills,
            'track' => $track,
        ]);
    })->name('public.'.$professionalPage);
}


/*
 * T009: source-verified project presentation. Reuse the canonical public API
 * serializer so public web cannot bypass the backend evidence URL policy.
 */
Route::get('/projects', function (Request $request) {
    $rawSearch = $request->query('q', '');
    $search = is_string($rawSearch) ? mb_substr(trim($rawSearch), 0, 80) : '';
    // Never interpolate untrusted filter values into the query or reveal
    // drafts: the public evidence gate remains authoritative.
    $rawStatus = $request->query('status', '');
    $status = is_string($rawStatus)
        && in_array($rawStatus, ['completed', 'in_progress', 'research', 'archived'], true)
            ? $rawStatus : '';
    $query = \App\Models\Project::query()->publiclyVerified();
    if ($status !== '') {
        $query->where('status', $status);
    }

    if ($search !== '') {
        $term = '%'.addcslashes($search, '%_\\').'%';
        $query->where(function ($q) use ($term): void {
            $q->where('title', 'like', $term)
                ->orWhere('summary_en', 'like', $term)
                ->orWhere('summary_ar', 'like', $term);
        });
    }

    $paginated = $query->orderByDesc('published_at')->orderBy('id')
        ->paginate(9)->withQueryString();
    $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
    $projects = $paginated->getCollection()
        ->map(fn ($project) => $api->publicProjectData($project, $request))
        ->filter(fn (array $project): bool => count($project['evidence_urls'] ?? []) > 0)
        ->values();

    return view('public.projects', compact('projects', 'paginated', 'search', 'status'));
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
            ->where('profile_id', $profile->id)->orderByDesc('started_on')->get()
        : collect();
    $certifications = $profile
        ? \App\Models\Certification::query()->publiclyVerified()
            ->where('profile_id', $profile->id)->orderBy('title')->get()
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
 * Secure-by-default contact flow. The recipient is intentionally not stored
 * in source or exposed to the browser; activation requires verified mail
 * delivery and a configured contact recipient on the server.
 */
Route::get('/contact', function () {
    $recipient = config('mail.contact_recipient');
    $available = is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)
        && in_array(config('mail.default'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true);

    return view('public.contact', compact('available'));
})->name('public.contact');

Route::post('/contact', function (Request $request) {
    $recipient = config('mail.contact_recipient');
    abort_unless(
        is_string($recipient) && filter_var($recipient, FILTER_VALIDATE_EMAIL)
        && in_array(config('mail.default'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend'], true),
        503,
        'Contact delivery has not been configured.'
    );

    $input = $request->validate([
        'name' => ['required', 'string', 'min:2', 'max:100', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
        'email' => ['required', 'email:rfc', 'max:254'],
        'subject' => ['required', 'string', 'min:3', 'max:140', 'not_regex:/[\\x00-\\x1F\\x7F]/u'],
        'message' => ['required', 'string', 'min:10', 'max:3000'],
        'website' => ['nullable', 'string', 'max:200'],
    ]);

    // Silently discard bot honeypots; do not store or dispatch untrusted mail.
    if (! empty($input['website'])) {
        return redirect()->route('public.contact', ['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']);
    }

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
        report($exception);
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
        $term = '%'.addcslashes($search, '%_\\').'%';
        $query->where(fn ($builder) => $builder->where('title_en', 'like', $term)
            ->orWhere('title_ar', 'like', $term));
    }
    if ($category !== '') {
        $query->whereHas('categories', fn ($builder) => $builder->where('slug', $category));
    }

    $categories = \App\Models\Category::query()
        ->whereHas('articles', fn ($article) => $article->publiclyVisible())
        ->orderBy('slug')->get();
    $paginated = $query->orderByDesc('published_at')->orderBy('id')->paginate(9)->withQueryString();
    $entries = $paginated->getCollection()
        ->map(fn (\App\Models\Article $article) => [
            'article' => $article,
            'revision' => $article->publicRevision(),
        ])
        ->filter(fn (array $entry) => $entry['revision'] !== null)
        ->values();

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
        $related = \App\Models\Article::query()->publiclyVisible()
            ->where('articles.id', '!=', $article->id)
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categoryIds))
            ->orderByDesc('articles.published_at')->orderBy('articles.id')
            ->limit(24)->get()
            ->filter(fn (\App\Models\Article $candidate) => $candidate->publicRevision() !== null)
            ->take(3)->values();
    }

    return view('public.article', compact('article', 'revision', 'related'));
})->where('slug', '[a-zA-Z0-9-]+')->name('public.article')->middleware('throttle:60,1');

/* T012 additive LAB + SEARCH: never show unreviewed or private data. */
Route::get('/lab', function () {
    $articles = \App\Models\Article::query()->publiclyVisible()
        ->orderByDesc('published_at')->orderBy('id')->limit(15)->get()
        ->filter(fn ($article) => $article->publicRevision() !== null);
    return view('public.lab', compact('articles'));
})->name('public.lab')->middleware('throttle:60,1');

Route::get('/search', function (Request $request) {
    $raw = $request->query('q', '');
    $search = is_string($raw) ? mb_substr(trim($raw), 0, 80) : '';
    $articles = collect();
    $projects = collect();

    if ($search !== '') {
        $term = '%'.addcslashes($search, '%_\\').'%';
        $articles = \App\Models\Article::query()->publiclyVisible()
            ->where(fn ($builder) => $builder->where('title_en', 'like', $term)
                ->orWhere('title_ar', 'like', $term))
            ->orderBy('id')->limit(10)->get()
            ->filter(fn ($article) => $article->publicRevision() !== null)->values();

        $api = app(\App\Http\Controllers\Api\PublicPortfolioController::class);
        $projects = \App\Models\Project::query()->publiclyVerified()
            ->where(fn ($builder) => $builder->where('title', 'like', $term)
                ->orWhere('summary_en', 'like', $term)->orWhere('summary_ar', 'like', $term))
            ->orderBy('id')->limit(10)->get()
            ->map(fn ($project) => $api->publicProjectData($project, $request))
            ->filter(fn (array $project) => ! empty($project['evidence_urls']))->values();
    }
    return view('public.search', compact('search', 'articles', 'projects'));
})->name('public.search')->middleware('throttle:60,1');

