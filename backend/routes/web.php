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
        return redirect()->route('admin.providers.index');
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
