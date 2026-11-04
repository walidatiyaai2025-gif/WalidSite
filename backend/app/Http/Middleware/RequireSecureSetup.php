<?php

namespace App\Http\Middleware;

use App\Support\Deployment\SiteInstaller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Never accept the first-run setup token, database credentials or owner
 * password over plaintext HTTP on an Internet-facing installation,
 * regardless of accidentally inherited APP_ENV=testing/local.
 *
 * A reverse proxy must be configured as a trusted proxy for Request::isSecure
 * to honor its forwarded scheme. Unknown forwarded headers are not trusted.
 */
final class RequireSecureSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        // Permit plain HTTP only for genuinely local development/test hosts.
        // An external Host never gets a test-environment HTTPS exemption.
        $isLocalHost = in_array($request->getHost(), ['localhost', '127.0.0.1', '::1'], true);
        if (! $request->isSecure() && ! ($isLocalHost && app()->environment(['local', 'testing']))) {
            // Preserve the public 404 contract for disabled or completed
            // installations before disclosing that setup requires HTTPS.
            // No setup token or credential is read or validated here.
            abort_unless(app(SiteInstaller::class)->allowed(), 404);
            abort(403, 'HTTPS is required for setup.');
        }

        return $next($request);
    }
}
