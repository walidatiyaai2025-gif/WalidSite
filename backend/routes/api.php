<?php

use App\Http\Controllers\Api\IdentityController;
use App\Http\Controllers\Api\AiProviderController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AdminArticleController;
use App\Http\Controllers\Api\PublicPortfolioController;
use App\Http\Controllers\Api\PublicAiExpertiseController;
use App\Http\Controllers\Api\PublicHomeController;
use App\Http\Controllers\Api\MobileHomeController;
use App\Http\Controllers\Api\MobileSavedController;
use App\Http\Controllers\Api\MobileBootstrapController;
use App\Http\Controllers\Api\PublicLabController;
use App\Http\Controllers\Api\PublicArticleController;
use App\Http\Controllers\Api\PublicRunbookController;
use App\Http\Controllers\Api\PublicLearningPathController;
use App\Http\Controllers\Api\PublicSearchController;
use App\Http\Controllers\Api\PublicResumeController;
use App\Http\Controllers\Api\PublicRecruiterController;
use App\Http\Controllers\Api\PublicGithubPortfolioController;
use App\Http\Controllers\Api\PublicContactController;
use App\Http\Middleware\RequireSecureApiTransport;
use Illuminate\Support\Facades\Route;

// Transport is checked before any login credentials are validated.
Route::post('/auth/login', [IdentityController::class, 'login'])
    ->middleware([RequireSecureApiTransport::class, 'throttle:6,1']);

