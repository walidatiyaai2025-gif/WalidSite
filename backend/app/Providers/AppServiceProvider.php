<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // T011/T014: construct the trusted handler allowlist once per app
        // container. Plain schedule:run / ai:tick must not require a manual
        // test-only registration step before editorial work can run.
        // Handler class names never come from jobs, DB rows, or HTTP input.
        $this->app->singleton(\App\AI\AiStepHandlerRegistry::class, function ($app) {
            $registry = new \App\AI\AiStepHandlerRegistry();
            $app->make(\App\AI\EditorialJobDispatcher::class)
                ->registerHandlers($registry);
            $app->make(\App\AI\MobileDiagnosisDispatcher::class)
                ->registerHandlers($registry);

            return $registry;
        });
        (new \App\Support\Deployment\FirstRunBootstrap())->prepare();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Authentication attempts must not consume the database/owner step
        // throttle budget or block a legitimate first-time installation.
        RateLimiter::for('site-setup-unlock', static fn (Request $request) =>
            Limit::perMinute(5)->by('site-setup-unlock|'.$request->ip())
        );
        RateLimiter::for('site-setup-actions', static fn (Request $request) =>
            Limit::perMinute(12)->by('site-setup-actions|'.$request->ip())
        );
    }
}
