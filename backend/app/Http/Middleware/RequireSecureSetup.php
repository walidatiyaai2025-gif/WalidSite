<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Never accept the first-run setup token, database credentials or owner
 * password over plaintext HTTP on a production installation.
 *
 * A reverse proxy must be configured as a trusted proxy for Request::isSecure
 * to honor its forwarded scheme. Unknown forwarded headers are not trusted.
 */
final class RequireSecureSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            abort(403, 'HTTPS is required for setup.');
        }

        return $next($request);
    }
}
