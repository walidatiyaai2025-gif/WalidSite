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


/*
 * T007 public profile endpoints. Only an active owner account with a published
 * profile may expose professional proof, and each child item has its own gate.
 * The seed is deliberately "review" and never bypasses these gates.
 */
Route::get('/about', function () {
    $profile = \App\Models\Profile::published()
        ->whereHas('user', fn ($q) => $q->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
        ->first();

    return view('public.about', [
        'profile' => $profile,
        'positions' => $profile
            ? \App\Models\CareerPosition::publiclyVerified()
                ->where('profile_id', $profile->id)->orderByDesc('started_on')->get()
            : collect(),
        'certifications' => $profile
            ? \App\Models\Certification::publiclyVerified()
                ->where('profile_id', $profile->id)->orderBy('title')->get()
            : collect(),
    ]);
})->name('public.about');

Route::get('/expertise', function (Request $request) {
    $profile = \App\Models\Profile::published()
        ->whereHas('user', fn ($q) => $q->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
        ->first();
    $skills = $profile
        ? \Illuminate\Support\Facades\DB::table('skills')
            ->join('profile_skill', 'skills.id', '=', 'profile_skill.skill_id')
            ->where('profile_skill.profile_id', $profile->id)
            ->where('skills.evidence_status', 'verified')
            ->orderBy('skills.category')->orderBy('skills.name_en')
            ->get(['skills.name_en', 'skills.name_ar', 'skills.category'])
        : collect();
    $categories = $skills->pluck('category')->unique()->values()->all();
    $category = mb_substr(trim((string) $request->query('category', '')), 0, 80);
    $filtered = $category === '' ? $skills : $skills->where('category', $category);

    return view('public.expertise', compact('profile', 'skills', 'categories', 'category', 'filtered'));
})->name('public.expertise');

Route::get('/hire', function (Request $request) {
    $tracks = [
        'database' => 'Database & Reliability',
        'software' => 'Software & Integration',
        'delivery' => 'Project Delivery',
    ];
    $track = (string) $request->query('track', 'database');
    if (! array_key_exists($track, $tracks)) {
        $track = 'database';
    }
    $profile = \App\Models\Profile::published()
        ->whereHas('user', fn ($q) => $q->where('status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'owner')))
        ->first();
    $proof = $profile
        ? \Illuminate\Support\Facades\DB::table('skills')
            ->join('profile_skill', 'skills.id', '=', 'profile_skill.skill_id')
            ->where('profile_skill.profile_id', $profile->id)
            ->where('skills.evidence_status', 'verified')
            ->where('skills.category', $tracks[$track])
            ->orderBy('skills.name_en')->get(['skills.name_en', 'skills.name_ar'])
        : collect();

    return view('public.hire', compact('profile', 'proof', 'track', 'tracks'));
})->name('public.hire');
