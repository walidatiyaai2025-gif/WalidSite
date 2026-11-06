<?php

namespace App\Http\Middleware;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use App\Domain\Deployment\CpanelReleaseMaintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Protect dynamic HTTP requests with the same private shared lease already
 * held by Laravel CLI, queue and scheduler workers.
 *
 * This does not authorize owner updates. A later owner-only controller must
 * release its shared HTTP lease and acquire the EXCLUSIVE writer-drain lock
 * before starting maintenance, backing up any data or changing code. If a
 * concurrent request wins that race the exclusive attempt fails CLOSED.
 *
 * Independent direct-SQL/foreign cron writers still require separate proof.
 */
final class CpanelHttpWriterBarrier
{
    private const BINARY_LEASE_ATTRIBUTE = '_walidsite_cpanel_binary_writer_lease';

    public function __construct(
        private readonly CpanelConsoleWriterLease $lease,
        private readonly ?string $applicationBase = null,
        private readonly ?string $homeDirectory = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The immutable cPanel dispatcher admits this 256-bit token ONLY
        // during an active maintenance lease. Verify it again from the pinned
        // private marker before Laravel's global writer-lock middleware.
        // A health probe cannot acquire LOCK_SH while the owner updater holds
        // LOCK_EX. Bypass is restricted to the read-only framework GET /up,
        // never an Admin, API, upload, POST, query or arbitrary route.
        if ($request->isMethod('GET') && $request->path() === 'up'
            && $request->query() === []
            && $request->headers->has('X-Walidsite-Release-Probe')) {
            try {
                $token = $request->headers->get('X-Walidsite-Release-Probe');
                if (! is_string($token)) {
                    throw new \DomainException('Malformed health probe token.');
                }
                (new CpanelReleaseMaintenance())->assertLiveHealthProbe(
                    $this->homeDirectory ?? dirname(public_path()), $token,
                );
            } catch (Throwable) {
                return new Response('Site maintenance is unavailable.', 503, [
                    'Cache-Control' => 'no-store',
                    'Retry-After' => '30',
                ]);
            }

            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store');
            return $response;
        }

        try {
            $this->lease->beginForConsole($this->applicationBase ?? base_path());
        } catch (Throwable) {
            // Never reveal private paths, lock identities or release tokens to
            // visitors. A corrupt or interrupted release serves no PHP work.
            return new Response('Site maintenance is unavailable.', 503, [
                'Cache-Control' => 'no-store',
                'Retry-After' => '30',
            ]);
        }

        $deferred = false;
        try {
            $response = $next($request);
            if ($response instanceof BinaryFileResponse) {
                // sendContent() happens after ordinary middleware returns.
                // Keep the lease alive through Kernel::terminate(), which runs
                // after binary content has been sent, including X-Sendfile
                // delegated responses. Store the lease on this *request*,
                // rather than a singleton that can cross request boundaries.
                $request->attributes->set(self::BINARY_LEASE_ATTRIBUTE, $this->lease);
                $deferred = true;
            } elseif ($response instanceof StreamedResponse) {
                // Laravel sends streamDownload callbacks *after* ordinary
                // middleware has returned. Retain the lock until those bytes
                // (and any associated application work) are finished.
                $callback = $response->getCallback();
                if (is_callable($callback)) {
                    $lease = $this->lease;
                    $response->setCallback(static function () use ($callback, $lease): void {
                        try {
                            $callback();
                        } finally {
                            $lease->end();
                        }
                    });
                    $deferred = true;
                }
            }

            return $response;
        } finally {
            // Exceptions and non-streamed responses must never strand a
            // shared lease, which would starve owner-only recovery.
            if (! $deferred) {
                $this->lease->end();
            }
        }
    }

    /**
     * Laravel calls terminable middleware after the response is sent. For
     * BinaryFileResponse the ordinary handle() finally is too early.
     */
    public function terminate(Request $request, Response $response): void
    {
        $lease = $request->attributes->get(self::BINARY_LEASE_ATTRIBUTE);
        if ($lease instanceof CpanelConsoleWriterLease) {
            $request->attributes->remove(self::BINARY_LEASE_ATTRIBUTE);
            $lease->end();
        }
    }
}
