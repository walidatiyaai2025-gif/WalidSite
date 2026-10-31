<?php

use App\Domain\Identity\WebLoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

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
        $track = (string) $request->query('track', 'all');
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
    $query = \App\Models\Project::query()->publiclyVerified();

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
        ->map(fn ($project) => $api->project($request, $project->slug)->getData(true)['data'])
        ->filter(fn (array $project): bool => count($project['evidence_urls'] ?? []) > 0)
        ->values();

    return view('public.projects', compact('projects', 'paginated', 'search'));
})->name('public.projects');

Route::get('/projects/{slug}', function (Request $request, string $slug) {
    $project = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
        ->project($request, $slug)->getData(true)['data'];
    // A project without a usable public evidence link is not a public case study.
    abort_if(empty($project['evidence_urls']), 404);

    return view('public.case-study', compact('project'));
})->where('slug', '[a-zA-Z0-9-]+')->name('public.project');

Route::get('/resume', function () {
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

    return view('public.resume', compact('profile', 'positions', 'certifications'));
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

    return view('public.article', compact('article', 'revision'));
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
            ->map(fn ($project) => $api->project($request, $project->slug)->getData(true)['data'])
            ->filter(fn (array $project) => ! empty($project['evidence_urls']))->values();
    }
    return view('public.search', compact('search', 'articles', 'projects'));
})->name('public.search')->middleware('throttle:60,1');

