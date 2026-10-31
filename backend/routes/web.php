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

        // T016 owns the dashboard. This is explicitly unavailable until then.
        return response('Admin workspace is under development.', 503);
    })->name('admin.home');
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
        'name' => ['required', 'string', 'min:2', 'max:100'],
        'email' => ['required', 'email:rfc', 'max:254'],
        'subject' => ['required', 'string', 'min:3', 'max:140'],
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
