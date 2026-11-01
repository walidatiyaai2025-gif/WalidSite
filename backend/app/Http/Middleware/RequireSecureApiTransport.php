<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In production, never read login passwords or authenticate bearer credentials
 * over plaintext HTTP. The reverse proxy must be explicitly trusted for its
 * forwarded scheme to affect Request::isSecure().
 *
 * Public read-only API routes deliberately do not use this middleware.
 */
final class RequireSecureApiTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            // No redirect: credentials and bearer headers must not be copied
            // to another location, nor processed by downstream auth handlers.
            abort(403, 'HTTPS is required for authenticated API requests.');
        }

        return $next($request);
    }
}
