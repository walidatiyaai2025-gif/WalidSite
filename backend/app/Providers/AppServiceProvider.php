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
        // One shared lease per console process; nested Artisan calls must
        // not release the outer scheduler/worker's lock prematurely.
        $this->app->singleton(\App\Domain\Deployment\CpanelConsoleWriterLease::class);
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
        if (config('app.front_controller_urls') === true) {
            \App\Domain\Deployment\CpanelNoRewriteUrls::apply(
                $this->app['url'],
                (string) config('app.url'),
                config('app.asset_url'),
            );
        }

        if ($this->app->runningInConsole()) {
            // On a physical cPanel install, all Laravel CLI writers (including
            // schedule:run, queue:work and nested commands) hold a shared
            // process-lifetime lock. The owner updater will need its exclusive
            // counterpart before a real recoverable backup/cutover.
            \Illuminate\Support\Facades\Event::listen(
                \Illuminate\Console\Events\CommandStarting::class,
                static function (): void {
                    app(\App\Domain\Deployment\CpanelConsoleWriterLease::class)
                        ->beginForConsole(base_path());
                },
            );
            \Illuminate\Support\Facades\Event::listen(
                \Illuminate\Console\Events\CommandFinished::class,
                static function (): void {
                    app(\App\Domain\Deployment\CpanelConsoleWriterLease::class)->end();
                },
            );
        }
        // Authentication attempts must not consume the database/owner step
        // throttle budget or block a legitimate first-time installation.
        RateLimiter::for('site-setup-unlock', static fn (Request $request) =>
            Limit::perMinute(5)->by('site-setup-unlock|'.$request->ip())
        );
        RateLimiter::for('site-setup-actions', static function (Request $request): Limit {
            // Both first-run entrypoints share the same strict possession-
            // token attempt budget. Fifteen ordinary wizard NEXT actions must
            // not exhaust that budget after the token was validated.
            if ($request->is('install/step/1')) {
                return Limit::perMinute(5)->by('site-setup-unlock|'.$request->ip());
            }
            if ($request->hasSession()
                && $request->session()->get('site_setup_authorized') === true) {
                // A possession-verified owner can finish all fifteen stages
                // without a spurious 429. The authenticated bucket is still
                // rate-limited, and cannot be exhausted by anonymous traffic.
                return Limit::perMinute(30)->by('site-setup-authorized|'.$request->ip());
            }
            return Limit::perMinute(12)->by('site-setup-actions|'.$request->ip());
        });
    }
}
