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
        // T011: one explicit registry per application process. T014 may
        // register reviewed job handlers during provider boot; no arbitrary
        // handler class names are ever resolved from database payloads.
        $this->app->singleton(\App\AI\AiStepHandlerRegistry::class);
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
