<?php

namespace App\Http\Middleware;

use App\Support\Deployment\SiteInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireInstallation
{
    public function __construct(private readonly SiteInstaller $installer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('site.setup_enabled')
            || strlen((string) config('site.setup_token')) < 32
            || $request->is('setup') || $request->is('setup/*')
            || $request->is('install') || $request->is('install/*')
            || $this->installer->isInstalled()) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            // A fresh one-extract cPanel visit must enter the canonical 15-stage
            // installation journey. /setup stays directly addressable for
            // guarded recovery of older interrupted first-run installations.
            return redirect()->route('site.install');
        }

        abort(503, 'The site is awaiting first-run setup.');
    }
}
