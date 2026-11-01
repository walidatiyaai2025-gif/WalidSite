<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/site-maintenance.php'));
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group(base_path('routes/editorial-automation.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reject insecure admin traffic before the web middleware starts
        // a session or processes CSRF and credentials. All admin routes,
        // including those registered in auxiliary route files, are covered.
        // This separate PII gate must also run before web sessions/CSRF.
        $middleware->prepend(\App\Http\Middleware\RequireSecureContactTransport::class);
        $middleware->prepend(\App\Http\Middleware\RequireSecureAdminTransport::class);
        // Session and CSRF use Laravel's standard 'web' middleware group.
        $middleware->web(append: [\App\Http\Middleware\RequireInstallation::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel flashes invalid form fields into the session by default.
        // Credentials for the cPanel first-run wizard must never be retained.
        $exceptions->dontFlash(['db_password', 'token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
