<?php

namespace App\Http\Middleware;

use App\Support\Deployment\SiteInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireInstallation
{
    public function handle(Request $request, Closure $next, SiteInstaller $installer): Response
    {
        if (! config('site.setup_enabled')
            || strlen((string) config('site.setup_token')) < 32
            || $request->is('setup') || $request->is('setup/*')
            || $installer->isInstalled()) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->route('site.setup');
        }

        abort(503, 'The site is awaiting first-run setup.');
    }
}
