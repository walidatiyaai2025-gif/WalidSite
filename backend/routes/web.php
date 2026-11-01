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
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        return view('admin.ai.providers', [
            'providers' => \App\Models\AiProvider::query()->orderBy('display_name')->paginate(20)
                ->appends(['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']),
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
            ->paginate(20)->appends(['lang' => $request->query('lang') === 'ar' ? 'ar' : 'en']);

        return view('admin.ai.usage', compact('rows'));
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
        // Empty input must not silently erase an existing secret.
        if ($request->boolean('clear_secret')) {
            $request->merge(['secret' => null]);
        } elseif (trim((string) $request->input('secret', '')) === '') {
            $request->request->remove('secret');
        }
        app(\App\Http\Controllers\Api\AiProviderController::class)->update($request, $provider);
        return redirect()->route('admin.providers.index',
            $request->query('lang') === 'ar' ? ['lang' => 'ar'] : []
        )->with('status', $request->query('lang') === 'ar'
            ? 'تم تحديث مزود الذكاء الاصطناعي.' : 'Provider updated.');
    })->middleware('throttle:20,1')->name('admin.providers.update');

    Route::post('/admin/ai/providers/{provider}/test', function (
        Request $request, \App\Models\AiProvider $provider
    ) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $result = app(\App\Http\Controllers\Api\AiProviderController::class)->test($request, $provider);
        $health = $result->getData(true)['data']['health_status'] ?? 'unverified';
        return redirect()->route('admin.providers.index',
            $request->query('lang') === 'ar' ? ['lang' => 'ar'] : []
        )->with('status', $request->query('lang') === 'ar'
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
        $revision = $article->revisions()->orderByDesc('revision_no')->first();
        $checks = $revision?->validation_json ?? [];
        // High-risk and professional content must use an explicit, recorded
        // owner-only exception decision, never the generic publish action.
        if (($checks['risk_level'] ?? null) !== 'low'
            || ($checks['contains_professional_claims'] ?? null) !== false) {
            return back()->withErrors(['publish' => 'Owner manual review and a reason are required.']);
        }
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($article, $request, $revision): void {
                $article = \App\Models\Article::query()->whereKey($article->id)
                    ->lockForUpdate()->firstOrFail();
                $article->publishVerified();
                app(\App\AI\EditorialScheduleRunner::class)->event(
                    'manual:'.\Illuminate\Support\Str::uuid(),
                    $article->id, $revision?->revision_no, $request->user()->id,
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
        // Revocation invalidates the public revision pointer immediately.
        \Illuminate\Support\Facades\DB::transaction(function () use ($article, $request): void {
            $locked = \App\Models\Article::query()->whereKey($article->id)
                ->lockForUpdate()->firstOrFail();
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
        ])->with('status', 'Article unpublished.');
    })->middleware('throttle:10,1')->name('admin.content.unpublish');

    // T020: generated artwork approval delegates to the existing domain
    // verifier. The web layer only captures an explicit human attestation;
    // it never reads private storage paths, prompts or provider metadata.
    Route::get('/admin/ai/images', function (Request $request) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate([
            'lang' => ['sometimes', 'in:ar,en'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
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
            ])->orderByDesc('job.created_at')->limit(20)->get();

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

        $model = \App\Models\AiModel::query()->with('provider')
            ->findOrFail($input['model_id']);
        $provider = $model->provider;
        $capabilities = $model->capabilities_json;
        $savedCredential = $provider?->getRawOriginal('secret_ciphertext');
        $eligible = $model->enabled
            && is_array($capabilities)
            && in_array('image-generation', $capabilities, true)
            && preg_match('/^[A-Za-z0-9_.:\\-]{1,90}$/D', (string) $model->provider_model_id) === 1
            && $provider !== null
            && ! $provider->trashed()
            && $provider->enabled
            && $provider->health_status === 'healthy'
            && $provider->provider_type === 'openai-compatible'
            && is_string($savedCredential)
            && $savedCredential !== '';
        if (! $eligible) {
            return back()->withErrors([
                'model_id' => $request->query('lang') === 'ar'
                    ? 'النموذج المحدد غير مؤهل حاليًا لتوليد الصور.'
                    : 'The selected model is not currently eligible for image generation.',
            ]);
        }

        $estimate = (int) $input['estimated_cost_minor'];
        $today = now('UTC')->startOfDay();
        $tomorrow = $today->copy()->addDay();
        $spentToday = (int) \Illuminate\Support\Facades\DB::table('ai_usage')
            ->where('provider_id', $provider->id)
            ->where('created_at', '>=', $today)
            ->where('created_at', '<', $tomorrow)
            ->sum('cost_minor');

        // Count still-unsettled reservations so repeated admin clicks cannot
        // overbook the configured daily ceiling before workers claim jobs.
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
            return back()->withErrors([
                'estimated_cost_minor' => $request->query('lang') === 'ar'
                    ? 'الحجز المطلوب يتجاوز الميزانية اليومية المتاحة للمزود.'
                    : 'The requested reservation exceeds the provider daily budget still available.',
            ]);
        }

        $revision = \App\Models\ArticleRevision::query()->with('article')
            ->findOrFail($input['revision_id']);
        try {
            $jobId = app(\App\AI\EditorialJobDispatcher::class)->enqueue(
                $revision, $provider, $model->provider_model_id, true, $estimate
            );
        } catch (\DomainException|\InvalidArgumentException $exception) {
            return back()->withErrors([
                'revision_id' => $request->query('lang') === 'ar'
                    ? 'المسودة تغيرت أو لم تعد مؤهلة. حدّث الصفحة وحاول من أحدث مسودة.'
                    : 'The draft changed or is no longer eligible. Refresh and use the latest draft.',
            ]);
        }

        return redirect()->route('admin.media.index', [
            'lang' => $request->query('lang') === 'ar' ? 'ar' : 'en',
        ])->with('status', $request->query('lang') === 'ar'
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
            $approved = app(\App\AI\EditorialArtworkRights::class)->approve(
                $asset,
                $request->user(),
                $request->boolean('licensed_for_publication'),
                $request->boolean('no_owner_impersonation')
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
        // Revocation is a fail-closed one-way admin action. Never expose
        // internal storage paths or silently restore licenses in this view.
        $asset->forceFill(['rights_status' => 'revoked', 'publishable' => false])->save();

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
        ]);

        $qualityRows = \App\Models\ArticleRevision::query()
            ->with(['article.heroAsset'])
            ->whereHas('article', fn ($article) => $article->where('status', 'draft'))
            ->whereRaw('article_revisions.revision_no = (SELECT MAX(latest.revision_no) FROM article_revisions AS latest WHERE latest.article_id = article_revisions.article_id)')
            ->orderByDesc('updated_at')->orderBy('id')->limit(50)->get()
            ->map(function ($revision): array {
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
            ->select([
                'job.id', 'job.job_type', 'job.status',
                'job.attempt_count', 'job.max_attempts',
                'job.next_attempt_at', 'job.last_error_code', 'job.updated_at',
                'provider.display_name as provider_name',
            ])
            ->orderByDesc('job.updated_at')->limit(50)->get();

        return view('admin.quality', compact('qualityRows', 'failedJobs'));
    })->name('admin.quality.index');

    Route::post('/admin/quality/jobs/{job}/retry', function (Request $request, string $job) {
        abort_unless($request->user()?->canAccessAdmin(), 403);
        $request->validate(['lang' => ['sometimes', 'in:ar,en']]);

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

        // Reuse the public API's active-owner, publication and safe-URL gates;
        // never expose raw claim records or unchecked user-supplied URLs.
        if ($profile && $professionalPage === 'about') {
            $approvedClaims = app(\App\Http\Controllers\Api\PublicPortfolioController::class)
                ->profile($request)->getData(true)['data']['claims'] ?? [];
        }

        return view('public.professional', [
            'page' => $professionalPage,
            'positions' => $positions,
            'certifications' => $certifications,
            'skills' => $skills,
            'approvedClaims' => $approvedClaims,
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
        ->orderByDesc('published_at')->orderBy('id')->lazy(100) as $record) {
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
Route::get('/contact', function (Request $request) {
    $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
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
        'message' => ['required', 'string', 'min:10', 'max:3000',
            'not_regex:/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/u'],
        'privacy_consent' => ['required', 'boolean', 'accepted'],
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

Route::get('/lab/runbooks/{id}', function (Request $request, string $id) {
    $request->validate(['lang' => ['sometimes', 'in:ar,en']]);
    // The detail is checked afresh on every read: a changed version, title,
    // review timestamp or checksum must fail closed with the API's own 404.
    $runbook = app(\App\Http\Controllers\Api\PublicRunbookController::class)
        ->show($id)->getData(true)['data'];

    return response()->view('public.lab-runbook', compact('runbook'))
        ->header('Cache-Control', 'no-store');
})->whereUuid('id')->name('public.lab.runbook')->middleware('throttle:60,1');

Route::get('/search', function (Request $request) {
    $input = $request->validate([
        'q' => ['sometimes', 'string', 'max:80'],
        'lang' => ['sometimes', 'in:ar,en'],
    ]);
    $search = trim($input['q'] ?? '');
    $articles = collect();
    $projects = collect();

    if ($search !== '') {
        $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        // Filter the pinned published revision's live evidence before
        // consuming one of ten search slots. Chunked DB iteration avoids
        // large in-memory lists even with long revoked prefixes.
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
            $articles->push($article);
            if ($articles->count() === 10) {
                break;
            }
        }

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
            $projects->push($project);
            if ($projects->count() === 10) {
                break;
            }
        }
    }
    return view('public.search', compact('search', 'articles', 'projects'));
})->name('public.search')->middleware('throttle:60,1');

