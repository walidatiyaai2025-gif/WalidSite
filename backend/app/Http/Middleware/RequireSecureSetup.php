<?php

namespace App\Http\Middleware;

use App\Support\Deployment\SiteInstaller;
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
    public function handle(Request $request, Closure $next, SiteInstaller $installer): Response
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            // Preserve the public 404 contract for disabled or completed
            // installations before disclosing that setup requires HTTPS.
            // No setup token or credential is read or validated here.
            abort_unless($installer->allowed(), 404);
            abort(403, 'HTTPS is required for setup.');
        }

        return $next($request);
    }
}
