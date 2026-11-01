<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject production admin credentials and browser session requests before
 * Laravel starts sessions, validates CSRF tokens or authenticates the owner.
 *
 * Applies to all routes under /admin, including routes declared in separate
 * maintenance/editorial files. Public pages and first-run setup are unchanged.
 *
 * Trust proxy headers only when Laravel's trusted-proxy configuration allows
 * them. Hosting must still terminate HTTPS and set HSTS/secure cookies.
 */
final class RequireSecureAdminTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')
            && ($request->is('admin') || $request->is('admin/*'))
            && ! $request->isSecure()) {
            // Never redirect: plaintext password/session requests must fail
            // closed instead of forwarding their payloads to another URL.
            abort(403, 'HTTPS is required for administration.');
        }

        return $next($request);
    }
}