// Block insecure bearer-token requests before Sanctum or privileged handlers.
Route::middleware([RequireSecureApiTransport::class, 'auth:sanctum'])->group(function (): void {
    Route::get('/mobile/me', [IdentityController::class, 'me']);
    // MOBILE-11: token-scoped, own-account saved resources. Live publication
    // authorization is checked again before every saved item is returned.
    Route::get('/mobile/saved', [MobileSavedController::class, 'index'])
        ->name('api.mobile.saved')->middleware('throttle:60,1');
    Route::post('/mobile/saved', [MobileSavedController::class, 'store'])
        ->name('api.mobile.saved.store')->middleware('throttle:30,1');
    Route::delete('/mobile/saved/{resource_type}/{resource_id}', [MobileSavedController::class, 'destroy'])
        ->where('resource_type', 'article|runbook')->whereUuid('resource_id')
        ->name('api.mobile.saved.destroy')->middleware('throttle:30,1');
    Route::post('/auth/logout', [IdentityController::class, 'logout']);
    Route::get('/admin/identity-check', [IdentityController::class, 'adminCheck']);
    // ADMIN-01: aggregated persisted metrics, never fabricated health or spend.
    Route::get('/admin/dashboard', [AdminDashboardController::class, 'index'])
        ->name('api.admin.dashboard')->middleware('throttle:60,1');

    // ADMIN-02: edit queue metadata only, never expose raw drafts or citations.
    Route::get('/admin/articles', [AdminArticleController::class, 'index'])
        ->name('api.admin.articles')->middleware('throttle:60,1');

    // Explicit administrator-scoped token + role required in the controller.
    Route::get('/admin/ai/providers', [AiProviderController::class, 'index']);
    Route::post('/admin/ai/providers', [AiProviderController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/admin/ai/providers/{provider}', [AiProviderController::class, 'show']);
    Route::get('/admin/ai/providers/{provider}/audit', [AiProviderController::class, 'auditHistory'])
        ->middleware('throttle:60,1');
    Route::patch('/admin/ai/providers/{provider}', [AiProviderController::class, 'update'])->middleware('throttle:20,1');
    Route::delete('/admin/ai/providers/{provider}', [AiProviderController::class, 'destroy'])
        ->name('api.admin.ai.providers.destroy');
    Route::post('/admin/ai/providers/{provider}/test', [AiProviderController::class, 'test'])->middleware('throttle:5,1');
});

// MOBILE-01: guest-only bootstrap and local preference capabilities. No
// private config, server-persisted user preferences or invented policy URLs.
Route::get('/public/app-bootstrap', [MobileBootstrapController::class, 'bootstrap'])
    ->name('api.public.app-bootstrap')->middleware('throttle:60,1');
Route::get('/mobile/preferences', [MobileBootstrapController::class, 'preferences'])
    ->name('api.mobile.preferences')->middleware('throttle:60,1');

// WEB-01: same evidence-qualified owner, project and article feed as the web home.
Route::get('/public/home', [PublicHomeController::class, 'index'])
    ->name('api.public.home')->middleware('throttle:60,1');
Route::get('/public/projects', [PublicPortfolioController::class, 'projects'])
    ->name('api.public.projects')->middleware('throttle:60,1');
Route::get('/public/projects/{slug}', [PublicPortfolioController::class, 'project'])
    ->where('slug', '[a-zA-Z0-9-]+')->name('api.public.project')->middleware('throttle:60,1');
Route::get('/public/profile', [PublicPortfolioController::class, 'profile'])
    ->name('api.public.profile')->middleware('throttle:60,1');
Route::get('/public/expertise', [PublicPortfolioController::class, 'expertise'])
    ->name('api.public.expertise')->middleware('throttle:60,1');
// WEB-06: AI expertise and case studies require live public evidence checks.
Route::get('/public/expertise/ai', [PublicAiExpertiseController::class, 'index'])
    ->name('api.public.expertise.ai')->middleware('throttle:60,1');
Route::get('/public/resume', [PublicResumeController::class, 'index'])
    ->name('api.public.resume')->middleware('throttle:60,1');
Route::get('/public/recruiter', [PublicRecruiterController::class, 'index'])
    ->name('api.public.recruiter')->middleware('throttle:60,1');
// WEB-10: public repositories only after live GitHub visibility verification.
Route::get('/public/github/portfolio', [PublicGithubPortfolioController::class, 'index'])
    ->name('api.public.github.portfolio')->middleware('throttle:60,1');
Route::get('/public/search', [PublicSearchController::class, 'index'])
    ->name('api.public.search')->middleware('throttle:60,1');

// WEB-14: anonymous consent-based contact, HTTPS required before accepting PII.
Route::post('/public/contact', [PublicContactController::class, 'store'])
    ->name('api.public.contact')->middleware([RequireSecureApiTransport::class, 'throttle:4,1']);

// WEB-09: verified articles and signed-off runbooks only; no invented tools.
Route::get('/public/tools', [PublicLabController::class, 'index'])
    ->name('api.public.tools')->middleware('throttle:60,1');

// MOBILE-02: reviewed guest home, no account-specific saved resources.
Route::get('/mobile/home', [MobileHomeController::class, 'index'])
    ->name('api.mobile.home')->middleware('throttle:60,1');

// MOBILE-08: published-only learning tracks with no private user progress.
Route::get('/mobile/learn', [PublicLearningPathController::class, 'index'])
    ->name('api.mobile.learn')->middleware('throttle:60,1');

// MOBILE-04/MOBILE-05: anonymous read-only, reviewed version-pinned guides.
// A missing/unreviewed guide is an honest 404; never return the bundled draft.
Route::get('/mobile/runbooks', [PublicRunbookController::class, 'index'])
    ->name('api.mobile.runbooks')->middleware('throttle:60,1');
Route::get('/mobile/solutions/{id}', [PublicRunbookController::class, 'show'])
    ->whereUuid('id')->name('api.mobile.solution')->middleware('throttle:60,1');

// T012 read-only editorial API: pinned, citation-checked public revisions only.
Route::get('/public/articles', [PublicArticleController::class, 'index'])
    ->name('api.public.articles')->middleware('throttle:60,1');

// Canonical /mobile/articles contract is the SAME evidenced editorial feed.
// Never introduce a separate mobile publication or citation policy.
Route::get('/mobile/articles', [PublicArticleController::class, 'index'])
    ->name('api.mobile.articles')->middleware('throttle:60,1');
Route::get('/public/articles/{slug}', [PublicArticleController::class, 'show'])
    ->where('slug', '[a-zA-Z0-9-]+')->name('api.public.article')->middleware('throttle:60,1');
