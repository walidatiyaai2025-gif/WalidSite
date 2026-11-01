<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public web contact POST accepts names, email addresses and messages.
 * Check transport globally before web session/CSRF/validation middleware so
 * plaintext production submissions are rejected without parsing private data.
 * The contact page GET remains public; hosting still must configure TLS/HSTS.
 */
final class RequireSecureContactTransport
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production')
            && $request->is('contact')
            && ! $request->isMethod('GET')
            && ! $request->isMethod('HEAD')
            && ! $request->isSecure()) {
            // Never redirect plaintext submitted PII to another location.
            abort(403, 'HTTPS is required for contact submissions.');
        }

        return $next($request);
    }
}
