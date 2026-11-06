<?php

namespace App\Http\Middleware;

use App\Domain\Deployment\CpanelConsoleWriterLease;
use Closure;
use Illuminate\Http\Request;
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
    public function __construct(
        private readonly CpanelConsoleWriterLease $lease,
        private readonly ?string $applicationBase = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
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
            if ($response instanceof StreamedResponse) {
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
}
